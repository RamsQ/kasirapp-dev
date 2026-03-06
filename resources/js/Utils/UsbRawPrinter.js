import EscPosEncoder from 'esc-pos-encoder';

// --- FUNGSI INTERNAL: KONVERSI GAMBAR KE BINARY ---
// Tetap dipertahankan untuk kebutuhan cetak Logo jika diperlukan di masa depan
const getBinaryImage = async (url) => {
    return new Promise((resolve, reject) => {
        const img = new Image();
        img.crossOrigin = "Anonymous";
        img.src = url;
        img.onload = () => {
            const canvas = document.createElement('canvas');
            const maxWidth = 120; 
            let targetWidth = maxWidth;
            let targetHeight = img.height * (maxWidth / img.width);
            targetHeight = Math.ceil(targetHeight / 8) * 8; 
            canvas.width = targetWidth;
            canvas.height = targetHeight;
            const ctx = canvas.getContext('2d');
            ctx.fillStyle = "#ffffff";
            ctx.fillRect(0, 0, canvas.width, canvas.height);
            ctx.drawImage(img, 0, 0, targetWidth, targetHeight);
            const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
            resolve({ data: imageData, width: canvas.width, height: canvas.height });
        };
        img.onerror = () => reject(new Error("Gagal memuat logo"));
    });
};

// --- HELPER STRUKTUR ---
const C_WIDTH = 32; 
const clean = (text) => text ? text.toString().trim() : "";
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

