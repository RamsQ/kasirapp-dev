<?php

namespace App\Services;

use App\Models\CrmCampaign;
use App\Models\CrmCampaignDelivery;
use App\Models\Customer;
use App\Models\CustomerSegment;
use App\Support\IndonesianPhoneNumber;

class CrmCampaignService
{
    /**
     * Campaign yang masih `processing` dianggap macet setelah beberapa menit
     * sehingga aman diklaim ulang oleh scheduler.
     */
    protected const STALE_PROCESSING_MINUTES = 15;

    /** Jumlah penerima maksimum per satu batch. */
    public const DEFAULT_BATCH_SIZE = 25;

    /**
     * Batas waktu satu batch. nginx/php-fpm biasanya memutus request di
     * sekitar 60 detik, jadi dijaga jauh di bawah itu.
     */
    public const BATCH_MAX_SECONDS = 45;

    public function __construct(
        protected CrmSegmentService $segments,
        protected CrmMessageSender $sender,
        protected CrmVoucherService $vouchers,
    ) {}

    public function recipientCount(CrmCampaign $campaign, bool $respectConsent = true): int
    {
        if (! $campaign->segment) {
            return 0;
        }

        return $this->segments->count($campaign->segment, $respectConsent);
    }

    /**
     * Alasan campaign tidak boleh dijalankan, atau null bila aman.
     * Status aktif dibaca langsung dari database supaya tidak memakai
     * relasi segment yang sudah ter-cache dan basi.
     */
    public function runBlocker(CrmCampaign $campaign): ?string
    {
        $segment = CustomerSegment::find($campaign->segment_id);
        if (! $segment) {
            return 'Segment campaign ini sudah dihapus.';
        }

        if (! $segment->is_active) {
            return 'Segment "'.$segment->name.'" sedang nonaktif. Aktifkan dulu di halaman CRM Campaigns.';
        }

        return null;
    }

    /**
     * Contoh pesan hasil personalisasi untuk beberapa penerima pertama.
     * Tidak menulis apa pun ke database dan tidak memanggil provider,
     * jadi aman dipakai sebagai preview sebelum campaign dijalankan.
     *
     * @return array<int,array{customer:Customer,target:?string,message:string,deliverable:bool,reason:?string}>
     */
    public function sample(CrmCampaign $campaign, int $limit = 10): array
    {
        if ($this->runBlocker($campaign) !== null) {
            return [];
        }

        $recipients = $this->segments->query($campaign->segment)->limit($limit)->get();

        return $recipients->map(function (Customer $customer) use ($campaign): array {
            $message = $this->renderMessage($campaign, $customer);
            $block = $this->deliveryBlocker($campaign, $customer);

            return [
                'customer' => $customer,
                'target' => $this->targetFor($campaign, $customer),
                'message' => $message,
                'deliverable' => $block === null,
                'reason' => $block,
            ];
        })->all();
    }

    /**
     * Alasan penerima tidak bisa dikirimi, atau null bila aman dikirim.
     */
    public function deliveryBlocker(CrmCampaign $campaign, Customer $customer): ?string
    {
        if ($customer->crm_opt_out_at) {
            return 'Pelanggan sudah opt-out dari komunikasi CRM.';
        }

        if (! $customer->marketing_opt_in) {
            return 'Pelanggan tidak mengizinkan marketing.';
        }

        if ($campaign->channel === 'email') {
            return blank($customer->email) ? 'Email pelanggan belum diisi.' : null;
        }

        if ($campaign->channel !== 'whatsapp') {
            return null;
        }

        // Nomor WhatsApp diseragamkan dulu. Fonnte hanya menerima format
        // internasional (`62812...`), sementara kasir mengetik seperti
        // `0812-3456-7890`. Tanpa ini nomor yang valid tetap ditolak provider,
        // dan kegagalannya muncul sebagai `failed` per pelanggan tanpa
        // penjelasan.
        return IndonesianPhoneNumber::rejectionReason($customer->no_telp ?: $customer->phone);
    }

