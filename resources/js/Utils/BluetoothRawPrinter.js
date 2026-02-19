import EscPosEncoder from 'esc-pos-encoder';

// --- KONFIGURASI ---
const C_WIDTH = 32; 

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
    const d = dateStr ? new Date(dateStr) : new Date();
    if (isNaN(d.getTime())) return "00-00-0000 00:00";
    return `${String(d.getDate()).padStart(2, '0')}-${String(d.getMonth() + 1).padStart(2, '0')}-${d.getFullYear()} ${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
};

// --- LOGIKA KONEKSI NATIVE (APK) DENGAN CHUNKING ---
const sendToNativeBluetooth = (data) => {
    return new Promise((resolve, reject) => {
        const btSerial = window.bluetoothSerial;
        const savedPrinter = JSON.parse(localStorage.getItem("selected_printer"));

        if (!btSerial) return reject("Plugin Bluetooth Serial tidak terdeteksi.");
        if (!savedPrinter?.address) return reject("Printer belum dipilih di menu Pairing.");

        const connectionTimeout = setTimeout(() => reject("Koneksi ke printer timeout."), 10000);

        btSerial.connect(savedPrinter.address, async () => {
            clearTimeout(connectionTimeout);
            try {
                // CHUNKING: Kirim 128 byte per potongan agar printer tidak "hang"
                const chunkSize = 128;
                for (let i = 0; i < data.length; i += chunkSize) {
                    const chunk = data.slice(i, i + chunkSize);
                    await new Promise((res, rej) => {
                        btSerial.write(chunk, res, rej);
                    });
                    await new Promise(res => setTimeout(res, 35)); // Jeda antar potongan
                }
                
                // Jeda sebelum disconnect agar buffer selesai
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
            reject("Printer tidak terdeteksi: " + err);
        });
    });
};

// --- LOGIKA KONEKSI WEB (BROWSER) ---
const sendToWebBluetooth = async (data) => {
    const SERVICE_UUID = '000018f0-0000-1000-8000-00805f9b34fb';
    const CHAR_UUID = '00002af1-0000-1000-8000-00805f9b34fb';
    
    if (typeof navigator === 'undefined' || !navigator.bluetooth) {
        throw new Error("Browser ini tidak mendukung Web Bluetooth.");
    }

    const device = await navigator.bluetooth.requestDevice({
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

// --- SWITCHER ---
const sendToBluetooth = async (data) => {
    if (typeof window !== 'undefined' && window.bluetoothSerial) {
        return await sendToNativeBluetooth(data);
    } else {
        return await sendToWebBluetooth(data);
    }
};

// --- FUNGSI UTAMA: PRINT TRANSAKSI ---
export const printTransactionBluetooth = async (transaction, receiptSetting) => {
    try {
        const encoder = new EscPosEncoder();
        let result = encoder.initialize().codepage('windows1252');
        const details = Array.isArray(transaction.details) ? transaction.details : [];

        const queueNum = transaction.queue_number || "----";
        const orderCode = (transaction.invoice || "").toString().replace(/[^0-9]/g, '').slice(-4).padStart(4, '0');
        
        const grandTotal = parseFloat(transaction.grand_total || 0);
        const discount = parseFloat(transaction.discount || 0);
        const subtotalGross = grandTotal + discount;

        // HEADER
        result.raw([0x1b, 0x61, 0x01]) // Center
              .bold(true).line(clean(receiptSetting?.store_name || "TOKO POS")).bold(false)
              .line(clean(receiptSetting?.store_address || ""))
              .line("-".repeat(C_WIDTH))
              .size('large').bold(true).line(queueNum).size('normal').bold(false)
              .line("-".repeat(C_WIDTH));

        // METADATA
        result.raw([0x1b, 0x61, 0x00]) // Left
              .line(formatRow("No. Trx:", clean(transaction.invoice)))
              .line(formatRow("Kode Pesan:", "#" + orderCode));

        // PLATFORM ONLINE
        if (transaction.online_platform) {
            result.line(formatRow("Platform:", clean(transaction.online_platform).toUpperCase()));
        }

        result.line(formatRow("Plg:", clean(transaction.customer_name || "UMUM").toUpperCase().substring(0, 18)))
              .line(formatRow("Tgl:", formatDate(transaction.created_at)))
              .line(formatRow("Kasir:", clean(transaction.cashier?.name || "KASIR").split(' ')[0].toUpperCase()))
              .line("-".repeat(C_WIDTH));

        // ITEMS
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

        // TOTALS
        result.line("-".repeat(C_WIDTH));
        
        if (discount > 0) {
            result.line(formatRow("SUBTOTAL", Math.round(subtotalGross).toLocaleString('id-ID')));
            result.line(formatRow("DISKON", "-" + Math.round(discount).toLocaleString('id-ID')));
        }

        result.bold(true).line(formatRow("TOTAL AKHIR", `Rp ${Math.round(grandTotal).toLocaleString('id-ID')}`)).bold(false)
              .line(formatRow("BAYAR", Math.round(transaction.cash || grandTotal).toLocaleString('id-ID')))
              .line(formatRow("KEMBALI", Math.round((transaction.cash || grandTotal) - grandTotal).toLocaleString('id-ID')))
              .line(formatRow("METODE", clean(transaction.payment_method || 'CASH').toUpperCase()))
              .line("-".repeat(C_WIDTH));

        // --- FITUR BARU: QRIS DYNAMIC QRCODE ---
        if (transaction.payment_url) {
            result.raw([0x1b, 0x61, 0x01]) // Center
                  .newline()
                  .line("SCAN UNTUK BAYAR")
                  .newline()
                  .qrcode(transaction.payment_url, 1, 6, 'm') // Model 1, Size 6, EC level M
                  .newline()
                  .line("GOPAY/QRIS/M-BANKING")
                  .newline();
        }

        // FOOTER
        result.raw([0x1b, 0x61, 0x01]) // Center
              .line(clean(receiptSetting?.store_footer || "Terima Kasih"))
              .newline().newline().newline().newline();

        await sendToBluetooth(result.encode());
        return { success: true };
    } catch (error) { throw error; }
};

// --- FUNGSI LAPORAN SHIFT ---
export const printShiftBluetooth = async (shift, receiptSetting) => {
    try {
        const encoder = new EscPosEncoder();
        let result = encoder.initialize().codepage('windows1252');
        const fPrice = (p) => Math.round(parseFloat(p || 0)).toLocaleString('id-ID');

        const cashSales = parseFloat(shift.total_cash_sales || 0);
        const pettyCash = parseFloat(shift.petty_cash_out || shift.total_expense || 0);
        const startCash = parseFloat(shift.starting_cash || 0);
        const systemSaldo = (startCash + cashSales) - pettyCash;

        result.raw([0x1b, 0x61, 0x01]).bold(true).line(clean(receiptSetting?.store_name || "TOKO POS")).bold(false)
              .line("LAPORAN TUTUP SHIFT")
              .line("-".repeat(C_WIDTH));

        result.raw([0x1b, 0x61, 0x00]) // Left
              .line(formatRow("KASIR:", clean(shift.user?.name || "KASIR").toUpperCase()))
              .line(formatRow("MULAI:", formatDate(shift.opened_at)))
              .line(formatRow("TUTUP:", formatDate(shift.closed_at)))
              .line("-".repeat(C_WIDTH));

        result.line(formatRow("MODAL AWAL", fPrice(startCash)))
              .line(formatRow("SALES TUNAI", fPrice(cashSales)))
              .line(formatRow("KAS KELUAR", "-" + fPrice(pettyCash)))
              .line(".".repeat(C_WIDTH))
              .line(formatRow("TOTAL SISTEM", fPrice(systemSaldo)))
              .bold(true).line(formatRow("FISIK LACI", fPrice(shift.total_cash_actual || shift.total_physical_cash))).bold(false)
              .line("-".repeat(C_WIDTH))
              .bold(true).line(formatRow("SELISIH", fPrice(shift.difference))).bold(false)
              .line(formatRow("TOTAL QRIS", fPrice(shift.total_qris_sales)));
              
        if (parseFloat(shift.total_discounts) > 0) {
            result.line(formatRow("TOT. DISKON", fPrice(shift.total_discounts)));
        }

        result.line("-".repeat(C_WIDTH));

        result.raw([0x1b, 0x61, 0x01]) // Center
              .line("TANDA TANGAN").newline().newline()
              .line("(...........)")
              .line("WAKTU CETAK:").line(formatDate(new Date()))
              .newline().newline().newline().newline().newline();

        await sendToBluetooth(result.encode());
        return { success: true };
    } catch (error) { throw error; }
};

export const printBluetooth = printTransactionBluetooth;