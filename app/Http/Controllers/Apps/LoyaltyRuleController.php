<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\LoyaltyRule;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class LoyaltyRuleController extends Controller
{
    public function index()
    {
        return Inertia::render('Dashboard/Settings/LoyaltyRules', [
            'rules' => LoyaltyRule::with(['product:id,title', 'category:id,name'])
                ->orderByDesc('priority')
                ->orderByDesc('min_spend')
                ->latest('id')
                ->get(),
            'products' => Product::orderBy('title')->get(['id', 'title']),
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'canManage' => $this->canManage(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeManagement();

        $validated = $this->validateRule($request);
        LoyaltyRule::create($validated);

        return back()->with('success', 'Rule loyalty berhasil dibuat.');
    }

    public function update(Request $request, LoyaltyRule $loyaltyRule)
    {
        $this->authorizeManagement();

        $loyaltyRule->update($this->validateRule($request));

        return back()->with('success', 'Rule loyalty berhasil diperbarui.');
    }

    public function destroy(LoyaltyRule $loyaltyRule)
    {
        $this->authorizeManagement();
        $loyaltyRule->delete();

        return back()->with('success', 'Rule loyalty berhasil dihapus.');
    }

    private function validateRule(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'min_spend' => ['required', 'integer', 'min:0'],
            'points' => ['required', 'integer', 'min:0', 'max:1000000'],
            'product_id' => ['nullable', 'integer', Rule::exists('products', 'id')],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'priority' => ['required', 'integer', 'min:0', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    private function authorizeManagement(): void
    {
        abort_unless(
            auth()->user()->can('settings.update') || auth()->user()->can('settings.cgos'),
            403,
        );
    }

    private function canManage(): bool
    {
        return auth()->user()->can('settings.update') || auth()->user()->can('settings.cgos');
    }
}
