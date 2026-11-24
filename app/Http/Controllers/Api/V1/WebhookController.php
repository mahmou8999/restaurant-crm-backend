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
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function verify(Request $request)
    {
        $account = WhatsAppAccount::where('is_active', true)->first();
        $expected = $account?->webhook_verify_token;
        if ($request->query('hub.mode') === 'subscribe'
            && $expected
            && hash_equals($expected, (string) $request->query('hub.verify_token'))) {
            return response((string) $request->query('hub.challenge'));
        }

        return response('Forbidden', 403);
    }

    public function receive(Request $request)
    {
        $account = WhatsAppAccount::where('is_active', true)->first();
        $signature = (string) $request->header('X-Hub-Signature-256');
        if ($account?->app_secret) {
            $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $account->app_secret);
            if (! hash_equals($expected, $signature)) {
                return response()->json(['message' => 'Invalid signature'], 401);
            }
        }

        $payload = $request->all();
        $entries = data_get($payload, 'entry', []);
        foreach ($entries as $entry) {
            foreach (data_get($entry, 'changes', []) as $change) {
                $value = data_get($change, 'value', []);
                $messages = data_get($value, 'messages', []);
                $statuses = data_get($value, 'statuses', []);
                foreach ($messages as $message) {
                    $eventId = 'message.'.($message['id'] ?? uniqid());
                    $this->storeAndProcess($eventId, $payload, $message, null);
                }
                foreach ($statuses as $status) {
                    $eventId = 'status.'.($status['id'] ?? uniqid()).'.'.($status['status'] ?? 'unknown');
                    $this->storeAndProcess($eventId, $payload, null, $status);
                }
            }
        }

        return response()->json(['ok' => true]);
    }

    private function storeAndProcess(string $eventId, array $payload, ?array $message, ?array $status): void
    {
        $event = WhatsAppWebhookEvent::firstOrCreate(
            ['event_id' => $eventId],
            ['payload' => $payload]
        );
        if ($event->processed_at) {
            return;
        }

        if ($status) {
            $waMessage = WhatsAppMessage::where('wamid', $status['id'])->first();
            if ($waMessage) {
                $allowed = ['Pending', 'Sent', 'Delivered', 'Read', 'Failed'];
                $newStatus = ucfirst(strtolower($status['status'] ?? 'Pending'));
                if (in_array($newStatus, $allowed, true)) {
                    $waMessage->update(['status' => $newStatus]);
                }
                if ($status['status'] === 'failed') {
                    $waMessage->update(['status' => 'Failed', 'meta_payload' => $status]);
                }
            }
        }

        if ($message) {
            $phone = PhoneNumber::normalize((string) ($message['from'] ?? ''));
            $customer = Customer::firstOrCreate(
                ['phone' => $phone],
                ['name' => 'WhatsApp '.$phone]
            );
            $body = data_get($message, 'text.body');
            if ($body === null) {
                $body = 'Media message';
            }
            $waMessage = WhatsAppMessage::create([
                'customer_id' => $customer->id,
                'wamid' => $message['id'] ?? null,
                'direction' => 'inbound',
                'status' => 'Delivered',
                'body' => $body,
                'meta_payload' => $message,
            ]);
            $conversation = Conversation::firstOrCreate(
                ['customer_id' => $customer->id],
                ['status' => 'open']
            );
            ConversationMessage::create([
                'conversation_id' => $conversation->id,
                'whatsapp_message_id' => $waMessage->id,
                'sender_type' => 'customer',
                'body' => $body,
            ]);
            $conversation->update(['status' => 'open', 'last_message_at' => now()]);
            app(BotAutomationService::class)->handleIncoming(
                $conversation,
                $customer,
                $body
            );
        }

        $event->update(['processed_at' => now()]);
    }
}
