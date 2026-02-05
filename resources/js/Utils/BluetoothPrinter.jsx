import { BleClient, numbersToDataView } from "@capacitor-community/bluetooth-le";
import ReceiptPrinterEncoder from "@point-of-sale/receipt-printer-encoder";

/**
 * Fungsi Utama untuk cetak via Bluetooth
 */
export const printBluetooth = async (transaction, receiptSetting) => {
    try {
        const savedDevice = localStorage.getItem("selected_printer");
        if (!savedDevice) {
            throw new Error("Printer belum dipilih. Silakan pairing terlebih dahulu di menu Pengaturan.");
        }

        const device = JSON.parse(savedDevice);
        const encoder = new ReceiptPrinterEncoder();

        // 1. Inisialisasi Data Struk (Format Esc/POS)
        // Menyesuaikan dengan data dari ThermalReceipt.jsx
        let data = encoder
            .initialize()
            .align('center')
            .line(receiptSetting.store_name.toUpperCase())
            .line(receiptSetting.store_address || '')
            .line(`TELP: ${receiptSetting.store_phone || '-'}`)
            .line("-".repeat(32)) // Garis pemisah
            .align('left')
            .line(`NO  : ${transaction.invoice}`)
            .line(`TGL : ${new Date(transaction.created_at).toLocaleString('id-ID')}`)
            .line(`KSR : ${transaction.cashier?.name?.split(' ')[0]}`)
            .line("-".repeat(32));

        // 2. Daftar Item
        transaction.details.forEach((item) => {
            data.line((item.product?.title || item.product_title).toUpperCase())
                .align('right')
                .line(`${item.qty} x ${new Intl.NumberFormat("id-ID").format(item.price / item.qty)} = ${new Intl.NumberFormat("id-ID").format(item.price)}`)
                .align('left');
        });

        // 3. Footer / Totalan
        data.line("-".repeat(32))
            .align('right')
            .bold(true)
            .line(`TOTAL: Rp ${new Intl.NumberFormat("id-ID").format(transaction.grand_total)}`)
            .bold(false)
            .align('center')
            .line("-".repeat(32))
            .line(receiptSetting.store_footer || 'Terima Kasih')
            .feed(3)
            .cut()
            .encode();

        // 4. Proses Pengiriman Data ke Hardware
        await BleClient.connect(device.deviceId);
        
        // Catatan: UUID Service dan Characteristic standar printer thermal 
        // biasanya adalah "000018f0-0000-1000-8000-00805f9b34fb" 
        // atau Anda bisa melakukan discovery service.
        const services = await BleClient.getServices(device.deviceId);
        
        // Menggunakan service pertama dan characteristic pertama yang ditemukan (umum pada printer thermal)
        const serviceUuid = services[0].uuid;
        const characteristicUuid = services[0].characteristics[0].uuid;

        await BleClient.write(device.deviceId, serviceUuid, characteristicUuid, numbersToDataView(data));
        
        await BleClient.disconnect(device.deviceId);
        return true;

    } catch (error) {
        console.error("Bluetooth Print Error:", error);
        throw error;
    }
};