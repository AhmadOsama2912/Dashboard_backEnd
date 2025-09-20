<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Package;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminPackageController extends Controller
{
    /**
     * GET /api/admin/v1/packages
     * Query: q, per_page, sort, direction
     */
    public function index(Request $request)
    {
        $data = $request->validate([
            'q'         => ['nullable','string','max:190'],
            'per_page'  => ['nullable','integer','min:1','max:100'],
            'sort'      => ['nullable','in:id,name,price,screens_limit,managers_limit,supervisors_limit,branches_limit,created_at'],
            'direction' => ['nullable','in:asc,desc'],
        ]);

        $perPage   = $data['per_page']  ?? 15;
        $sort      = $data['sort']      ?? 'created_at';
        $direction = $data['direction'] ?? 'desc';

        $query = Package::query()
            ->withCount('customers');

        if (!empty($data['q'])) {
            $q = $data['q'];
            $query->where('name', 'like', "%{$q}%");
        }

        $paginator = $query
            ->orderBy($sort, $direction)
            ->paginate($perPage);

        $paginator->getCollection()->transform(fn (Package $p) => [
            'id'                 => $p->id,
            'name'               => $p->name,
            'screens_limit'      => $p->screens_limit,
            'managers_limit'     => $p->managers_limit,
            'supervisors_limit'  => $p->supervisors_limit,
            'branches_limit'     => $p->branches_limit,
            'price'              => $p->price,
            'support_description'=> $p->support_description,
            'customers_count'    => $p->customers_count,
            'created_at'         => $p->created_at,
        ]);

        return response()->json($paginator);
    }

    /**
     * GET /api/admin/v1/packages/{package}
     */
    public function show(Package $package)
    {
        $package->loadCount('customers');

        return response()->json([
            'id'                 => $package->id,
            'name'               => $package->name,
            'screens_limit'      => $package->screens_limit,
            'managers_limit'     => $package->managers_limit,
            'supervisors_limit'  => $package->supervisors_limit,
            'branches_limit'     => $package->branches_limit,
            'price'              => $package->price,
            'support_description'=> $package->support_description,
            'customers_count'    => $package->customers_count,
            'created_at'         => $package->created_at,
            'updated_at'         => $package->updated_at,
        ]);
    }

    /**
     * POST /api/admin/v1/packages (your existing store())
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name'               => ['required','string','max:190','unique:packages,name'],
            'screens_limit'      => ['required','integer','min:0'],
            'managers_limit'     => ['required','integer','min:0'],
            'supervisors_limit'  => ['required','integer','min:0'],
            'branches_limit'     => ['required','integer','min:0'],
            'price'              => ['required','numeric','min:0'],
            'support_description'=> ['nullable','string'],
        ]);

        $pkg = Package::create($data);

        return response()->json(['message'=>'Package created','package'=>$pkg], 201);
    }

    public function update(Request $request, Package $package)
    {
        $data = $request->validate([
            'name'               => ['nullable','string','max:190', Rule::unique('packages','name')->ignore($package->id)],
            'screens_limit'      => ['nullable','integer','min:0'],
            'managers_limit'     => ['nullable','integer','min:0'],
            'supervisors_limit'  => ['nullable','integer','min:0'],
            'branches_limit'     => ['nullable','integer','min:0'],
            'price'              => ['nullable','numeric','min:0'],
            'support_description'=> ['nullable','string'],
        ]);

        $package->fill($data)->save();

        return response()->json(['message' => 'Package updated', 'package' => $package]);
    }

    public function destroy(Package $package)
    {
        // Fail nicely if customers exist (since you likely used restrictOnDelete in FK)
        if ($package->customers()->exists()) {
            return response()->json([
                'message' => 'Cannot delete a package that has customers. Move customers to another package first.'
            ], 409);
        }

        $package->delete(); // Soft delete
        return response()->json(['message' => 'Package deleted']);
    }

}
