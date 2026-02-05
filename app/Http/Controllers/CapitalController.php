<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class CapitalController extends Controller
{
    public function store(Request $request) {
    $request->validate(['amount' => 'required|numeric', 'source' => 'required']);
    \App\Models\Capital::create([
        'amount' => $request->amount,
        'source' => $request->source,
        'date'   => now()
    ]);
    return back()->with('success', 'Modal berhasil ditambahkan!');
    }
}
