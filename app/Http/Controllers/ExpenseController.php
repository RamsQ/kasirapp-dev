<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ExpenseController extends Controller
{
    /**
     * Menyimpan pengeluaran baru dari halaman Laporan Keuangan
     */
    public function store(Request $request)
    {
        // 1. Validasi Input
        $request->validate([
            'name'   => 'required|string|max:255',
            'amount' => 'required|numeric|min:0',
            'date'   => 'required|date',
            'source' => 'required|string', // Kas Laci, Modal Luar, Hutang Dagang
            'image'  => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048', // Max 2MB
        ]);

        // 2. Proses Upload Foto Nota (jika ada)
        $imagePath = null;
        if ($request->hasFile('image')) {
            // Simpan file ke storage/app/public/expenses
            $path = $request->file('image')->store('public/expenses');
            $imagePath = basename($path);
        }

        /**
         * 3. Simpan ke Database
         * Kolom 'source' digunakan untuk menghitung saldo di Neraca (ReportController).
         * Kolom 'category' digunakan untuk menandai transaksi khusus seperti Pelunasan Hutang.
         */
        Expense::create([
            'user_id'  => auth()->id(),
            'name'     => $request->name,
            'amount'   => $request->amount,
            'date'     => $request->date,
            'source'   => $request->source,
            'category' => $request->category ?? 'Operasional',
            'image'    => $imagePath,
            'note'     => $request->note,
        ]);

        // 4. Redirect balik dengan pesan sukses
        return redirect()->back()->with('success', 'Transaksi berhasil dicatat!');
    }
}