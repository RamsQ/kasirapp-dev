<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\Product;
use App\Models\Expense;
use App\Services\GeminiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AiController extends Controller
{
    protected $gemini;

    public function __construct(GeminiService $gemini)
    {
        $this->gemini = $gemini;
    }

    public function index()
    {
        return inertia('Dashboard/Ai/Consultation', [
            'auth' => [
                'user' => auth()->user()->load('roles')
            ]
        ]);
    }

    public function chat(Request $request)
    {
        $request->validate(['message' => 'required|string']);

        // 1. Ambil Konteks Bisnis Real-time
        $today = now()->format('Y-m-d');
        $totalSales = Transaction::whereDate('created_at', $today)->sum('grand_total') ?? 0;
        $totalExpense = Expense::whereDate('date', $today)->sum('amount') ?? 0;
        $lowStockCount = Product::where('stock', '<=', 5)->count();

        // 2. Susun Prompt untuk Gemini 2.5
        $prompt = "Anda adalah Business Coach profesional untuk Mangku POS. 
        Gunakan data ini untuk menjawab:
        - Omzet hari ini: Rp " . number_format($totalSales, 0, ',', '.') . "
        - Pengeluaran hari ini: Rp " . number_format($totalExpense, 0, ',', '.') . "
        - Stok kritis: " . $lowStockCount . " item.
        
        Jawablah pertanyaan user dengan gaya bahasa yang profesional, memotivasi, dan solutif.
        User bertanya: " . $request->message;

        // 3. Panggil Service (Otomatis menggunakan gemini-2.5-flash dari config)
        $aiResponse = $this->gemini->generateResponse($prompt);

        return response()->json(['reply' => $aiResponse]);
    }
}