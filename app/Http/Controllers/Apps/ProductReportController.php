<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Category;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Barryvdh\DomPDF\Facade\Pdf;

class ProductReportController extends Controller
{
    /**
     * Tampilan Laporan Produk di Dashboard (Inertia)
     */
    public function index(Request $request)
    {
        $start_date = $request->start_date;
        $end_date   = $request->end_date;

        $products = Product::with('category')
            // Hitung total qty terjual dengan filter tanggal pada relasi details
            ->withSum(['details as total_sold' => function ($query) use ($start_date, $end_date) {
                $query->when($start_date && $end_date, function ($q) use ($start_date, $end_date) {
                    $q->whereBetween('created_at', [$start_date . ' 00:00:00', $end_date . ' 23:59:59']);
                });
            }], 'qty')
            // Hitung total omzet dengan filter tanggal pada relasi details
            ->withSum(['details as total_revenue' => function ($query) use ($start_date, $end_date) {
                $query->when($start_date && $end_date, function ($q) use ($start_date, $end_date) {
                    $q->whereBetween('created_at', [$start_date . ' 00:00:00', $end_date . ' 23:59:59']);
                });
            }], 'price')
            ->when($request->search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('title', 'like', '%' . $search . '%')
                      ->orWhere('barcode', 'like', '%' . $search . '%');
                });
            })
            ->when($request->category_id, function ($query, $category_id) {
                $query->where('category_id', $category_id);
            })
            ->orderBy($request->order_by ?: 'total_sold', $request->direction ?: 'desc')
            ->paginate(10)
            ->withQueryString();

        $categories = Category::orderBy('name')->get();

        return Inertia::render('Dashboard/Reports/Products', [
            'products'   => $products,
            'categories' => $categories,
            'filters'    => $request->all(['search', 'category_id', 'order_by', 'direction', 'start_date', 'end_date'])
        ]);
    }

    /**
     * Export Laporan Produk ke PDF
     */
    public function export(Request $request)
    {
        $start_date = $request->start_date;
        $end_date   = $request->end_date;

        $products = Product::with('category')
            ->withSum(['details as total_sold' => function ($query) use ($start_date, $end_date) {
                $query->when($start_date && $end_date, function ($q) use ($start_date, $end_date) {
                    $q->whereBetween('created_at', [$start_date . ' 00:00:00', $end_date . ' 23:59:59']);
                });
            }], 'qty')
            ->withSum(['details as total_revenue' => function ($query) use ($start_date, $end_date) {
                $query->when($start_date && $end_date, function ($q) use ($start_date, $end_date) {
                    $q->whereBetween('created_at', [$start_date . ' 00:00:00', $end_date . ' 23:59:59']);
                });
            }], 'price')
            ->when($request->search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('title', 'like', '%' . $search . '%')
                      ->orWhere('barcode', 'like', '%' . $search . '%');
                });
            })
            ->when($request->category_id, function ($query, $category_id) {
                $query->where('category_id', $category_id);
            })
            ->orderBy('total_sold', 'desc')
            ->get();

        $pdf = Pdf::loadView('exports.product_report', [
            'products'   => $products,
            'date'       => now()->format('d F Y'),
            'start_date' => $start_date,
            'end_date'   => $end_date,
        ]);

        $pdf->setPaper('a4', 'portrait');
        return $pdf->download('laporan-produk-' . now()->format('Y-m-d') . '.pdf');
    }
}