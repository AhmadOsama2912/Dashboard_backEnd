<?php
// app/Http/Controllers/Admin/AdminEnrollmentCodeController.php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EnrollmentCode;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminEnrollmentCodeController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'customer_id'        => ['required','exists:customers,id'],
            'max_uses'           => ['nullable','integer','min:1'],
            'expires_in_minutes' => ['nullable','integer','min:1'],
        ]);

        $code = EnrollmentCode::create([
            'customer_id' => $data['customer_id'],
            'code'        => Str::upper(Str::random(6)), // e.g., ABC123
            'max_uses'    => $data['max_uses'] ?? 1,
            'expires_at'  => isset($data['expires_in_minutes'])
                ? now()->addMinutes($data['expires_in_minutes'])
                : null,
        ]);

        return response()->json(['message' => 'Enrollment code created', 'code' => $code], 201);
    }

    public function index(Request $request)
    {
        return response()->json(EnrollmentCode::latest()->paginate(15));
    }
}
