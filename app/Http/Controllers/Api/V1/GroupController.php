<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CustomerGroup;
use Illuminate\Http\Request;

class GroupController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(CustomerGroup::withCount('customers')->paginate($request->integer('per_page', 15)));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        return response()->json(CustomerGroup::create($data), 201);
    }

    public function show(CustomerGroup $group)
    {
        return response()->json($group->load('customers'));
    }

    public function update(Request $request, CustomerGroup $group)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);
        $group->update($data);

        return response()->json($group->fresh());
    }

    public function destroy(CustomerGroup $group)
    {
        $group->delete();

        return response()->json(['message' => 'تم حذف المجموعة']);
    }

    public function addCustomers(Request $request, CustomerGroup $group)
    {
        $data = $request->validate(['customer_ids' => ['required', 'array'], 'customer_ids.*' => ['integer', 'exists:customers,id']]);
        $group->customers()->syncWithoutDetaching($data['customer_ids']);

        return response()->json($group->load('customers'));
    }
}
