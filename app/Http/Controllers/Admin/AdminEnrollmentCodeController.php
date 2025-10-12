<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\EnrollmentCode;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminEnrollmentCodeController extends Controller
{
    public function index(Request $request)
    {
        $perPage = min((int)($request->input('per_page', 20)), 100);
        $q = EnrollmentCode::query()->with('customer:id,name');

        if ($cid = $request->input('customer_id')) $q->where('customer_id', $cid);
        if ($request->filled('q')) {
            $q->where('code', 'like', '%'.$request->input('q').'%');
        }

        $p = $q->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'data' => $p->items(),
            'meta' => ['total' => $p->total(), 'per_page'=>$p->perPage(), 'current_page'=>$p->currentPage()]
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'customer_id'  => ['required','integer','exists:customers,id'],
            'max_uses'     => ['required','integer','min:1','max:1000'],
            'license_days' => ['required','integer','min:1','max:3650'],
            'expires_at'   => ['nullable','date'],
            'note'         => ['nullable','string','max:500'],
        ]);

        $customer = Customer::withCount('screens')->with('package')->findOrFail($data['customer_id']);
        $limit = (int)($customer->package->screens_limit ?? 0);
        $current = (int)($customer->screens_count ?? 0);
        $available = max($limit - $current, 0);

        if ($limit > 0 && $data['max_uses'] > $available) {
            return response()->json([
                'message' => "Requested max_uses ({$data['max_uses']}) exceeds available seats ({$available}) in the customer's package."
            ], 422);
        }

        // generate unique code
        do {
            $code = strtoupper(Str::random(8));
        } while (EnrollmentCode::where('code', $code)->exists());

        $row = EnrollmentCode::create([
            'code'        => $code,
            'customer_id' => $data['customer_id'],
            'max_uses'    => $data['max_uses'],
            'used_count'  => 0,
            'license_days'=> $data['license_days'],
            'expires_at'  => $data['expires_at'] ?? null,
            'note'        => $data['note'] ?? null,
            'created_by'  => optional($request->user())->id,
        ]);

        return response()->json([
            'message' => 'Enrollment code created',
            'code'    => $row,
        ], 201);
    }
}
