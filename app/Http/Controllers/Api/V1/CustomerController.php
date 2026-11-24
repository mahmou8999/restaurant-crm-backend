<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ConsentRecord;
use App\Models\Customer;
use App\Models\Import;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $query = Customer::query()->with('groups');
        if ($search = $request->string('search')->toString()) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }
        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }

        return response()->json($query->paginate($request->integer('per_page', 15)));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string'],
            'marketing_consent' => ['sometimes', 'boolean'],
        ]);
        $validated['phone'] = PhoneNumber::normalize($validated['phone']);
        $customer = Customer::create(collect($validated)->only(['name', 'phone', 'email', 'city', 'status'])->all());
        if ($request->boolean('marketing_consent')) {
            ConsentRecord::create([
                'customer_id' => $customer->id,
                'consent_type' => 'marketing',
                'source' => 'api',
                'consented_at' => now(),
            ]);
        }

        return response()->json($customer, 201);
    }

    public function show(Customer $customer)
    {
        return response()->json($customer->load(['groups', 'consents']));
    }

    public function update(Request $request, Customer $customer)
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['sometimes', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'string'],
        ]);
        if (isset($validated['phone'])) {
            $validated['phone'] = PhoneNumber::normalize($validated['phone']);
        }
        $customer->update($validated);

        return response()->json($customer->fresh());
    }

    public function destroy(Customer $customer)
    {
        $customer->delete();

        return response()->json(['message' => 'تم أرشفة العميل']);
    }

    public function messages(Customer $customer)
    {
        return response()->json($customer->messages()->latest()->paginate(20));
    }

    public function import(Request $request)
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt']]);
        $file = $request->file('file');
        $stream = fopen($file->getRealPath(), 'r');
        $header = fgetcsv($stream);
        if (! $header) {
            fclose($stream);

            return response()->json(['message' => 'الملف فارغ'], 422);
        }
        $header = array_map(fn ($value) => strtolower(trim((string) $value)), $header);
        $record = Import::create([
            'user_id' => $request->user()->id,
            'file_name' => $file->getClientOriginalName(),
            'status' => 'processing',
        ]);
        $total = $imported = $failed = 0;
        while (($row = fgetcsv($stream)) !== false) {
            if (count(array_filter($row, fn ($value) => $value !== null && $value !== '')) === 0) {
                continue;
            }
            $total++;
            $data = array_combine($header, array_pad($row, count($header), null));
            try {
                $phone = PhoneNumber::normalize((string) ($data['phone'] ?? ''));
                if ($phone === '+' || empty($data['name'])) {
                    throw new \RuntimeException('بيانات العميل ناقصة');
                }
                $customer = Customer::withTrashed()->updateOrCreate(
                    ['phone' => $phone],
                    [
                        'name' => (string) $data['name'],
                        'email' => $data['email'] ?? null,
                        'city' => $data['city'] ?? null,
                        'status' => $data['status'] ?? 'active',
                        'deleted_at' => null,
                    ]
                );
                $consent = strtolower(trim((string) ($data['consent'] ?? '')));
                if ($consent !== '') {
                    ConsentRecord::create([
                        'customer_id' => $customer->id,
                        'consent_type' => 'marketing',
                        'source' => 'import',
                        'consented_at' => in_array($consent, ['1', 'true', 'yes', 'نعم']) ? now() : null,
                        'revoked_at' => in_array($consent, ['0', 'false', 'no', 'لا']) ? now() : null,
                    ]);
                }
                $imported++;
            } catch (\Throwable) {
                $failed++;
            }
        }
        fclose($stream);
        $record->update(['total_rows' => $total, 'imported_rows' => $imported, 'failed_rows' => $failed, 'status' => 'completed']);

        return response()->json($record);
    }

    public function export(): StreamedResponse
    {
        $response = new StreamedResponse(function () {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['name', 'phone', 'email', 'city', 'status']);
            Customer::query()->chunkById(500, function ($customers) use ($output) {
                foreach ($customers as $customer) {
                    fputcsv($output, [$customer->name, $customer->phone, $customer->email, $customer->city, $customer->status]);
                }
            });
            fclose($output);
        });
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="customers.csv"');

        return $response;
    }
}
