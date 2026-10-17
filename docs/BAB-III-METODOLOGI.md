# BAB III — METODOLOGI

## 3.1 Metode Penelitian

### 3.1.1 Pendekatan Penelitian

Penelitian ini menggunakan **penelitian terapan (*applied research*)** dengan
pendekatan **studi kasus tunggal (*single case study*)** pada satu sistem
 Point of Sale (POS) berbasis web untuk usaha restoran, yaitu LakuPOS.

Pendekatan terapan dipilih karena tujuan penelitian bukan mencari kesimpulan
pada umumnya, melainkan menghasilkan perubahan konkret pada sistem yang
dalam keadaan nyata. Setiap keputusan metodologis dalam bab ini diambil
berdasarkan apakah ia dapat diterapkan langsung pada kode yang sedang
berjalan, bukan apakah ia secara metodologis elegance.

Objek kasus yang dipilih adalah aplikasi kasir LakuPOS versi 2.1 yang
sudah digunakan untuk operasional harian.SKor data historis transaksi
yang telah terkumpul menjadi sumber validasi bagi setiap perubahan.

### 3.1.2 Metode Pengumpulan Data

Pengumpulan data dilakukan melalui empat teknik yang saling melengkapi.

**Tabel 3.1 Teknik Pengumpulan Data**

| No | Teknik | Sumber | Dipakai untuk |
|----|--------|--------|---------------|
| 1 | Kode program sebagai data | Repository aplikasi | Memahami perilaku sistem |
| 2 | Basis data operasional | MySQL `mangku_pos` | Menguji kebenaran perhitungan |
| 3 | Pengujian otomatis | 530 test, 2.921 asersi | Membuktikan tidak ada regresi |
| 4 | Verifikasi peramban | Chrome DevTools, 22 halaman | Membuktikan perilaku di layar nyata |

#### 3.1.2.1 Analisis Kode Program

Kode program diperlakukan sebagai sumber data primer, bukan sebagai objek
yang sudah jadi. Setiap klaim tentang perilaku sistem diverifikasi dengan
menelusuri jalur eksekusi sebenarnya, bukan dokumentasi.

Hal ini penting karena pada beberapa kasus, dokumentasi dan kode
tidak konsisten. Contoh konkrit: `config/kafka.php` membaca variabel
`KAFKA_BROKERS`, sementara berkas `.env` mendefinisikan
`KAFKA_BROKER_ADDRESS`. Karena nilai bawaannya kebetulan sama, ketidaksesuaian
ini tidak pernah terasa sampai kecurigaan muncul saat menelusuri konfigurasi.

#### 3.1.2.2 Analisis Basis Data

Basis data operasional menjadi tolok ukur kebenaran. Setiap perubahan pada
perhitungan keuangan selalu diuji terhadap data historis nyata, bukan hanya
terhadap data buatan.

Pengambilan data dilakukan dengan kueri langsung ke MySQL, misalnya untuk
memverifikasi distribusi nilai transaksi, frekuensi kunjungan pelanggan,
dan status konfigurasi layanan pihak ketiga.

#### 3.1.2.3 Pengujian Otomatis

Pengujian otomatis menjadi alat bukti utama. Setiap klaim perbaikan dalam
laporan ini disertai test yang gagal sebelum perbaikan dan lulus sesudahnya.

Kasus yang paling illustrate adalah pencatatan pengiriman WhatsApp.
Pengiriman yang **berhasil** tercatat `failed` karena Fonnte mengembalikan
id pesan dalam bentuk larik:

```json
{"detail": true, "id": ["abc123"], "process": "pending"}
```

Kode lama melakukan konversi `(string)` langsung terhadap nilai tersebut.
PHP melempar warning yang menjadi exception, lalu ditangkap blok `catch`, dan
hasilnya dikembalikan sebagai `failed` — meskipun pesan sudah diterima.
Perbaikan ini harus disertai test yang mereproduksi bentuk balasan asli,
karena test dengan bentuk data yang disederhanakan akan lolos dan
tidak menemukan apa pun.

#### 3.1.2.4 Verifikasi Peramban

