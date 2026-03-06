import EscPosEncoder from 'esc-pos-encoder';

// --- KONFIGURASI ---
const C_WIDTH = 32; 
const SERVICE_UUID = '000018f0-0000-1000-8000-00805f9b34fb';
const CHAR_UUID = '00002af1-0000-1000-8000-00805f9b34fb';

// --- HELPER FORMATTING ---
const clean = (text) => {
    if (!text) return "";
    return text.toString()
        .normalize("NFD").replace(/[\u0300-\u036f]/g, "") // Hilangkan aksen
        .replace(/[^\x20-\x7E]/g, "") // Hanya ASCII standar
        .trim();
};

const formatRow = (left, right) => {
    const lStr = clean(left);
    const rStr = clean(right);
    const spaceCount = C_WIDTH - (lStr.length + rStr.length);
    return lStr + " ".repeat(spaceCount > 0 ? spaceCount : 1) + rStr;
};

const formatDate = (dateStr) => {
    const d = new Date(dateStr);
    if (isNaN(d.getTime())) return "00-00-0000 00:00";
    return `${String(d.getDate()).padStart(2, '0')}-${String(d.getMonth() + 1).padStart(2, '0')}-${d.getFullYear()} ${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
};

// --- JALUR 1: NATIVE APK (Bluetooth Serial) DENGAN LOGIKA CHUNKING ---
const sendToNativeInChunks = (data) => {
    return new Promise((resolve, reject) => {
        const btSerial = window.bluetoothSerial;
        const savedPrinter = JSON.parse(localStorage.getItem("selected_printer"));

        if (!btSerial) return reject("Plugin Bluetooth tidak ditemukan.");
        if (!savedPrinter?.address) return reject("Printer belum dipilih di menu Pairing.");

        const connectionTimeout = setTimeout(() => reject("Koneksi ke printer timeout."), 10000);

        btSerial.connect(savedPrinter.address, async () => {
            clearTimeout(connectionTimeout);
            try {
                // CHUNKING: Kirim data dalam potongan kecil 128 byte agar printer tidak macet
                const chunkSize = 128; 
                for (let i = 0; i < data.length; i += chunkSize) {
                    const chunk = data.slice(i, i + chunkSize);
                    await new Promise((res, rej) => {
                        btSerial.write(chunk, res, rej);
                    });
                    // Jeda 35ms untuk memberi waktu hardware printer memproses data
                    await new Promise(res => setTimeout(res, 35));
                }
                
                setTimeout(() => {
                    btSerial.disconnect();
                    resolve({ success: true });
                }, 1000);

            } catch (err) {
                btSerial.disconnect();
                reject("Gagal kirim data: " + err);
            }
        }, (err) => {
            clearTimeout(connectionTimeout);
            reject("Gagal terhubung ke printer: " + err);
        });
    });
};

// --- JALUR 2: BROWSER (Web Bluetooth API) ---
const sendToWeb = async (data) => {
    const navBT = typeof navigator !== 'undefined' ? navigator.bluetooth : null;
    if (!navBT) throw new Error("Bluetooth tidak didukung di browser ini.");

    const device = await navBT.requestDevice({
        filters: [{ services: [SERVICE_UUID] }],
        optionalServices: [SERVICE_UUID]
    });
    const server = await device.gatt.connect();
    const service = await server.getPrimaryService(SERVICE_UUID);
    const characteristic = await service.getCharacteristic(CHAR_UUID);

    const chunkSize = 512;
    for (let i = 0; i < data.length; i += chunkSize) {
        await characteristic.writeValue(data.slice(i, i + chunkSize));
    }
};

// --- ENCODER STRUK TRANSAKSI (SUPPORTS REVIEW BILL) ---
const encodeReceipt = (transaction, receiptSetting) => {
    const encoder = new EscPosEncoder();
    let result = encoder.initialize().codepage('windows1252'); 
    
    const details = Array.isArray(transaction.details) ? transaction.details : [];
    const isBill = !!transaction.is_bill; // Cek apakah ini mode review bill
    
    // --- HELPER LABEL METODE PEMBAYARAN ---
    const getPaymentLabel = (method) => {
        if (isBill) return "BELUM BAYAR";
        const m = method?.toLowerCase();
        if (m === 'cash') return "TUNAI";
        if (m === 'midtrans' || m === 'xendit') return "QRIS AUTO";
        if (m === 'qris_manual') return "QRIS STATIS";
        if (m === 'transfer') return "TRANSFER";
        return (method || "CASH").toUpperCase();
    };

    // 1. Logika Nomor Antrean
    const getQueue = () => {
        if (transaction.queue_number) return transaction.queue_number;
        const match = transaction.customer_name?.match(/Q-\d+/);
        return match ? match[0] : "----";
    };

    const queueNum = getQueue();

    // 2. Logika Kode Pesanan
    const displayCode = transaction.reference_code 
        ? transaction.reference_code.toString().replace("#", "") 
        : (transaction.invoice ? transaction.invoice.slice(-4) : "0000");

    const grandTotal = parseFloat(transaction.grand_total || 0);
    const totalDiscount = parseFloat(transaction.discount || 0);
    const subtotalGross = grandTotal + totalDiscount;

    // Header
    result.raw([0x1b, 0x61, 0x01]) // Center
          .bold(true).line(clean(isBill ? "--- BILL (DRAF) ---" : (receiptSetting?.store_name || "TOKO POS"))).bold(false)
          .line(clean(receiptSetting?.store_address || ""))
          .line("TELP: " + clean(receiptSetting?.store_phone || "000"))
          .line("-".repeat(C_WIDTH))
          .size('large').bold(true).line(queueNum).size('normal').bold(false)
          .line("-".repeat(C_WIDTH));

    // Metadata
    result.raw([0x1b, 0x61, 0x00]) // Left
          .line(formatRow("Order:", "#" + displayCode))
          .line(formatRow("No. Trx:", clean(transaction.invoice)))
          .line(formatRow("Tgl:", formatDate(transaction.created_at || new Date())))
          .line(formatRow("Plg:", clean(transaction.customer_name || "UMUM").toUpperCase().substring(0, 18)))
          .line(formatRow("Kasir:", clean(transaction.cashier?.name || "KASIR").split(' ')[0].toUpperCase()));
    
    if (transaction.online_platform) {
        result.line(formatRow("Platform:", clean(transaction.online_platform).toUpperCase()));
    }

    result.line("-".repeat(C_WIDTH));

    // List Item
    details.forEach(item => {
        const title = clean(item.product?.title || item.product_title || "PRODUK").toUpperCase();
        const price = parseFloat(item.price || 0);
        const qty = parseFloat(item.qty || 1);
        result.bold(true).line(title).bold(false);
        result.line(formatRow(`${qty.toFixed(0)} x ${Math.round(price/qty).toLocaleString('id-ID')}`, Math.round(price).toLocaleString('id-ID')));
        if (item.notes && !item.notes.includes('BONUS PROMO')) {
            result.italic(true).line("*" + clean(item.notes).toLowerCase()).italic(false);
        }
    });

    result.line("-".repeat(C_WIDTH));
    
    // Rincian Pembayaran & Diskon
    if (totalDiscount > 0) {
        result.line(formatRow("SUBTOTAL", Math.round(subtotalGross).toLocaleString('id-ID')));
        result.italic(true).line(formatRow("DISKON TOTAL", "-" + Math.round(totalDiscount).toLocaleString('id-ID'))).italic(false);
    }

    result.bold(true).line(formatRow("TOTAL", `Rp ${Math.round(grandTotal).toLocaleString('id-ID')}`)).bold(false);
    
    // Sembunyikan Detail Bayar jika ini hanya Bill
    if (!isBill) {
        result.line(formatRow("BAYAR", Math.round(transaction.cash || grandTotal).toLocaleString('id-ID')))
              .line(formatRow("KEMBALI", Math.round((transaction.cash || grandTotal) - grandTotal).toLocaleString('id-ID')));
    }

    result.line(formatRow("METODE", getPaymentLabel(transaction.payment_method)))
          .line("-".repeat(C_WIDTH));

    // QR Code (Hanya untuk Transaksi Lunas dengan Payment URL)
    if (transaction.payment_url && !isBill) {
        result.raw([0x1b, 0x61, 0x01])
              .newline()
              .line("SCAN UNTUK BAYAR")
              .newline()
              .qrcode(transaction.payment_url, 1, 6, 'm') 
              .newline()
              .line("GOPAY/QRIS/M-BANKING")
              .newline();
    }

    // Footer
    result.raw([0x1b, 0x61, 0x01])
          .line(clean(isBill ? "* PESANAN BELUM DIBAYAR *" : (receiptSetting?.store_footer || "Terima Kasih")))
          .newline().newline().newline().newline(); 
    
    return result.encode();
};

// --- ENCODER LAPORAN SHIFT ---
const encodeShiftReport = (shift, receiptSetting) => {
    const encoder = new EscPosEncoder();
    let result = encoder.initialize().codepage('windows1252');
    const fPrice = (p) => Math.round(parseFloat(p || 0)).toLocaleString('id-ID');

    const cashSales = parseFloat(shift.total_cash_sales || 0);
    const pettyCash = parseFloat(shift.total_expense || shift.petty_cash_out || 0);
    const startCash = parseFloat(shift.starting_cash || 0);
    
    const systemSaldo = (startCash + cashSales) - pettyCash;

    result.raw([0x1b, 0x61, 0x01]).bold(true).line(clean(receiptSetting?.store_name || "TOKO POS")).bold(false)
          .line("LAPORAN TUTUP SHIFT").line("-".repeat(C_WIDTH));

    result.raw([0x1b, 0x61, 0x00]) // Left
          .line(formatRow("KASIR:", clean(shift.user?.name || "KASIR").toUpperCase()))
          .line(formatRow("MULAI:", formatDate(shift.opened_at)))
          .line(formatRow("TUTUP:", formatDate(shift.closed_at)))
          .line("-".repeat(C_WIDTH));

    result.line(formatRow("MODAL AWAL", fPrice(startCash)))
          .line(formatRow("SALES TUNAI", fPrice(cashSales)))
          .line(formatRow("KAS KELUAR", "-" + fPrice(pettyCash)))
          .line(".".repeat(C_WIDTH))
          .line(formatRow("SISTEM LACI", fPrice(systemSaldo)))
          .bold(true).line(formatRow("FISIK LACI", fPrice(shift.total_cash_actual || shift.total_physical_cash))).bold(false)
          .line("-".repeat(C_WIDTH))
          .bold(true).line(formatRow("SELISIH", fPrice(shift.difference))).bold(false);

    result.newline()
          .line("INFO PENDAPATAN DIGITAL:")
          .line(formatRow("QRIS (AUTO/STATIS)", fPrice(shift.total_qris_sales)))
          .line(formatRow("TRANSFER BANK", fPrice(shift.total_transfer_sales || 0)))
          .line("-".repeat(C_WIDTH));
          
    if (parseFloat(shift.total_discounts) > 0) {
        result.line(formatRow("TOTAL DISKON", fPrice(shift.total_discounts)));
    }

    result.raw([0x1b, 0x61, 0x01]) // Center
          .newline().line("TANDA TANGAN").newline().newline()
          .line("(...........)")
          .line("WAKTU CETAK:").line(formatDate(new Date()))
          .newline().newline().newline().newline().newline(); 
    
    return result.encode();
};

// --- EXPORT FUNGSI UTAMA ---
export const smartPrint = async (data, receiptSetting, type = 'transaction') => {
    try {
        const isNative = typeof window !== 'undefined' && !!window.bluetoothSerial;
        const encodedData = type === 'shift' ? encodeShiftReport(data, receiptSetting) : encodeReceipt(data, receiptSetting);

        if (isNative) {
            return await sendToNativeInChunks(encodedData);
        } else {
            return await sendToWeb(encodedData);
        }
    } catch (err) {
        throw err;
    }
};