// --- FUNGSI 1: PRINT STRUK FINAL (LUNAS) ---
export const printUsbRaw = async (transaction, receiptSetting) => {
    try {
        const device = await navigator.usb.requestDevice({ filters: [] });
        await device.open();
        if (device.configuration === null) await device.selectConfiguration(1);
        await device.claimInterface(0);

        const encoder = new EscPosEncoder();
        let result = encoder.initialize().codepage('windows1252');

        const details = Array.isArray(transaction.details) ? transaction.details : [];
        const noAntrean = transaction.queue_number || details[0]?.queue_number || (transaction.customer_name?.match(/Q-\d+/)?.[0]) || "----";

        // Helper Label Metode
        const getPaymentLabel = (method) => {
            const m = method?.toLowerCase();
            if (m === 'cash') return "TUNAI";
            if (m === 'midtrans' || m === 'xendit') return "QRIS AUTO";
            if (m === 'qris_manual') return "QRIS STATIS"; 
            if (m === 'transfer') return "TRANSFER";
            return (method || "CASH").toUpperCase();
        };

        // HEADER (CENTER)
        result.raw([0x1b, 0x61, 0x01]); 
        result.bold(true).line(clean(receiptSetting?.store_name || "TOKO POS")).bold(false)
              .line(clean(receiptSetting?.store_address || "Alamat Toko"))
              .line("TELP: " + clean(receiptSetting?.store_phone || "0000000"))
              .line("-".repeat(C_WIDTH));

        // ANTREAN BESAR
        result.size('large').bold(true).line(noAntrean).size('normal').bold(false)
              .line("-".repeat(C_WIDTH));

        // METADATA (LEFT)
        result.raw([0x1b, 0x61, 0x00]);
        const getDisplayCode = () => {
            if (transaction.reference_code) return transaction.reference_code;
            if (transaction.customer_name && transaction.customer_name.includes('#')) {
                return transaction.customer_name.split('#').pop().trim();
            }
            return (transaction.invoice || "").replace(/[^0-9]/g, '').slice(-4).padStart(4, '0');
        };
        const displayCode = getDisplayCode();
        
        result.line(formatRow("Order:", "#" + displayCode));
        result.line(formatRow("Tgl:", formatDate(transaction.created_at)));
        result.line(formatRow("Plg:", clean(transaction.customer_name || "UMUM").toUpperCase().substring(0, 18)));
        result.line(formatRow("Kasir:", clean(transaction.cashier?.name?.split(' ')[0] || "KASIR").toUpperCase()));
        result.line("-".repeat(C_WIDTH));

        // LIST ITEMS & HITUNG DISKON OTOMATIS
        let calculatedSubtotal = 0;
        details.forEach(item => {
            const isFree = item.notes?.includes('BONUS PROMO') || parseFloat(item.price) === 0;
            const title = clean(item.product?.title || item.product_title || "PRODUK").toUpperCase();
            
            const normalPricePerItem = parseFloat(item.product?.sell_price || 0);
            const actualPriceTotal = parseFloat(item.price || 0);
            const qty = parseFloat(item.qty || 0);

            if (!isFree) {
                const normalTotal = normalPricePerItem * qty;
                calculatedSubtotal += (normalTotal > 0) ? normalTotal : actualPriceTotal;
            }

            result.bold(true).line(title).bold(false);
            result.line(formatRow(
                `${qty.toFixed(0)} x ${isFree ? '0' : Math.round(actualPriceTotal/qty).toLocaleString('id-ID')}`, 
                isFree ? 'FREE' : Math.round(actualPriceTotal).toLocaleString('id-ID')
            ));
            
            // LOGIKA BUNDLING
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
            result.line(formatRow("SUBTOTAL", Math.round(calculatedSubtotal).toLocaleString('id-ID')));
            result.line(formatRow("DISKON TOTAL", `-${Math.round(actualDiscount).toLocaleString('id-ID')}`));
        }

        result.bold(true).line(formatRow("TOTAL AKHIR", `Rp ${Math.round(grandTotal).toLocaleString('id-ID')}`)).bold(false);
        result.line(formatRow("BAYAR", Math.round(cashReceived).toLocaleString('id-ID')));
        result.line(formatRow("KEMBALI", Math.round(changeAmount).toLocaleString('id-ID')));
        result.line(formatRow("METODE", getPaymentLabel(transaction.payment_method)));
        
        if (transaction.online_platform) {
            result.line(formatRow("PLATFORM", clean(transaction.online_platform).toUpperCase()));
        }

        result.line("-".repeat(C_WIDTH));

        result.raw([0x1b, 0x61, 0x01]);
        result.line(clean(receiptSetting?.store_footer || "Terima Kasih")).newline().newline().cut();

        const data = result.encode();
        try { await device.transferOut(1, data); } catch (e) { await device.transferOut(2, data); }
        return { success: true };
    } catch (error) { throw error; }
};

// --- FUNGSI 2: PRINT BILL TAGIHAN (DRAF SEBELUM BAYAR) ---
export const printBillUsb = async (transaction, receiptSetting) => {
    try {
        const device = await navigator.usb.requestDevice({ filters: [] });
        await device.open();
        if (device.configuration === null) await device.selectConfiguration(1);
        await device.claimInterface(0);

        const encoder = new EscPosEncoder();
        let result = encoder.initialize().codepage('windows1252');

        const qNum = transaction.queue_number || (transaction.cart_data && transaction.cart_data[0]?.queue_number) || (transaction.customer_name?.match(/Q-\d+/)?.[0]) || "----";
        
        // Logika Pengambilan Kode Order
        const getDisplayCode = () => {
            if (transaction.reference_code) return transaction.reference_code;
            if (transaction.customer_name && transaction.customer_name.includes('#')) {
                return transaction.customer_name.split('#').pop().trim();
            }
            const rawVal = transaction.ref_number || transaction.invoice || "0000";
            return rawVal.toString().replace(/[^0-9]/g, '').slice(-4).padStart(4, '0');
        };
        const displayCode = getDisplayCode();

        result.raw([0x1b, 0x61, 0x01]);
        result.bold(true).line("--- BILL (DRAF) ---").bold(false)
              .line(clean(receiptSetting?.store_name || "TOKO POS").toUpperCase())
              .line("-".repeat(C_WIDTH));

        // ANTREAN BESAR
        result.size('large').bold(true).line(clean(qNum)).size('normal').bold(false)
              .line("-".repeat(C_WIDTH));

        result.raw([0x1b, 0x61, 0x00]);
        result.line(formatRow("Order ID:", "#" + displayCode));
        result.line(formatRow("Tgl:", formatDate(transaction.created_at)));
        result.line(formatRow("Plg:", clean(transaction.customer_name || "UMUM").toUpperCase().substring(0, 18)));
        result.line(formatRow("Meja:", clean(transaction.table_name || transaction.table?.name || "TAKE AWAY").toUpperCase()));
        
        result.raw([0x1b, 0x61, 0x01]).line("-".repeat(C_WIDTH)).raw([0x1b, 0x61, 0x00]);

        let subtotalBill = 0;
        const items = transaction.details || transaction.cart_data || [];
        items.forEach(item => {
            const title = clean(item.product_title || item.product?.title || "PRODUK").toUpperCase();
            const p = parseFloat(item.price || 0);
            const q = parseFloat(item.qty || 0);
            const normalPrice = parseFloat(item.product?.sell_price || (p/q));
            subtotalBill += (normalPrice * q);

            result.bold(true).line(title).bold(false);
            result.line(formatRow(`${q.toFixed(0)} x ${Math.round(p/q).toLocaleString('id-ID')}`, Math.round(p).toLocaleString('id-ID')));

            // LOGIKA BUNDLING DALAM BILL
            const bundleItems = item.product?.bundle_items || item.bundle_items;
            if (bundleItems && Array.isArray(bundleItems)) {
                bundleItems.forEach(bi => {
                    const biTitle = clean(bi.title || bi.product_title).toUpperCase();
                    const biQty = (parseFloat(bi.pivot?.qty || 1) * q).toFixed(0);
                    result.line(` - ${biTitle} x${biQty}`);
                });
            }
        });

        const totalBill = parseFloat(transaction.grand_total || transaction.total || 0);
        const discBill = Math.max(0, subtotalBill - totalBill);

        result.raw([0x1b, 0x61, 0x01]).line("-".repeat(C_WIDTH)).raw([0x1b, 0x61, 0x00]);
        if (discBill > 0) {
            result.line(formatRow("SUBTOTAL", Math.round(subtotalBill).toLocaleString('id-ID')));
            result.line(formatRow("DISKON", `-${Math.round(discBill).toLocaleString('id-ID')}`));
        }
        result.bold(true).line(formatRow("TOTAL BILL", `Rp ${Math.round(totalBill).toLocaleString('id-ID')}`)).bold(false);
        result.line(formatRow("METODE", "BELUM BAYAR"));
        
        result.raw([0x1b, 0x61, 0x01]);
        result.line("-".repeat(C_WIDTH));
        result.bold(true).line("* PESANAN BELUM DIBAYAR *").bold(false);
        result.line("BUKAN BUKTI PEMBAYARAN SAH").newline().newline().cut();
        
        const data = result.encode();
        try { await device.transferOut(1, data); } catch (e) { await device.transferOut(2, data); }
        return { success: true };
    } catch (error) { throw error; }
};

// --- FUNGSI 3: PRINT LAPORAN SHIFT (SINKRON TUNAI VS DIGITAL) ---
export const printShiftUsbRaw = async (shift, receiptSetting) => {
    try {
        const device = await navigator.usb.requestDevice({ filters: [] });
        await device.open();
        if (device.configuration === null) await device.selectConfiguration(1);
        await device.claimInterface(0);

        const encoder = new EscPosEncoder();
        let result = encoder.initialize().codepage('windows1252');

        const formatPrice = (p) => Math.round(parseFloat(p || 0)).toLocaleString('id-ID');

        result.raw([0x1b, 0x61, 0x01]); 
        result.bold(true).line(clean(receiptSetting?.store_name || "TOKO POS")).bold(false)
              .line(clean(receiptSetting?.store_address || "ALAMAT TOKO"))
              .line("-".repeat(C_WIDTH))
              .bold(true).line("LAPORAN TUTUP SHIFT").bold(false)
              .line("-".repeat(C_WIDTH));

        result.raw([0x1b, 0x61, 0x00]); 
        result.line(formatRow("KASIR:", clean(shift.user?.name).toUpperCase().substring(0, 18)));
        result.line(formatRow("MULAI:", formatDate(shift.opened_at)));
        result.line(formatRow("TUTUP:", formatDate(shift.closed_at)));
        result.line("-".repeat(C_WIDTH));

        const cashSales = parseFloat(shift.total_cash_sales || 0);
        const pettyCash = parseFloat(shift.total_expense || 0);
        const startCash = parseFloat(shift.starting_cash || 0);
        const systemSaldo = (startCash + cashSales) - pettyCash;

        result.line(formatRow("MODAL AWAL", formatPrice(startCash)));
        result.line(formatRow("SALES TUNAI", formatPrice(cashSales)));
        result.line(formatRow("KAS KELUAR", "-" + formatPrice(pettyCash)));
        
        result.line(".".repeat(C_WIDTH));
        
        result.line(formatRow("SISTEM LACI", formatPrice(systemSaldo)));
        result.bold(true).line(formatRow("FISIK LACI", formatPrice(shift.total_cash_actual))).bold(false);
        
        result.line("-".repeat(C_WIDTH));

        result.bold(true).line(formatRow("SELISIH", formatPrice(shift.difference))).bold(false);

        // INFO DIGITAL (Bank)
        result.newline().raw([0x1b, 0x61, 0x01]).line("INFO PENDAPATAN DIGITAL").raw([0x1b, 0x61, 0x00]);
        result.line(formatRow("QRIS STATIS/AUTO", formatPrice(shift.total_qris_sales)));
        result.line(formatRow("TRANSFER BANK", formatPrice(shift.total_transfer_sales || 0)));
        
        result.line("-".repeat(C_WIDTH));

        result.raw([0x1b, 0x61, 0x01]); 
        result.line("TANDA TANGAN").newline().newline().line("(...........)").newline().line("WAKTU CETAK:").line(formatDate(new Date())).newline().newline().cut();

        const data = result.encode();
        try { await device.transferOut(1, data); } catch (e) { await device.transferOut(2, data); }
        return { success: true };
    } catch (error) { throw error; }
};