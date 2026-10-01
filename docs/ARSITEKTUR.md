# Arsitektur LakuPOS

Dokumen ini menjelaskan bentuk sistem berdasarkan kode yang benar-benar ada.
Kalau ada bagian yang tidak cocok dengan kode, kodenya yang salah atau dokumen
ini yang perlu diperbarui — bukan sebaliknya.

---

## 1. Peta lapisan

```mermaid
flowchart TB
    subgraph Clients["Pelanggan"]
        M["Menu QR<br/>(PWA, tanpa login)"]
        C["Kasir di HP"]
    end

    subgraph Operators["Operator"]
        P["Kasir / kasir shift"]
        O["Owner / manajer"]
        K["Dapur<br/>(layar KDS)"]
    end

    M --> Edge
    C --> Edge
    P --> Edge
    O --> Edge
    K --> Edge

    Edge["Nginx / Caddy<br/>TLS + static assets"]

    subgraph App["Laravel 12 + Inertia"]
        W["routes/web.php<br/>179 route, auth + permission"]
        A["routes/api.php<br/>callback & pembayaran mandiri"]
        MW["Middleware<br/>auth, permission, HandleInertiaRequests"]

        Ctl["Controllers<br/>Apps/ dan Api/"]
        Svc["Services<br/>22 file"]
        Mdl["Models<br/>Eloquent"]
    end

    Edge --> W --> MW --> Ctl
    Edge --> A --> Ctl
    Ctl --> Svc --> Mdl

    Mdl --> DB[("MySQL<br/>mangku_pos")]

    subgraph Outside["Layanan luar"]
        MT["Midtrans"]
        XD["Xendit"]
        FN["Fonnte<br/>WhatsApp"]
        GM["Gemini / Groq"]
        PS["Pusher"]
    end

    Svc --> MT
    Svc --> XD
    Svc --> FN
    Svc --> GM
    Svc --> PS

    subgraph Cron["Scheduler<br/>9 perintah terjadwal"]
        SC["cron setiap menit"]
    end
    SC --> Ctl
    SC --> Svc
```

---

## 2. Service dan tanggung jawabnya

Bagian ini yang paling penting dijaga. Setiap service punya satu peran supaya tidak ada perhitungan yang berdiri sendiri-salone.

### Pembukuan — sumber kebenaran tunggal

```mermaid
flowchart LR
    subgraph Data["Sumber data"]
        TD[("transactions")]
        TB[("transaction_details")]
        TBD[("stock_batches")]
        SM[("stock_movements")]
        PR[("profits")]
    end

    FRS["FinancialReportService<br/><b>validTransactions() + summary()</b>"]
    AN["AnalyticsService"]

    TD --> FRS
    TB --> FRS
    TBD --> FRS
    SM --> FRS
    PR --> FRS

    FRS -->|"laporan keuangan"| R1["Laporan Keuangan"]
    FRS -->|"dipakai ulang"| AN
    AN --> R2["Analitik Grafis"]

    style FRS fill:#e8f0ff,stroke:#4f46e5
    style AN fill:#fff7ed,stroke:#ea580c
```

`AnalyticsService` **tidak** menghitung ulang HPP. Ia memanggil
`FinancialReportService::validTransactions()` dan `summary()`. Karena itu KPI
di analitik tidak mungkin berbeda dari Laporan Keuangan — tidak ada dua
perhitungan yang bisa menyimpang.

Aturan pembukuan yang berlaku di seluruh sistem:

```
omzet bersih      = taxable_amount
HPP               = SUM(buy_price x qty) dari ledger batch
laba kotor        = omzet bersih - HPP
laba bersih       = laba kotor - fee gateway - beban
diskon (%)        = dihitung terhadap omzet SEBELUM diskon
```

### Stok

```mermaid
flowchart TB
    subgraph Masuk["Stok masuk"]
        SI["StockInController"]
        SPR["StockPurchaseRecorder"]
        SB[("stock_batches")]
    end

    subgraph Keluar["Stok keluar"]
        ISS["InventoryStockService"]
        SI2["StockIntelligenceService"]
        RS[("stock_reservations")]
        SBA[("stock_batch_allocations")]
    end

    SI --> SPR --> SB
    SB -->|ledger batch| ISS
    ISS --> SI2
    SI2 --> RS
    SI2 --> SBA
    SBA -->|"konsumsi batch"| SB

    style SPR fill:#f0fdf4,stroke:#16a34a
    style SI2 fill:#f0fdf4,stroke:#16a34a
```

`stock_batches` adalah ledger. Setiap batch yang dipakai dicatat di
`stock_batch_allocations`, jadi HPP bisa ditelusuri ke batch asal — bukan
sekadar angka yang tersimpan di baris transaksi.

### Pembayaran

```mermaid
flowchart LR
    subgraph Callback["Callback masuk"]
        PNC["PaymentController"]
    end

    subgraph Inti["Pusat penyelesaian"]
        PSS["PaymentSettlementService<br/><b>canonical</b>"]
        PE[("payment_events<br/>audit")]
        PS2[("payment_settlements")]
    end

    subgraph Sumber["Sumber penyelesaian"]
        CASH["Tunai di kasir"]
        GW["Midtrans / Xendit"]
        HOLD["HoldPaymentService<br/>pemesanan dari menu QR"]
    end

    CASH --> PSS
    GW --> PNC --> PSS
    HOLD --> PSS

    PSS --> PE
    PSS --> PS2
    PSS -->|"konsumsi stok + poin"| ISS["InventoryStockService"]

    style PSS fill:#fef2f2,stroke:#dc2626
```

