<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Expense extends Model
{
    use HasFactory;

    /**
     * Properti fillable untuk mass assignment.
     * Ditambahkan 'source' untuk menentukan sumber dana (Kas Laci / Modal Luar).
     */
    protected $fillable = [
        'user_id', 
        'name', 
        'category', 
        'amount', 
        'date', 
        'image', 
        'note', 
        'source'
    ];

    protected $casts = [
        'amount' => 'integer',
        'date'   => 'date:Y-m-d',
    ];

    /**
     * Menambahkan image_url ke dalam response JSON secara otomatis.
     */
    protected $appends = ['image_url'];

    /**
     * Relasi ke model User (Siapa petugas yang menginput).
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Accessor untuk mendapatkan URL lengkap gambar nota.
     */
    public function getImageUrlAttribute()
    {
        if (!$this->image) return null;
        
        // Mengarahkan ke symlink storage (php artisan storage:link)
        return asset('storage/expenses/' . $this->image);
    }
}