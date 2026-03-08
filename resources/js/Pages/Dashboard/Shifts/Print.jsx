import React, { useState, useEffect } from "react";
import { Head, Link } from "@inertiajs/react";
import { 
    IconArrowLeft, IconPrinter, IconUsb, IconReceipt, 
    IconInfoCircle, IconDeviceDesktop, IconCpu,
    IconBluetooth, IconLoader2, IconCash, IconQrcode, IconDeviceMobileVibration
} from "@tabler/icons-react";
import { printShiftUsbRaw } from "@/Utils/UsbRawPrinter"; 
import { smartPrint } from "@/Utils/BluetoothHybridService"; 
import toast, { Toaster } from "react-hot-toast";

export default function Print({ shift, receiptSetting, auto_print = false }) {
    if (!shift) return <div className="p-10 text-center text-white font-black uppercase tracking-widest">Memuat Data Shift...</div>;

    const [isBtPrinting, setIsBtPrinting] = useState(false);
    const C_WIDTH = 24; // Lebar karakter standar thermal 58mm

    // --- LOGIKA FORMAL FORMATTING ---
    const formatPrice = (price = 0) => 
        new Intl.NumberFormat("id-ID", { style: "currency", currency: "IDR", minimumFractionDigits: 0 })
            .format(price).replace("Rp", "").trim();

    // Helper untuk Printer RAW (Monospace String)
    const formatRowStr = (left, right) => {
        const lStr = left ? left.toString().trim() : "";
        const rStr = right ? right.toString().trim() : "";
        const spaceCount = C_WIDTH - (lStr.length + rStr.length);
        return lStr + " ".repeat(spaceCount > 0 ? spaceCount : 1) + rStr;
    };

    // Helper untuk Tampilan Web (Flexbox Rata Kanan)
    const FlexRow = ({ label, value, className = "" }) => (
        <div className={`flex justify-between items-end w-full gap-2 ${className}`}>
            <span className="text-left truncate uppercase">{label}</span>
            <span className="text-right font-black shrink-0">{value}</span>
        </div>
    );

    // --- MAPPING DATA KEUANGAN ---
    const startCash = parseFloat(shift.starting_cash || 0);
    const cashSales = parseFloat(shift.total_cash_sales || 0);
    const pettyCash = parseFloat(shift.total_expense || 0); // Kas Keluar
    const systemSaldo = (startCash + cashSales) - pettyCash; // Saldo seharusnya di laci

    // Data Non-Tunai (Informasi Digital)
    const totalQrisManual = parseFloat(shift.total_qris_sales || 0);
    const totalTransfer = parseFloat(shift.total_transfer_sales || 0);
    // TAMBAHKAN QRIS AUTO (MIDTRANS/XENDIT)
    const totalQrisAuto = parseFloat(shift.total_midtrans_sales || 0); 
    const totalDigital = totalQrisManual + totalTransfer + totalQrisAuto;

    // --- FUNGSI PRINT BLUETOOTH ---
    const handleBluetoothPrint = async () => {
        setIsBtPrinting(true);
        try {
            const dataForPrinter = {
                ...shift,
                petty_cash_out: pettyCash,
                total_qris_sales: totalQrisManual,
                total_transfer_sales: totalTransfer,
                total_midtrans_sales: totalQrisAuto // Kirim ke service printer
            };
            
            await smartPrint(dataForPrinter, receiptSetting, 'shift');
            toast.success('Laporan Dicetak!');
        } catch (error) {
            toast.error("Gagal cetak Bluetooth");
        } finally {
            setIsBtPrinting(false);
        }
    };

    return (
        <div className="min-h-screen bg-[#0f172a] font-sans text-slate-200 overflow-hidden flex flex-col md:flex-row print:bg-white print:block">
            <Head title={`Laporan Shift - ${shift.user?.name}`} />
            <Toaster />

            {/* SIDEBAR KONTROL */}
            <aside className="w-full md:w-80 bg-[#1e293b] border-r border-slate-700/50 p-6 flex flex-col justify-between print:hidden shrink-0 shadow-2xl">
                <div className="space-y-8">
                    <div className="flex items-center gap-3">
                        <div className="p-2.5 bg-indigo-600 rounded-xl shadow-lg shadow-indigo-500/20">
                            <IconReceipt className="text-white" size={24} />
                        </div>
                        <h1 className="text-lg font-black tracking-tighter text-white uppercase italic">Audit Terminal</h1>
                    </div>

                    <div className="space-y-6">
                        <section>
                            <p className="text-[10px] font-black uppercase tracking-[0.2em] text-slate-500 mb-4 flex items-center gap-2">
                                <IconInfoCircle size={14} /> Ringkasan Kas
                            </p>
                            <div className="space-y-3 bg-slate-950/50 p-5 rounded-2xl border border-slate-700/30 shadow-inner">
                                <FlexRow label="Selisih" value={shift.difference === 0 ? 'MATCH' : formatPrice(shift.difference)} className={shift.difference < 0 ? 'text-rose-400' : 'text-emerald-400'} />
                                <FlexRow label="Total Omzet" value={formatPrice(cashSales + totalDigital)} className="text-indigo-400 border-t border-slate-800 pt-2 mt-2" />
                            </div>
                        </section>

                        <section className="space-y-3">
                            <button 
                                onClick={handleBluetoothPrint}
                                disabled={isBtPrinting}
                                className="w-full bg-indigo-600 hover:bg-indigo-500 text-white py-4 rounded-[1.25rem] font-black text-xs tracking-widest flex items-center justify-center gap-3 transition-all active:scale-95 shadow-xl shadow-indigo-500/20"
                            >
                                {isBtPrinting ? <IconLoader2 size={24} className="animate-spin" /> : <IconBluetooth size={24} />}
                                {isBtPrinting ? "PRINTING..." : "PRINT THERMAL (BT)"}
                            </button>

                            <div className="grid grid-cols-2 gap-2">
                                <button onClick={() => printShiftUsbRaw(shift, receiptSetting)} className="bg-orange-500 hover:bg-orange-600 text-white py-3 rounded-xl font-bold text-[10px] flex items-center justify-center gap-2 active:scale-95 shadow-lg shadow-orange-500/20 uppercase tracking-widest">
                                    <IconUsb size={16} /> USB Raw
                                </button>
                                <button onClick={() => window.print()} className="bg-slate-700 hover:bg-slate-600 text-white py-3 rounded-xl font-bold text-[10px] flex items-center justify-center gap-2 active:scale-95 shadow-lg uppercase tracking-widest">
                                    <IconPrinter size={16} /> Browser
                                </button>
                            </div>
                        </section>
                    </div>
                </div>

                <Link 
                    href={route("transactions.index")}
                    className="w-full flex items-center justify-center gap-2 text-slate-500 hover:text-white py-6 text-xs font-black uppercase tracking-[0.2em] transition-colors border-t border-slate-700/50 mt-6"
                >
                    <IconArrowLeft size={18} /> Menu Utama
                </Link>
            </aside>

            {/* PREVIEW AREA */}
            <main className="flex-1 overflow-y-auto p-4 md:p-12 flex flex-col items-center justify-start print:p-0 print:block print:bg-white custom-scrollbar">
                <div className="mb-6 flex items-center gap-2 text-slate-500 uppercase font-black text-[10px] tracking-[0.3em] print:hidden">
                    <IconDeviceDesktop size={14} className="animate-pulse" /> Live Audit Preview
                </div>

                {/* THE RECEIPT */}
                <div className="relative group">
                    <div className="absolute -inset-1 bg-indigo-500/10 rounded-3xl blur opacity-25 group-hover:opacity-50 transition duration-1000 print:hidden"></div>
                    
                    <div className="relative bg-white shadow-2xl p-6 text-black font-mono text-[11px] w-[58mm] leading-tight print:shadow-none print:p-0 border border-slate-100">
                        
                        {/* HEADER */}
                        <header className="flex flex-col items-center text-center uppercase w-full mb-3">
                            <div className="font-black text-[14px] leading-tight mb-1">{receiptSetting?.store_name || "POS System"}</div>
                            <div className="text-[8px] leading-tight opacity-70 mb-3">{receiptSetting?.store_address}</div>
                            <div className="w-full border-b border-dashed border-black/30 mb-2"></div>
                            <div className="font-black tracking-tighter text-[12px]">LAPORAN SHIFT</div>
                            <div className="w-full border-b border-dashed border-black/30 mt-2"></div>
                        </header>

                        {/* METADATA */}
                        <div className="space-y-0.5 mb-3">
                            <FlexRow label="Kasir:" value={(shift.user?.name || "KASIR").split(' ')[0]} />
                            <FlexRow label="Buka:" value={(shift.opened_at || "").substring(11,16)} />
                            <FlexRow label="Tutup:" value={shift.closed_at ? shift.closed_at.substring(11,16) : 'AKTIF'} />
                        </div>
                        <div className="border-b border-dashed border-black/30 mb-3 w-full"></div>

                        {/* AUDIT TUNAI (LACI) */}
                        <div className="space-y-1">
                            <FlexRow label="Modal Awal" value={formatPrice(startCash)} />
                            <FlexRow label="Sales Tunai" value={formatPrice(cashSales)} />
                            <FlexRow label="Kas Keluar" value={`-${formatPrice(pettyCash)}`} className="text-slate-500 italic" />
                            <div className="border-b border-dotted border-black/20 my-1"></div>
                            <FlexRow label="Sistem Laci" value={formatPrice(systemSaldo)} className="font-black" />
                            <FlexRow label="Fisik Laci" value={formatPrice(shift.total_cash_actual)} className="font-black" />
                        </div>

                        <div className="bg-slate-50 p-2 my-3 rounded-lg border border-black/5">
                            <FlexRow label="SELISIH" value={formatPrice(shift.difference)} className="text-[12px] font-black" />
                        </div>

                        <div className="border-b border-dashed border-black/30 mb-3 w-full"></div>

                        {/* DIGITAL BREAKDOWN (RINGKAS: QRIS DIGABUNG) */}
                        <div className="space-y-1.5 pb-4">
                        <div className="text-[9px] font-black uppercase opacity-40 mb-1 tracking-widest">Informasi Non-Tunai</div>
    
                        {/* Menggabungkan Nilai Auto + Manual */}
                        <FlexRow label="QRIS AUTO/STATIS" value={formatPrice(totalQrisAuto + totalQrisManual)} />
    
                        <FlexRow label="TRANSFER BANK" value={formatPrice(totalTransfer)} />
    
                        <div className="border-b border-dotted border-black/20 my-1"></div>
                        <FlexRow label="TOTAL DIGITAL" value={formatPrice(totalDigital)} className="font-black" />
                        </div>

                        {/* FOOTER & VALIDASI */}
                        <footer className="text-center mt-6 uppercase border-t border-dashed border-black/30 pt-6">
                            <div className="flex flex-col items-center">
                                <div className="text-[9px] font-black mb-10 tracking-widest italic opacity-40 italic">Verifikasi Kasir</div>
                                <div className="w-32 h-[0.5px] bg-black/40 mb-1"></div>
                                <span className="font-black text-[11px]">{shift.user?.name.toUpperCase()}</span>
                                <span className="text-[8px] opacity-50 mt-1 italic tracking-tighter">
                                    Dicetak: {new Date().toLocaleTimeString('id-ID', {hour12: false}).substring(0,5)} WIB
                                </span>
                            </div>
                        </footer>

                        {/* Ornamen Gunting Thermal */}
                        <div className="mt-8 flex justify-center print:hidden">
                            <div className="w-full h-3 opacity-10" style={{ backgroundImage: 'radial-gradient(circle, #000 1px, transparent 1px)', backgroundSize: '4px 4px' }}></div>
                        </div>
                    </div>
                </div>
            </main>

            <style dangerouslySetInnerHTML={{ __html: `
                .custom-scrollbar::-webkit-scrollbar { width: 4px; }
                .custom-scrollbar::-webkit-scrollbar-thumb { background: #334155; border-radius: 10px; }
                @media print {
                    @page { margin: 0; size: 58mm auto; }
                    body { background: white !important; -webkit-print-color-adjust: exact; }
                    .print\\:hidden, aside { display: none !important; }
                    main { padding: 0 !important; overflow: visible !important; width: 100% !important; }
                    .shadow-2xl, .group { box-shadow: none !important; }
                }
            ` }} />
        </div>
    );
}