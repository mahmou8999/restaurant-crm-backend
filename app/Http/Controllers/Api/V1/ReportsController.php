<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\WhatsAppMessage;
use Illuminate\Http\Request;

class ReportsController extends Controller
{
    public function index(Request $request)
    {
        $from = $request->date('from');
        $to = $request->date('to');

        $campaignQuery = Campaign::query();
        $messageQuery = WhatsAppMessage::query();
        $customerQuery = Customer::query();
        if ($from) {
            $campaignQuery->where('created_at', '>=', $from->startOfDay());
            $messageQuery->where('created_at', '>=', $from->startOfDay());
            $customerQuery->where('created_at', '>=', $from->startOfDay());
        }
        if ($to) {
            $campaignQuery->where('created_at', '<=', $to->endOfDay());
            $messageQuery->where('created_at', '<=', $to->endOfDay());
            $customerQuery->where('created_at', '<=', $to->endOfDay());
        }

        return response()->json([
            'period' => [
                'from' => $from?->toDateString(),
                'to' => $to?->toDateString(),
            ],
            'customers_added' => $customerQuery->count(),
            'campaigns_created' => $campaignQuery->count(),
            'messages' => [
                'total' => $messageQuery->count(),
                'inbound' => (clone $messageQuery)->where('direction', 'inbound')->count(),
                'outbound' => (clone $messageQuery)->where('direction', 'outbound')->count(),
                'failed' => (clone $messageQuery)->where('status', 'Failed')->count(),
            ],
        ]);
    }

    public function campaigns()
    {
        return response()->json(Campaign::withCount([
            'recipients',
            'recipients as sent_count' => fn ($query) => $query->where('status', 'Sent'),
            'recipients as failed_count' => fn ($query) => $query->where('status', 'Failed'),
            'recipients as postponed_count' => fn ($query) => $query->where('status', 'Postponed'),
        ])->latest()->paginate(50));
    }
}
