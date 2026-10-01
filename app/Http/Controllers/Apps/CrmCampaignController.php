<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\CrmCampaign;
use App\Models\Customer;
use App\Models\CustomerSegment;
use App\Models\Product;
use App\Services\CrmCampaignService;
use App\Services\CrmMessageSender;
use App\Services\CrmSegmentService;
use App\Services\CrmVoucherService;
use App\Support\IndonesianPhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class CrmCampaignController extends Controller
{
    public function __construct(
        protected CrmSegmentService $segments,
        protected CrmCampaignService $campaigns,
        protected CrmMessageSender $sender,
    ) {}

    public function index()
    {
        $segments = CustomerSegment::query()->latest('id')->get()->map(function (CustomerSegment $segment) {
            $segment->setAttribute('recipients', $this->segments->count($segment));

            return $segment;
        });
        $campaigns = CrmCampaign::with(['segment:id,name,is_active'])
            ->withCount('deliveries')
            ->latest('id')
            ->get();
        $products = Product::orderBy('title')->get(['id', 'title']);
        $categories = Category::orderBy('name')->get(['id', 'name']);

        return Inertia::render('Dashboard/Crm/Campaigns', [
            'segments' => $segments,
            'campaigns' => $campaigns,
            'products' => $products,
            'categories' => $categories,
            'canManage' => $this->canManage(),
            'stats' => [
                'customers' => Customer::whereNull('crm_opt_out_at')->count(),
                'opted_out' => Customer::whereNotNull('crm_opt_out_at')->count(),
                'active_campaigns' => CrmCampaign::whereIn('status', ['draft', 'scheduled', 'processing'])->count(),
                'sent_messages' => CrmCampaign::sum('sent_count'),
                'failed_messages' => CrmCampaign::sum('failed_count'),
            ],
        ]);
    }

    public function storeSegment(Request $request)
    {
        $this->authorizeManagement();
        $validated = $this->validateSegment($request);
        CustomerSegment::create($validated + ['created_by' => auth()->id()]);

        return back()->with('success', 'Segment pelanggan berhasil disimpan.');
    }

    public function updateSegment(Request $request, CustomerSegment $segment)
    {
        $this->authorizeManagement();
        $segment->update($this->validateSegment($request));

        return back()->with('success', 'Segment pelanggan berhasil diperbarui.');
    }

    private function validateSegment(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'rules' => ['required', 'array'],
            'is_active' => ['nullable', 'boolean'],
            'rules.tiers' => ['nullable', 'array'],
            'rules.tiers.*' => [Rule::in(['bronze', 'silver', 'gold', 'platinum'])],
            'rules.min_lifetime_spend' => ['nullable', 'numeric', 'min:0'],
            'rules.max_lifetime_spend' => ['nullable', 'numeric', 'min:0'],
            'rules.min_transactions' => ['nullable', 'integer', 'min:1'],
            'rules.inactive_days' => ['nullable', 'integer', 'min:1'],
            'rules.birthday_month' => ['nullable', 'integer', 'between:1,12'],
            'rules.birthday_day' => ['nullable', 'integer', 'between:1,31'],
            'rules.product_id' => ['nullable', 'integer', Rule::exists('products', 'id')],
            'rules.category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
        ], [
            'name.required' => 'Nama segment wajib diisi.',
            'rules.required' => 'Filter segment wajib diisi.',
            'rules.tiers.*.in' => 'Tier hanya boleh bronze, silver, gold, atau platinum.',
            'rules.min_lifetime_spend.numeric' => 'Min lifetime spend harus berupa angka.',
            'rules.min_lifetime_spend.min' => 'Min lifetime spend tidak boleh negatif.',
            'rules.min_transactions.integer' => 'Min transaksi harus berupa angka bulat.',
            'rules.min_transactions.min' => 'Min transaksi minimal 1.',
            'rules.inactive_days.integer' => 'Jumlah hari tidak belanja harus berupa angka bulat.',
            'rules.inactive_days.min' => 'Jumlah hari tidak belanja minimal 1.',
            'rules.birthday_month.between' => 'Bulan lahir harus antara 1 sampai 12.',
            'rules.birthday_day.between' => 'Tanggal lahir harus antara 1 sampai 31.',
            'rules.product_id.exists' => 'Produk yang dipilih tidak ditemukan.',
            'rules.category_id.exists' => 'Kategori yang dipilih tidak ditemukan.',
        ]);

        $validated['rules'] = $this->normalizeRules($validated['rules']);

        return $validated;
    }

    public function destroySegment(CustomerSegment $segment)
    {
        $this->authorizeManagement();
        $segment->delete();

        return back()->with('success', 'Segment pelanggan berhasil dihapus.');
    }

    public function storeCampaign(Request $request)
    {
        $this->authorizeManagement();
        $validated = $this->validateCampaign($request);
        $validated['status'] = ! empty($validated['scheduled_at']) ? 'scheduled' : 'draft';
        $validated['created_by'] = auth()->id();
        CrmCampaign::create($validated);

        return back()->with('success', 'Campaign CRM berhasil dibuat.');
    }

    public function updateCampaign(Request $request, CrmCampaign $campaign)
    {
        $this->authorizeManagement();
        $validated = $this->validateCampaign($request);
        if (! empty($validated['scheduled_at'])) {
            $validated['status'] = 'scheduled';
        } elseif ($campaign->status === 'scheduled') {
            $validated['status'] = 'draft';
        }
        $campaign->update($validated);

        return back()->with('success', 'Campaign CRM berhasil diperbarui.');
    }

    /**
     * Detail campaign: contoh pesan hasil personalisasi + riwayat pengiriman.
     */
    public function show(Request $request, CrmCampaign $campaign)
    {
        $campaign->load(['segment:id,name,is_active', 'sharedPromoCode']);

        $deliveries = $campaign->deliveries()
            ->with('customer:id,name,no_telp,email')
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('search'), function ($query) use ($request): void {
                $search = '%'.$request->string('search')->toString().'%';
                $query->where(function ($inner) use ($search): void {
                    $inner->where('target', 'like', $search)
                        ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', $search));
                });
            })
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $statusCounts = $campaign->deliveries()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return Inertia::render('Dashboard/Crm/CampaignDetail', [
            'campaign' => $campaign,
            'sample' => $this->campaigns->sample($campaign, 10),
            'runBlocker' => $this->campaigns->runBlocker($campaign),
            'deliveries' => $deliveries,
            'statusCounts' => $statusCounts,
            'filters' => [
                'status' => $request->string('status')->toString(),
                'search' => $request->string('search')->toString(),
            ],
        ]);
    }

    public function run(Request $request, CrmCampaign $campaign)
    {        $this->authorizeManagement();

        if ($blocker = $this->campaigns->runBlocker($campaign)) {
            return back()->with('error', 'Campaign tidak bisa diproses. '.$blocker);
        }

        $result = $this->campaigns->run($campaign, $request->boolean('force'));

        if ($result['remaining']) {
            return back()->with('success', sprintf(
                'Diproses %d penerima: preview %d, terkirim %d, gagal %d, dilewati %d. '
                .'Sisa penerima dikirim otomatis oleh scheduler pada menit berikutnya.',
                $result['total'],
                $result['preview'],
                $result['sent'],
                $result['failed'],
                $result['skipped'],
            ));
        }

        return back()->with('success', sprintf(
            'Campaign selesai. Preview: %d, terkirim: %d, gagal: %d, dilewati: %d. '
            .'Buka "Lihat Pesan" untuk mengecek isi pesan.',
            $result['preview'],
            $result['sent'],
            $result['failed'],
            $result['skipped'],
        ));
    }

    public function destroyCampaign(CrmCampaign $campaign)
    {
        $this->authorizeManagement();
        $campaign->delete();

        return back()->with('success', 'Campaign CRM berhasil dihapus.');
    }

    /**
     * Kirim satu pesan uji ke nomor yang dipilih operator.
     *
     * Tujuannya satu: membuktikan token dan perangkat Fonnte benar-benar
     * hidup SEBELUM campaign sungguhan dijalankan. Tanpa ini, kegagalan
     * kesalahan konfigurasi baru muncul setelah campaign menyentuh pelanggan —
     * sebagai `failed` per baris delivery yang jauh lebih sulit ditelusuri.
     *
     * Sifat penting:
     *  - tidak punya campaign, jadi tidak menyentuh `crm_campaigns` maupun
     *    `crm_campaign_deliveries`, dan tidak menerbitkan voucher;
     *  - tercatat di log aplikasi, supaya ada jejak siapa mengetes ke mana;
     *  - hanya bisa dipanggil oleh role yang boleh mengelola campaign.
     */
    public function testSend(Request $request)
    {
        $this->authorizeManagement();

        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:40'],
            'channel' => ['required', Rule::in(['whatsapp', 'email'])],
        ], [], [
            'phone' => 'nomor tujuan',
            'channel' => 'kanal',
        ]);

        $rawPhone = trim($validated['phone']);

        /*
         * Penolakan dikirim sebagai `ValidationException`, bukan
         * `back()->with('error')`.
         *
         * Alasannya: halaman ini mengirim lewat Inertia, dan Inertia hanya
         * memanggil `onError` untuk respons 422. Flash `error` ikut dibawa
         * ke props `flash.error`, tapi tidak ada penangan global yang
         * menampilkannya -- yang dipakai halaman ini hanya `toast.success`
         * di dalam `onSuccess`. Kalau penolakan dikirim sebagai flash,
         * operator justru akan melihat "Pesan uji terkirim" padahal tidak
         * ada pesan yang keluar sama sekali.
         */
        if ($validated['channel'] === 'whatsapp') {
            $target = IndonesianPhoneNumber::toInternational($rawPhone);

            if ($target === null) {
                throw ValidationException::withMessages([
                    'phone' => [IndonesianPhoneNumber::rejectionReason($rawPhone)],
                ]);
            }
        } else {
            $target = $rawPhone;

            if (! filter_var($target, FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages([
                    'phone' => ['Alamat email "'.$rawPhone.'" tidak valid. '
                        .'Contoh: nama@tokoanda.com'],
                ]);
            }
        }

        // Isi pesan uji sengaja ditandai jelas supaya penerima tahu itu pesan uji,
        // dan tidak salah mengira sebagai promo atau tagihan.
        $message = "LakuPOS Aja: ini pesan uji dari kasir. "
            ."Nomor tujuan \u{1F4F1} ".$target." terhubung dengan baik.";

        $startedAt = microtime(true);
        $result = $this->sender->send(
            $validated['channel'],
            $target,
            $message,
            'Tes koneksi LakuPOS',
        );

        Log::info('CRM test send', [
            'user_id' => $request->user()?->id,
            'channel' => $validated['channel'],
            'input' => $rawPhone,
            'target' => $target,
            'status' => $result['status'],
            'provider_reference' => $result['provider_reference'],
            'error' => $result['error'],
            'seconds' => round(microtime(true) - $startedAt, 2),
        ]);

        if ($result['status'] === 'sent') {
            return back()->with('success', 'Tes koneksi berhasil. Pesan sudah diterima provider '
                .'untuk '.$target.'. Cek '.$target.' di WhatsApp atau email.');
        }

        // Sama seperti penolakan format di atas: provider menolak adalah
        // kegagalan, jadi harus muncul lewat `onError`. Kalau dikirim sebagai
        // flash success, operator akan mengira tesnya berhasil padahal
        // provider sebenarnya menolak.
        throw ValidationException::withMessages([
            'phone' => ['Tes koneksi gagal: '.($result['error'] ?: 'Provider menolak pesan.')
                .' Periksa token dan status perangkat di Fonnte.'],
        ]);
    }

    private function validateCampaign(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'segment_id' => ['required', 'integer', Rule::exists('customer_segments', 'id')],
            'type' => ['required', Rule::in(['birthday', 'tier_promotion', 'win_back', 'manual'])],
            'channel' => ['required', Rule::in(['whatsapp', 'email', 'copy'])],
            'subject' => ['nullable', 'string', 'max:150'],
            'message' => ['required', 'string', 'max:5000'],
            'scheduled_at' => ['nullable', 'date'],
            'is_dry_run' => ['nullable', 'boolean'],
            'voucher_mode' => ['nullable', Rule::in([CrmVoucherService::MODE_NONE, CrmVoucherService::MODE_PER_RECIPIENT, CrmVoucherService::MODE_SHARED])],
            'voucher_name' => ['nullable', 'string', 'max:120'],
            'voucher_type' => ['nullable', Rule::in(['fixed', 'percentage'])],
            'voucher_value' => ['nullable', 'numeric', 'min:0'],
            'voucher_usage_limit' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'voucher_min_spend' => ['nullable', 'numeric', 'min:0'],
            'voucher_expires_at' => ['nullable', 'date'],
            'voucher_prefix' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9]*$/'],
            'shared_voucher_code' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/'],
        ], [
            'name.required' => 'Nama campaign wajib diisi.',
            'segment_id.required' => 'Pilih segment penerima dulu.',
            'segment_id.exists' => 'Segment yang dipilih tidak ditemukan.',
            'type.required' => 'Pilih tipe campaign.',
            'type.in' => 'Tipe campaign tidak valid.',
            'channel.required' => 'Pilih channel pengiriman.',
            'channel.in' => 'Channel tidak valid.',
            'message.required' => 'Isi pesan wajib diisi.',
            'message.max' => 'Isi pesan maksimal 5000 karakter.',
            'voucher_mode.in' => 'Mode voucher tidak valid.',
            'voucher_type.in' => 'Tipe diskon harus Potong Tetap atau Persentase.',
            'voucher_value.numeric' => 'Nilai voucher harus berupa angka.',
            'voucher_value.min' => 'Nilai voucher tidak boleh negatif.',
            'voucher_usage_limit.min' => 'Batas pemakaian minimal 1 kali.',
            'voucher_usage_limit.max' => 'Batas pemakaian maksimal 9999 kali.',
            'voucher_min_spend.numeric' => 'Minimum belanja harus berupa angka.',
            'voucher_prefix.regex' => 'Awalan kode hanya boleh huruf dan angka.',
            'shared_voucher_code.regex' => 'Kode promo hanya boleh huruf, angka, tanda hubung, dan underscore.',
        ]);

        $validated = $this->normalizeCampaignVoucher($validated);

        $this->assertMessagePlaceholders($validated);

        return $validated;
    }

    /**
     * Tolak pesan yang placeholder-nya tidak bisa diisi.
     *
     * Dua masalah yang di-catching di sini, keduanya baru terlihat setelah
     * pesan terkirim ke pelanggan sungguhan:
     *
     * 1. Placeholder salah ketik. `{namaa}` akan terkirim apa adanya ke
     *    WhatsApp pelanggan, karena tidak ada yang menyalin `{namaa}` jadi
     *    nama. Tidak ada error, tidak ada deliveri gagal -- hanya pesan
     *    yang salah baca.
     *
     * 2. Placeholder voucher tanpa voucher. Kalau mode-nya "Tanpa Voucher",
     *    `{kode_voucher}` diekspansi menjadi string kosong, jadi pelanggan
     *    menerima "Kode: " dengan angka nol di belakangnya.
     *
     * Dicek di server, bukan hanya di frontend, karena halaman campaign bisa
     * diakses dari perangkat berbeda dan keduanya harus sepakat.
     */
    private function assertMessagePlaceholders(array $validated): void
    {
        $message = (string) ($validated['message'] ?? '');
        $errors = [];

        $unknown = CrmCampaignService::unknownPlaceholders($message);

        if ($unknown !== []) {
            $errors[] = 'Placeholder tidak dikenal: '.implode(', ', $unknown)
                .'. Yang tersedia: {nama}, {tier}, {poin}, {kode_voucher}, {nama_voucher}.';
        }

        $mode = $validated['voucher_mode'] ?? CrmVoucherService::MODE_NONE;

        if ($mode === CrmVoucherService::MODE_NONE) {
            $used = array_values(array_filter(
                CrmCampaignService::voucherPlaceholders(),
                fn (string $token) => str_contains($message, $token)
            ));

            if ($used !== []) {
                $errors[] = 'Pesan memakai '.implode(', ', $used)
                    .' tapi mode voucher-nya "Tanpa Voucher". Pelanggan akan menerima '
                    .'placeholder itu kosong. Pilih mode voucher, atau hapus placeholder-nya.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(['message' => $errors]);
        }
    }

    /**
     * Voucher hanya disimpan bila mode != none dan definisinya lengkap.
     * Mode `none` membersihkan semua kolom voucher supaya tidak ada sisa
     * kode lama yang ikut terkirim pada campaign berikutnya.
     */
    private function normalizeCampaignVoucher(array $validated): array
    {
        $mode = $validated['voucher_mode'] ?? CrmVoucherService::MODE_NONE;

        if ($mode === CrmVoucherService::MODE_NONE) {
            return array_merge($validated, [
                'voucher_mode' => CrmVoucherService::MODE_NONE,
                'voucher_name' => null,
                'voucher_type' => null,
                'voucher_value' => null,
                'voucher_usage_limit' => 1,
                'voucher_min_spend' => 0,
                'voucher_expires_at' => null,
                'voucher_prefix' => null,
                'shared_voucher_code' => null,
            ]);
        }

        if (blank($validated['voucher_value'] ?? null)) {
            throw ValidationException::withMessages([
                'voucher_value' => 'Nilai voucher wajib diisi untuk mode ini.',
            ]);
        }

        if ($mode === CrmVoucherService::MODE_SHARED && blank($validated['shared_voucher_code'] ?? null)) {
            throw ValidationException::withMessages([
                'shared_voucher_code' => 'Kode promo bersama wajib diisi.',
            ]);
        }

        $validated['voucher_mode'] = $mode;

        // Dua baris ini sebelumnya membaca `$validated['voucher_name']` dan
        // `$validated['voucher_prefix']` langsung. Kedua field itu `nullable`
        // di aturan validasi, jadi tidak mengirimkannya itu sah -- tapi
        // baris ini tetap meledak dengan "Undefined array key" dan
        // membalas HTTP 500. Formulir di UI kebetulan selalu mengirimnya,
        // jadi tidak pernah terlihat selama ini, tapi request dari luar
        // (tes, integrasi, kiriman ponsel) langsung gagal.
        $validated['voucher_name'] = ($validated['voucher_name'] ?? null) ?: ('Voucher '.$validated['name']);
        $validated['voucher_prefix'] = ($validated['voucher_prefix'] ?? null) ?: 'PROMO';

        // Batas pemakaian hanya relevan untuk kode unik per penerima.
        // Kode promo bersama sudah punya kuota sendiri di `promo_codes`,
        // jadi mode shared memaksa batas 1 agar tidak ada dua tempat yang
        // sama-sama mengatur kuota kode yang sama.
        $validated['voucher_usage_limit'] = $mode === CrmVoucherService::MODE_SHARED
            ? 1
            : max(1, (int) ($validated['voucher_usage_limit'] ?? 1));

        if ($mode === CrmVoucherService::MODE_PER_RECIPIENT) {
            $validated['shared_voucher_code'] = null;
        }

        return $validated;
    }

    private function normalizeRules(array $rules): array
    {
        $normalized = [];
        foreach (['tiers', 'min_lifetime_spend', 'max_lifetime_spend', 'min_transactions', 'inactive_days', 'birthday_month', 'birthday_day', 'product_id', 'category_id'] as $key) {
            if (array_key_exists($key, $rules) && $rules[$key] !== '' && $rules[$key] !== null) {
                $normalized[$key] = $rules[$key];
            }
        }

        return $normalized ?: ['tiers' => []];
    }

    private function authorizeManagement(): void
    {
        abort_unless($this->canManage(), 403);
    }

    private function canManage(): bool
    {
        return auth()->user()->can('crm-manage')
            || auth()->user()->can('settings.update')
            || auth()->user()->can('settings.cgos');
    }
}