Beberapa cacat tidak muncul di test suite maupun di log log karena
hanya terjadi pada waktu render. Untuk kasus tersebut, verifikasi dilakukan
langsung pada peramban.

### 3.1.3 Sumber Penelitian Lainnya

> **[Bagian ini perlu dilengkapi oleh peneliti]**
>
> Bab ini hanya memuat data yang benar-benar terkumpul selama
> pengembangan. Jika penelitian Anda juga mencakup:
>
> - **Studi pustaka** — cantumkan sumber rujukan dan tahun terbit
> - **Wawancara** — jumlah responden, posisi, dan tanggal wawancara
> - **Observasi** — lokasi, durasi, dan apa yang diamati
> - **Survei** — jumlah responden dan cara sampling
>
> maka tambahkan di sini beserta metode analisisnya pada Subbab 3.2.

---

## 3.2 Proses Analisis

### 3.2.1 Teknik Analisis

Analisis dilakukan dengan teknik **analisis kebutuhan berorientasi cacat
(*defect-oriented requirements analysis*)**. Berbeda dengan analisis
kebutuhan fungsional yang membangun sistem dari nol, teknik ini bekerja
dari pertanyaan: *kepada hasil apakah sistem ini gagal, dan bagaimana
mengetahuinya?*

Empat pertanyaan pemandu yang digunakan:

```
1. Apakah angka yang ditampilkan bisa berbeda satu sama lain?
2. Apakah ada kondisi di mana sistem menunjukkan "berhasil" padahal gagal?
3. Apakah ada kondisi di mana sistem menunjukkan "gagal" padahal berhasil?
4. Apakah ada kondisi di mana sistem benar, tetapi tidak bisa dipakai?
```

Pertanyaan keempat harus dibedakan: validitas dan kegunaan adalah
dua hal yang berbeda. Sistem yang hitungannya benar tetapi tidak dapat
dibaca kasir tetap gagal di lapangan.

### 3.2.2 Teknik dan Alat

**Tabel 3.2 Teknik dan Alat Analisis**

| Teknik | Alat | Keluaran |
|--------|------|----------|
| Penelusuran jalur eksekusi | Pencarian statis, `git log` | Peta dependensi |
| Pembuktian klaim | PHPUnit / Laravel Dusk | 530 test |
| Pengukuran tata letak | Chrome DevTools Protocol | Tabel overflow |
| Uji langsung ke pihak ketiga | `Http::fake()`, staging Fonnte | Bentuk balasan |
| Dekomposisi perhitungan | Pembacaan service | Titik divergensi |

### 3.2.3 Hasil Analisis Kebutuhan

Analisis menghasilkan 47 temuan yang dikelompokkan ke dalam empat
kategori berdasarkan dampak.

**Tabel 3.3 Temuan Berdasarkan Dampak**

| Kategori | Jumlah | Contoh |
|----------|--------|--------|
| Kebenaran pembukuan | 9 | HPP dihitung ulang di laporan berbeda |
| Salah label keberhasilan | 7 | Pengiriman sukses tercatat `failed` |
| Tidak dapat dipakai | 12 | Tidak ada navigasi di layar kecil |
| Menahan akar masalah | 6 | Tabel terpotong permanen |

#### 3.2.3.1 Temuan Ketategori Kebenaran Pembukuan

Ditemukan bahwa `AnalyticsService` dan `FinancialReportService` masing-masing
menghitung HPP secara mandiri. Keduanya menghasilkan angka yang tidak selalu
sama, sehingga KPI di analitik dapat berbeda dari Laporan Keuangan.

Ditemukan juga bahwa omzet bersih dihitung dari `grand_total` pada satu
laporan, yang sudah mencakup pajak — sehingga pajak terhitung dua kali.

#### 3.2.3.2 Temuan Ketategori Salah Label Keberhasilan

Dua kasus ditemukan yang saling berlawanan, keduanya berbahaya.

Kasus pertama: pengiriman WhatsApp yang berhasil tercatat `failed` (§3.1.2.3).
Konsekuensinya, operator akan mengira pesan gagal dan mengirim ulang — sehingga
pelanggan menerima pesan dua kali.

