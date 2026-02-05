<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UserRequest extends FormRequest
{
    /**
     * Izinkan request ini (Hanya Owner/Admin biasanya).
     */
    public function authorize(): bool
    {
        return true; 
    }

    /**
     * Aturan validasi untuk Tambah dan Edit User.
     */
    public function rules(): array
    {
        // Mengambil ID user dari route (jika sedang proses EDIT)
        $userId = $this->route('user') ? $this->route('user')->id : null;

        return [
            'name'      => 'required|string|max:255',
            'email'     => 'required|email|unique:users,email,' . $userId,
            'password'  => $this->isMethod('POST') 
                            ? 'required|min:8|confirmed' 
                            : 'nullable|min:8|confirmed',
            'roles'             => 'nullable|array',
            'selectedRoles'     => 'nullable|array',
            // Pastikan is_face_mandatory divalidasi sebagai boolean
            'is_face_mandatory' => 'nullable|boolean', 
        ];
    }
}