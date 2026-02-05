import React, { useState, useEffect } from "react";
import { Head, Link } from "@inertiajs/react";
import { 
    IconArrowLeft, IconPrinter, IconCash, IconPackage, 
    IconBuildingStore, IconReceipt, IconHash, IconCalendar, IconUser,
    IconFileInvoice, IconUsers, IconBrandWhatsapp, IconExternalLink, IconScale,
    IconBoxSeam, IconBluetooth, IconTicket, IconGift
} from "@tabler/icons-react";
import Swal from "sweetalert2";
import ThermalReceipt, { ThermalReceipt58mm } from "@/Components/Receipt/ThermalReceipt";
import { printBluetooth } from "@/Utils/BluetoothPrinter";

export default function Print({ transaction, receiptSetting, isPublic = false, qrisImage = null, autoPrint = false }) {
    
    // --- LOGIKA CETAK OTOMATIS (BROWSER) ---
    useEffect(() => {
        if (autoPrint && transaction?.invoice) {
            const timer = setTimeout(() => {
                window.print();
            }, 1000);
            return () => clearTimeout(timer);
        }
    }, [autoPrint, transaction]);

    // 1. Guard Utama
    if (!transaction || !transaction.invoice) {
        return <div className="p-10 text-center text-white bg-slate-900 min-h-screen font-black uppercase italic tracking-widest">Memuat Transaksi...</div>;
    }

    const [printMode, setPrintMode] = useState("invoice");

    // --- SETUP DATA TOKO ---
    const storeName = receiptSetting?.store_name || "TOKO POS";
    const storeAddress = receiptSetting?.store_address || "Alamat belum diatur";
    const storePhone = receiptSetting?.store_phone || "-";
    const storeFooter = receiptSetting?.store_footer || "Terima kasih atas kunjungan Anda";
    const storeLogo = receiptSetting?.store_logo ? `/storage/receipt/${receiptSetting.store_logo}` : null;

    const formatPrice = (price = 0) => 
        new Intl.NumberFormat("id-ID", { style: "currency", currency: "IDR", minimumFractionDigits: 0 }).format(price || 0);

    // --- LOGIKA HITUNG TOTAL HEMAT UNTUK INVOICE A4 ---
    const calculateFinalSavings = () => {
        let totalHargaNormalBarang = 0;
        (transaction.details || []).forEach((item) => {
            const qty = parseFloat(item.qty) || 0;
            // Gunakan buy_price (harga normal dari controller) sebagai patokan harga normal
            const priceNormal = parseFloat(item.buy_price || item.product?.sell_price || (parseFloat(item.price) / qty));
            totalHargaNormalBarang += (priceNormal * qty);
        });

        const grandTotal = parseFloat(transaction.grand_total) || 0;
        const totalHemat = totalHargaNormalBarang - grandTotal;
        return totalHemat > 0 ? Math.round(totalHemat) : 0;
    };

    const totalSavings = calculateFinalSavings();
    const details = Array.isArray(transaction.details) ? transaction.details : [];

    // --- FUNGSI CETAK BLUETOOTH ---
    const handleBluetoothPrint = async () => {
        try {
            await printBluetooth(transaction, receiptSetting);
            Swal.fire({
                icon: 'success',
                title: 'Berhasil',
                text: 'Dokumen sedang dicetak via Bluetooth',
                timer: 2000,
                showConfirmButton: false,
                background: '#0f172a',
                color: '#fff'
            });
        } catch (error) {
            Swal.fire({
                icon: 'error',
                title: 'Gagal Cetak',
                text: error.message,
                background: '#0f172a',
                color: '#fff'
            });
        }
    };

    // --- FUNGSI WHATSAPP ---
    const sendWhatsApp = () => {
        let phone = transaction.customer?.phone || "";
        if (!phone) {
            Swal.fire({
                title: 'Nomor HP Pelanggan',
                text: "Masukkan nomor HP secara manual:",
                input: 'number',
                showCancelButton: true,
                confirmButtonText: 'Kirim WA',
                confirmButtonColor: '#10b981',
            }).then((result) => {
                if (result.isConfirmed && result.value) processWhatsApp(result.value);
            });
            return;
        }
        processWhatsApp(phone);
    };

    const processWhatsApp = (rawPhone) => {
        let phone = rawPhone.replace(/\D/g, "");
        if (phone.startsWith("0")) phone = "62" + phone.slice(1);
        else if (!phone.startsWith("62")) phone = "62" + phone;

        const shareLink = `${window.location.origin}/share/invoice/${transaction.invoice}`;
        const message = 
            `*STRUK DIGITAL ${storeName.toUpperCase()}*\n` +
            `Halo *${transaction.customer?.name || "Pelanggan"}*,\n` +
            `No. Invoice: #${transaction.invoice}\n` +
            `Total: *${formatPrice(transaction.grand_total)}*\n` +
            `Link Struk: ${shareLink}`;

        window.open(`https://wa.me/${phone}?text=${encodeURIComponent(message)}`, "_blank");
    };

    return (
        <>
            <Head title={`Invoice #${transaction.invoice}`} />

            <div className={`min-h-screen ${isPublic ? 'bg-white' : 'bg-slate-950'} text-slate-300 p-2 md:p-8 print:bg-white print:p-0`}>
                
                {!isPublic && (
                    <div className="max-w-5xl mx-auto mb-6 flex flex-wrap justify-between items-center gap-4 print:hidden">
                        <Link href={route("transactions.index")} className="flex items-center gap-2 text-slate-400 hover:text-white transition-colors bg-slate-900 px-4 py-2 rounded-xl border border-slate-800 shadow-lg font-bold text-xs uppercase tracking-widest">
                            <IconArrowLeft size={18} /> Kembali
                        </Link>
                        
                        <div className="flex bg-slate-900 p-1.5 rounded-2xl border border-slate-800">
                            {[
                                { id: "invoice", label: "A4", icon: IconFileInvoice }, 
                                { id: "thermal80", label: "80mm", icon: IconReceipt },
                                { id: "thermal58", label: "58mm", icon: IconReceipt }
                            ].map((mode) => (
                                <button key={mode.id} onClick={() => setPrintMode(mode.id)} className={`flex items-center gap-2 px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest transition-all ${printMode === mode.id ? "bg-emerald-600 text-white shadow-md" : "text-slate-500 hover:text-slate-200"}`}>
                                    <mode.icon size={16} /> {mode.label}
                                </button>
                            ))}
                        </div>

                        <div className="flex gap-2">
                            <button onClick={sendWhatsApp} className="flex items-center gap-2 px-4 py-2.5 bg-emerald-500 hover:bg-emerald-400 text-white font-black rounded-xl shadow-lg text-[10px] uppercase tracking-widest transition-all">
                                <IconBrandWhatsapp size={18} /> WA
                            </button>
                            <button onClick={handleBluetoothPrint} className="flex items-center gap-2 px-4 py-2.5 bg-blue-600 hover:bg-blue-500 text-white font-black rounded-xl shadow-lg text-[10px] uppercase tracking-widest transition-all">
                                <IconBluetooth size={18} /> BT
                            </button>
                            <button onClick={() => window.print()} className="flex items-center gap-2 px-4 py-2.5 bg-primary-600 hover:bg-primary-500 text-white font-black rounded-xl shadow-lg text-[10px] uppercase tracking-widest transition-all">
                                <IconPrinter size={18} /> BROWSER
                            </button>
                        </div>
                    </div>
                )}

                <div className="max-w-4xl mx-auto print:max-w-full">
                    <div className={`print-content bg-white ${isPublic ? '' : 'rounded-[2.5rem] shadow-2xl'} overflow-hidden print:shadow-none print:rounded-none`}>
                        
                        {printMode === "invoice" ? (
                            <div className="p-6 md:p-12 text-slate-800 bg-white min-h-[1000px] flex flex-col transition-colors relative">
                                {/* Header Invoice A4 */}
                                <div className="flex justify-between items-start mb-12">
                                    <div className="flex gap-4 items-start">
                                        {storeLogo ? <img src={storeLogo} className="w-20 h-20 object-contain" alt="store-logo" /> : <div className="w-16 h-16 bg-slate-100 rounded-2xl flex items-center justify-center text-slate-400"><IconBuildingStore size={32} /></div>}
                                        <div>
                                            <h1 className="text-2xl font-black uppercase tracking-tighter text-slate-900 leading-none">{storeName}</h1>
                                            <p className="text-[11px] text-slate-500 max-w-xs mt-2 leading-relaxed font-medium uppercase tracking-wide">{storeAddress}</p>
                                        </div>
                                    </div>
                                    <div className="text-right">
                                        <h2 className="text-5xl font-black text-emerald-500/10 leading-none mb-2 tracking-tighter uppercase italic">Invoice</h2>
                                        <div className="flex flex-col items-end gap-1">
                                            <span className="bg-emerald-600 text-white px-3 py-1 rounded-lg text-xs font-bold font-mono shadow-sm">#{transaction.invoice}</span>
                                            {transaction.queue_number && (
                                                <div className="flex items-center gap-1 text-slate-900 bg-slate-100 px-3 py-1 rounded-lg border border-slate-200 mt-1">
                                                    <IconTicket size={14} className="text-primary-600" />
                                                    <span className="text-sm font-black italic tracking-tight">#{transaction.queue_number}</span>
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                </div>

                                {/* Info Grid */}
                                <div className="grid grid-cols-2 md:grid-cols-4 gap-6 mb-12 border-y border-slate-100 py-6">
                                    <div><p className="text-[9px] font-black text-slate-400 uppercase mb-1 tracking-widest">Pelanggan</p><p className="text-sm font-bold text-slate-900 uppercase">{transaction.customer?.name || "UMUM"}</p></div>
                                    <div><p className="text-[9px] font-black text-slate-400 uppercase mb-1 tracking-widest">Kasir</p><p className="text-sm font-bold text-slate-700 uppercase">{transaction.cashier?.name}</p></div>
                                    <div><p className="text-[9px] font-black text-slate-400 uppercase mb-1 tracking-widest">Waktu</p><p className="text-sm font-bold text-slate-700">{new Date(transaction.created_at).toLocaleString('id-ID')}</p></div>
                                    <div><p className="text-[9px] font-black text-slate-400 uppercase mb-1 tracking-widest">Meja / Status</p><p className="text-sm font-bold text-emerald-600 font-black italic uppercase">{transaction.table_name || "BAWA PULANG"}</p></div>
                                </div>

                                {/* Table Items */}
                                <div className="flex-1 overflow-x-auto">
                                    <table className="w-full text-slate-800">
                                        <thead>
                                            <tr className="border-b-2 border-slate-900 text-left text-slate-900 uppercase">
                                                <th className="py-4 text-[10px] font-black tracking-widest">Produk</th>
                                                <th className="py-4 text-right text-[10px] font-black tracking-widest">Harga</th>
                                                <th className="py-4 text-center text-[10px] font-black tracking-widest">Jumlah</th>
                                                <th className="py-4 text-right text-[10px] font-black tracking-widest">Subtotal</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-100">
                                            {details.map((item, i) => {
                                                const unitPrice = item.price / item.qty;
                                                const currentUnit = item.unit || item.product_unit?.unit_name || "Pcs";
                                                const bundleItems = item.product?.bundle_items || [];
                                                
                                                return (
                                                    <tr key={i} className="align-top">
                                                        <td className="py-5">
                                                            <p className="font-bold text-slate-900 uppercase leading-tight mb-1 flex items-center gap-1">
                                                                {item.product?.title || item.product_title}
                                                                {item.product?.type === 'bundle' && <IconBoxSeam size={14} className="text-purple-500" />}
                                                                {item.price == 0 && <span className="text-[8px] bg-emerald-100 text-emerald-700 px-1.5 py-0.5 rounded-full uppercase ml-1 font-black">Gratis</span>}
                                                            </p>
                                                            
                                                            {/* LOGIKA MENAMPILKAN ISI BUNDLE DI INVOICE A4 */}
                                                            {item.product?.type === 'bundle' && bundleItems.length > 0 && (
                                                                <div className="mt-1 flex flex-col gap-0.5 border-l-2 border-slate-100 pl-3">
                                                                    {bundleItems.map((bi, idx) => (
                                                                        <p key={idx} className="text-[10px] text-slate-500 italic uppercase">
                                                                            • {bi.title} (x{parseFloat(bi.pivot?.qty || 1) * item.qty})
                                                                        </p>
                                                                    ))}
                                                                </div>
                                                            )}

                                                            <span className="text-[9px] font-black text-slate-400 uppercase tracking-tighter bg-slate-50 px-2 py-0.5 rounded border border-slate-100 inline-block mt-1">Unit: {currentUnit}</span>
                                                        </td>
                                                        <td className="py-5 text-right text-sm">
                                                            <span className="font-bold text-slate-700">{formatPrice(unitPrice)}</span>
                                                        </td>
                                                        <td className="py-5 text-center text-sm font-black text-slate-900">
                                                            {item.qty} <span className="text-[10px] text-slate-400 font-bold uppercase ml-0.5">{currentUnit}</span>
                                                        </td>
                                                        <td className="py-5 text-right text-sm font-black text-slate-900">{formatPrice(item.price)}</td>
                                                    </tr>
                                                );
                                            })}
                                        </tbody>
                                    </table>
                                </div>

                                {/* Summary Section */}
                                <div className="mt-12 flex justify-end">
                                    <div className="w-full md:w-96 bg-slate-900 p-6 rounded-[2.5rem] shadow-xl text-white">
                                        <div className="space-y-2 mb-6 border-b border-white/10 pb-4">
                                            {totalSavings > 0 && (
                                                <div className="flex justify-between items-center text-emerald-400 font-black italic uppercase text-[10px] tracking-widest">
                                                    <span className="flex items-center gap-1"><IconGift size={14}/> Anda Hemat</span>
                                                    <span>{formatPrice(totalSavings)}</span>
                                                </div>
                                            )}
                                        </div>

                                        <div className="flex justify-between items-center pt-2">
                                            <span className="text-[10px] font-black uppercase text-slate-400 tracking-widest">Grand Total</span>
                                            <span className="text-3xl font-black text-emerald-400 italic tracking-tighter">{formatPrice(transaction.grand_total)}</span>
                                        </div>

                                        <div className="mt-6 flex flex-col gap-2 text-slate-400 border-t border-white/5 pt-4 text-[10px] font-bold uppercase tracking-widest">
                                            <div className="flex justify-between"><span>Metode</span><span className="text-white italic">{transaction.payment_method}</span></div>
                                            <div className="flex justify-between"><span>Bayar</span><span className="text-white">{formatPrice(transaction.cash || transaction.grand_total)}</span></div>
                                            <div className="flex justify-between"><span>Kembali</span><span className="text-white">{formatPrice(transaction.change || 0)}</span></div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div className="mt-auto pt-10 text-center border-t border-slate-50 italic text-slate-400 text-xs">
                                    "{storeFooter}"
                                </div>
                            </div>
                        ) : (
                            <div className="bg-white p-4 flex justify-center min-h-[600px]">
                                {printMode === "thermal80" ? (
                                    <ThermalReceipt 
                                        transaction={transaction} 
                                        storeName={storeName} 
                                        storeAddress={storeAddress} 
                                        storePhone={storePhone} 
                                        footerMessage={storeFooter} 
                                        storeLogo={storeLogo}
                                        qrisImage={qrisImage}
                                    />
                                ) : (
                                    <ThermalReceipt58mm 
                                        transaction={transaction} 
                                        storeName={storeName} 
                                        storePhone={storePhone} 
                                        footerMessage={storeFooter} 
                                        storeLogo={storeLogo}
                                        qrisImage={qrisImage}
                                    />
                                )}
                            </div>
                        )}
                    </div>
                </div>
            </div>

            <style>{`
                @media print {
                    .print-content { width: 100%; margin: 0 !important; padding: 0 !important; }
                    body { background: white !important; }
                    .print-content > div { box-shadow: none !important; border-radius: 0 !important; }
                }
                ::-webkit-scrollbar { width: 5px; }
                ::-webkit-scrollbar-thumb { background: #334155; border-radius: 10px; }
            `}</style>
        </>
    );
}

Print.layout = (page) => page;