Kasus kedua: `normalizeCampaignVoucher` meledak dengan HTTP 500 ketika
`voucher_prefix` tidak dikirimkan, padahal field tersebut `nullable` dan
formulir antarmuka selalu mengirimkannya. Cacat ini tidak pernah terlihat
karena jalur yang tersedia secara manual selalu menadayakannya.

#### 3.2.3.3 Temuan Ketategori Tidak Dapat Dipakai

Ditemukan bahwa pada lebar layar di bawah 768 piksel, aplikasi tidak
memiliki navigasi sama sekali. Sidebar disembunyikan dengan kelas
`hidden md:flex`, dan tombol pembukanya juga `hidden md:flex`. Kedua
keterbatasan tersebut saling menutupi, sehingga neither kondisi dapat
diperbaiki sebagian.

Tiga tabel ditemukan dibungkus `overflow-hidden` yang menggantikan
`overflow-x-auto`, sehingga kolom terakhir terpotong permanen di layar
sempit — tanpa scrollbar untuk dijangkau.

Halaman masuk ditemukan tidak dapat digulir sama sekali karena kombinasi
`min-h-screen` dan `overflow-hidden`.

#### 3.2.3.4 Temuan Ketategori Menahan Masalah

Ditemukan bahwa `CustomerVoucher` tidak memiliki penghitung pemakaian.
Sekali pakai dijamin hanya sebagai efek samping: `consumeVoucher()`
mengatur `status` menjadi `used`, dan pemeriksaan menolak nilai tersebut.
Tidak ada satu pun kolom yang mencatat pemakaian kedua dan seterusnya.

Ditemukan bahwa `used_transaction_id` hanya menyimpan satu transaksi —
transaksi terakhir. Padahal voucher dapat dipakai lebih dari sekali. Akibatnya,
pembatalan transaksi kedua dari pemakaian keempat tidak akan mengembalikan
kuota, dan satu pemakaian hilang permanen.

---

## 3.3 Proses Perancangan

### 3.3.1 Tahap Analisis Kebutuhan

Kebutuhan diturunkan dari temuan, dengan prioritas ditentukan oleh tiga
faktor: besarnya kerugian rupiah, banyaknya pengguna yang terdampak, dan
berapa kali temuannya akan muncul kembali bila dibiarkan.

**Tabel 3.4 Matriks Prioritas**

| Temuan | Kerugian | Pengguna | Frekuensi | Prioritas |
|--------|----------|----------|-----------|-----------|
| HPP berbeda antar laporan | Sangat tinggi | Pemilik | Harian | 1 |
| Pengiriman sukses = gagal | Sedang | Pemasar | Per campaign | 2 |
| Tidak ada navigasi di HP | Rendah | Semua kasir | Setiap pemakaian | 3 |
| Tabel terpotong | Rendah | Manajer | Setiap laporan | 4 |

### 3.3.2 Tahap Perancangan Arsitektur

Perancangan berpusat pada satu prinsip: **menyatu kan sumber kebenaran**.

Sebelum perubahan, sistem memiliki beberapa sumber kebenaran yang
saling meniadakan. Perancangan memusatkan perhitungan keuangan pada satu
layanan, dan menjadikan layanan lain sebagai konsumen, bukan sebagai
penGLISHganti.

```
Sebelum:  FinancialReportService -> Laporan Keuangan
          AnalyticsService       -> Analitik   (mandiri)

Sesudah: FinancialReportService -> Laporan Keuangan
          AnalyticsService       -> Analitik   (konsumen)
```

Prinsip yang sama diterapkan pada pembayaran: satu pusat penyelesaian
menjadi satu-satunya tempat yang berwenang mengubah status pembayaran.

### 3.3.3 Tahap Perancangan Antarmuka

Perancangan antarmuka mengikuti dua keputusan yang diambil bersama
pemilik sistem:

**Tabel 3.5 Keputusan Desain Antarmuka**

| Keputusan | Pilihan | Alasan |
|------------|---------|--------|
| Navigasi layar sempit | Tombol hamburger (drawer) | Layar penuh untuk konten; target sentuh lebih besar |
| Tabel lebar | Gulir horizontal | Data tidak disembunyikan; menggulir vertikal lebih lambat |
| Judul halaman | Ditampilkan di layar sempit | Konten jadi satu-satunya penanda posisi |

