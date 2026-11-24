<?php

namespace App\Services;

use App\Models\BotMenu;
use App\Models\BotRule;
use App\Models\BotSession;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\WhatsAppSenderFactory;

class BotAutomationService
{
    public function __construct(private WhatsAppSenderFactory $senders) {}

    public function handleIncoming(Conversation $conversation, Customer $customer, string $body): void
    {
        $account = WhatsAppAccount::query()->where('is_active', true)->first();
        if (! $account) {
            return;
        }

        $session = BotSession::firstOrCreate(
            ['customer_id' => $customer->id],
            ['state' => 'idle']
        );

        $normalized = mb_strtolower(trim($body));

        if ($session->activeMenu) {
            $option = $session->activeMenu->options()
                ->get()
                ->first(fn ($item) => mb_strtolower(trim($item->title)) === $normalized);

            if ($option) {
                if ($option->action_type === 'transfer') {
                    $conversation->update(['status' => 'open', 'assigned_to' => null]);
                    $session->update(['active_menu_id' => null, 'state' => 'handoff']);
                    $this->sendReply(
                        $account,
                        $conversation,
                        $option->action_value ?: 'تم تحويل المحادثة لموظف'
                    );

                    return;
                }

                $reply = $option->action_type === 'message'
                    ? $option->action_value
                    : $option->action_value;
                $this->sendReply($account, $conversation, $reply);

                return;
            }
        }

        $rule = BotRule::query()
            ->where('is_active', true)
            ->get()
            ->first(function (BotRule $item) use ($normalized) {
                $trigger = mb_strtolower(trim($item->trigger_value));

                return $item->trigger_type === 'exact'
                    ? $normalized === $trigger
                    : str_contains($normalized, $trigger);
            });

        if ($rule) {
            $this->sendReply($account, $conversation, $rule->response);

            return;
        }

        $activeMenu = BotMenu::query()->where('is_active', true)->first();
        if ($activeMenu) {
            $lines = $activeMenu->options->map(
                fn ($option, $index) => ($index + 1).'. '.$option->title
            )->implode("\n");
            $session->update([
                'active_menu_id' => $activeMenu->id,
                'state' => 'menu',
            ]);
            $this->sendReply(
                $account,
                $conversation,
                trim($activeMenu->title."\n".$lines)
            );
        }
    }

    private function sendReply(
        WhatsAppAccount $account,
        Conversation $conversation,
        string $body
    ): void {
        $driver = $this->senders->driver($account);
        $result = $this->senders->make($account)->sendText(
            $conversation->customer->phone,
            $body
        );
        $wamid = data_get($result, 'messages.0.id')
            ?? data_get($result, 'key.id')
            ?? data_get($result, 'id');
        $message = WhatsAppMessage::recordOutbound([
            'customer_id' => $conversation->customer_id,
            'wamid' => $wamid,
            'direction' => 'outbound',
            'status' => 'Sent',
            'body' => $body,
            'meta_payload' => $result,
            'provider' => $driver,
        ]);
        ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'whatsapp_message_id' => $message->id,
            'sender_type' => 'bot',
            'body' => $body,
        ]);
        $conversation->update(['status' => 'open', 'last_message_at' => now()]);
    }
}
