<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\MessageTemplate;
use App\Models\SystemSetting;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\WhatsAppConnectionException;
use App\Services\WhatsApp\WhatsAppSenderFactory;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class WhatsAppController extends Controller
{
    public function status(WhatsAppSenderFactory $senders): JsonResponse
    {
        $account = WhatsAppAccount::where('is_active', true)->first();
        $driver = $senders->driver($account);
        $connected = (bool) $account;
        $qr = null;
        if ($driver === 'evolution') {
            $sender = $senders->evolution($account);
            try {
                $state = $sender->connectionState();
                $connected = (data_get($state, 'instance.state') ?? data_get($state, 'state')) === 'open';
                $qr = $connected ? null : $this->qr($sender->qr());
                $this->saveConnectionState($account, $connected);
            } catch (WhatsAppConnectionException $exception) {
                return response()->json([
                    'driver' => $driver,
                    'connected' => false,
                    'qr' => null,
                    'message' => $exception->getMessage(),
                ], 503);
            }
        }

        return response()->json([
            'driver' => $driver,
            'connected' => $connected,
            'qr' => $qr,
            'account' => $account ? [
                'name' => $account->name,
                'phone_number_id' => $account->phone_number_id,
                'business_account_id' => $account->business_account_id,
                'instance_name' => $account->instance_name,
                'session_status' => $account->session_status,
            ] : null,
        ]);
    }

    public function templates(): JsonResponse
    {
        return response()->json(MessageTemplate::query()->latest()->paginate(50));
    }

    public function storeTemplate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'language' => ['sometimes', 'string', 'max:10'],
            'category' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string'],
        ]);
        $template = MessageTemplate::create($data + ['meta_status' => 'LOCAL']);

        return response()->json($template, 201);
    }

    public function updateTemplate(Request $request, MessageTemplate $template): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'language' => ['sometimes', 'string', 'max:10'],
            'category' => ['nullable', 'string', 'max:255'],
            'body' => ['sometimes', 'required', 'string'],
        ]);
        $template->update($data);

        return response()->json($template->fresh());
    }

    public function syncTemplates(WhatsAppSenderFactory $senders): JsonResponse
    {
        $account = WhatsAppAccount::where('is_active', true)->first();
        if ($senders->driver($account) === 'evolution') {
            return response()->json(['message' => 'Evolution uses local text templates.'], 409);
        }
        if (! $account || ! $account->business_account_id) {
            return response()->json(['message' => 'حساب WhatsApp غير مكتمل'], 422);
        }
        $version = config('services.whatsapp.graph_version', 'v21.0');
        $response = Http::withToken($account->access_token)
            ->acceptJson()
            ->get("https://graph.facebook.com/{$version}/{$account->business_account_id}/message_templates");
        if (! $response->successful()) {
            return response()->json(['message' => $response->json('error.message') ?? 'فشلت المزامنة'], 502);
        }
        foreach ($response->json('data', []) as $template) {
            $components = $template['components'] ?? [];
            $bodyComponent = collect($components)
                ->first(fn (array $component): bool => strtoupper($component['type'] ?? '') === 'BODY');
            MessageTemplate::updateOrCreate(
                ['name' => $template['name'], 'language' => $template['language']],
                [
                    'category' => $template['category'] ?? null,
                    'meta_status' => $template['status'] ?? 'PENDING',
                    'components' => $components,
                    'body' => is_array($bodyComponent) ? ($bodyComponent['text'] ?? null) : null,
                ]
            );
        }

        return response()->json(['synced' => count($response->json('data', []))]);
    }

    public function send(Request $request, WhatsAppSenderFactory $senders): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string'],
            'body' => ['required', 'string'],
        ]);
        $account = WhatsAppAccount::where('is_active', true)->first();
        $driver = $senders->driver($account);
        if (! $account && $driver === 'meta') {
            return response()->json(['message' => 'لا يوجد حساب WhatsApp نشط'], 422);
        }
        $phone = PhoneNumber::normalize($data['phone']);
        $result = $senders->make($account)->sendText($phone, $data['body']);
        $wamid = $this->messageId($result);
        $customer = Customer::firstOrCreate(
            ['phone' => $phone],
            ['name' => 'WhatsApp '.$phone]
        );
        $message = WhatsAppMessage::recordOutbound([
            'customer_id' => $customer->id,
            'wamid' => $wamid,
            'direction' => 'outbound',
            'status' => 'Sent',
            'body' => $data['body'],
            'meta_payload' => $result,
            'provider' => $driver,
        ]);

        return response()->json($message, 201);
    }

    public function evolutionConnect(WhatsAppSenderFactory $senders): JsonResponse
    {
        $account = WhatsAppAccount::query()->where('is_active', true)->first();
        $result = $senders->evolution($account)->connect();
        $account = $this->evolutionAccount();
        $sender = $senders->evolution($account);
        $state = $sender->connectionState();
        $connected = (data_get($state, 'instance.state') ?? data_get($state, 'state')) === 'open';
        $this->saveConnectionState($account, $connected);

        return response()->json([
            'driver' => 'evolution',
            'connected' => $connected,
            'qr' => $connected ? null : ($this->qr($result) ?? $this->qr($sender->qr())),
            'instance' => $account->instance_name,
        ]);
    }

    public function evolutionStatus(WhatsAppSenderFactory $senders): JsonResponse
    {
        $account = WhatsAppAccount::query()->where('is_active', true)->first();
        $sender = $senders->evolution($account);
        try {
            $state = $sender->connectionState();
            $connected = (data_get($state, 'instance.state') ?? data_get($state, 'state')) === 'open';
            $qr = $connected ? null : $this->qr($sender->qr());
            $this->saveConnectionState($account, $connected);
        } catch (WhatsAppConnectionException $exception) {
            return response()->json([
                'driver' => 'evolution',
                'connected' => false,
                'qr' => null,
                'message' => $exception->getMessage(),
            ], 503);
        }

        return response()->json([
            'driver' => 'evolution',
            'connected' => $connected,
            'state' => data_get($state, 'instance.state') ?? data_get($state, 'state', 'close'),
            'qr' => $qr,
            'instance' => $account?->instance_name ?: config('whatsapp.evolution_instance'),
        ]);
    }

    public function evolutionDisconnect(WhatsAppSenderFactory $senders): JsonResponse
    {
        $account = WhatsAppAccount::query()->where('is_active', true)->first();
        $senders->evolution($account)->disconnect();
        $this->saveConnectionState($account, false);

        return response()->json(['driver' => 'evolution', 'connected' => false]);
    }

    private function evolutionAccount(): WhatsAppAccount
    {
        $account = WhatsAppAccount::query()->where('is_active', true)->first();
        if (! $account) {
            $account = WhatsAppAccount::create([
                'name' => 'Evolution API',
                'phone_number_id' => config('whatsapp.evolution_instance'),
                'access_token' => '',
                'driver' => 'evolution',
                'instance_name' => config('whatsapp.evolution_instance'),
                'session_status' => 'disconnected',
                'is_active' => true,
            ]);
        } else {
            $account->update([
                'driver' => 'evolution',
                'instance_name' => $account->instance_name ?: config('whatsapp.evolution_instance'),
                'session_status' => 'disconnected',
            ]);
            $account->refresh();
        }

        SystemSetting::updateOrCreate(['key' => 'sender_driver'], ['value' => 'evolution']);

        return $account;
    }

    private function saveConnectionState(?WhatsAppAccount $account, bool $connected): void
    {
        if (! $account) {
            return;
        }

        $wasConnected = $account->session_status === 'connected';
        $account->update([
            'session_status' => $connected ? 'connected' : 'disconnected',
            'last_connected_at' => $connected && ! $wasConnected ? now() : $account->last_connected_at,
        ]);
    }

    private function qr(array $response): ?string
    {
        $qr = data_get($response, 'base64')
            ?? data_get($response, 'qrcode.base64')
            ?? data_get($response, 'code');

        return is_string($qr) ? $qr : null;
    }

    private function messageId(array $response): ?string
    {
        $id = data_get($response, 'messages.0.id')
            ?? data_get($response, 'key.id')
            ?? data_get($response, 'message.key.id')
            ?? data_get($response, 'id');

        return is_string($id) ? $id : null;
    }
}