### 3.3.4 Tahap Perancangan Basis Data

Perancangan skema_changeswere Forecastkai
menghindari pemindahan data. Setiap kolom baru memiliki nilai bawaan
yang mempertahankan perilaku lama.

**Tabel 3.6 Perubahan Skema**

| Tabel | Kolom | Bawaan | Alasan |
|-------|-------|--------|--------|
| `settings` | `kitchen_display_enabled_at` | NULL | Instalasi lama tidak dibatasi — menyembunyikan order secara diam-diam lebih buruk daripada layar berantakan |
| `settings` | `kitchen_display_enabled_at` | — | Dicatat saat KDS dinyalakan |
| `customer_vouchers` | `usage_limit` | 1 | Mempertahankan perilaku sekali pakai |
| `customer_vouchers` | `used_count` | 0 | Dihitung ulang dari `status` |
| `crm_campaigns` | `voucher_usage_limit` | 1 | Idem |

Perhitungan ulang `used_count` untuk voucher yang sudah pernah terpakai
merupakan langkah wajib. Tanpa itu, kuota satu kali pada voucher lama
akan terlihat belum habis dan kode yang sudah pernah dipakai akan
HidupBaselineks kembali.

### 3.3.5 Perancangan Pengujian

Setiap perbaikan dirancang agar gagal sebelum dan lulus sesudahnya.
Tiga bentuk pengujian dirancang khusus untuk kelas kesalahan yang ditemukan:

**Tabel 3.7 Bentuk Pengujian yang Dirancang**

| Kesalahan | Bentuk Pengujian |
|-----------|------------------|
| Divergensi perhitungan | Kedua laporan diuji dari sumber yang sama |
| Salah label | Data masukan berbentuk balasan asli, bukan disederhanakan |
| Salah pakai | Pengujian pada lebar layar nyata melalui peramban |

---

## 3.4 Implementasi

### 3.4.1 Langkah Implementasi

Implementasi dilaksanakan dalam lima tahap berurutan.

**Tahap 1 — Pemulihan kebenaran pembukuan**

```
Tujuan     menyatukan perhitungan HPP dan omzet
Berkas     FinancialReportService, AnalyticsService
Pengujian  530 test, semua laporan diuji dari sumber yang sama
```

Perubahan pertama yang dilakukan adalah menghapus perhitungan HPP dari
`AnalyticsService` dan menggantinya dengan pemanggilan
`FinancialReportService::summary()`. Perubahan ini perlu diuji dasar
sebelum perubahan lain, karena seluruh perubahan berikutnya bergantung
padanya.

**Tahap 2 — Perbaikan pencatatan hasil**

```
Tujuan     hanya label "berhasil" yang benar
Berkas     CrmMessageSender, CrmVoucherService, LoyaltyService
Pengujian  8 test pada bentuk balasan Fonnte, 10 test batas pemakaian
```

Pada tahap ini ditemukan dua bug yang saling menutupi di
`CrmMessageSender`: id larik yang membuat pengiriman sukses tercatat
gagal, dan `detail` yang tidak diperiksa padahal Fonnte membalas
200 OK saat menolak.

**Tahap 3 — Kesesuaian antarmuka**

```
Tujuan     aplikasi dapat dipakai di semua ukuran layar
Berkas     8 komponen dan 14 halaman
Pengujian  22 halaman pada lebar 375, 768, 1024, dan 1280 piksel
```

Pengujian antarmuka dilakukan dengan menyisipkan halaman aplikasi ke dalam
`<iframe>` berukuran 375 piksel. Cara ini dipilih karena aturan media CSS
di dalam iframe dievaluasi terhadap ukuran iframe tersebut, sehingga
perilaku layar sempit dapat diuji tanpa mengubah ukuran jendela peramban.

**Tahap 4 — Perbaikan konfigurasi dan definisi**

```
Tujuan     menutup celah yang hanya muncul di luar pengujian
Berkas     config/services.php, config/kafka.php, bootstrap/app.php
Pengujian  pemeriksaan statis pada setiap nama variabel konfigurasi
```

Tahap iniidora discovering bug namespace PSR-4 pada
`PaymentCallbackController`, yang membuat kelas dilewati oleh autoloader.

