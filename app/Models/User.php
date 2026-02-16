<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;
// IMPORT NOTIFIKASI KUSTOM
use App\Notifications\CustomResetPassword; 

class User extends Authenticatable
{
    /**
     * SoftDeletes: Memungkinkan penghapusan akun sementara (mengisi deleted_at)
     * HasRoles: Integrasi dengan Spatie Permission
     */
    use HasFactory, Notifiable, HasRoles, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'avatar',
        'face_data', 
        'is_face_mandatory', // Fitur Face Auth Mandatory
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'face_data'         => 'array',    // Menyimpan data koordinat wajah sebagai array JSON
            'is_face_mandatory' => 'boolean',  // Status wajib scan wajah
        ];
    }

    /**
     * --- FITUR: OVERRIDE PASSWORD RESET ---
     * Mengirimkan email reset password menggunakan template mewah yang baru dibuat.
     */
    public function sendPasswordResetNotification($token)
    {
        $this->notify(new CustomResetPassword($token));
    }

    /**
     * Relasi ke StockOpname
     * Seorang user bisa melakukan banyak stock opname
     */
    public function stockOpnames()
    {
        return $this->hasMany(StockOpname::class);
    }

    /**
     * Relasi ke Hold (Antrean/Pesanan QR)
     * Menghubungkan pesanan mandiri pelanggan ke Admin/Kasir tertentu
     */
    public function holds()
    {
        return $this->hasMany(Hold::class);
    }

    /**
     * --- BARU: Get permissions names as array ---
     * Digunakan oleh Controller untuk mengirim data permission ke Sidebar
     */
    public function getPermissionArray()
    {
        return $this->getAllPermissions()->pluck('name')->mapWithKeys(function ($pr) {
            return [$pr => true];
        })->toArray();
    }

    /**
     * get all permissions users
     * Fitur lama: Digunakan oleh helper Permission.jsx di frontend
     */
    public function getPermissions()
    {
        return $this->getAllPermissions()->mapWithKeys(function ($permission) {
            return [
                $permission['name'] => true
            ];
        });
    }

    /**
     * check role isSuperAdmin
     */
    public function isSuperAdmin()
    {
        return $this->hasRole('super-admin');
    }

    /**
     * --- BARU: Otomatis memberikan semua permission ke Super Admin ---
     * Fungsi ini meng-override pengecekan Spatie agar jika user adalah super-admin,
     * dia tidak perlu lagi dicek permission-nya satu per satu.
     */
    public function hasPermissionTo($permission, $guardName = null): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return $this->parentHasPermissionTo($permission, $guardName);
    }

    /**
     * Helper untuk memanggil parent function dari Spatie HasRoles
     */
    protected function parentHasPermissionTo($permission, $guardName = null): bool
    {
        return $this->hasPermissionToSpatie($permission, $guardName);
    }

    /**
     * Alias untuk original Spatie hasPermissionTo agar tidak bentrok (Recursion)
     */
    use HasRoles {
        hasPermissionTo as hasPermissionToSpatie;
    }
}