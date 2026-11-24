<?php

use App\Jobs\SendCampaignMessage;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Customer;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function () {
    Campaign::query()
        ->where('status', 'Scheduled')
        ->whereNotNull('scheduled_at')
        ->where('scheduled_at', '<=', now())
        ->chunkById(100, function ($campaigns) {
            foreach ($campaigns as $campaign) {
                $campaign->update(['status' => 'Running']);
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
                foreach ($query->cursor() as $customer) {
                    $recipient = CampaignRecipient::firstOrCreate([
                        'campaign_id' => $campaign->id,
                        'customer_id' => $customer->id,
                    ]);
                    SendCampaignMessage::dispatch($recipient->id);
                }
            }
        });
})->everyMinute()->name('dispatch-scheduled-campaigns')->withoutOverlapping();
