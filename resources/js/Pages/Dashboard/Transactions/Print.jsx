import React, { useState, useEffect } from "react";
import { Head, Link, router } from "@inertiajs/react";
import { 
    IconArrowLeft, IconPrinter, IconUsb, IconBrandWhatsapp,
    IconReceipt, IconDeviceDesktop, IconInfoCircle, IconShare, 
    IconBluetooth, IconLoader2, IconHash, IconGift, IconTag, IconExternalLink
} from "@tabler/icons-react";
import { printUsbRaw } from "@/Utils/UsbRawPrinter"; 
import { smartPrint } from "@/Utils/BluetoothHybridService"; 
import Swal from "sweetalert2";
import toast from "react-hot-toast";

export default function Print({ transaction, receiptSetting, isPublic = false, autoPrint = false }) {
    if (!transaction) return <div className="p-10 text-center text-white">Data Transaksi tidak ditemukan...</div>;

    const [isBtPrinting, setIsBtPrinting] = useState(false);
    const C_WIDTH = 25; 

    const details = Array.isArray(transaction.details) ? transaction.details : [];

    // --- HELPER LABEL METODE PEMBAYARAN ---
    const getPaymentLabel = (method) => {
        const m = method?.toLowerCase();
        if (m === 'cash') return "TUNAI";
        if (m === 'midtrans' || m === 'xendit') return "QRIS AUTO";
        if (m === 'qris_manual') return "QRIS STATIS"; 
        if (m === 'transfer') return "TRANSFER";
        return (method || "CASH").toUpperCase();
    };

    // --- LOGIKA PENGAMBILAN KODE ORDER ---
    const getOrderCode = () => {
        let rawVal = "";
        if (transaction.reference_code) rawVal = transaction.reference_code.toString();
        else if (transaction.customer_name?.includes('#')) rawVal = transaction.customer_name.split('#').pop();
        else rawVal = (transaction.invoice || "0000").toString();
        const numericOnly = rawVal.replace(/[^0-9]/g, '');
        return numericOnly.slice(-4).padStart(4, '0');
    };

    const getQueueNumber = () => {
        if (transaction.queue_number) return transaction.queue_number;
        const match = transaction.customer_name?.match(/Q-\d+/);
        if (match) return match[0];
        return "----";
    };

    const displayOrder = getOrderCode();
    const displayQueue = getQueueNumber();

    const formatPrice = (price = 0) => 
        new Intl.NumberFormat("id-ID", { style: "currency", currency: "IDR", minimumFractionDigits: 0 })
            .format(price).replace("Rp", "").trim();

    const formatRow = (left, right) => {
        const lStr = left ? left.toString().trim() : "";
        const rStr = right ? right.toString().trim() : "";
        const spaceCount = C_WIDTH - (lStr.length + rStr.length);
        return lStr + " ".repeat(spaceCount > 0 ? spaceCount : 1) + rStr;
    };

    // --- LOGIKA PERHITUNGAN DISKON (FIX: ITEM + GLOBAL) ---
    const grandTotal = parseFloat(transaction.grand_total || 0);
    
    // 1. Hitung Diskon Item (Selisih Harga Jual Normal vs Harga Deal)
    const itemDiscounts = details.reduce((acc, item) => {
        const normalPrice = parseFloat(item.product?.sell_price || 0);
        const actualPrice = parseFloat(item.price) / parseFloat(item.qty);
        const diff = normalPrice - actualPrice;
        return acc + (diff > 0 ? diff * item.qty : 0);
    }, 0);

    // 2. Tambah Diskon Global (Jika ada potongan langsung di keranjang)
    const globalDiscount = parseFloat(transaction.discount || 0);
    
    // 3. Total Semua Diskon
    const totalSaved = itemDiscounts + globalDiscount;
    const subtotalGross = grandTotal + totalSaved;

    const cashReceived = parseFloat(transaction.cash || grandTotal);
    const changeAmount = cashReceived - grandTotal;

    // --- KIRIM WHATSAPP ---
    const handleSendWhatsapp = async () => {
        let phoneNumber = transaction.customer?.phone || "";
        if (!phoneNumber) {
            const { value: inputPhone } = await Swal.fire({
                title: 'Kirim Struk Digital',
                input: 'number',
                inputLabel: 'Masukkan nomor WhatsApp pelanggan',
                inputPlaceholder: '62812xxxxxxxx',
                showCancelButton: true,
                confirmButtonColor: '#22c55e'
            });
            if (inputPhone) { phoneNumber = inputPhone.startsWith('0') ? '62' + inputPhone.substring(1) : inputPhone; } 
            else return;
        }

        const validationLink = `${window.location.origin}/p/invoice/${transaction.invoice}`;
        let message = `*STRUK DIGITAL - ${receiptSetting?.store_name || "TOKO KAMI"}*\n`;
        message += `--------------------------------\n`;
        message += `No. Antrean : *${displayQueue}*\n`;
        message += `--------------------------------\n\n`;
        
        details.forEach(item => {
            message += `*${(item.product?.title || item.product_title).toUpperCase()}*\n`;
            message += `${parseFloat(item.qty)} x ${formatPrice(item.price/item.qty)} = ${formatPrice(item.price)}\n`;
        });

        if (totalSaved > 0) {
            message += `\nSubtotal : ${formatPrice(subtotalGross)}`;
            message += `\nDiskon   : -${formatPrice(totalSaved)}`;
        }

        message += `\n*TOTAL : Rp ${formatPrice(grandTotal)}*\n`;
        message += `🔗 *Link Struk:* \n${validationLink}`;
        window.open(`https://wa.me/${phoneNumber}?text=${encodeURIComponent(message)}`, '_blank');
    };

    // --- BLUETOOTH PRINT ---
    const handleBluetoothPrint = async () => {
        setIsBtPrinting(true);
        try {
            const formattedTransaction = {
                ...transaction,
                queue_number: displayQueue,
                discount: totalSaved, // Kirim total diskon yang sudah dihitung ulang
                details: details.map(d => ({ ...d, product_title: d.product?.title || d.product_title }))
            };
            await smartPrint(formattedTransaction, receiptSetting);
            toast.success("Cetak Bluetooth Berhasil!");
        } catch (error) {
            if (autoPrint) window.print();
            else toast.error("Gagal cetak");
        } finally { setIsBtPrinting(false); }
    };

    useEffect(() => {
        if (autoPrint && transaction?.invoice) {
            if (window.bluetoothSerial) { handleBluetoothPrint(); } 
            else { const timer = setTimeout(() => window.print(), 1000); return () => clearTimeout(timer); }
        }
    }, [autoPrint, transaction]);

    return (
        <div className="min-h-screen bg-[#0f172a] font-sans text-slate-200 overflow-hidden flex flex-col md:flex-row print:bg-white print:block">
            <Head title={`Print #${transaction.invoice}`} />

            {!isPublic && (
                <aside className="w-full md:w-80 bg-[#1e293b] border-r border-slate-700/50 p-6 flex flex-col justify-between print:hidden shadow-2xl">
                    <div>
                        <div className="flex items-center gap-3 mb-10 font-bold text-white uppercase tracking-tighter italic">
                            <IconReceipt className="text-orange-500" size={24} /> Billing Terminal
                        </div>
                        <nav className="space-y-6">
                            <div className="bg-slate-900/50 p-4 rounded-xl border border-slate-700/30 space-y-2">
                                <div className="flex justify-between text-xs font-bold border-b border-slate-700/30 pb-2 mb-2 italic">
                                    <span className="text-slate-500 uppercase tracking-tighter">Antrean</span>
                                    <span className="text-white text-xl font-black">{displayQueue}</span>
                                </div>
                                <div className="flex justify-between text-xs font-bold border-b border-slate-700/30 pb-2 mb-2">
                                    <span className="text-slate-500 uppercase">Metode</span>
                                    <span className="text-emerald-400 font-black">{getPaymentLabel(transaction.payment_method)}</span>
                                </div>
                                <div className="flex justify-between text-xs">
                                    <span className="text-slate-500">Order ID</span>
                                    <span className="text-orange-400 font-mono font-bold">#{displayOrder}</span>
                                </div>
                            </div>
                            
                            <div className="space-y-3">
                                <button onClick={handleBluetoothPrint} disabled={isBtPrinting} className={`w-full py-4 ${isBtPrinting ? 'bg-indigo-800' : 'bg-indigo-600 hover:bg-indigo-500'} text-white rounded-2xl font-black text-sm flex items-center justify-center gap-3 transition-all active:scale-95`}>
                                    {isBtPrinting ? <IconLoader2 size={24} className="animate-spin" /> : <IconBluetooth size={24} />}
                                    {isBtPrinting ? "PROSES..." : "CETAK STRUK"}
                                </button>
                                <button onClick={handleSendWhatsapp} className="w-full py-4 bg-emerald-600 hover:bg-emerald-500 text-white rounded-2xl font-black text-sm flex items-center justify-center gap-3 transition-all active:scale-95">
                                    <IconBrandWhatsapp size={24} /> KIRIM WA
                                </button>
                                <div className="grid grid-cols-2 gap-2">
                                    <button onClick={() => printUsbRaw(transaction, receiptSetting)} className="bg-orange-500 text-white py-3 rounded-xl font-bold text-[10px] flex justify-center gap-2 active:scale-95"><IconUsb size={16} /> USB</button>
                                    <button onClick={() => window.print()} className="bg-slate-700 text-white py-3 rounded-xl font-bold text-[10px] flex justify-center gap-2 active:scale-95"><IconPrinter size={16} /> BROWSER</button>
                                </div>
                            </div>
                        </nav>
                    </div>
                    <Link href={route("transactions.index")} className="w-full flex items-center justify-center gap-2 text-slate-400 hover:text-white py-2 text-sm font-medium transition-colors border-t border-slate-700/50 pt-6">
                        <IconArrowLeft size={18} /> Kembali
                    </Link>
                </aside>
            )}

            <main className="flex-1 p-4 md:p-12 flex flex-col items-center overflow-y-auto custom-scrollbar">
                <div className="bg-white p-5 text-black font-mono text-[11px] w-[52mm] shadow-2xl print:shadow-none leading-tight border border-slate-100">
                    <header className="text-center uppercase mb-1">
                        <div className="font-black text-[13px] leading-tight mb-1">{receiptSetting?.store_name || "TOKO ANDA"}</div>
                        <div className="text-[9px] opacity-70 leading-none">{receiptSetting?.store_address}</div>
                        <div className="mt-2 opacity-30">{"-".repeat(C_WIDTH)}</div>
                    </header>

                    <div className="text-center my-2">
                        <div className="text-[34px] font-black leading-none tracking-tighter">{displayQueue}</div>
                        <div className="mt-2 opacity-30">{"-".repeat(C_WIDTH)}</div>
                    </div>

                    <div className="whitespace-pre-wrap uppercase">
                        {formatRow("Plg:", (transaction.customer_name || "UMUM").toUpperCase().substring(0, 15))}
                        {"\n" + formatRow("No.Trx:", transaction.invoice)}
                        {"\n" + formatRow("Order:", `#${displayOrder}`)}
                        {"\n" + (() => {
                            const d = new Date(transaction.created_at);
                            return formatRow("Tgl:", `${String(d.getDate()).padStart(2,'0')}-${String(d.getMonth()+1).padStart(2,'0')}-${d.getFullYear()} ${String(d.getHours()).padStart(2,'0')}:${String(d.getMinutes()).padStart(2,'0')}`);
                        })()}
                        {"\n" + formatRow("Kasir:", (transaction.cashier?.name || "KASIR").split(' ')[0].toUpperCase())}
                        {"\n" + formatRow("METODE:", getPaymentLabel(transaction.payment_method))}
                        
                        <div className="my-1 opacity-30">{"-".repeat(C_WIDTH)}</div>

                        {details.map((item, i) => (
                            <React.Fragment key={i}>
                                <div className="font-black mt-1">{(item.product?.title || item.product_title).toUpperCase()}</div>
                                {formatRow(`${parseFloat(item.qty)} x ${formatPrice(item.price/item.qty)}`, formatPrice(item.price))}
                                {item.notes && !item.notes.includes('BONUS PROMO') && <div className="text-[9px] lowercase italic opacity-70 ml-1">*{item.notes}</div>}
                            </React.Fragment>
                        ))}

                        <div className="my-1 opacity-30">{"-".repeat(C_WIDTH)}</div>
                        
                        {/* TAMPILAN DISKON TOTAL (SINKRON DENGAN GROSIR) */}
                        {totalSaved > 0 && (
                            <>
                                {formatRow("SUBTOTAL", formatPrice(subtotalGross))}
                                {"\n"}
                                <div className="font-black italic">
                                    {formatRow("DISKON TOTAL", "-" + formatPrice(totalSaved))}
                                </div>
                            </>
                        )}

                        <div className="font-black text-[11px] pt-1 border-t border-dashed border-black/20 mt-1">
                            {formatRow("TOTAL", `Rp ${formatPrice(grandTotal)}`)}
                        </div>
                        {formatRow("BAYAR", formatPrice(cashReceived))}
                        {formatRow("KEMBALI", formatPrice(Math.max(0, changeAmount)))}
                    </div>

                    <footer className="text-center mt-6 pt-4 border-t border-dashed border-black/20">
                        <div className="font-black text-[9px] tracking-tight">{receiptSetting?.store_footer || "Terima Kasih"}</div>
                    </footer>
                </div>
            </main>

            <style dangerouslySetInnerHTML={{ __html: `
                .custom-scrollbar::-webkit-scrollbar { width: 4px; }
                .custom-scrollbar::-webkit-scrollbar-thumb { background: #334155; border-radius: 10px; }
                @media print {
                    @page { margin: 0; size: auto; }
                    body { background: white !important; }
                    .print\\:hidden, aside { display: none !important; }
                    main { padding: 0 !important; overflow: visible !important; width: 100% !important; }
                    .shadow-2xl, border { box-shadow: none !important; border: none !important; }
                }
            ` }} />
        </div>
    );
}