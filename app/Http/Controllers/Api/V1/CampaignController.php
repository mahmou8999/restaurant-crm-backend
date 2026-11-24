<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\SendCampaignMessage;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Customer;
use App\Models\WhatsAppAccount;
use App\Services\WhatsApp\WhatsAppSenderFactory;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;

class CampaignController extends Controller
{
    public function index(Request $request)
    {
        $query = Campaign::query()->with('template')->withCount('recipients');
        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }

        return response()->json($query->latest()->paginate($request->integer('per_page', 15)));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'template_id' => ['nullable', 'integer', 'exists:message_templates,id'],
            'scheduled_at' => ['nullable', 'date'],
            'filters' => ['nullable', 'array'],
        ]);
        $data['status'] = ! empty($data['scheduled_at']) ? 'Scheduled' : 'Draft';

        return response()->json(Campaign::create($data), 201);
    }

    public function show(Campaign $campaign)
    {
        return response()->json($campaign->load(['template', 'recipients.customer']));
    }

    public function update(Request $request, Campaign $campaign)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'template_id' => ['nullable', 'integer', 'exists:message_templates,id'],
            'scheduled_at' => ['nullable', 'date'],
            'filters' => ['nullable', 'array'],
        ]);
        $campaign->update($data);

        return response()->json($campaign->fresh());
    }

    public function destroy(Campaign $campaign)
    {
        $campaign->delete();

        return response()->json(['message' => 'تم حذف الحملة']);
    }

    private function eligibleCustomers(Campaign $campaign)
    {
        $query = Customer::query()->whereHas('consents', function ($q) {
            $q->where('consent_type', 'marketing')->whereNull('revoked_at');
        });
        $filters = $campaign->filters ?? [];
        if (! empty($filters['city'])) {
            $query->where('city', $filters['city']);
        }
        if (! empty($filters['group_ids'])) {
            $query->whereHas('groups', fn ($q) => $q->whereIn('customer_groups.id', $filters['group_ids']));
        }

        return $query;
    }

    public function preview(Campaign $campaign)
    {
        $query = $this->eligibleCustomers($campaign);

        return response()->json([
            'campaign_id' => $campaign->id,
            'eligible_count' => $query->count(),
            'customers' => $query->limit(50)->get(['id', 'name', 'phone', 'city']),
        ]);
    }

    public function test(Request $request, Campaign $campaign, WhatsAppSenderFactory $senders)
    {
        $data = $request->validate(['phone' => ['required', 'string']]);
        $account = WhatsAppAccount::where('is_active', true)->first();
        if (! $account || ! $campaign->template) {
            return response()->json(['message' => 'الحساب أو قالب الرسالة غير متوفر'], 422);
        }
        $phone = PhoneNumber::normalize($data['phone']);
        $result = $senders->sendTemplate(
            $account,
            $phone,
            $campaign->template
        );

        return response()->json($result);
    }

    public function start(Campaign $campaign)
    {
        if (! in_array($campaign->status, ['Draft', 'Scheduled', 'Paused'])) {
            return response()->json(['message' => 'الحملة لا يمكن تشغيلها من حالتها الحالية'], 422);
        }
        $campaign->update(['status' => 'Running']);
        foreach ($this->eligibleCustomers($campaign)->cursor() as $customer) {
            $recipient = CampaignRecipient::firstOrCreate([
                'campaign_id' => $campaign->id,
                'customer_id' => $customer->id,
            ]);
            SendCampaignMessage::dispatch($recipient->id);
        }

        return response()->json($campaign->fresh()->loadCount('recipients'));
    }

    public function pause(Campaign $campaign)
    {
        $campaign->update(['status' => 'Paused']);

        return response()->json($campaign);
    }

    public function resume(Campaign $campaign)
    {
        if (! in_array($campaign->status, ['Paused', 'Blocked'], true)) {
            return response()->json(['message' => 'الحملة لا يمكن استئنافها من حالتها الحالية'], 422);
        }

        $campaign->update(['status' => 'Running']);
        foreach ($campaign->recipients()->whereIn('status', ['Pending', 'Postponed'])->cursor() as $recipient) {
            $availableAt = $recipient->available_at;
            $recipient->update([
                'status' => 'Pending',
                'error' => null,
            ]);
            $job = SendCampaignMessage::dispatch($recipient->id);
            if ($availableAt && $availableAt->isFuture()) {
                $job->delay($availableAt);
            }
        }

        return response()->json($campaign);
    }

    public function report(Campaign $campaign)
    {
        return response()->json([
            'campaign' => $campaign,
            'counts' => [
                'total' => $campaign->recipients()->count(),
                'pending' => $campaign->recipients()->where('status', 'Pending')->count(),
                'postponed' => $campaign->recipients()->where('status', 'Postponed')->count(),
                'sent' => $campaign->recipients()->where('status', 'Sent')->count(),
                'failed' => $campaign->recipients()->where('status', 'Failed')->count(),
            ],
        ]);
    }
}
