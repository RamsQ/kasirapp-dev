<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Models\OnlineSetting;
use Illuminate\Http\Request;
use Inertia\Inertia;

class OnlineSettingController extends Controller
{
    public function index()
    {
        return Inertia::render('Dashboard/Settings/Online', [
            'settings' => OnlineSetting::all()
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'markup_percent' => 'required|numeric|min:0',
            'additional_fee' => 'required|numeric|min:0',
        ]);

        OnlineSetting::create($request->all());

        return back()->with('success', 'Platform harga online berhasil ditambahkan!');
    }

    public function destroy($id)
    {
        OnlineSetting::findOrFail($id)->delete();
        return back()->with('success', 'Platform berhasil dihapus!');
    }
}