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

    // --- FIX LOGIKA PENGAMBILAN KODE & ANTREAN ---
    const getOrderCode = () => {
        let rawVal = "";
        if (transaction.reference_code) rawVal = transaction.reference_code.toString();
        else if (transaction.customer_name?.includes('#')) rawVal = transaction.customer_name.split('#').pop();
        else rawVal = (transaction.invoice || "").toString();
        
        const numericOnly = rawVal.replace(/[^0-9]/g, '');
        return numericOnly.slice(-4).padStart(4, '0') || "0000";
    };

    const getQueueNumber = () => {
        // Prioritas 1: Kolom database queue_number
        if (transaction.queue_number) return transaction.queue_number;
        // Prioritas 2: Data dari detail pertama (jika ada)
        if (details[0]?.queue_number) return details[0].queue_number;
        // Prioritas 3: Regex dari nama pelanggan (format Q-000)
        const match = transaction.customer_name?.match(/Q-\d+/);
        if (match) return match[0];
        // Prioritas 4: Jika dari QR Menu biasanya ada kode unik di nama
        if (transaction.customer_name?.includes('#')) {
             const code = transaction.customer_name.split('#').pop().trim();
             if (code.length >= 3) return "Q-" + code.slice(-3);
        }
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

    const grandTotal = parseFloat(transaction.grand_total || 0);
    const manualDiscount = parseFloat(transaction.discount || 0);
    const subtotalGross = grandTotal + manualDiscount;
    const cashReceived = parseFloat(transaction.cash || grandTotal);
    const changeAmount = cashReceived - grandTotal;

    const handleSendWhatsapp = async () => {
        let phoneNumber = transaction.customer?.phone || "";
        if (!phoneNumber) {
            const { value: inputPhone } = await Swal.fire({
                title: 'Kirim Struk Digital',
                input: 'number',
                inputLabel: 'Masukkan nomor WhatsApp pelanggan (contoh: 62812...)',
                inputPlaceholder: '62812xxxxxxxx',
                showCancelButton: true,
                confirmButtonColor: '#22c55e',
                confirmButtonText: 'Kirim Sekarang'
            });
            if (inputPhone) {
                phoneNumber = inputPhone.startsWith('0') ? '62' + inputPhone.substring(1) : inputPhone;
            } else return;
        }

        const validationLink = `${window.location.origin}/p/invoice/${transaction.invoice}`;
        let message = `*STRUK DIGITAL - ${receiptSetting?.store_name || "TOKO KAMI"}*\n`;
        message += `--------------------------------\n`;
        message += `No. Antrean : *${displayQueue}*\n`;
        message += `No. Invoice : ${transaction.invoice}\n`;
        message += transaction.online_platform ? `Platform    : *${transaction.online_platform.toUpperCase()}*\n` : '';
        message += `Tanggal     : ${new Date(transaction.created_at).toLocaleString('id-ID')}\n`;
        message += `--------------------------------\n\n`;
        
        details.forEach(item => {
            const isFree = item.notes?.includes('BONUS PROMO') || parseFloat(item.price) === 0;
            message += `*${(item.product?.title || item.product_title).toUpperCase()}*\n`;
            message += `${parseFloat(item.qty)} x ${formatPrice(item.price/item.qty)} = ${isFree ? 'GRATIS' : formatPrice(item.price)}\n`;
        });

        message += `\n*TOTAL : ${formatPrice(grandTotal)}*\n`;
        message += `🔗 *Link Validasi:* \n${validationLink}`;

        window.open(`https://wa.me/${phoneNumber}?text=${encodeURIComponent(message)}`, '_blank');
    };

    const handleBluetoothPrint = async () => {
        setIsBtPrinting(true);
        try {
            const formattedTransaction = {
                ...transaction,
                queue_number: displayQueue, // Pastikan queue yang ter-render dikirim ke service
                details: details.map(d => ({
                    ...d,
                    product_title: d.product?.title || d.product_title
                }))
            };

            await smartPrint(formattedTransaction, receiptSetting);
            toast.success("Berhasil mencetak ke Bluetooth!");
        } catch (error) {
            console.error("BT Print Error:", error);
            const errorMsg = typeof error === 'string' ? error : (error.message || "Gagal terhubung");
            if (autoPrint) window.print();
            else Swal.fire({ icon: 'error', title: 'Gagal Cetak', text: errorMsg, background: '#1e293b', color: '#fff' });
        } finally {
            setIsBtPrinting(false);
        }
    };

    useEffect(() => {
        if (autoPrint && transaction?.invoice) {
            if (window.bluetoothSerial) {
                handleBluetoothPrint();
            } else {
                const timer = setTimeout(() => window.print(), 1000);
                return () => clearTimeout(timer);
            }
        }
    }, [autoPrint, transaction]);

    return (
        <div className="min-h-screen bg-[#0f172a] font-sans text-slate-200 overflow-hidden flex flex-col md:flex-row print:bg-white print:block">
            <Head title={`Print #${transaction.invoice}`} />

            {!isPublic && (
                <aside className="w-full md:w-80 bg-[#1e293b] border-r border-slate-700/50 p-6 flex flex-col justify-between print:hidden">
                    <div>
                        <div className="flex items-center gap-3 mb-10 font-bold text-white">
                            <IconReceipt className="text-orange-500" size={24} /> Billing Terminal
                        </div>
                        <nav className="space-y-6">
                            <div className="bg-slate-900/50 p-4 rounded-xl border border-slate-700/30 space-y-2">
                                <div className="flex justify-between text-xs font-bold border-b border-slate-700/30 pb-2 mb-2 italic">
                                    <span className="text-slate-500 uppercase tracking-tighter">Antrean</span>
                                    <span className="text-white text-xl font-black">{displayQueue}</span>
                                </div>
                                <div className="flex justify-between text-xs">
                                    <span className="text-slate-500">Order ID</span>
                                    <span className="text-orange-400 font-mono">#{displayOrder}</span>
                                </div>
                                {transaction.online_platform && (
                                    <div className="flex justify-between text-xs pt-2 border-t border-slate-700/30">
                                        <span className="text-slate-500 uppercase">Platform</span>
                                        <span className="text-emerald-400 font-black uppercase tracking-tighter">{transaction.online_platform}</span>
                                    </div>
                                )}
                            </div>
                            
                            <div className="space-y-3">
                                <button onClick={handleBluetoothPrint} disabled={isBtPrinting} className={`w-full py-4 ${isBtPrinting ? 'bg-indigo-800' : 'bg-indigo-600 hover:bg-indigo-500'} text-white rounded-2xl font-black text-sm flex items-center justify-center gap-3 transition-all shadow-lg active:scale-95`}>
                                    {isBtPrinting ? <IconLoader2 size={24} className="animate-spin" /> : <IconBluetooth size={24} />}
                                    {isBtPrinting ? "MENCETAK..." : "CETAK BLUETOOTH"}
                                </button>
                                <button onClick={handleSendWhatsapp} className="w-full py-4 bg-emerald-600 hover:bg-emerald-500 text-white rounded-2xl font-black text-sm flex items-center justify-center gap-3 transition-all shadow-lg active:scale-95">
                                    <IconBrandWhatsapp size={24} /> KIRIM WHATSAPP
                                </button>
                                <div className="grid grid-cols-2 gap-2">
                                    <button onClick={() => printUsbRaw(transaction, receiptSetting)} className="bg-orange-500 text-white py-3 rounded-xl font-bold text-[10px] flex justify-center gap-2 active:scale-95"><IconUsb size={16} /> USB RAW</button>
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
                <div className="bg-white p-5 text-black font-mono text-[11px] w-[52mm] shadow-2xl print:shadow-none leading-tight">
                    <header className="text-center uppercase mb-1">
                        <div className="font-black text-[13px] leading-tight mb-1">{receiptSetting?.store_name || "TOKO ANDA"}</div>
                        <div className="text-[9px] opacity-70 leading-none">{receiptSetting?.store_address}</div>
                        <div className="mt-2 opacity-30">{"-".repeat(C_WIDTH)}</div>
                    </header>

                    {/* BESARKAN NOMOR ANTREAN DI STRUK FISIK */}
                    <div className="text-center my-2">
                        <div className="text-[34px] font-black leading-none tracking-tighter">{displayQueue}</div>
                        <div className="mt-2 opacity-30">{"-".repeat(C_WIDTH)}</div>
                    </div>

                    <div className="whitespace-pre-wrap uppercase">
                        {formatRow("Plg:", (transaction.customer_name || "UMUM").toUpperCase().substring(0, 15))}
                        {"\n" + formatRow("No.Trx:", transaction.invoice)}
                        {"\n" + formatRow("Order:", `#${displayOrder}`)}
                        {transaction.online_platform && ("\n" + formatRow("Platform:", transaction.online_platform.toUpperCase()))}
                        {"\n" + (() => {
                            const d = new Date(transaction.created_at);
                            const tgl = `${String(d.getDate()).padStart(2,'0')}-${String(d.getMonth()+1).padStart(2,'0')}-${d.getFullYear()} ${String(d.getHours()).padStart(2,'0')}:${String(d.getMinutes()).padStart(2,'0')}`;
                            return formatRow("Tgl:", tgl);
                        })()}
                        {"\n" + formatRow("Kasir:", (transaction.cashier?.name || "KASIR").split(' ')[0].toUpperCase())}
                        {"\n" + formatRow("METODE:", (transaction.payment_method || "CASH").toUpperCase())}
                        
                        <div className="my-1 opacity-30">{"-".repeat(C_WIDTH)}</div>

                        {details.map((item, i) => {
                            const isFree = item.notes?.includes('BONUS PROMO') || parseFloat(item.price) === 0;
                            return (
                                <React.Fragment key={i}>
                                    <div className="font-black mt-1">{(item.product?.title || item.product_title).toUpperCase()}</div>
                                    {formatRow(
                                        `${parseFloat(item.qty).toFixed(0)} x ${isFree ? '0' : formatPrice(item.price/item.qty)}`, 
                                        isFree ? 'FREE' : formatPrice(item.price)
                                    )}
                                    {item.notes && !item.notes.includes('BONUS PROMO') && (
                                        <div className="text-[9px] lowercase italic opacity-70 ml-1">*{item.notes}</div>
                                    )}
                                </React.Fragment>
                            );
                        })}

                        <div className="my-1 opacity-30">{"-".repeat(C_WIDTH)}</div>
                        
                        {manualDiscount > 0 && (formatRow("SUBTOTAL", formatPrice(subtotalGross)) + "\n")}
                        {manualDiscount > 0 && (
                            <div className="text-black">
                                {formatRow("DISKON", "-" + formatPrice(manualDiscount)) + "\n"}
                            </div>
                        )}

                        <div className="font-black text-[11px] pt-1 border-t border-dashed border-black/20">
                            {formatRow("TOTAL", `Rp ${formatPrice(grandTotal)}`)}
                        </div>
                        {formatRow("BAYAR", formatPrice(cashReceived))}
                        {formatRow("KEMBALI", formatPrice(Math.max(0, changeAmount)))}
                    </div>

                    <footer className="text-center mt-6 pt-4 border-t border-dashed border-black/20">
                        <div className="font-black text-[9px] tracking-tight">{receiptSetting?.store_footer || "Terima Kasih"}</div>
                        <div className="text-[8px] opacity-50 mt-1 italic">Scan QR untuk struk digital</div>
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
                    .shadow-2xl { box-shadow: none !important; }
                }
            ` }} />
        </div>
    );
}