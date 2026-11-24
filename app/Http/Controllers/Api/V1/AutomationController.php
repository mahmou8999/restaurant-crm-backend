<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BotMenu;
use App\Models\BotMenuOption;
use App\Models\BotRule;
use Illuminate\Http\Request;

class AutomationController extends Controller
{
    public function rulesIndex()
    {
        return response()->json(BotRule::latest()->paginate(50));
    }

    public function rulesStore(Request $request)
    {
        $data = $request->validate([
            'trigger_type' => ['required', 'string'],
            'trigger_value' => ['required', 'string'],
            'response' => ['required', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        return response()->json(BotRule::create($data), 201);
    }

    public function rulesUpdate(Request $request, BotRule $rule)
    {
        $data = $request->validate([
            'trigger_type' => ['sometimes', 'string'],
            'trigger_value' => ['sometimes', 'string'],
            'response' => ['sometimes', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $rule->update($data);

        return response()->json($rule->fresh());
    }

    public function menusIndex()
    {
        return response()->json(BotMenu::with('options')->paginate(50));
    }

    public function menusStore(Request $request)
    {
        $data = $request->validate([
            'title' => ['required', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        return response()->json(BotMenu::create($data), 201);
    }

    public function menuOptionStore(Request $request, BotMenu $menu)
    {
        $data = $request->validate([
            'title' => ['required', 'string'],
            'action_type' => ['required', 'string'],
            'action_value' => ['required', 'string'],
        ]);
        $option = BotMenuOption::create([
            'menu_id' => $menu->id,
            ...$data,
        ]);

        return response()->json($option, 201);
    }
}
