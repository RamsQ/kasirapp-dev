<?php

namespace App\Http\Controllers;

use App\Http\Requests\RoleRequest;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        // [MODIFIKASI] Mengambil jumlah baris dari request, default adalah 10
        $per_page = $request->per_page ?: 10;

        // get all role data
        $roles = Role::query()
            ->with('permissions')
            ->when($request->search, fn ($query) => $query->where('name', 'like', '%'.$request->search.'%'))
            ->select('id', 'name')
            ->latest()
            // [MODIFIKASI] Menggunakan variabel dinamis $per_page
            ->paginate($per_page)
            ->withQueryString();

        // get all permission data
        $permissions = Permission::query()
            ->select('id', 'name')
            ->orderBy('name')
            ->get();

        // render view
        return Inertia::render('Dashboard/Roles/Index', [
            'roles' => $roles,
            'permissions' => $permissions,
            // [TAMBAHAN] Kirim filter aktif kembali ke view untuk sinkronisasi dropdown
            'filters' => $request->all(['search', 'per_page']),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(RoleRequest $request)
    {
        // create new role data
        $role = Role::create(['name' => $request->name]);

        // give permissions to role
        $role->givePermissionTo($request->selectedPermission);

        // render view
        return back();
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(RoleRequest $request, Role $role)
    {
        // update role data
        $role->update(['name' => $request->name]);

        // sync role permissions
        $role->syncPermissions($request->selectedPermission);

        // render view
        return back();
    }

    /**
     * Role yang tidak boleh dihapus karena sistem bergantung padanya.
     * Menghapus super-admin akan mengunci refund, gateway, dan seluruh
     * middleware izin yang ditugaskan ke role tersebut.
     *
     * @var list<string>
     */
    private const PROTECTED_ROLES = ['super-admin'];

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Role $role)
    {
        if (in_array($role->name, self::PROTECTED_ROLES, true)) {
            return back()->with('error', 'Role "'.$role->name.'" adalah role sistem dan tidak boleh dihapus.');
        }

        // Role yang masih dipakai user tidak boleh dihapus, karena tabel pivot
        // Spatie tidak memakai FK constraint sehingga assignment-nya akan
        // yatim diam-diam tanpa error.
        if ($role->users()->exists()) {
            return back()->with('error', 'Role "'.$role->name.'" masih dipakai oleh '.$role->users()->count().' pengguna. Pindahkan pengguna dulu.');
        }

        // delete role data
        $role->delete();

        // render view
        return back()->with('success', 'Role berhasil dihapus.');
    }
}
