<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\ConversationAssignment;
use App\Models\ConversationMessage;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\WhatsAppSenderFactory;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    public function index(Request $request)
    {
        $query = Conversation::query()->with(['customer', 'messages']);
        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }

        return response()->json($query->latest('last_message_at')->paginate($request->integer('per_page', 15)));
    }

    public function show(Conversation $conversation)
    {
        return response()->json($conversation->load(['customer', 'messages']));
    }

    public function reply(Request $request, Conversation $conversation, WhatsAppSenderFactory $senders)
    {
        $data = $request->validate(['body' => ['required', 'string']]);
        $account = WhatsAppAccount::where('is_active', true)->first();
        if (! $account) {
            return response()->json(['message' => 'لا يوجد حساب WhatsApp نشط'], 422);
        }
        $driver = $senders->driver($account);
        $result = $senders->make($account)->sendText($conversation->customer->phone, $data['body']);
        $message = WhatsAppMessage::recordOutbound([
            'customer_id' => $conversation->customer_id,
            'wamid' => data_get($result, 'messages.0.id')
                ?? data_get($result, 'key.id')
                ?? data_get($result, 'id'),
            'direction' => 'outbound',
            'status' => 'Sent',
            'body' => $data['body'],
            'meta_payload' => $result,
            'provider' => $driver,
        ]);
        ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'whatsapp_message_id' => $message->id,
            'sender_type' => 'agent',
            'body' => $data['body'],
        ]);
        $conversation->update(['last_message_at' => now(), 'status' => 'open']);

        return response()->json($message, 201);
    }

    public function assign(Request $request, Conversation $conversation)
    {
        $data = $request->validate(['user_id' => ['required', 'integer', 'exists:users,id']]);
        $conversation->update(['assigned_to' => $data['user_id']]);
        $conversation->assignments = null;
        ConversationAssignment::create([
            'conversation_id' => $conversation->id,
            'user_id' => $data['user_id'],
            'assigned_at' => now(),
        ]);

        return response()->json($conversation->fresh());
    }

    public function close(Conversation $conversation)
    {
        $conversation->update(['status' => 'closed']);

        return response()->json($conversation);
    }
}