    /**
     * Alamat tujuan penerima, sudah dalam format yang diterima provider.
     *
     * Untuk WhatsApp, nomor dinormalisasi ke `62812...`. Ini satu-satunya
     * tempat nomor dibaca, jadi hasil normalisasinya ikut dipakai oleh preview,
     * penyimpanan delivery, dan pengiriman sungguhan sekaligus.
     */
    public function targetFor(CrmCampaign $campaign, Customer $customer): ?string
    {
        $target = match ($campaign->channel) {
            'whatsapp' => IndonesianPhoneNumber::toInternational(
                $customer->no_telp ?: $customer->phone
            ),
            'email' => $customer->email,
            default => null,
        };

        return blank($target) ? null : (string) $target;
    }

    /**
     * Jalankan satu BAGIAN campaign lalu berhenti.
     *
     * Tiap penerima memicu satu panggilan HTTP ke provider, jadi campaign besar
     * butuh waktu lama. Kalau semuanya berjalan di satu request, prosesnya
     * dibunuh timeout (nginx/php-fpm ~60 detik): campaign berhenti di tengah
     * jalan, kasir melihat error, padahal sebagian pesan sudah terkirim dan
     * stok/poin sudah berubah sebagian.
     *
     * Karena itu pengiriman dipecah: satu bagian per pemanggilan, sisanya
     * dilanjutkan scheduler. Tidak butuh queue worker.
     *
     * @return array{total:int,sent:int,failed:int,skipped:int,preview:int,status:string,remaining:bool}
     */
    public function run(CrmCampaign $campaign, bool $force = false, ?int $limit = null, ?int $maxSeconds = null, bool $resume = false): array
    {
        if (! $force && $campaign->scheduled_at && $campaign->scheduled_at->isFuture()) {
            return $this->emptyResult();
        }

        if ($this->runBlocker($campaign) !== null) {
            return $this->emptyResult();
        }

        if (! $this->claim($campaign, $resume)) {
            return $this->emptyResult();
        }

        $campaign->refresh();
        if ($this->runBlocker($campaign) !== null) {
            $campaign->update(['status' => 'failed']);

            return $this->emptyResult();
        }

        return $this->drain(
            $campaign,
            $limit ?? self::DEFAULT_BATCH_SIZE,
            $maxSeconds ?? self::BATCH_MAX_SECONDS,
        );
    }

    /**
     * @return array{total:int,sent:int,failed:int,skipped:int,preview:int,status:string,remaining:bool}
     */
    private function drain(CrmCampaign $campaign, int $limit, int $maxSeconds): array
    {
        $limit = max(1, min($limit, 500));
        $startedAt = microtime(true);
        $counts = [
            'total' => 0,
            'sent' => 0,
            'failed' => 0,
            'skipped' => 0,
            'preview' => 0,
            'remaining' => false,
        ];

        $this->segments->query($campaign->segment)
            ->chunkById(100, function ($customers) use ($campaign, &$counts, $limit, $startedAt, $maxSeconds): bool {
                foreach ($customers as $customer) {
                    // Penerima yang sudah selesai di batch sebelumnya TIDAK
                    // boleh memakan kuota batch. Kalau tidak, campaign besar
                    // tidak akan pernah selesai: tiap batch mengulang dari
                    // awal dan kehabisan kuota hanya untuk penerima yang
                    // sudah terkirim.
                    if ($this->alreadyDelivered($campaign, $customer)) {
                        continue;
                    }

                    // Berhenti sebelum kehabisan waktu agar tidak terbunuh
                    // timeout di tengah pemanggilan provider.
                    if ($counts['total'] >= $limit || (microtime(true) - $startedAt) >= $maxSeconds) {
                        $counts['remaining'] = true;

                        return false;
                    }

                    $counts['total']++;
                    $this->deliverToCustomer($campaign, $customer, $counts);
                }

                return true;
            });

        return $counts + $this->applyProgress($campaign, $counts);
    }

