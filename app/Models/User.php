<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;
use App\Notifications\CustomResetPassword; 
use Illuminate\Database\Eloquent\Casts\Attribute; // Penting untuk Accessor

class User extends Authenticatable
{
    /**
     * SoftDeletes: Memungkinkan penghapusan akun sementara (mengisi deleted_at)
     * HasRoles: Integrasi dengan Spatie Permission
     */
    use HasFactory, Notifiable, HasRoles, SoftDeletes;

    /**
     * Alias untuk original Spatie hasPermissionTo agar tidak bentrok (Recursion)
     */
    use HasRoles {
        hasPermissionTo as hasPermissionToSpatie;
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'image',             // DISESUAIKAN: Dari avatar ke image agar sinkron dengan ProfileController
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
     * --- BARU: Accessor untuk Image ---
     * Menghasilkan URL lengkap untuk foto profil.
     * Mencegah masalah path manual di frontend dan menangani fallback UI Avatars.
     */
    protected function image(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value 
                ? (str_contains($value, 'http') ? $value : asset('/storage/users/' . $value)) 
                : 'https://ui-avatars.com/api/?name=' . urlencode($this->name) . '&background=4e73df&color=ffffff&size=100',
        );
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
     */
    public function stockOpnames()
    {
        return $this->hasMany(StockOpname::class);
    }

    /**
     * Relasi ke Hold (Antrean/Pesanan QR)
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

        return $this->hasPermissionToSpatie($permission, $guardName);
    }

    /**
     * Helper untuk memanggil parent function dari Spatie HasRoles
     */
    protected function parentHasPermissionTo($permission, $guardName = null): bool
    {
        return $this->hasPermissionToSpatie($permission, $guardName);
    }
}