**Tahap 5 — Dokumentasi arsitektur**

```
Tujuan     menjaga alasan di balik struktur
Berkas     docs/ARSITEKTUR.md
Pengujian  pemeriksaan sintaks Mermaid dan konsistensi kode
```

### 3.4.2 Perangkat Lunak yang Digunakan

**Tabel 3.8 Perangkat Lunak yang Digunakan**

| Perangkat | Versi | Guna |
|-----------|-------|------|
| PHP | 8.2.12 | Runtime dan perkakas pengujian |
| Laravel | 12.38.1 | Kerangka kerja aplikasi |
| Composer | 2.9.2 | Pengelolaan dependensi |
| Node.js | v22.14.0 | Lingkungan bangun antarmuka |
| npm | 10.9.2 | Pengelolaan dependensi front-end |
| Vite | — | Bundler dan PWA |
| MySQL | — | Basis data operasional |
| PHPUnit | — | Pengujian otomatis |
| Chrome DevTools | — | Verifikasi peramban |

### 3.4.3 Tantangan Implementasi

**Tabel 3.9 Tantangan dan Penyelesaiannya**

| Tantangan | Penyelesaian |
|-----------|--------------|
| Build sukses meski kode rusak | Verifikasi peramban tidak pernah dilewati |
| Error menumpuk di konsol antar build | Timestamp error dibandingkan dengan waktu klik |
| Nilai transisi belum selesai saat dibaca | Transisi dimatikan saat pengukuran |
|.some() kesalahan unicode | Pemeriksaan karakter asing pada setiap penyuntingan terotomatisasi |
| Test lama bergantung pada `.env` mesin | Konfigurasi di reset eksplisit dalam test |

---

## 3.5 Pengujian dan Evaluasi

### 3.5.1 Strategi Pengujian

Pengujian memakai empat tingkat, dari yang paling cepat ke yang paling
mahal.

**Tabel 3.10 Tingkat Pengujian**

| Tingkat | Cakupan | Jumlah | Durasi |
|---------|---------|--------|--------|
| Sintaks | PHP dan JSX | 26 berkas | < 1 menit |
| Unit dan fitur | 530 test, 2.921 asersi | — | 91 detik |
| Peramban | 22 halaman × 4 lebar layar | 88 pemeriksaan | ~25 menit |
| Integrasi | Bentuk balasan Fonnte | 8 test | < 1 menit |

### 3.5.2 Hasil Pengujian Fungsional

**Tabel 3.11 Hasil Pengujian**

| Indikator | Sebelum | Setelah |
|-----------|---------|---------|
| Jumlah test | 462 | 530 |
| Jumlah asersi | 2.770 | 2.921 |
| Test gagal | 0 | 0 |
| Waktu pengujian | 144 detik | 91 detik |

Penambahan test tanpa penurunan kecepatan terjadi karena sebagian besar
test baru menggunakan basis data dalam memori, bukan transaksi basis data
nyata.

### 3.5.3 Hasil Pengujian Antarmuka

Pengujian tata letak dilakukan pada empat lebar layar yang mewakili
keadaan nyata: ponsel (375 piksel), tablet (768 piksel), laptop (1024 piksel),
dan monitor (1280 piksel).

**Tabel 3.12 Hasil Pengujian Tata Letak**

| Lebar | Halaman diuji | Gulir horizontal | Catatan |
|-------|---------------|-------------------|---------|
| 375 | 22 | 0 | Drawer berfungsi |
| 768 | 4 | 0 | Sidebar sebagai kolom |
| 1024 | 4 | 0 | — |
| 1280 | 4 | 0 | Sisi dapat dilipat 80/260 px |

### 3.5.4 Hasil Evaluasi terhadap Temuan Awal

**Tabel 3.13 Efektivitas Perbaikan**

