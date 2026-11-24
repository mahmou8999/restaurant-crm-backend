<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CannedReply;
use App\Models\SystemSetting;
use App\Models\WhatsAppAccount;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function index()
    {
        return response()->json(SystemSetting::all());
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'settings' => ['required', 'array'],
            'settings.*.key' => ['required', 'string'],
            'settings.*.value' => ['nullable'],
        ]);
        foreach ($data['settings'] as $setting) {
            if ($setting['key'] === 'sender_driver'
                && ! in_array($setting['value'], ['meta', 'evolution'], true)) {
                return response()->json(['message' => 'sender_driver must be meta or evolution.'], 422);
            }
            if (in_array($setting['key'], ['daily_limit', 'delay_min', 'delay_max'], true)
                && (! is_numeric($setting['value'])
                    || (int) $setting['value'] < ($setting['key'] === 'daily_limit' ? 1 : 0)
                    || (int) $setting['value'] > 86400)) {
                return response()->json(['message' => 'Pacing settings must be non-negative numbers.'], 422);
            }
        }

        foreach ($data['settings'] as $setting) {
            SystemSetting::updateOrCreate(
                ['key' => $setting['key']],
                ['value' => is_scalar($setting['value']) ? (string) $setting['value'] : json_encode($setting['value'])]
            );
            if ($setting['key'] === 'sender_driver') {
                $account = WhatsAppAccount::query()
                    ->where('is_active', true)
                    ->first();
                if ($account) {
                    $account->update([
                        'driver' => $setting['value'],
                        'instance_name' => $setting['value'] === 'evolution'
                            ? ($account->instance_name ?: config('whatsapp.evolution_instance'))
                            : $account->instance_name,
                        'session_status' => 'disconnected',
                    ]);
                }
            }
        }

        return response()->json(SystemSetting::all());
    }

    public function cannedReplies()
    {
        return response()->json(CannedReply::latest()->paginate(50));
    }

    public function storeCannedReply(Request $request)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string'],
        ]);

        return response()->json(CannedReply::create($data), 201);
    }

    public function updateCannedReply(Request $request, CannedReply $cannedReply)
    {
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'message' => ['sometimes', 'string'],
        ]);
        $cannedReply->update($data);

        return response()->json($cannedReply->fresh());
    }

    public function destroyCannedReply(CannedReply $cannedReply)
    {
        $cannedReply->delete();

        return response()->json(['message' => 'تم حذف الرد الجاهز']);
    }
}
