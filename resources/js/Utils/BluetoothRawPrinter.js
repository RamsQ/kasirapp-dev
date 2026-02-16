import EscPosEncoder from 'esc-pos-encoder';

// --- KONFIGURASI BLUETOOTH & PRESISI ---
const C_WIDTH = 32; 
const SERVICE_UUID = '000018f0-0000-1000-8000-00805f9b34fb';
const CHAR_UUID = '00002af1-0000-1000-8000-00805f9b34fb';

// --- HELPER STRUKTUR ---
const clean = (text) => text ? text.toString().trim() : "";

const formatRow = (left, right) => {
    const lStr = clean(left);
    const rStr = clean(right);
    const spaceCount = C_WIDTH - (lStr.length + rStr.length);
    // Memastikan minimal ada 1 spasi jika teks terlalu panjang
    return lStr + " ".repeat(spaceCount > 0 ? spaceCount : 1) + rStr;
};

const formatDate = (dateStr) => {
    const d = new Date(dateStr);
    if (isNaN(d.getTime())) return "00-00-0000 00:00";
    return `${String(d.getDate()).padStart(2, '0')}-${String(d.getMonth() + 1).padStart(2, '0')}-${d.getFullYear()} ${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
};

// --- LOGIKA KONEKSI ---
let cachedDevice = null;

const getBluetoothDevice = async () => {
    if (cachedDevice && cachedDevice.gatt.connected) return cachedDevice;
    const device = await navigator.bluetooth.requestDevice({
        filters: [{ services: [SERVICE_UUID] }],
        optionalServices: [SERVICE_UUID]
    });
    cachedDevice = device;
    device.addEventListener('gattserverdisconnected', () => { cachedDevice = null; });
    return device;
};

const sendToBluetooth = async (data) => {
    const device = await getBluetoothDevice();
    const server = await device.gatt.connect();
    const service = await server.getPrimaryService(SERVICE_UUID);
    const characteristic = await service.getCharacteristic(CHAR_UUID);

    const chunkSize = 512;
    for (let i = 0; i < data.length; i += chunkSize) {
        const chunk = data.slice(i, i + chunkSize);
        await characteristic.writeValue(chunk);
    }
};

// --- FUNGSI UTAMA: PRINT TRANSAKSI ---
export const printTransactionBluetooth = async (transaction, receiptSetting) => {
    try {
        const encoder = new EscPosEncoder();
        let result = encoder.initialize().codepage('windows1252');
        const details = Array.isArray(transaction.details) ? transaction.details : [];

        // 1. LOGIKA EKSTRAKSI NOMOR ANTREAN (QUEUE)
        const getQueue = () => {
            if (transaction.queue_number) return transaction.queue_number;
            if (details[0]?.queue_number) return details[0].queue_number;
            const match = transaction.customer_name?.match(/Q-\d+/);
            return match ? match[0] : "----";
        };

        // 2. LOGIKA EKSTRAKSI KODE ORDER (4 DIGIT ANGKA UNIK)
        const getOrderCode = () => {
            let val = "";
            if (transaction.reference_code) val = transaction.reference_code.toString().replace(/[^0-9]/g, '');
            if (!val && transaction.customer_name?.includes('#')) {
                val = transaction.customer_name.split('#').pop().replace(/[^0-9]/g, '');
            }
            if (!val) {
                val = (transaction.invoice || "").toString().replace(/[^0-9]/g, '');
            }
            return val.slice(-4).padStart(4, '0') || "0000";
        };

        const queueNum = getQueue();
        const orderCode = getOrderCode();

        // --- START ENCODING ---
        // HEADER (CENTER)
        result.raw([0x1b, 0x61, 0x01])
              .bold(true).line(clean(receiptSetting?.store_name || "TOKO POS")).bold(false)
              .line(clean(receiptSetting?.store_address || ""))
              .line("TELP: " + clean(receiptSetting?.store_phone || "000"))
              .line("-".repeat(C_WIDTH));

        // ANTREAN BESAR
        result.size('large').bold(true).line(queueNum).size('normal').bold(false)
              .line("-".repeat(C_WIDTH));

        // METADATA (LEFT)
        result.raw([0x1b, 0x61, 0x00])
              .line(formatRow("Invoice:", clean(transaction.invoice)))
              .line(formatRow("Plg:", clean(transaction.customer_name || "UMUM").toUpperCase().substring(0, 18)))
              .line(formatRow("order:", "#" + orderCode))
              .line(formatRow("Tgl:", formatDate(transaction.created_at)))
              .line(formatRow("Meja:", clean(transaction.table_name || "TAKE AWAY").toUpperCase()))
              .line(formatRow("Kasir:", clean(transaction.cashier?.name || "KASIR").split(' ')[0].toUpperCase()))
              .line("-".repeat(C_WIDTH));

        // ITEMS
        let calculatedSubtotal = 0;
        details.forEach(item => {
            const isFree = item.notes?.includes('BONUS PROMO') || parseFloat(item.price) === 0;
            const title = clean(item.product?.title || item.product_title || "PRODUK").toUpperCase();
            const price = parseFloat(item.price || 0);
            const qty = parseFloat(item.qty || 1);
            if (!isFree) calculatedSubtotal += price;

            // Judul Produk
            result.bold(true).line(title).bold(false);
            
            // Rincian Qty x Harga (Menggunakan baris baru agar tidak menabrak judul)
            result.line(formatRow(
                `${qty.toFixed(0)} x ${isFree ? '0' : Math.round(price/qty).toLocaleString('id-ID')}`, 
                isFree ? 'FREE' : Math.round(price).toLocaleString('id-ID')
            ));

            // --- LOGIKA BUNDLING ---
            const bundleItems = item.product?.bundle_items || item.bundle_items;
            if (bundleItems && Array.isArray(bundleItems)) {
                bundleItems.forEach(bi => {
                    const biTitle = clean(bi.title || bi.product_title).toUpperCase();
                    const biQty = (parseFloat(bi.pivot?.qty || 1) * qty).toFixed(0);
                    result.line(` - ${biTitle} x${biQty}`);
                });
            }
        });

        const grandTotal = parseFloat(transaction.grand_total || 0);
        const actualDiscount = Math.max(0, calculatedSubtotal - grandTotal);
        const cashReceived = parseFloat(transaction.cash || grandTotal);
        const changeAmount = Math.max(0, cashReceived - grandTotal);

        result.line("-".repeat(C_WIDTH));
        if (actualDiscount > 0) {
            result.line(formatRow("SUBTOTAL", Math.round(calculatedSubtotal).toLocaleString('id-ID')))
                  .line(formatRow("DISKON", `-${Math.round(actualDiscount).toLocaleString('id-ID')}`));
        }

        result.bold(true).line(formatRow("TOTAL AKHIR", `Rp ${Math.round(grandTotal).toLocaleString('id-ID')}`)).bold(false)
              .line(formatRow("BAYAR", Math.round(cashReceived).toLocaleString('id-ID')))
              .line(formatRow("KEMBALI", Math.round(changeAmount).toLocaleString('id-ID')))
              .line(formatRow("METODE", clean(transaction.payment_method || 'CASH').toUpperCase()));
        
        if (transaction.online_platform) {
            result.line(formatRow("PLATFORM", clean(transaction.online_platform).toUpperCase()));
        }

        result.line("-".repeat(C_WIDTH))
              .raw([0x1b, 0x61, 0x01])
              .line(clean(receiptSetting?.store_footer || "Terima Kasih")).newline().newline().cut();

        await sendToBluetooth(result.encode());
        return { success: true };
    } catch (error) { throw error; }
};

// --- FUNGSI LAPORAN SHIFT ---
export const printShiftBluetooth = async (shift, receiptSetting) => {
    try {
        const encoder = new EscPosEncoder();
        let result = encoder.initialize().codepage('windows1252');
        const formatPrice = (p) => Math.round(parseFloat(p || 0)).toLocaleString('id-ID');

        result.raw([0x1b, 0x61, 0x01])
              .bold(true).line(clean(receiptSetting?.store_name || "TOKO POS")).bold(false)
              .line(clean(receiptSetting?.store_address || ""))
              .line("-".repeat(C_WIDTH))
              .bold(true).line("LAPORAN TUTUP SHIFT").bold(false)
              .line("-".repeat(C_WIDTH))
              .raw([0x1b, 0x61, 0x00])
              .line(formatRow("KASIR:", clean(shift.user?.name).toUpperCase().split(' ')[0]))
              .line(formatRow("MULAI:", formatDate(shift.opened_at)))
              .line(formatRow("TUTUP:", formatDate(shift.closed_at)))
              .line("-".repeat(C_WIDTH));

        const systemCash = parseFloat(shift.starting_cash) + parseFloat(shift.total_cash_expected) - parseFloat(shift.total_expense || 0);
        
        result.line(formatRow("MODAL AWAL", formatPrice(shift.starting_cash)))
              .line(formatRow("SALES TUNAI", formatPrice(shift.total_cash_expected)))
              .line(formatRow("KAS KELUAR", "-" + formatPrice(shift.total_expense)))
              .line(".".repeat(C_WIDTH))
              .bold(true).line(formatRow("TOTAL SISTEM", formatPrice(systemCash)))
              .line(formatRow("FISIK LACI", formatPrice(shift.total_physical_cash || shift.total_cash_actual))).bold(false)
              .line("-".repeat(C_WIDTH))
              .bold(true).line(formatRow("SELISIH", formatPrice(shift.difference))).bold(false)
              .line(formatRow("TOTAL QRIS", formatPrice(shift.total_qris_sales)))
              .line("-".repeat(C_WIDTH))
              .raw([0x1b, 0x61, 0x01])
              .line("TANDA TANGAN").newline().newline()
              .line("( KASIR )").newline()
              .line("WAKTU CETAK:").line(formatDate(new Date()))
              .newline().newline().cut();

        await sendToBluetooth(result.encode());
        return { success: true };
    } catch (error) { throw error; }
};

export const printBluetooth = printTransactionBluetooth;