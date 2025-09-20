<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\Request;

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
            'sort'       => ['nullable','in:id,name,package_id,created_at'],
            'direction'  => ['nullable','in:asc,desc'],
        ]);

        $perPage   = $data['per_page']  ?? 15;
        $sort      = $data['sort']      ?? 'created_at';
        $direction = $data['direction'] ?? 'desc';

        $query = Customer::query()
            ->with(['package:id,name'])
            ->withCount('users');

        if (!empty($data['q'])) {
            $q = $data['q'];
            $query->where('name', 'like', "%{$q}%");
        }

        if (!empty($data['package_id'])) {
            $query->where('package_id', $data['package_id']);
        }

        $paginator = $query
            ->orderBy($sort, $direction)
            ->paginate($perPage);

        $paginator->getCollection()->transform(function (Customer $c) {
            return [
                'id'          => $c->id,
                'name'        => $c->name,
                'package'     => $c->package ? ['id'=>$c->package->id,'name'=>$c->package->name] : null,
                'logo'        => $c->logo,
                'users_count' => $c->users_count,
                'created_at'  => $c->created_at,
            ];
        });

        return response()->json($paginator);
    }

    /**
     * GET /api/admin/v1/customers/{customer}
     */
    public function show(Customer $customer)
    {
        $customer->load(['package:id,name'], 'users:id,customer_id,username,email,role');
        $customer->loadCount('users');

        return response()->json([
            'id'          => $customer->id,
            'name'        => $customer->name,
            'package'     => $customer->package ? ['id'=>$customer->package->id,'name'=>$customer->package->name] : null,
            'logo'        => $customer->logo,
            'users_count' => $customer->users_count,
            'users'       => $customer->users->map(fn ($u) => [
                'id'         => $u->id,
                'username'   => $u->username,
                'email'      => $u->email,
                'role'       => $u->role,
                'customer_id'=> $u->customer_id,
            ]),
            'created_at'  => $customer->created_at,
            'updated_at'  => $customer->updated_at,
        ]);
    }

    /**
     * POST /api/admin/v1/customers (your existing store())
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name'       => ['required','string','max:190'],
            'package_id' => ['required','exists:packages,id'],
            'logo'       => ['nullable','string','max:255'],
            'meta'       => ['nullable','array'],
        ]);

        $customer = Customer::create($data);

        return response()->json(['message'=>'Customer created','customer'=>$customer], 201);
    }

    public function update(Request $request, Customer $customer)
    {
        $data = $request->validate([
            'name'       => ['nullable','string','max:190'],
            'package_id' => ['nullable','exists:packages,id'],
            'logo'       => ['nullable','string','max:255'],
            'meta'       => ['nullable','array'],
        ]);

        $customer->fill($data)->save();

        return response()->json(['message' => 'Customer updated', 'customer' => $customer->fresh(['package'])]);
    }

    public function destroy(Customer $customer)
    {
        // Soft delete customer (users are on cascade on DELETE, but with soft deletes we just mark as deleted)
        $customer->delete();

        return response()->json(['message' => 'Customer deleted']);
    }
}
