import React, { useState, useEffect } from "react";
import { Head, Link, router } from "@inertiajs/react";
import { 
    IconArrowLeft, IconPrinter, IconUsb, IconBrandWhatsapp,
    IconReceipt, IconDeviceDesktop, IconInfoCircle, IconShare, 
    IconBluetooth, IconLoader2, IconHash, IconGift, IconTag, IconExternalLink
} from "@tabler/icons-react";
import { printUsbRaw } from "@/Utils/UsbRawPrinter"; 
import { printTransactionBluetooth } from "@/Utils/BluetoothRawPrinter"; 
import Swal from "sweetalert2";

export default function Print({ transaction, receiptSetting, isPublic = false, autoPrint = false }) {
    if (!transaction) return <div className="p-10 text-center text-white">Data Transaksi tidak ditemukan...</div>;

    const [isBtPrinting, setIsBtPrinting] = useState(false);
    const C_WIDTH = 27; 

    // --- 1. DEFINISIKAN DETAILS ---
    const details = Array.isArray(transaction.details) ? transaction.details : [];

    // --- 2. LOGIKA DATA ---
    const getOrderCode = () => {
        let rawVal = "";
        if (transaction.reference_code) {
            rawVal = transaction.reference_code.toString();
        } 
        else if (transaction.customer_name?.includes('#')) {
            rawVal = transaction.customer_name.split('#').pop();
        } 
        else {
            rawVal = (transaction.invoice || "").toString();
        }
        const numericOnly = rawVal.replace(/[^0-9]/g, '');
        return numericOnly.slice(-4).padStart(4, '0') || "0000";
    };

    const getQueueNumber = () => {
        if (transaction.queue_number) return transaction.queue_number;
        if (details[0]?.queue_number) return details[0].queue_number;
        const match = transaction.customer_name?.match(/Q-\d+/);
        return match ? match[0] : "----";
    };

    const displayOrder = getOrderCode();
    const displayQueue = getQueueNumber();

    // --- 3. HELPER FORMATTING ---
    const formatPrice = (price = 0) => 
        new Intl.NumberFormat("id-ID", { style: "currency", currency: "IDR", minimumFractionDigits: 0 })
            .format(price).replace("Rp", "").trim();

    const formatRow = (left, right) => {
        const lStr = left ? left.toString().trim() : "";
        const rStr = right ? right.toString().trim() : "";
        const spaceCount = C_WIDTH - (lStr.length + rStr.length);
        return lStr + " ".repeat(spaceCount > 0 ? spaceCount : 1) + rStr;
    };

    const grandTotal = parseFloat(transaction.grand_total || 0);
    const subtotalGross = details.reduce((acc, item) => acc + parseFloat(item.price || 0), 0);
    const manualDiscount = parseFloat(transaction.discount || 0);
    
    // Perbaikan logika kembalian agar tidak minus
    const cashReceived = parseFloat(transaction.cash || grandTotal);
    const changeAmount = cashReceived - grandTotal;

    // --- 4. FUNGSI KIRIM WHATSAPP + LINK VALIDASI ---
    const handleSendWhatsapp = async () => {
        let phoneNumber = transaction.customer?.phone || "";

        // Jika nomor kosong (pelanggan umum), minta input nomor
        if (!phoneNumber) {
            const { value: inputPhone } = await Swal.fire({
                title: 'Kirim Struk Digital',
                input: 'number',
                inputLabel: 'Masukkan nomor WhatsApp pelanggan (contoh: 62812...)',
                inputPlaceholder: '62812xxxxxxxx',
                showCancelButton: true,
                confirmButtonColor: '#22c55e',
                cancelButtonColor: '#64748b',
                confirmButtonText: 'Kirim Sekarang',
                cancelButtonText: 'Batal'
            });
            if (inputPhone) {
                // Pastikan format nomor diawali 62
                phoneNumber = inputPhone.startsWith('0') ? '62' + inputPhone.substring(1) : inputPhone;
            } else return;
        }

        // Generate Link Validasi (Arahkan ke rute publik invoice)
        const validationLink = `${window.location.origin}/p/invoice/${transaction.invoice}`;

        // Format Pesan WhatsApp
        let message = `*STRUK DIGITAL - ${receiptSetting?.store_name || "TOKO KAMI"}*\n`;
        message += `--------------------------------\n`;
        message += `No. Antrean : *${displayQueue}*\n`;
        message += `No. Invoice : ${transaction.invoice}\n`;
        message += `Tanggal     : ${new Date(transaction.created_at).toLocaleString('id-ID')}\n`;
        message += `Pelanggan   : ${transaction.customer_name || "Umum"}\n`;
        message += `--------------------------------\n\n`;
        
        details.forEach(item => {
            const isFree = item.notes?.includes('BONUS PROMO') || parseFloat(item.price) === 0;
            message += `*${(item.product?.title || item.product_title).toUpperCase()}*\n`;
            message += `${parseFloat(item.qty)} x ${formatPrice(item.price/item.qty)} = ${isFree ? 'GRATIS' : formatPrice(item.price)}\n`;
        });

        message += `\n--------------------------------\n`;
        if (manualDiscount > 0) {
            message += `Subtotal : ${formatPrice(subtotalGross)}\n`;
            message += `Diskon   : -${formatPrice(manualDiscount)}\n`;
        }
        message += `*TOTAL    : ${formatPrice(grandTotal)}*\n`;
        message += `Bayar    : ${formatPrice(cashReceived)}\n`;
        message += `Kembali  : ${formatPrice(Math.max(0, changeAmount))}\n`;
        message += `Metode   : ${(transaction.payment_method || "CASH").toUpperCase()}\n`;
        message += `--------------------------------\n\n`;
        message += `🔗 *Link Validasi Struk:* \n${validationLink}\n\n`;
        message += `_${receiptSetting?.store_footer || "Terima Kasih atas kunjungan Anda!"}_`;

        const encodedMessage = encodeURIComponent(message);
        const whatsappUrl = `https://wa.me/${phoneNumber}?text=${encodedMessage}`;
        window.open(whatsappUrl, '_blank');
    };

    // --- 5. FUNGSI PRINT ---
    const handleBluetoothPrint = async () => {
        setIsBtPrinting(true);
        try {
            await printTransactionBluetooth(transaction, receiptSetting);
        } catch (error) {
            console.error("BT Print Error:", error);
            if (autoPrint) {
                window.print();
            } else {
                alert("Bluetooth Error: " + error.message);
            }
        } finally {
            setIsBtPrinting(false);
        }
    };

    // --- 6. AUTO PRINT LOGIC ---
    useEffect(() => {
        if (autoPrint && transaction?.invoice) {
            const btPrinterActive = localStorage.getItem("bluetooth_printer_name");
            const timer = setTimeout(() => {
                if (btPrinterActive) {
                    handleBluetoothPrint();
                } else {
                    window.print();
                }
            }, 1000);
            return () => clearTimeout(timer);
        }
    }, [autoPrint, transaction]);

    return (
        <div className="min-h-screen bg-[#0f172a] font-sans text-slate-200 overflow-hidden flex flex-col md:flex-row print:bg-white print:block">
            <Head title={`Print #${transaction.invoice}`} />

            {/* SIDEBAR KONTROL */}
            {!isPublic && (
                <aside className="w-full md:w-80 bg-[#1e293b] border-r border-slate-700/50 p-6 flex flex-col justify-between print:hidden">
                    <div>
                        <div className="flex items-center gap-3 mb-10 font-bold text-white">
                            <IconReceipt className="text-orange-500" size={24} /> Billing Terminal
                        </div>
                        <nav className="space-y-6">
                            <div className="bg-slate-900/50 p-4 rounded-xl border border-slate-700/30 space-y-2">
                                <div className="flex justify-between text-xs font-bold border-b border-slate-700/30 pb-2 mb-2">
                                    <span className="text-slate-500 uppercase">Antrean</span>
                                    <span className="text-white text-lg">{displayQueue}</span>
                                </div>
                                <div className="flex justify-between text-xs">
                                    <span className="text-slate-500">Order Code</span>
                                    <span className="text-orange-400 font-mono">#{displayOrder}</span>
                                </div>
                                <div className="text-[10px] text-slate-500 italic pt-1 flex justify-between">
                                    <span>No.Trx:</span>
                                    <span className="truncate ml-2">{transaction.invoice}</span>
                                </div>
                            </div>
                            
                            <div className="space-y-3">
                                <button 
                                    onClick={handleBluetoothPrint} 
                                    disabled={isBtPrinting} 
                                    className={`w-full py-4 ${isBtPrinting ? 'bg-indigo-800' : 'bg-indigo-600 hover:bg-indigo-500'} text-white rounded-2xl font-black text-sm flex items-center justify-center gap-3 transition-all shadow-lg active:scale-95`}
                                >
                                    {isBtPrinting ? <IconLoader2 size={24} className="animate-spin" /> : <IconBluetooth size={24} />}
                                    {isBtPrinting ? "MENCETAK..." : "CETAK BLUETOOTH"}
                                </button>

                                <button 
                                    onClick={handleSendWhatsapp}
                                    className="w-full py-4 bg-emerald-600 hover:bg-emerald-500 text-white rounded-2xl font-black text-sm flex items-center justify-center gap-3 transition-all shadow-lg active:scale-95 shadow-emerald-500/10"
                                >
                                    <IconBrandWhatsapp size={24} /> KIRIM WHATSAPP
                                </button>

                                <div className="grid grid-cols-2 gap-2">
                                    <button onClick={() => printUsbRaw(transaction, receiptSetting)} className="bg-orange-500 text-white py-3 rounded-xl font-bold text-[10px] flex justify-center gap-2 active:scale-95 shadow-lg shadow-orange-500/10">
                                        <IconUsb size={16} /> USB RAW
                                    </button>
                                    <button onClick={() => window.print()} className="bg-slate-700 text-white py-3 rounded-xl font-bold text-[10px] flex justify-center gap-2 active:scale-95">
                                        <IconPrinter size={16} /> BROWSER
                                    </button>
                                </div>
                            </div>
                        </nav>
                    </div>
                    
                    <Link href={route("transactions.index")} className="w-full flex items-center justify-center gap-2 text-slate-400 hover:text-white py-2 text-sm font-medium transition-colors border-t border-slate-700/50 pt-6">
                        <IconArrowLeft size={18} /> Kembali
                    </Link>
                </aside>
            )}

            {/* PREVIEW AREA */}
            <main className="flex-1 p-4 md:p-12 flex flex-col items-center overflow-y-auto custom-scrollbar">
                <div className="bg-white p-4 text-black font-mono text-[11px] w-[52mm] shadow-2xl print:shadow-none">
                    <header className="text-center uppercase mb-2">
                        <div className="font-black text-[13px] leading-tight">{receiptSetting?.store_name || "TOKO ANDA"}</div>
                        <div className="text-[9px] opacity-70">{receiptSetting?.store_address}</div>
                        <div className="mt-1 opacity-30">{"-".repeat(C_WIDTH)}</div>
                    </header>

                    <div className="text-center my-2">
                        <div className="text-[32px] font-black leading-none">{displayQueue}</div>
                        <div className="mt-1 opacity-30">{"-".repeat(C_WIDTH)}</div>
                    </div>

                    <div className="whitespace-pre-wrap uppercase">
                        {formatRow("Plg:", (transaction.customer_name || "UMUM").toUpperCase().substring(0, 15)) + "\n"}
                        {formatRow("No.Trx:", transaction.invoice) + "\n"}
                        {formatRow("order:", `#${displayOrder}`) + "\n"}
                        {(() => {
                            const d = new Date(transaction.created_at);
                            const tgl = `${String(d.getDate()).padStart(2,'0')}-${String(d.getMonth()+1).padStart(2,'0')}-${d.getFullYear()} ${String(d.getHours()).padStart(2,'0')}:${String(d.getMinutes()).padStart(2,'0')}`;
                            return formatRow("Tgl:", tgl) + "\n";
                        })()}
                        {formatRow("Kasir:", (transaction.cashier?.name || "KASIR").split(' ')[0].toUpperCase()) + "\n"}
                        <span className="opacity-30">{"-".repeat(C_WIDTH) + "\n"}</span>

                        {details.map((item, i) => {
                            const isFree = item.notes?.includes('BONUS PROMO') || parseFloat(item.price) === 0;
                            return (
                                <React.Fragment key={i}>
                                    <div className="font-black flex items-center gap-1">
                                        {isFree && <IconGift size={10} />}
                                        {(item.product?.title || item.product_title).toUpperCase()}
                                    </div>
                                    
                                    {formatRow(
                                        `${parseFloat(item.qty).toFixed(0)} x ${isFree ? '0' : formatPrice(item.price/item.qty)}`, 
                                        isFree ? 'FREE' : formatPrice(item.price)
                                    ) + "\n"}

                                    {item.product?.type === 'bundle' && item.product?.bundle_items?.map((bi, idx) => (
                                        <div key={idx} className="text-[9px] lowercase italic opacity-70 ml-2">
                                            {`- ${bi.title} (${parseFloat(bi.pivot?.qty || 1) * item.qty}pcs)`}
                                        </div>
                                    ))}
                                </React.Fragment>
                            );
                        })}
                        <span className="opacity-30">{"-".repeat(C_WIDTH) + "\n"}</span>

                        {manualDiscount > 0 && (
                            <>
                                {formatRow("SUBTOTAL", formatPrice(subtotalGross)) + "\n"}
                                {formatRow("DISKON", `-${formatPrice(manualDiscount)}`) + "\n"}
                            </>
                        )}

                        <div className="font-black text-[11px] py-1">
                            {formatRow("TOTAL", `Rp ${formatPrice(grandTotal)}`) + "\n"}
                        </div>
                        {formatRow("BAYAR", formatPrice(cashReceived)) + "\n"}
                        {formatRow("KEMBALI", formatPrice(Math.max(0, changeAmount))) + "\n"}
                        
                        <div className="mt-1 opacity-70">
                            {formatRow("METODE:", (transaction.payment_method || "CASH").toUpperCase()) + "\n"}
                            {transaction.online_platform && formatRow("PLATFORM:", transaction.online_platform.toUpperCase()) + "\n"}
                        </div>
                    </div>

                    {/* Link Validasi di Struk Kertas */}
                    <div className="mt-4 pt-4 border-t border-dotted border-black/20 text-[7px] text-center italic break-all">
                        Cek Struk Online: <br /> {`${window.location.origin}/p/invoice/${transaction.invoice}`}
                    </div>

                    <footer className="text-center mt-4 pt-4">
                        <div className="font-black text-[9px] tracking-tight">{receiptSetting?.store_footer || "Terima Kasih"}</div>
                        <div className="text-[7px] mt-1 opacity-50 italic">Powered by Billing POS</div>
                    </footer>
                </div>
            </main>

            <style dangerouslySetInnerHTML={{ __html: `
                .custom-scrollbar::-webkit-scrollbar { width: 4px; }
                .custom-scrollbar::-webkit-scrollbar-thumb { background: #334155; border-radius: 10px; }
                @media print {
                    @page { margin: 0; size: auto; }
                    body { margin: 0; padding: 0; background: white !important; }
                    .print\\:hidden { display: none !important; }
                    aside { display: none !important; }
                    main { padding: 0 !important; overflow: visible !important; }
                }
            ` }} />
        </div>
    );
}