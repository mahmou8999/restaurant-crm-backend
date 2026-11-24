<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\CampaignPacing;
use App\Services\WhatsApp\WhatsAppConnectionException;
use App\Services\WhatsApp\WhatsAppSenderFactory;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class SendCampaignMessage implements ShouldQueue
{
    use Queueable;

    public int $timeout = 40;

    public function __construct(public int $recipientId) {}

    public function retryUntil(): DateTimeInterface
    {
        return now()->addDays(30);
    }

    public function handle(WhatsAppSenderFactory $senders, CampaignPacing $pacing): void
    {
        $recipient = CampaignRecipient::with(['campaign.template', 'customer'])->findOrFail($this->recipientId);
        $campaign = $recipient->campaign;
        if ($recipient->status !== 'Pending' || $campaign->status !== 'Running') {
            return;
        }

        if ($recipient->available_at?->isFuture()) {
            $this->release(max(1, $recipient->available_at->timestamp - now()->timestamp));

            return;
        }
        if ($recipient->available_at) {
            $recipient->update(['available_at' => null]);
        }

        if (! $recipient->customer->hasMarketingConsent()) {
            $recipient->update([
                'status' => 'Failed',
                'failed_at' => now(),
                'error' => 'Marketing consent revoked',
            ]);
            $this->completeCampaignIfFinished($campaign);

            return;
        }

        $account = WhatsAppAccount::query()->where('is_active', true)->first();
        $driver = $senders->driver($account);
        if ((! $account && $driver === 'meta') || ! $campaign->template) {
            $recipient->update([
                'status' => 'Failed',
                'failed_at' => now(),
                'error' => 'WhatsApp account or template missing',
            ]);
            $this->completeCampaignIfFinished($campaign);

            return;
        }

        $lock = Cache::lock($pacing->lockKey($account?->id ?? 0), 60);
        if (! $lock->get()) {
            $this->release(5);

            return;
        }

        try {
            $recipient->refresh();
            $campaign->refresh();
            if ($recipient->status !== 'Pending' || $campaign->status !== 'Running') {
                return;
            }

            if ($driver === 'evolution') {
                try {
                    if (! $senders->evolution($account)->isConnected()) {
                        $pacing->postponePendingRecipients($campaign);

                        return;
                    }
                } catch (WhatsAppConnectionException) {
                    $pacing->postponePendingRecipients($campaign);

                    return;
                }
            }

            $windowDelay = $pacing->secondsUntilAllowedWindow();
            if ($windowDelay > 0) {
                $this->release($windowDelay);

                return;
            }

            $sentToday = $pacing->sentToday();
            if ($sentToday >= $pacing->dailyLimit()) {
                $this->release($pacing->secondsUntilNextDayLimitReset());

                return;
            }

            $nextAllowedAt = $pacing->nextAllowedAt();
            if ($nextAllowedAt && $nextAllowedAt->isFuture()) {
                $this->release(max(1, $nextAllowedAt->timestamp - now()->timestamp));

                return;
            }

            try {
                $result = $senders->sendTemplate(
                    $account,
                    $recipient->customer->phone,
                    $campaign->template,
                    $recipient->customer->name
                );
            } catch (WhatsAppConnectionException) {
                $pacing->postponePendingRecipients($campaign);

                return;
            } catch (RuntimeException $exception) {
                $recipient->update([
                    'status' => 'Failed',
                    'failed_at' => now(),
                    'error' => $exception->getMessage(),
                ]);
                Log::warning('Campaign message failed to send.', [
                    'campaign_id' => $campaign->id,
                    'recipient_id' => $recipient->id,
                    'error' => $exception->getMessage(),
                ]);
                $this->completeCampaignIfFinished($campaign);

                return;
            }

            $wamid = data_get($result, 'messages.0.id')
                ?? data_get($result, 'key.id')
                ?? data_get($result, 'message.key.id')
                ?? data_get($result, 'id');
            WhatsAppMessage::recordOutbound([
                'customer_id' => $recipient->customer_id,
                'campaign_id' => $recipient->campaign_id,
                'wamid' => is_string($wamid) ? $wamid : null,
                'direction' => 'outbound',
                'status' => 'Sent',
                'body' => $driver === 'evolution'
                    ? $this->renderEvolutionTemplate($campaign->template->body, $campaign->template->components, $recipient->customer->name)
                    : null,
                'meta_payload' => $result,
                'provider' => $driver,
            ]);
            $recipient->update(['status' => 'Sent', 'sent_at' => now(), 'error' => null]);
            $pacing->scheduleNextMessage($pacing->sentToday());
            $this->completeCampaignIfFinished($campaign);
        } finally {
            $lock->release();
        }
    }

    private function completeCampaignIfFinished(Campaign $campaign): void
    {
        if ($campaign->status === 'Running'
            && ! $campaign->recipients()->whereIn('status', ['Pending', 'Postponed'])->exists()) {
            $campaign->update(['status' => 'Completed']);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $components
     */
    private function renderEvolutionTemplate(?string $body, ?array $components, string $customerName): ?string
    {
        if (! $body) {
            $bodyComponent = collect($components ?? [])
                ->first(fn (array $component): bool => strtoupper($component['type'] ?? '') === 'BODY');
            $body = is_array($bodyComponent) ? ($bodyComponent['text'] ?? null) : null;
        }

        return is_string($body) ? str_replace('{{name}}', $customerName, $body) : null;
    }
}