    /**
     * Tentukan status akhir atau menyisanya untuk scheduler.
     *
     * Penghitung tidak dijumlahkan secara kumulatif, melainkan dihitung ulang
     * dari `crm_campaign_deliveries`. Kalau pengirimannya gagal lalu berhasil
     * saat retry, angkanya harus ikut turun -- kalau dijumlahkan, campaign
     * akan selalu terlihat `partial` dan tidak pernah `sent`.
     *
     * @param  array<string,mixed>  $counts
     * @return array{status:string,remaining:bool}
     */
    private function applyProgress(CrmCampaign $campaign, array $counts): array
    {
        $tally = $this->recountProgress($campaign);

        if ($counts['remaining']) {
            // Masih ada sisa: tetap `processing` supaya tidak bisa diklaim dua
            // kali, dan dilanjutkan scheduler pada menit berikutnya.
            $campaign->update(['status' => 'processing', 'sent_at' => null]);

            return ['status' => 'processing', 'remaining' => true];
        }

        $status = $campaign->is_dry_run || $campaign->channel === 'copy'
            ? ($tally['failed'] > 0 ? 'partial' : 'previewed')
            : ($tally['failed'] > 0 ? 'partial' : 'sent');

        $campaign->update([
            'status' => $status,
            'sent_at' => $status === 'sent' ? now() : null,
        ]);

        return ['status' => $status, 'remaining' => false];
    }

    /**
     * @return array{sent:int,failed:int,skipped:int,total:int}
     */
    private function recountProgress(CrmCampaign $campaign): array
    {
        $tally = CrmCampaignDelivery::where('campaign_id', $campaign->id)
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $sent = (int) $tally->get('sent', 0) + (int) $tally->get('preview', 0);
        $failed = (int) $tally->get('failed', 0);
        $skipped = (int) $tally->get('skipped', 0);

        $campaign->update([
            'total_recipients' => (int) $tally->sum(),
            'sent_count' => $sent,
            'failed_count' => $failed,
            'skipped_count' => $skipped,
        ]);

        return [
            'sent' => $sent,
            'failed' => $failed,
            'skipped' => $skipped,
            'total' => (int) $tally->sum(),
        ];
    }

    /**
     * Ambil lock inklusif proses agar campaign tidak terkirim dua kali oleh
     * scheduler dan tombol proses manual sekaligus.
     *
     * `processing` hanya boleh diklaim ulang oleh scheduler lewat
     * `$resume = true`, karena scheduler berjalan `withoutOverlapping` dan
     * menjadi pemilik siklus hidup campaign. Jalur manual harus menunggu
     * statusnya dianggap macet, supaya request yang sebelumnya mati tidak
     * dilompati selagi masih diproses.
     */
    private function claim(CrmCampaign $campaign, bool $resume = false): bool
    {
        $stale = now()->subMinutes(self::STALE_PROCESSING_MINUTES);

        return CrmCampaign::whereKey($campaign->id)
            ->where(function ($query) use ($resume, $stale): void {
                $query->whereIn('status', ['draft', 'scheduled', 'failed', 'partial', 'previewed'])
                    ->orWhere(function ($query) use ($resume, $stale): void {
                        $query->where('status', 'processing')
                            ->where(function ($query) use ($resume, $stale): void {
                                if ($resume) {
                                    $query->whereRaw('1 = 1');
                                } else {
                                    $query->whereNull('last_run_at')->orWhere('last_run_at', '<', $stale);
                                }
                            });
                    });
            })
            ->update([
                'status' => 'processing',
                'last_run_at' => now(),
            ]) > 0;
    }

