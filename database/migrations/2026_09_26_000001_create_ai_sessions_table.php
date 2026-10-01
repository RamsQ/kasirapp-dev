<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Riwayat percakapan disimpan di server agar model tetap ingat konteks
        // pertanyaan sebelumnya. Disimpan sebagai JSON agar tidak perlu tabel
        // terpisah untuk tiap giliran percakapan.
        Schema::create('ai_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('period', 20)->default('today');
            $table->json('history')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_sessions');
    }
};
