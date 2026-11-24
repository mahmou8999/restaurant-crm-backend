<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\WhatsAppMessage;

class DashboardController extends Controller
{
    public function index()
    {
        return response()->json([
            'customers' => [
                'total' => Customer::count(),
                'active' => Customer::where('status', 'active')->count(),
            ],
            'campaigns' => [
                'total' => Campaign::count(),
                'running' => Campaign::where('status', 'Running')->count(),
                'scheduled' => Campaign::where('status', 'Scheduled')->count(),
                'completed' => Campaign::where('status', 'Completed')->count(),
            ],
            'conversations' => [
                'open' => Conversation::where('status', 'open')->count(),
                'closed' => Conversation::where('status', 'closed')->count(),
            ],
            'messages' => [
                'total' => WhatsAppMessage::count(),
                'sent' => WhatsAppMessage::where('status', 'Sent')->count(),
                'delivered' => WhatsAppMessage::where('status', 'Delivered')->count(),
                'read' => WhatsAppMessage::where('status', 'Read')->count(),
                'failed' => WhatsAppMessage::where('status', 'Failed')->count(),
            ],
        ]);
    }
}
