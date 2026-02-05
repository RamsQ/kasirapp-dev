<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Models\Table;
use App\Models\ReceiptSetting;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TableController extends Controller
{
    /**
     * Tampilan Daftar Meja
     */
    public function index()
    {
        $tables = Table::orderBy('name')->paginate(10);
        return Inertia::render('Dashboard/Tables/Index', [
            'tables' => $tables
        ]);
    }

    /**
     * Simpan Meja Baru
     */
    public function store(Request $request)
    {
        $request->validate(['name' => 'required|unique:tables,name']);
        Table::create(['name' => $request->name, 'status' => 'available']);
        return back()->with('success', 'Meja berhasil ditambahkan.');
    }

    /**
     * Update Nama Meja
     */
    public function update(Request $request, Table $table)
    {
        $request->validate(['name' => 'required|unique:tables,name,' . $table->id]);
        $table->update(['name' => $request->name]);
        return back()->with('success', 'Meja berhasil diupdate.');
    }

    /**
     * Hapus Meja
     */
    public function destroy(Table $table)
    {
        if ($table->status === 'occupied') {
            return back()->with('error', 'Meja sedang digunakan!');
        }
        $table->delete();
        return back()->with('success', 'Meja berhasil dihapus.');
    }

    /**
     * Tampilan Halaman Cetak QR Meja (Fitur Baru)
     */
    public function printQr()
    {
        // Mengambil semua data meja tanpa pagination untuk dicetak
        $tables = Table::orderBy('name')->get();
        
        // Mengambil nama toko dari pengaturan struk
        $storeName = ReceiptSetting::first()->store_name ?? 'KASIR POS';

        return Inertia::render('Dashboard/Tables/PrintQr', [
            'tables'    => $tables,
            'storeName' => $storeName,
            'baseUrl'   => url('/menu') // Menghasilkan root URL (misal: https://tokoanda.com/menu)
        ]);
    }
}