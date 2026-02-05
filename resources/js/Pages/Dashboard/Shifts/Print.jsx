import React, { useState, useEffect } from "react";
import { Head, Link, router } from "@inertiajs/react";
import { 
    IconArrowLeft, IconPrinter, IconCash, IconClock, 
    IconBuildingStore, IconReceipt, IconCalendar, IconUser,
    IconDeviceFloppy, IconScale, IconLogout
} from "@tabler/icons-react";

export default function Print({ shift, receiptSetting, auto_print = false }) {
    if (!shift) return <div className="p-10 text-center">Data Shift tidak ditemukan...</div>;

    // State untuk ukuran kertas (Default 80mm)
    const [paperSize, setPaperSize] = useState("80");

    const storeName = receiptSetting?.store_name || "TOKO POS";
    const storeAddress = receiptSetting?.store_address || "Alamat belum diatur";

    const formatPrice = (price = 0) => 
        new Intl.NumberFormat("id-ID", { style: "currency", currency: "IDR", minimumFractionDigits: 0 }).format(price || 0);

    // --- LOGIKA AUTO PRINT ---
    useEffect(() => {
        if (auto_print) {
            const timer = setTimeout(() => {
                window.print();
            }, 1000);
            return () => clearTimeout(timer);
        }
    }, [auto_print]);

    // --- FUNGSI LOGOUT FINAL ---
    const handleLogout = () => {
        router.post(route('logout'));
    };

    return (
        <>
            <Head title={`Print Laporan Shift - ${shift.user?.name}`} />

            <div className="min-h-screen bg-slate-950 text-slate-300 p-4 md:p-8 print:bg-white print:p-0">
                
                {/* --- NAVIGATION CONTROLS --- */}
                <div className="max-w-xl mx-auto mb-6 flex flex-col gap-4 print:hidden">
                    <div className="flex justify-between items-center">
                        <Link href={route("transactions.index")} className="flex items-center gap-2 text-slate-400 hover:text-white transition-colors bg-slate-900 px-4 py-2 rounded-xl border border-slate-800 font-bold text-xs uppercase tracking-widest">
                            <IconArrowLeft size={18} /> Kasir
                        </Link>
                        
                        <div className="flex gap-2">
                            <button onClick={() => window.print()} className="flex items-center gap-2 px-6 py-2 bg-emerald-600 hover:bg-emerald-500 text-white font-black rounded-xl shadow-lg active:scale-95 text-xs uppercase tracking-widest">
                                <IconPrinter size={20} /> CETAK
                            </button>
                            <button onClick={handleLogout} className="flex items-center gap-2 px-6 py-2 bg-red-600 hover:bg-red-500 text-white font-black rounded-xl shadow-lg active:scale-95 text-xs uppercase tracking-widest">
                                <IconLogout size={20} /> LOGOUT
                            </button>
                        </div>
                    </div>

                    <div className="bg-slate-900 p-1.5 rounded-2xl border border-slate-800 flex items-center justify-between">
                        <span className="text-[10px] font-black px-3 uppercase tracking-widest text-slate-500">Ukuran Kertas:</span>
                        <div className="flex gap-1">
                            {["80", "58"].map((size) => (
                                <button 
                                    key={size}
                                    onClick={() => setPaperSize(size)} 
                                    className={`px-6 py-2 rounded-xl text-[10px] font-black uppercase transition-all ${paperSize === size ? "bg-primary-600 text-white shadow-md" : "text-slate-500 hover:text-slate-200"}`}
                                >
                                    {size}mm
                                </button>
                            ))}
                        </div>
                    </div>
                </div>

                {/* --- AREA STRUK THERMAL --- */}
                <div className="flex justify-center pb-20 print:pb-0">
                    <div 
                        className={`bg-white text-black p-6 shadow-2xl print:shadow-none transition-all duration-300 rounded-[2rem] print:rounded-none ${paperSize === "80" ? "w-[80mm]" : "w-[58mm]"}`}
                        id="receipt-content"
                    >
                        {/* Header Toko */}
                        <div className="text-center mb-6">
                            <h2 className="font-black text-xl uppercase leading-tight tracking-tighter">{storeName}</h2>
                            <p className="text-[10px] font-medium leading-tight mt-2 opacity-70 uppercase tracking-wide">{storeAddress}</p>
                            <div className="border-b-2 border-black border-dashed my-4"></div>
                            <h3 className="font-black text-xs uppercase tracking-[0.2em]">Laporan Sesi Kasir</h3>
                        </div>

                        {/* Info Shift */}
                        <div className="text-[11px] space-y-1.5 mb-4 font-medium uppercase tracking-tight">
                            <div className="flex justify-between"><span>Kasir:</span><span className="font-black">{shift.user?.name}</span></div>
                            <div className="flex justify-between text-slate-600"><span>Mulai:</span><span>{new Date(shift.opened_at).toLocaleString('id-ID', {day:'2-digit', month:'short', year:'numeric', hour:'2-digit', minute:'2-digit'})}</span></div>
                            <div className="flex justify-between text-slate-600"><span>Tutup:</span><span>{new Date(shift.closed_at).toLocaleString('id-ID', {day:'2-digit', month:'short', year:'numeric', hour:'2-digit', minute:'2-digit'})}</span></div>
                        </div>

                        <div className="border-b border-black border-dashed my-4"></div>

                        {/* Rincian Saldo */}
                        <div className="text-[12px] space-y-3">
                            <div className="flex justify-between">
                                <span className="font-bold uppercase tracking-tighter">Modal Awal:</span>
                                <span className="font-mono">{formatPrice(shift.starting_cash)}</span>
                            </div>
                            <div className="flex justify-between">
                                <span className="font-bold uppercase tracking-tighter">Penjualan Tunai:</span>
                                <span className="font-mono">{formatPrice(shift.total_cash_expected)}</span>
                            </div>
                            <div className="flex justify-between text-red-600 italic">
                                <span className="font-bold uppercase tracking-tighter">Kas Keluar:</span>
                                <span className="font-mono">-{formatPrice(shift.total_expense || 0)}</span>
                            </div>
                            
                            <div className="bg-slate-100 p-2 rounded-lg space-y-1">
                                <div className="flex justify-between font-black text-xs uppercase pt-1">
                                    <span>Saldo Sistem:</span>
                                    <span className="font-mono">{formatPrice(parseFloat(shift.starting_cash) + parseFloat(shift.total_cash_expected) - parseFloat(shift.total_expense || 0))}</span>
                                </div>
                                <div className="flex justify-between font-black text-sm pt-1 border-t border-black/10">
                                    <span>Uang Fisik:</span>
                                    <span className="font-mono">{formatPrice(shift.total_cash_actual)}</span>
                                </div>
                            </div>
                        </div>

                        <div className="border-b-2 border-black border-dashed my-5"></div>

                        {/* Selisih & QRIS */}
                        <div className="space-y-4">
                            <div className="text-center bg-black text-white py-3 rounded-xl">
                                <p className="text-[10px] font-bold uppercase tracking-widest opacity-70">Selisih Kas</p>
                                <p className={`text-xl font-black ${shift.difference < 0 ? 'text-red-400' : 'text-emerald-400'}`}>
                                    {formatPrice(shift.difference)}
                                </p>
                            </div>
                            
                            <div className="flex justify-between font-bold text-[11px] uppercase italic border-2 border-dashed border-black/20 p-2 rounded-lg">
                                <span>Total QRIS (Non-Tunai):</span>
                                <span className="font-mono">{formatPrice(shift.total_qris_sales)}</span>
                            </div>
                        </div>

                        {/* Footer Struk */}
                        <div className="text-center text-[10px] mt-8 space-y-6">
                            <p className="italic font-medium opacity-60">Laporan ini sah sebagai bukti audit operasional kasir.</p>
                            
                            {/* Signature Area */}
                            <div className="grid grid-cols-2 gap-8 pt-4">
                                <div className="space-y-10">
                                    <div className="border-b border-black w-full"></div>
                                    <p className="font-black uppercase tracking-widest text-[9px]">Kasir</p>
                                </div>
                                <div className="space-y-10">
                                    <div className="border-b border-black w-full"></div>
                                    <p className="font-black uppercase tracking-widest text-[9px]">Manager</p>
                                </div>
                            </div>

                            <p className="pt-6 font-black uppercase tracking-tighter opacity-40">
                                Dicetak: {new Date().toLocaleString('id-ID')}
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <style dangerouslySetInnerHTML={{ __html: `
                @media print {
                    body { background: white !important; margin: 0; padding: 0; }
                    #receipt-content { 
                        width: ${paperSize}mm !important; 
                        margin: 0 auto;
                        padding: 2mm !important;
                        box-shadow: none !important;
                        border: none !important;
                    }
                    .print\\:hidden { display: none !important; }
                }
                #receipt-content { overflow: hidden; }
            ` }} />
        </>
    );
}