    /**
     * @return array{total:int,sent:int,failed:int,skipped:int,preview:int,status:string,remaining:bool}
     */
    private function emptyResult(): array
    {
        return [
            'total' => 0,
            'sent' => 0,
            'failed' => 0,
            'skipped' => 0,
            'preview' => 0,
            'status' => 'processing',
            'remaining' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $counts
     */
    private function deliverToCustomer(CrmCampaign $campaign, Customer $customer, array &$counts): void
    {
        $blocker = $this->deliveryBlocker($campaign, $customer);
        if ($blocker !== null) {
            $this->recordDelivery($campaign, $customer, null, $this->renderMessage($campaign, $customer), 'skipped', null, $blocker);
            $counts['skipped']++;

            return;
        }

        $target = $this->targetFor($campaign, $customer);
        $isPreview = $campaign->is_dry_run || $campaign->channel === 'copy';

        // Kode voucher baru diterbitkan saat pengiriman sungguhan. Pada dry-run
        // kode contoh yang dipakai supaya tidak ada voucher palsu yang terbit.
        $message = $this->renderMessage($campaign, $customer, issueVoucher: ! $isPreview);

        if ($isPreview) {
            $this->recordDelivery($campaign, $customer, $target, $message, 'preview');
            $counts['preview']++;

            return;
        }

        $result = $this->sender->send(
            $campaign->channel,
            (string) $target,
            $message,
            $campaign->subject,
        );
        $this->recordDelivery(
            $campaign,
            $customer,
            $target,
            $message,
            $result['status'],
            $result['provider_reference'],
            $result['error'],
        );
        $counts[$result['status'] === 'sent' ? 'sent' : 'failed']++;
    }

    private function alreadyDelivered(CrmCampaign $campaign, Customer $customer): bool
    {
        return CrmCampaignDelivery::where('campaign_id', $campaign->id)
            ->where('customer_id', $customer->id)
            ->whereIn('status', ['sent', 'preview', 'skipped'])
            ->exists();
    }

    private function recordDelivery(
        CrmCampaign $campaign,
        Customer $customer,
        ?string $target,
        string $message,
        string $status,
        ?string $providerReference = null,
        ?string $error = null,
    ): void {
        $delivery = CrmCampaignDelivery::firstOrNew([
            'campaign_id' => $campaign->id,
            'customer_id' => $customer->id,
        ]);

        $delivery->fill([
            'channel' => $campaign->channel,
            'target' => $target,
            'message' => $message,
            'status' => $status,
            'provider_reference' => $providerReference,
            'error' => $error,
            'sent_at' => $status === 'sent' ? now() : null,
        ])->save();
    }

    /**
     * Placeholder yang bisa dipakai di pesan campaign.
     *
     * Dipakai juga untuk memvalidasi pesan di sisi server, dan daftarnya
     * dikembalikan ke frontend supaya daftar yang tampil di layar sama persis
     * dengan yang benar-benar berfungsi.
     *
     * @return array<string,string>  label => kunci
     */
    public static function placeholders(): array
    {
        return [
            '{nama}' => '{name}',
            '{name}' => '{name}',
            '{tier}' => '{tier}',
            '{poin}' => '{points}',
            '{points}' => '{points}',
            '{kode_voucher}' => '{voucher_code}',
            '{voucher_code}' => '{voucher_code}',
            '{nama_voucher}' => '{voucher_name}',
            '{voucher_name}' => '{voucher_name}',
        ];
    }

    /**
     * Placeholder yang butuh voucher aktif. Kalau campaign tidak punya
     * voucher, semua placeholder ini akan terkirim kosong.
     *
     * @return array<int,string>
     */
    public static function voucherPlaceholders(): array
    {
        return ['{voucher_code}', '{kode_voucher}', '{voucher_name}', '{nama_voucher}'];
    }

    /**
     * Placeholder di dalam pesan yang tidak dikenali.
     *
     * Ditampilkan ke operator kalau ada, karena placeholder yang salah ketik
     * akan terkirim apa adanya ke pelanggan -- misalnya `{namaa}` muncul
     * utuh di pesan WhatsApp tanpa ada yang realizes.
     *
     * @return array<int,string>
     */
    public static function unknownPlaceholders(?string $message): array
    {
        preg_match_all('/\{[a-zA-Z0-9_]+\}/', (string) $message, $matches);

        $known = array_keys(self::placeholders());
        $unknown = [];

        foreach (array_unique($matches[0] ?? []) as $token) {
            if (! in_array($token, $known, true)) {
                $unknown[] = $token;
            }
        }

        return $unknown;
    }

    private function renderMessage(CrmCampaign $campaign, Customer $customer, bool $issueVoucher = false): string
    {
        $code = $this->vouchers->codeForMessage($campaign, $customer, $issueVoucher);
        $name = $this->vouchers->nameForMessage($campaign);

        return strtr((string) $campaign->message, [
            '{name}' => (string) $customer->name,
            '{tier}' => $customer->tierLabel(),
            '{points}' => (string) $customer->loyalty_points,
            '{voucher_code}' => $code,
            '{voucher_name}' => $name,

            // Alias bahasa Indonesia. Ditambahkan karena sebagian besar orang menulis
            // placeholder dalam bahasa yang sama dengan pesan yang ditulisnya.
            // Kedua penulisan menghasilkan teks yang sama persis.
            '{nama}' => (string) $customer->name,
            '{poin}' => (string) $customer->loyalty_points,
            '{kode_voucher}' => $code,
            '{nama_voucher}' => $name,
        ]);
    }
}