`PaymentSettlementService` adalah **satu-satunya** tempat yang mengubah status
pembayaran. Semua sumber — tunai, gateway, dan hold dari menu QR — masuk
melaluinya. Ini yang mencegah kas bank dan stok bergerak dua kali untuk
satu pembayaran.

Verifikasi nominal di `markPaid()` menolak selisih sekecil apa pun:

```php
if (abs($paidAmount - $expected) > 0.009) { /* ditolak */ }
```

### CRM

```mermaid
flowchart TB
    SEG["CustomerSegment<br/>filter dinamis"] --> CSS["CrmSegmentService"]
    CSS --> SEG2[("customer_segments")]

    SEG --> CC["CrmCampaignService"]
    CC --> CVS["CrmVoucherService"]
    CC --> CMS["CrmMessageSender"]

    CVS --> CV[("customer_vouchers<br/>kode unik per penerima")]
    CVS --> PC[("promo_codes<br/>kode bersama")]

    CMS --> FN2["Fonnte API"]

    CC --> DEL[("crm_campaign_deliveries")]
    LS["LoyaltyService"] --> CV
    LS --> PC

    style CC fill:#faf5ff,stroke:#9333ea
    style LS fill:#faf5ff,stroke:#9333ea
```

Voucher pribadi terikat ke `customer_id`, jadi kodenya hanya bisa dipakai
pemiliknya. Batas pemakaian disimpan di `usage_limit` / `used_count`; `status`
hanya akibat dari kuota.

### AI

```mermaid
flowchart LR
    UI["AI Business Coach"] --> ABS["AiBusinessContextService"]
    ABS --> FRS2["FinancialReportService"]
    APF["AiProviderFactory"] --> GP["GeminiProvider"]
    APF --> GQ["GroqProvider"]
    ABS --> APF
```

`AiBusinessContextService` mengambil konteks bisnis lewat
`FinancialReportService` — bukan query sendiri. Jadi angka yang dilihat AI
adalah angka yang sama dengan yang Anda lihat di Laporan Keuangan.

---

## 3. Alur satu transaksi

```mermaid
sequenceDiagram
    participant K as Kasir
    participant C as TransactionController
    participant CS as CogsService
    participant SI as StockIntelligenceService
    participant LS as LoyaltyService
    participant PSS as PaymentSettlementService
    participant DB as MySQL

    K->>C: POST /dashboard/transactions/store
    C->>LS: voucherDiscountFor()
    LS-->>C: potongan
    C->>DB: transactions + details + profits
    C->>SI: reserveForTransaction()
    SI->>DB: stock_reservations + batch allocations
    C->>LS: consumeVoucher()
    C->>PSS: settle()
    PSS->>DB: payment_events
    PSS-->>K: redirect ke struk
```

Langkah yang tidak boleh terlewat: `reserveForTransaction()` berjalan
**sebelum** pembayaran diselesaikan. Kalau pembayaran gagal, reservasi
dilepas. Kalau dibatalkan, `reverseVoucher()` mengembalikan pemakaian voucher.

---

## 4. Scheduler

Sembilan perintah, semuanya `withoutOverlapping()`:

| Perintah | Jadwal | Untuk apa |
|---|---|---|
| `crm:process-campaigns` | tiap menit | lanjutkan campaign yang terpotong |
| `payments:reconcile` | tiap 5 menit | cocokkan pembayaran yang menggantung |
| `stock:release-expired-reservations` | tiap 5 menit | lepas reservasi kedaluwarsa |
| `report:sales-daily` | tiap menit | laporan penjualan terjadwal |
| `inventory:reconcile` | 02:10 | selisih HPP mengendap |
| `repair:profit` | 02:25 | isi HPP estimasi pada data lama |
| `ai:prune-sessions` | 03:05 | bersihkan sesi AI |
| `crm:prune-old-campaigns` | 03:20 | bersihkan delivery lama |
| `report:monthly` | tanggal 1, 01:00 | laporan bulanan |

---

## 5. Batasan yang harus diketahui

Bagian ini sengaja ditulis supaya tidak ada yang terkejut saat deployment.

| Batasan | Akibat | Perbaikan |
|---|---|---|
| `KAFKA_BROKER_ADDRESS` di `.env` | dibaca tidak ada; `config/kafka.php` mencari `KAFKA_BROKERS` | samakan nama variabelnya |
| Order dari menu QR tidak pernah ke Kafka | `customerOrder()` hanya memicu `OrderPlaced` (Pusher), bukan `TransactionCreated` | tambahkan pemanggilan event-nya |
| Tidak ada `trustProxies` | di belakang proxy, `isSecure()` bisa false | tambahkan di `bootstrap/app.php` |
| Tidak ada `forceHttps` | tidak ada pengalihan otomatis ke HTTPS | tambahkan middleware |
| `SANCTUM_STATEFUL_DOMAINS` | pembayaran mandiri dari menu QR ditolak setelah pindah domain | isi di `.env` |
| `MAIL_MAILER=log` | email masuk file log, bukan inbox | isi kredensial SMTP |
| `midtrans_production=0` | masih sandbox | ganti key dan flag ke produksi |

---

## 6. Alur deployment

```mermaid
flowchart LR
    G["git pull"] --> C1["composer install --no-dev -o"]
    C1 --> N1["npm ci"]
    N1 --> N2["npm run build"]
    N2 --> M["php artisan migrate --force"]
    M --> S["php artisan db:seed --class=PermissionSeeder"]
    S --> CA["config:clear / config:cache"]
    CA --> RT["route:clear / view:clear"]
    RT --> SCH["crontab: schedule:run tiap menit"]

    style CA fill:#fef2f2,stroke:#dc2626
```

`php artisan ziggy:generate` perlu dijalankan ulang setiap kali ada route
baru ditambahkan, kalau tidak frontend akan memanggil URL yang tidak dikenal.
