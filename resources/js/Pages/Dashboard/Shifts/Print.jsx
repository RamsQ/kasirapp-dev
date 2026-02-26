import React, { useState, useEffect } from "react";
import { Head, Link, router } from "@inertiajs/react";
import { 
    IconArrowLeft, IconPrinter, IconUsb, IconLogout, 
    IconReceipt, IconInfoCircle, IconDeviceDesktop, IconCpu,
    IconBluetooth, IconLoader2, IconQrcode, IconCreditCard, IconCash
} from "@tabler/icons-react";
import { printShiftUsbRaw } from "@/Utils/UsbRawPrinter"; 
import { smartPrint } from "@/Utils/BluetoothHybridService"; 
import toast from "react-hot-toast";

export default function Print({ shift, receiptSetting, auto_print = false }) {
    if (!shift) return <div className="p-10 text-center text-white">Data Shift tidak ditemukan...</div>;

    const [isBtPrinting, setIsBtPrinting] = useState(false);
    const C_WIDTH = 26; 

    // --- LOGIKA PERHITUNGAN ---
    const formatRow = (left, right) => {
        const lStr = left ? left.toString().trim() : "";
        const rStr = right ? right.toString().trim() : "";
        const spaceCount = C_WIDTH - (lStr.length + rStr.length);
        return lStr + " ".repeat(spaceCount > 0 ? spaceCount : 1) + rStr;
    };

    const formatPrice = (price = 0) => 
        new Intl.NumberFormat("id-ID", { style: "currency", currency: "IDR", minimumFractionDigits: 0 })
            .format(price).replace("Rp", "").trim();

    // Mapping data agar selaras dengan audit laci fisik (hanya tunai)
    const cashSales = parseFloat(shift.total_cash_sales || 0);
    const pettyCash = parseFloat(shift.total_expense || 0); // Kas Keluar
    const startCash = parseFloat(shift.starting_cash || 0);
    
    // Saldo seharusnya di laci (Modal + Penjualan Tunai - Pengeluaran)
    const systemSaldo = (startCash + cashSales) - pettyCash;

    // Data Non-Tunai (Informasi Digital)
    const totalQris = parseFloat(shift.total_qris_sales || 0);
    const totalTransfer = parseFloat(shift.total_transfer_sales || 0);

    // --- FUNGSI PRINT BLUETOOTH (HYBRID) ---
    const handleBluetoothPrint = async () => {
        setIsBtPrinting(true);
        try {
            // Data disesuaikan agar encoder di BluetoothHybridService bisa membaca field digital baru
            const dataForPrinter = {
                ...shift,
                petty_cash_out: pettyCash,
                total_qris_sales: totalQris,
                total_transfer_sales: totalTransfer
            };
            
            await toast.promise(smartPrint(dataForPrinter, receiptSetting, 'shift'), {
                loading: 'Menghubungkan ke Printer...',
                success: 'Laporan Berhasil Dicetak!',
                error: (err) => `Gagal: ${err}`
            });
        } catch (error) {
            console.error(error);
        } finally {
            setIsBtPrinting(false);
        }
    };

    // --- AUTO PRINT EFFECT ---
    useEffect(() => {
        if (auto_print) {
            const timer = setTimeout(() => {
                const isAPK = typeof window !== 'undefined' && !!window.bluetoothSerial;
                if (isAPK) {
                    handleBluetoothPrint();
                } else {
                    window.print();
                }
            }, 1000);
            return () => clearTimeout(timer);
        }
    }, [auto_print]);

    return (
        <div className="min-h-screen bg-[#0f172a] font-sans text-slate-200 overflow-hidden flex flex-col md:flex-row print:bg-white print:block">
            <Head title={`Laporan Sesi - ${shift.user?.name}`} />

            {/* SIDEBAR KONTROL (Hidden on Print) */}
            <aside className="w-full md:w-80 bg-[#1e293b] border-r border-slate-700/50 p-6 flex flex-col justify-between print:hidden shrink-0 shadow-2xl">
                <div>
                    <div className="flex items-center gap-3 mb-10">
                        <div className="p-2 bg-indigo-500 rounded-lg shadow-lg shadow-indigo-500/20">
                            <IconReceipt className="text-white" size={24} />
                        </div>
                        <h1 className="text-lg font-bold tracking-tight text-white uppercase italic tracking-tighter">Print Terminal</h1>
                    </div>

                    <nav className="space-y-6">
                        <section>
                            <p className="text-[10px] font-bold uppercase tracking-[0.2em] text-slate-500 mb-4 flex items-center gap-2">
                                <IconInfoCircle size={14} /> Ringkasan Audit
                            </p>
                            <div className="space-y-3 bg-slate-900/50 p-4 rounded-xl border border-slate-700/30 shadow-inner leading-tight">
                                <div className="flex justify-between text-xs">
                                    <span className="text-slate-500 uppercase font-black text-[9px]">Selisih Laci</span>
                                    <span className={`font-mono font-black ${shift.difference < 0 ? 'text-red-400' : 'text-green-400'}`}>
                                        {shift.difference === 0 ? 'MATCH' : formatPrice(shift.difference)}
                                    </span>
                                </div>
                                <div className="flex justify-between text-xs pt-2 border-t border-slate-800">
                                    <span className="text-slate-500 uppercase font-black text-[9px]">Total Digital</span>
                                    <span className="text-indigo-400 font-black">{formatPrice(totalQris + totalTransfer)}</span>
                                </div>
                            </div>
                        </section>

                        <section className="space-y-3">
                            <p className="text-[10px] font-bold uppercase tracking-[0.2em] text-slate-500 mb-4 flex items-center gap-2">
                                <IconCpu size={14} /> Metode Cetak
                            </p>
                            
                            <button 
                                onClick={handleBluetoothPrint}
                                disabled={isBtPrinting}
                                className="w-full bg-indigo-600 hover:bg-indigo-500 text-white py-4 rounded-2xl font-black text-sm flex items-center justify-center gap-3 transition-all active:scale-95 shadow-lg shadow-indigo-500/20 disabled:opacity-50"
                            >
                                {isBtPrinting ? <IconLoader2 size={24} className="animate-spin" /> : <IconBluetooth size={24} />}
                                {isBtPrinting ? "KONEKSI..." : "BT THERMAL PRINT"}
                            </button>

                            <div className="grid grid-cols-2 gap-2">
                                <button 
                                    onClick={() => printShiftUsbRaw(shift, receiptSetting)}
                                    className="bg-orange-500 hover:bg-orange-600 text-white py-3 rounded-xl font-bold text-[10px] flex items-center justify-center gap-2 transition-all active:scale-95 shadow-lg shadow-orange-500/20"
                                >
                                    <IconUsb size={18} /> USB RAW
                                </button>
                                <button 
                                    onClick={() => window.print()}
                                    className="bg-slate-700 hover:bg-slate-600 text-white py-3 rounded-xl font-bold text-[10px] flex items-center justify-center gap-2 transition-all active:scale-95 shadow-lg"
                                >
                                    <IconPrinter size={18} /> BROWSER
                                </button>
                            </div>
                        </section>
                    </nav>
                </div>

                <div className="space-y-3 pt-6 border-t border-slate-700/50">
                    <Link 
                        href={route("transactions.index")}
                        className="w-full flex items-center justify-center gap-2 text-slate-400 hover:text-white py-2 text-sm font-medium transition-colors uppercase tracking-widest text-[10px]"
                    >
                        <IconArrowLeft size={18} /> Menu Kasir
                    </Link>
                </div>
            </aside>

            {/* PREVIEW AREA */}
            <main className="flex-1 overflow-y-auto p-4 md:p-12 flex flex-col items-center justify-start print:p-0 print:block print:bg-white custom-scrollbar">
                <div className="mb-6 flex items-center gap-2 text-slate-500 uppercase font-bold text-[10px] tracking-widest print:hidden">
                    <IconDeviceDesktop size={14} /> Live Struk Preview
                </div>

                {/* THE RECEIPT (Digital Twin) */}
                <div className="relative group">
                    <div className="absolute -inset-1 bg-white/5 rounded-lg blur opacity-25 group-hover:opacity-50 transition duration-1000 print:hidden"></div>
                    
                    <div className="relative bg-white shadow-[0_20px_50px_rgba(0,0,0,0.3)] p-5 text-black font-mono text-[11px] w-[52mm] leading-tight print:shadow-none print:p-0 overflow-hidden border border-slate-100">
                        
                        {/* HEADER */}
                        <header className="flex flex-col items-center text-center uppercase w-full mb-2">
                            <div className="font-black text-[13px] leading-tight mb-1">{receiptSetting?.store_name || "Toko Anda"}</div>
                            <div className="text-[8px] leading-tight opacity-70 mb-2">{receiptSetting?.store_address || "Alamat Toko"}</div>
                            <div className="w-full text-center opacity-30">{"-".repeat(C_WIDTH)}</div>
                            <div className="font-black my-1 tracking-tighter">LAPORAN TUTUP SHIFT</div>
                            <div className="w-full text-center opacity-30">{"-".repeat(C_WIDTH)}</div>
                        </header>

                        {/* METADATA SHIFT */}
                        <div className="whitespace-pre-wrap font-mono uppercase leading-tight w-full">
                            {formatRow("KASIR:", (shift.user?.name || "KASIR").split(' ')[0].toUpperCase()) + "\n"}
                            {formatRow("MULAI:", (shift.opened_at || "").substring(0,10)) + "\n"}
                            {formatRow("JAM:", (shift.opened_at || "").substring(11,16)) + "\n"}
                            {formatRow("TUTUP:", shift.closed_at ? shift.closed_at.substring(11,16) : 'AKTIF') + "\n"}
                            <span className="opacity-30">{"-".repeat(C_WIDTH) + "\n"}</span>

                            {/* RINCIAN SALDO FISIK (Hanya Tunai) */}
                            <div className="py-1">
                                {formatRow("MODAL AWAL", formatPrice(startCash)) + "\n"}
                                {formatRow("SALES TUNAI", formatPrice(cashSales)) + "\n"}
                                {formatRow("KAS KELUAR", "-" + formatPrice(pettyCash)) + "\n"}
                            </div>
                            <span className="opacity-30">{".".repeat(C_WIDTH) + "\n"}</span>
                            
                            <div className="font-black py-1">
                                {formatRow("SISTEM LACI", formatPrice(systemSaldo)) + "\n"}
                                {formatRow("FISIK LACI", formatPrice(shift.total_cash_actual)) + "\n"}
                            </div>
                            <span className="opacity-30">{"-".repeat(C_WIDTH) + "\n"}</span>

                            {/* HASIL AKHIR AUDIT */}
                            <div className="font-black text-[11px] py-1">
                                {formatRow("SELISIH", formatPrice(shift.difference)) + "\n"}
                            </div>
                            <span className="opacity-30">{"-".repeat(C_WIDTH) + "\n"}</span>

                            {/* SALDO DIGITAL (Hanya Informasi) */}
                            <div className="pt-1 pb-2">
                                <div className="text-[9px] italic opacity-60 mb-1">Informasi Digital (Bank):</div>
                                {formatRow("QRIS STATIS", formatPrice(totalQris)) + "\n"}
                                {formatRow("TRANSFER", formatPrice(totalTransfer)) + "\n"}
                                <span className="opacity-30">{".".repeat(C_WIDTH) + "\n"}</span>
                                <div className="font-bold">
                                    {formatRow("TOTAL DIGITAL", formatPrice(totalQris + totalTransfer))}
                                </div>
                            </div>
                            <span className="opacity-30">{"-".repeat(C_WIDTH) + "\n"}</span>
                        </div>

                        {/* FOOTER & TANDA TANGAN */}
                        <footer className="text-center mt-6 uppercase font-mono">
                            <div className="text-[9px] font-bold tracking-widest mb-10 text-slate-400 italic">Pernyataan Kasir</div>
                            
                            <div className="flex flex-col items-center">
                                <div className="w-24 h-[0.5px] bg-black mb-1"></div>
                                <span className="font-black text-[11px]">( KASIR )</span>
                                <span className="text-[8px] mt-1 opacity-60 font-bold italic">{shift.user?.name.toUpperCase()}</span>
                            </div>
                            
                            <div className="text-[8px] mt-12 opacity-30 border-t border-dashed border-black/10 pt-4">
                                WAKTU CETAK: {new Date().toLocaleString('id-ID', {hour12: false}).substring(0,16)}
                                <br />
                                <span className="lowercase italic tracking-tighter opacity-50">#shift_audit_v1.6_80mm</span>
                            </div>
                        </footer>

                        <div className="mt-4 flex justify-center print:hidden">
                            <div className="w-full h-2 bg-slate-50" style={{ clipPath: 'polygon(0% 0%, 5% 100%, 10% 0%, 15% 100%, 20% 0%, 25% 100%, 30% 0%, 35% 100%, 40% 0%, 45% 100%, 50% 0%, 55% 100%, 60% 0%, 65% 100%, 70% 0%, 75% 100%, 80% 0%, 85% 100%, 90% 0%, 95% 100%, 100% 0%)' }}></div>
                        </div>
                    </div>
                </div>
            </main>

            <style dangerouslySetInnerHTML={{ __html: `
                .custom-scrollbar::-webkit-scrollbar { width: 4px; }
                .custom-scrollbar::-webkit-scrollbar-thumb { background: #334155; border-radius: 10px; }
                @media print {
                    @page { margin: 0; size: auto; }
                    body { margin: 0; padding: 0; background: white !important; }
                    .print\\:hidden, aside { display: none !important; }
                    main { padding: 0 !important; overflow: visible !important; width: 100% !important; }
                    .shadow-2xl, .shadow-xl { box-shadow: none !important; }
                    pre { white-space: pre-wrap !important; }
                    .border { border: none !important; }
                }
            ` }} />
        </div>
    );
}