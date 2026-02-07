import { BleClient, numbersToDataView } from "@capacitor-community/bluetooth-le";
import EscPosEncoder from "esc-pos-encoder";

export const printBluetooth = async (transaction, receiptSetting) => {
    try {
        const savedDevice = localStorage.getItem("selected_printer");
        if (!savedDevice) {
            throw new Error("Printer belum dipilih.");
        }

        const device = JSON.parse(savedDevice);
        const encoder = new EscPosEncoder();

        // Menggunakan line() manual sebagai pengganti feed() jika sering gagal di Android
        const encodedData = encoder
            .initialize()
            .align('center')
            .line((receiptSetting.store_name || 'KASIR').toUpperCase())
            .line(receiptSetting.store_address || '')
            .line("-".repeat(32))
            .align('left')
            .line(`NO  : ${transaction.invoice || 'BILL'}`)
            .line(`TGL : ${new Date().toLocaleDateString('id-ID')}`)
            .line(`KSR : ${transaction.cashier?.name?.split(' ')[0] || 'Admin'}`)
            .line("-".repeat(32));

        // Tambahkan Item
        const details = transaction.details || transaction.cart_data || [];
        details.forEach((item) => {
            const name = (item.product?.title || item.product_title || item.title || 'Produk').toUpperCase();
            const qty = parseFloat(item.qty || 1);
            const price = parseFloat(item.price || 0);
            
            encodedData.line(name)
                .align('right')
                .line(`${qty} x ${new Intl.NumberFormat("id-ID").format(price / qty)} = ${new Intl.NumberFormat("id-ID").format(price)}`)
                .align('left');
        });

        // Finalisasi data
        const finalBuffer = encodedData
            .line("-".repeat(32))
            .align('right')
            .line(`TOTAL: Rp ${new Intl.NumberFormat("id-ID").format(transaction.grand_total || transaction.total)}`)
            .align('center')
            .line("-".repeat(32))
            .line(receiptSetting.store_footer || 'Terima Kasih')
            .line(" ") // Manual feed 1
            .line(" ") // Manual feed 2
            .line(" ") // Manual feed 3
            .cut()
            .encode();

        // --- PROSES KONEKSI ANTI-TIMEOUT ---
        await BleClient.connect(device.deviceId);
        
        // Sangat krusial untuk printer Bluetooth LE agar tidak timeout saat kirim data banyak
        try {
            await BleClient.requestMTU(device.deviceId, 512);
        } catch (e) {
            console.warn("MTU Request tidak didukung, menggunakan standar.");
        }

        const services = await BleClient.getServices(device.deviceId);
        
        // Cari characteristic yang mendukung WRITE secara dinamis
        let writeCharacteristic = null;
        for (const service of services) {
            const char = service.characteristics.find(c => c.properties.write || c.properties.writeWithoutResponse);
            if (char) {
                writeCharacteristic = { service: service.uuid, characteristic: char.uuid };
                break;
            }
        }

        if (!writeCharacteristic) throw new Error("Karakteristik write tidak ditemukan");

        // Kirim data dalam chunk (potongan) 20 byte jika buffer sangat besar (opsional tapi aman)
        await BleClient.write(
            device.deviceId, 
            writeCharacteristic.service, 
            writeCharacteristic.characteristic, 
            numbersToDataView(Array.from(finalBuffer))
        );
        
        await BleClient.disconnect(device.deviceId);
        return true;

    } catch (error) {
        console.error("Bluetooth Print Error:", error);
        // Pastikan disconnect jika gagal agar device tidak "hang"
        try { await BleClient.disconnect(device.deviceId); } catch (e) {}
        throw error;
    }
};