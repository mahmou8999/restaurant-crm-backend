<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppWebhookEvent;
use App\Services\BotAutomationService;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EvolutionWebhookController extends Controller
{
    public function receive(Request $request): JsonResponse
    {
        $secret = (string) (config('whatsapp.evolution_webhook_secret')
            ?: config('whatsapp.evolution_key'));
        $providedSecret = (string) $request->header('x-evolution-webhook-secret');
        if ($secret === '' || ! hash_equals($secret, $providedSecret)) {
            return response()->json(['message' => 'Invalid webhook secret.'], 401);
        }

        $payload = $request->all();
        $event = str_replace('_', '.', strtolower((string) data_get($payload, 'event')));
        $instance = (string) data_get($payload, 'instance');
        $account = WhatsAppAccount::query()
            ->where('driver', 'evolution')
            ->where('instance_name', $instance)
            ->where('is_active', true)
            ->first();

        if (! $account) {
            return response()->json(['message' => 'Unknown Evolution instance.'], 404);
        }

        if ($event === 'connection.update') {
            $state = data_get($payload, 'data.state')
                ?? data_get($payload, 'data.instance.state')
                ?? 'close';
            $connected = $state === 'open';
            $wasConnected = $account->session_status === 'connected';
            $account->update([
                'session_status' => $connected ? 'connected' : 'disconnected',
                'last_connected_at' => $connected && ! $wasConnected ? now() : $account->last_connected_at,
            ]);

            return response()->json(['ok' => true]);
        }

        if ($event === 'messages.update') {
            foreach ($this->payloadItems(data_get($payload, 'data', [])) as $update) {
                $this->updateMessageStatus((array) $update);
            }

            return response()->json(['ok' => true]);
        }

        if ($event !== 'messages.upsert') {
            return response()->json(['ok' => true, 'ignored' => true]);
        }

        $data = data_get($payload, 'data', []);
        foreach ($this->payloadItems($data) as $message) {
            if (is_array($message)) {
                $this->storeMessage($instance, $payload, $message);
            }
        }

        return response()->json(['ok' => true]);
    }

    /**
     * @return array<int|string, mixed>
     */
    private function payloadItems(mixed $data): array
    {
        if (! is_array($data)) {
            return [];
        }

        return array_is_list($data) ? $data : [$data];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $message
     */
    private function storeMessage(string $instance, array $payload, array $message): void
    {
        $key = (array) data_get($message, 'key', []);
        $messageId = data_get($key, 'id') ?? data_get($message, 'id');
        if (! is_string($messageId) || $messageId === '') {
            return;
        }

        $fromMe = (bool) data_get($key, 'fromMe', false);
        $eventId = 'evolution.message.'.$instance.'.'.$messageId;
        $event = WhatsAppWebhookEvent::firstOrCreate(
            ['event_id' => $eventId],
            ['payload' => $payload]
        );
        if ($event->processed_at) {
            return;
        }

        $remoteJid = (string) data_get($key, 'remoteJid', '');
        if ($remoteJid === '') {
            $event->update(['processed_at' => now()]);

            return;
        }
        $phoneDigits = explode('@', $remoteJid)[0];
        $phone = PhoneNumber::normalize(explode(':', $phoneDigits)[0]);
        $customer = Customer::firstOrCreate(
            ['phone' => $phone],
            ['name' => (string) (data_get($message, 'pushName') ?: 'WhatsApp '.$phone)]
        );
        $body = $this->messageBody((array) data_get($message, 'message', []));
        $waMessage = WhatsAppMessage::firstOrCreate(
            ['wamid' => $messageId],
            [
                'customer_id' => $customer->id,
                'direction' => $fromMe ? 'outbound' : 'inbound',
                'status' => $fromMe ? 'Sent' : 'Delivered',
                'body' => $body,
                'meta_payload' => $message,
                'provider' => 'evolution',
            ]
        );

        if (! $fromMe) {
            $conversation = Conversation::firstOrCreate(
                ['customer_id' => $customer->id],
                ['status' => 'open']
            );
            ConversationMessage::firstOrCreate(
                [
                    'conversation_id' => $conversation->id,
                    'whatsapp_message_id' => $waMessage->id,
                ],
                ['sender_type' => 'customer', 'body' => $body]
            );
            $conversation->update(['status' => 'open', 'last_message_at' => now()]);
            app(BotAutomationService::class)->handleIncoming($conversation, $customer, $body);
        }

        $event->update(['processed_at' => now()]);
    }

    /**
     * @param  array<string, mixed>  $update
     */
    private function updateMessageStatus(array $update): void
    {
        $messageId = data_get($update, 'key.id') ?? data_get($update, 'id');
        if (! is_string($messageId) || $messageId === '') {
            return;
        }

        $status = strtoupper((string) (data_get($update, 'update.status')
            ?? data_get($update, 'status', '')));
        $newStatus = match ($status) {
            'READ', '3', '4' => 'Read',
            'DELIVERY_ACK', 'DELIVERED', '2' => 'Delivered',
            'ERROR', 'FAILED', '0' => 'Failed',
            'SERVER_ACK', 'SENT', '1' => 'Sent',
            default => null,
        };
        if ($newStatus === null) {
            return;
        }

        $message = WhatsAppMessage::query()
            ->where('provider', 'evolution')
            ->where('wamid', $messageId)
            ->first();
        if ($message) {
            $message->update([
                'status' => $newStatus,
                'meta_payload' => $newStatus === 'Failed' ? $update : $message->meta_payload,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function messageBody(array $message): string
    {
        $body = (
            data_get($message, 'conversation')
            ?? data_get($message, 'extendedTextMessage.text')
            ?? data_get($message, 'imageMessage.caption')
            ?? data_get($message, 'videoMessage.caption')
            ?? data_get($message, 'documentMessage.caption')
            ?? data_get($message, 'audioMessage.caption')
            ?? 'Media message'
        );

        return is_string($body) ? $body : 'Media message';
    }
}
