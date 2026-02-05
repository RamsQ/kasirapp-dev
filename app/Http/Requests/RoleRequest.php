<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RoleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Ubah jadi true agar tidak 403 Forbidden
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // Ambil ID role dari route jika sedang update
        // Contoh: /roles/11 (11 adalah ID)
        $id = $this->route('role') ? $this->route('role')->id : null;

        return [
            // Nama wajib diisi & harus unik di tabel roles, kecuali untuk ID ini sendiri
            'name' => 'required|unique:roles,name,' . $id,
            
            // Permissions bersifat opsional tapi jika ada harus berupa array
            'permissions' => 'nullable|array',
        ];
    }
}