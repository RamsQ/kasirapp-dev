<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AiSession extends Model
{
    use HasFactory;

    /** Maksimal giliran percakapan yang disimpan agar prompt tidak membludak. */
    public const MAX_TURNS = 6;

    protected $fillable = [
        'user_id',
        'period',
        'history',
    ];

    protected $casts = [
        'history' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Simpan giliran baru dan potong riwayat lama bila melebihi batas.
     */
    public function remember(string $role, string $content): void
    {
        $history = $this->history ?? [];
        $history[] = ['role' => $role, 'content' => $content];

        $this->update([
            'history' => array_slice($history, -self::MAX_TURNS),
        ]);
    }
}
