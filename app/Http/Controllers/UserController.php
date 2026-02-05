<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Requests\UserRequest;
use Inertia\Inertia;
use Spatie\Permission\Models\Role;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;

class UserController extends Controller
{
    /**
     * Tampilkan daftar user.
     */
    public function index()
    {
        $users = User::query()
            ->with('roles')
            ->when(request()->search, function($query) {
                $query->where('name', 'like', '%' . request()->search . '%')
                      ->orWhere('email', 'like', '%' . request()->search . '%');
            })
            // Menambahkan 'is_face_mandatory' dan 'face_data' ke select untuk indikator di UI
            ->select('id', 'name', 'avatar', 'email', 'face_data', 'is_face_mandatory', 'created_at')
            ->latest()
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('Dashboard/Users/Index', [
            'users' => $users,
        ]);
    }

    /**
     * Form tambah user.
     */
    public function create()
    {
        if (!auth()->user()->can('users.create')) {
            abort(403, 'Anda tidak memiliki izin untuk menambah pengguna.');
        }

        $roles = Role::query()->select('id', 'name')->orderBy('name')->get();
        return Inertia::render('Dashboard/Users/Create', [
            'roles' => $roles
        ]);
    }

    /**
     * Simpan user baru ke database.
     */
    public function store(UserRequest $request)
    {
        if (!auth()->user()->can('users.create')) {
            return back()->withErrors(['error' => 'Akses ditolak.']);
        }

        $user = User::create([
            'name'              => $request->name,
            'email'             => $request->email,
            'password'          => bcrypt($request->password),
            // Owner mengatur apakah user wajib menggunakan Face ID
            'is_face_mandatory' => $request->is_face_mandatory ?? false,
        ]);

        $user->assignRole($request->selectedRoles);

        return to_route('users.index')->with('success', 'User berhasil ditambahkan!');
    }

    /**
     * Form edit user.
     */
    public function edit(User $user)
    {
        if (!auth()->user()->can('users.edit')) {
            abort(403, 'Anda tidak memiliki izin untuk mengedit pengguna.');
        }

        $roles = Role::query()->select('id', 'name')->orderBy('name')->get();
        $user->load(['roles']);

        return Inertia::render('Dashboard/Users/Edit', [
            'roles' => $roles, 
            'user'  => $user
        ]);
    }

    /**
     * Update data user.
     */
    public function update(UserRequest $request, User $user)
    {
        if (!auth()->user()->can('users.edit')) {
            return back()->withErrors(['error' => 'Akses ditolak.']);
        }

        $data = [
            'name'              => $request->name, 
            'email'             => $request->email,
            // Update status mandatory Face ID dari Owner
            'is_face_mandatory' => $request->is_face_mandatory ?? false,
        ];
        
        if ($request->password) { 
            $data['password'] = bcrypt($request->password); 
        }

        $user->update($data);
        $user->syncRoles($request->selectedRoles);

        return to_route('users.index')->with('success', 'User berhasil diperbarui!');
    }

    /**
     * Hapus user (Soft Delete).
     */
    public function destroy($id)
    {
        if (!auth()->user()->can('users.delete')) {
            return back()->withErrors(['error' => 'Anda tidak memiliki izin untuk menghapus akun!']);
        }

        $ids = explode(',', $id);
        
        if (in_array(auth()->id(), $ids)) {
            return back()->withErrors(['error' => 'Tindakan ditolak! Anda tidak bisa menghapus akun Anda sendiri.']);
        }

        User::whereIn('id', $ids)->delete();

        return back()->with('success', 'User berhasil dinonaktifkan!');
    }
}