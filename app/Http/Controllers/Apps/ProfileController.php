<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ProfileController extends Controller
{
    /**
     * Menampilkan halaman profil
     * Sesuaikan path render ke 'Profile/Index' sesuai struktur folder React Anda
     */
    public function index()
    {
        return Inertia::render('Profile/Index', [
            'user' => auth()->user(),
            'status' => session('status'),
        ]);
    }

    /**
     * Memperbarui informasi profil (Nama, Email, dan Foto)
     */
    public function update(Request $request)
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        $request->validate([
            'name'  => 'required|string|max:255',
            'email' => ['required', 'email', Rule::unique('users')->ignore($user->id)],
            'image' => 'nullable|image|mimes:jpg,jpeg,png|max:2048', 
        ]);

        $user->name = $request->name;
        $user->email = $request->email;

        // Logika Upload Foto Profil
        if ($request->hasFile('image')) {
            
            // 1. Hapus foto lama jika ada di storage (bukan URL luar)
            if ($user->image && !str_contains($user->image, 'http')) {
                Storage::disk('public')->delete('users/' . $user->image);
            }

            // 2. Ambil file dan simpan ke: storage/app/public/users
            $image = $request->file('image');
            $filename = $image->hashName();
            
            // Menggunakan storeAs untuk kontrol folder yang lebih baik
            $image->storeAs('public/users', $filename);
            
            // 3. Simpan nama filenya saja ke kolom 'image' di database
            $user->image = $filename;
        }

        $user->save();

        return redirect()->back()->with('success', 'Profil berhasil diperbarui!');
    }

    /**
     * Memperbarui Password
     */
    public function updatePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required',
            'password'         => 'required|min:8|confirmed',
        ]);

        /** @var \App\Models\User $user */
        $user = auth()->user();

        if (!Hash::check($request->current_password, $user->password)) {
            return back()->withErrors(['current_password' => 'Password saat ini tidak cocok.']);
        }

        $user->password = Hash::make($request->password);
        $user->save();

        return back()->with('success', 'Password berhasil diubah!');
    }

    /**
     * BARU: Memperbarui Data Biometrik Wajah (Face ID)
     * Method ini menerima descriptor wajah dari face-api.js di frontend
     */
    public function updateFace(Request $request)
    {
        $request->validate([
            'face_data' => 'required|array',
        ]);

        /** @var \App\Models\User $user */
        $user = auth()->user();
        
        // Simpan array koordinat wajah ke database
        $user->update([
            'face_data' => $request->face_data
        ]);

        return redirect()->back()->with('success', 'Data verifikasi wajah berhasil didaftarkan! Keamanan biometrik kini aktif.');
    }
}