| Temuan | Status | Bukti |
|--------|--------|--------|
| HPP berbeda antar laporan | Terselesaikan | Test membandingkan kedua laporan dari sumber sama |
| Pengiriman sukses tercatat gagal | Terselesaikan | 8 test bentuk balasan asli |
| Navigasi hilang di HP | Terselesaikan | Drawer terverifikasi pada 5 jalurinterop |
| Tabel terpotong permanen | Terselesaikan | 0 gulir tak disengaja |
| Halaman masuk tidak dapat digulir | Terselesaikan | Verifikasi peramban |
| Menu aktif tidak pernah menyala | Terselesaikan | 32 tautan terverifikasi |
| Normalisasi nomor telepon | Terselesaikan | 8 bentuk penulisan tested |
| Batas pemakaian voucher | Terselesaikan | 10 test, termasuk pembatalan bukan pemakaian terakhir |
| Placeholder pesan | Terselesaikan | 14 test |
| HTTP 500 pada voucher tanpa prefiks | Terselesaikan | Test regresi |

### 3.5.5 Keterbatasan Penelitian

Penelitian ini memiliki keterbatasan yang perlu dinyatakan terbuka.

**Pertama**, data operasional yang dianalisis adalah data contoh, bukan data
transaksi riil. Nilai transaksinya realistis, tetapi data pelanggan seluruhnya
tidak nyata. Karena itu, rekomendasi parameter bisnis — misalnya batas
pemakaian voucher — dibuat berdasarkan perhitungan margin, bukan berdasarkan
perilaku pelanggan yang terukur.

**Kedua**, pengujian dilakukan pada satu lingkungan (Windows dengan XAMPP,
MySQL jaringan lokal). Perilaku pada lingkungan produksi dengan reverse proxy,
HTTPS, dan konkurensi tatsudit versed gains yang berbeda belum teruji.

**Ketiga**, integrasi layanan pihak ketiga diuji dengan simulasi (`Http::fake`)
kecuali satu kasus yang diuji langsung terhadap API Fonnte dengan
permintaan baca saja. Pengujian pengiriman nyata belum dilakukan.

**Keempat**, beberapa temuan masih terbuka dan tercatat pada
Subbab 3.6.

### 3.5.6 Permasalahan yang Masih Terbuka

**Tabel 3.14 Permasalahan yang Belum Terselesaikan**

| Permasalahan | Dampak | Penyebab |
|--------------|--------|----------|
| Order dari menu QR tidak mencapai Kafka | Data transaksi tidak lengkap di aliran real-time | `customerOrder()` hanya memicu notifikasi Pusher |
| Nama variabel Kafka tidak cocok | Perubahan broker diabaikan | `.env` berbeda satu kata dari `config/kafka.php` |
| `trustProxies` belum ada | Deteksi HTTPS mungkin gagal di belakang proxy | Belum diimplementasikan |
| Email masih ke berkas log | Kampanye email tidak terkirim | Kredensial SMTP belum diisi |
| Laporan keabuan data | Angka belum terverifikasi | Data operasional masih data contoh |

---

## 3.6 Ringkasan Metodologis

Metodologi yang digunakan bersifat **iteratif dan berbasis bukti**. Setiap
temuan diverifikasi terhadap sistem yang berjalan sebelum diperbaiki, dan
setiap perbaikan disertai pengujian yang gagal sebelum dan lulus sesudahnya.

Keunikan pendekatan ini terletak pada pengakuan bahwa **build yang sukses
bukan bukti bahwa kode benar**. Beberapa cacat yang ditemukan hanya muncul
pada saat render di peramban, bukan di log dan tidak di test suite. Karena
itu, verifikasi peramban dipertahankan sebagai tahap yang tidak dapat
dilewati, bukan sebagai pelengkap.

Keterbatasan terbesar penelitian ini adalah ketiadaan data operasional
riil. Karena itu, parameter bisnis yang direkomendasikan dalam dokumen ini
perlu divalidasi ulang setelah sistem berjalan dengan data sebenarnya.

---

> **Catatan untuk peneliti**
>
> Lampiran dokumen ini dapat dilengkapi dengan:
>
> - Lampiran A — Daftar 47 temuan lengkap beserta lokasi kode
> - Lampiran B — Daftar 530 test
> - Lampiran C — Tabel pengukuran tata letak per halaman
> - Lampiran D — Dokumentasi pengujian manual
>
> Data tersebut tersedia pada repositori dan dapat disusun ulang sesuai
> format yang diminta institution.
