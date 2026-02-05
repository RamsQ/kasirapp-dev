<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Expense; 
use Illuminate\Http\Request;
use Inertia\Inertia;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB; 
use Barryvdh\DomPDF\Facade\Pdf;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\ExpiredProductsExport;

class ExpiredProductController extends Controller
{
    /**
     * Menampilkan halaman laporan expired
     */
    public function index(Request $request)
    {
        $startDate = $request->start_date ?? Carbon::now()->format('Y-m-d');
        $endDate   = $request->end_date   ?? Carbon::now()->addDays(30)->format('Y-m-d');

        $products = Product::with('category')
            ->whereNotNull('expired_date')
            ->whereBetween('expired_date', [$startDate, $endDate])
            ->orderBy('expired_date', 'asc')
            ->get();

        return Inertia::render('Dashboard/Reports/Expired', [
            'products'   => $products,
            'filter'     => [
                'start_date' => $startDate,
                'end_date'   => $endDate,
            ]
        ]);
    }

    /**
     * Menghapus stok expired dan otomatis mencatat ke Keuangan
     */
    public function destroyStock(Request $request, $id)
    {
        $product = Product::findOrFail($id);

        if ($product->stock <= 0) {
            return back()->with('error', 'Stok produk ini sudah kosong.');
        }

        DB::transaction(function () use ($product) {
            $qtyExpired = $product->stock;
            $totalLoss  = $product->buy_price * $qtyExpired;

            // 1. Catat Otomatis ke Keuangan
            Expense::create([
                'name'         => "Penyusutan Stok (Expired)", // Menambah field 'name' agar tidak error
                'account_name' => 'Beban Penurunan Nilai Persediaan',
                'category'     => 'Kerugian Stok',
                'amount'       => $totalLoss,
                'description'  => "Otomatis (Expired): Penghapusan stok {$product->title} sebanyak {$qtyExpired} pcs.",
                'date'         => now(),
                'user_id'      => auth()->id(),
            ]);

            // 2. Nolkan Stok Produk
            $product->update([
                'stock' => 0
            ]);
        });

        return back()->with('success', 'Stok expired berhasil dihapus dan dicatat sebagai kerugian di keuangan.');
    }

    public function exportPdf(Request $request)
    {
        $startDate = $request->start_date ?? Carbon::now()->format('Y-m-d');
        $endDate   = $request->end_date   ?? Carbon::now()->addDays(30)->format('Y-m-d');

        $products = Product::whereBetween('expired_date', [$startDate, $endDate])
            ->orderBy('expired_date', 'asc')
            ->get();

        $pdf = Pdf::loadView('exports.expired_pdf', compact('products', 'startDate', 'endDate'));
        $pdf->setPaper('a4', 'portrait');

        return $pdf->download("laporan-expired-{$startDate}-to-{$endDate}.pdf");
    }

    public function exportExcel(Request $request)
    {
        $startDate = $request->start_date ?? Carbon::now()->format('Y-m-d');
        $endDate   = $request->end_date   ?? Carbon::now()->addDays(30)->format('Y-m-d');

        return Excel::download(
            new ExpiredProductsExport($startDate, $endDate), 
            "laporan-expired-{$startDate}-to-{$endDate}.xlsx"
        );
    }
}