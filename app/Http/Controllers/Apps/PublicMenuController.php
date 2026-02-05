<?php

namespace App\Http\Controllers\Apps;
use App\Http\Controllers\Controller;
use App\Models\{Product, Table, Category};
use Inertia\Inertia;

class PublicMenuController extends Controller {
    public function index($table_id = null) {
        return Inertia::render('Public/CustomerMenu', [
            'products'   => Product::where('stock', '>', 0)->orderBy('title')->get(),
            'table'      => $table_id ? Table::find($table_id) : null,
            'categories' => Category::orderBy('name')->get(),
        ]);
    }
}