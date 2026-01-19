<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class AdminCustomerController extends Controller
{
    /**
     * GET /api/admin/v1/customers
     * Query: q, package_id, per_page, sort, direction
     */
    public function index(Request $request)
    {
        $data = $request->validate([
            'q'          => ['nullable','string','max:190'],
            'package_id' => ['nullable','integer','exists:packages,id'],
            'per_page'   => ['nullable','integer','min:1','max:100'],
            'sort'       => ['nullable','in:id,name,created_at,users_count,screens_count'],
            'direction'  => ['nullable','in:asc,desc'],
        ]);

        $perPage   = $data['per_page']  ?? 15;
        $sort      = $data['sort']      ?? 'created_at';
        $direction = $data['direction'] ?? 'desc';

        $q = Customer::query()
            ->with(['package:id,name'])
            ->withCount(['users', 'screens']);

        if (!empty($data['q'])) {
            $term = '%'.$data['q'].'%';
            $q->where(function ($qq) use ($term) {
                $qq->where('name',  'like', $term)
                   ->orWhere('email','like', $term)
                   ->orWhere('phone','like', $term);
            });
        }

        if (!empty($data['package_id'])) {
            $q->where('package_id', $data['package_id']);
        }

        // If sort is on a computed count, ensure orderBy works
        if (in_array($sort, ['users_count','screens_count'], true)) {
            $q->orderBy($sort, $direction);
        } else {
            $q->orderBy($sort, $direction);
        }

        $paginator = $q->paginate($perPage);

        $paginator->getCollection()->transform(fn (Customer $c) => $this->presentCustomer($c));

        return response()->json($paginator);
    }

    /**
     * GET /api/admin/v1/customers/{customer}
     */
    public function show(Customer $customer)
    {
        $customer->load(['package:id,name'], 'users:id,customer_id,username,email,role');
        $customer->loadCount(['users','screens']);

        return response()->json([
            'customer' => $this->presentCustomer($customer, includeUsers: true),
        ]);
    }

    /**
     * POST /api/admin/v1/customers
     * Accepts multipart/form-data for logo and JSON for meta (array).
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name'        => ['required','string','max:190'],
            'email'       => ['required','string','email','max:190','unique:customers,email'],
            'phone'       => ['nullable','string','max:50'],
            'note'        => ['nullable','string','max:500'],
            'package_id'  => ['required','exists:packages,id'],
            'logo'        => ['nullable','file','image','max:2048'],
            'meta'        => ['nullable','array'],
        ]);

        if ($request->hasFile('logo')) {
            $data['logo'] = $request->file('logo')->store('logos', 'public');
        } else {
            unset($data['logo']);
        }

        $customer = Customer::create($data)->load('package:id,name');
        $customer->loadCount(['users','screens']);

        return response()->json([
            'message'  => 'Customer created',
            'customer' => $this->presentCustomer($customer),
        ], 201);
    }

    /**
     * PATCH /api/admin/v1/customers/{customer}
     * Accepts either a new file for logo or a string path (to keep existing).
     * To remove the logo pass logo=null explicitly.
     */
    public function update(Request $request, Customer $customer)
    {
        $data = $request->validate([
            'name'        => ['sometimes','string','max:190'],
            'email'       => ['sometimes','string','email','max:190', Rule::unique('customers','email')->ignore($customer->id)],
            'phone'       => ['sometimes','nullable','string','max:50'],
            'note'        => ['sometimes','nullable','string','max:500'],
            'package_id'  => ['sometimes','exists:packages,id'],
            'logo'        => ['sometimes'], // file or null or string
            'meta'        => ['sometimes','nullable','array'],
        ]);

        // Handle logo possibilities: file upload / explicit null / string path
        if ($request->hasFile('logo')) {
            // optionally delete old logo file if you want
            if ($customer->logo && Storage::disk('public')->exists($customer->logo)) {
                Storage::disk('public')->delete($customer->logo);
            }
            $data['logo'] = $request->file('logo')->store('logos', 'public');
        } elseif ($request->exists('logo') && $request->input('logo') === null) {
            // explicit removal
            if ($customer->logo && Storage::disk('public')->exists($customer->logo)) {
                Storage::disk('public')->delete($customer->logo);
            }
            $data['logo'] = null;
        } elseif (array_key_exists('logo', $data) && is_string($data['logo'])) {
            // keep the provided string path (no action)
        } else {
            unset($data['logo']);
        }

        $customer->fill($data)->save();

        $customer->load('package:id,name');
        $customer->loadCount(['users','screens']);

        return response()->json([
            'message'  => 'Customer updated',
            'customer' => $this->presentCustomer($customer),
        ]);
    }

    /**
     * DELETE /api/admin/v1/customers/{customer}
     */
    public function destroy(Customer $customer)
    {
        // If you want to remove stored logo on delete, uncomment:
        // if ($customer->logo && Storage::disk('public')->exists($customer->logo)) {
        //     Storage::disk('public')->delete($customer->logo);
        // }

        $customer->delete();

        return response()->json(['message' => 'Customer deleted']);
    }

    /* ------------------------------------------------------------- */
    /* Helpers                                                       */
    /* ------------------------------------------------------------- */

    private function presentCustomer(Customer $c, bool $includeUsers = false): array
    {
        $arr = [
            'id'             => $c->id,
            'name'           => $c->name,
            'email'          => $c->email,
            'phone'          => $c->phone,
            'note'           => $c->note,
            'package'        => $c->package ? ['id' => $c->package->id, 'name' => $c->package->name] : null,
            'package_id'     => $c->package_id,
            'logo'           => $c->logo,                                  // stored path
            'logo_url'       => $c->logo ? Storage::url($c->logo) : null,  // public URL (if using public disk)
            'meta'           => $c->meta,
            'users_count'    => (int) ($c->users_count ?? $c->users()->count()),
            'screens_count'  => (int) ($c->screens_count ?? $c->screens()->count()),
            'created_at'     => optional($c->created_at)->toIso8601String(),
            'updated_at'     => optional($c->updated_at)->toIso8601String(),
        ];

        if ($includeUsers) {
            $arr['users'] = $c->relationLoaded('users')
                ? $c->users->map(fn ($u) => [
                    'id'          => $u->id,
                    'username'    => $u->username,
                    'email'       => $u->email,
                    'role'        => $u->role,
                    'customer_id' => $u->customer_id,
                ])->values()
                : [];
        }

        return $arr;
    }

    
}
