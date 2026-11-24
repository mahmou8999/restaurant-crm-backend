<?php

namespace App\Services\WhatsApp;

use App\Models\Campaign;
use App\Models\SystemSetting;
use App\Models\WhatsAppMessage;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CampaignPacing
{
    private const TIMEZONE = 'Asia/Riyadh';

    public function lockKey(int $accountId): string
    {
        return 'whatsapp-campaign-pacing-'.$accountId;
    }

    public function dailyLimit(): int
    {
        return max(1, (int) (SystemSetting::query()->where('key', 'daily_limit')->value('value') ?? 40));
    }

    public function nextAllowedAt(): ?Carbon
    {
        $timestamp = Cache::get('whatsapp-campaign-next-allowed-at');

        return is_numeric($timestamp) ? Carbon::createFromTimestamp((int) $timestamp) : null;
    }

    public function scheduleNextMessage(int $sentToday): void
    {
        $minimumDelay = max(0, (int) (SystemSetting::query()->where('key', 'delay_min')->value('value') ?? 30));
        $maximumDelay = max($minimumDelay, (int) (SystemSetting::query()->where('key', 'delay_max')->value('value') ?? 60));
        $delay = $sentToday % 20 === 0 ? 600 : random_int($minimumDelay, $maximumDelay);

        Cache::put('whatsapp-campaign-next-allowed-at', now()->addSeconds($delay)->timestamp, now()->addDay());
    }

    public function sentToday(): int
    {
        $now = Carbon::now(self::TIMEZONE);

        return WhatsAppMessage::query()
            ->whereNotNull('campaign_id')
            ->whereIn('status', ['Sent', 'Delivered', 'Read'])
            ->whereBetween('created_at', [
                $now->copy()->startOfDay()->utc(),
                $now->copy()->endOfDay()->utc(),
            ])
            ->count();
    }

    public function secondsUntilAllowedWindow(): int
    {
        $now = Carbon::now(self::TIMEZONE);
        $opening = $now->copy()->setTime(12, 0);

        if ($now->lt($opening)) {
            return max(1, $opening->getTimestamp() - $now->getTimestamp());
        }

        if ($now->hour >= 21) {
            $opening->addDay();

            return max(1, $opening->getTimestamp() - $now->getTimestamp());
        }

        return 0;
    }

    public function secondsUntilNextDayLimitReset(): int
    {
        $now = Carbon::now(self::TIMEZONE);
        $nextOpening = $now->copy()->addDay()->setTime(12, 0);

        return max(1, $nextOpening->getTimestamp() - $now->getTimestamp());
    }

    public function postponePendingRecipients(Campaign $campaign): void
    {
        $availableAt = Carbon::now(self::TIMEZONE)->addDay()->setTime(9, 0)->utc();
        DB::transaction(function () use ($campaign, $availableAt): void {
            $campaign->update(['status' => 'Blocked']);
            $campaign->recipients()
                ->where('status', 'Pending')
                ->update([
                    'status' => 'Postponed',
                    'available_at' => $availableAt,
                    'error' => 'WhatsApp connection lost; manual resume required.',
                ]);
        });
    }
}
