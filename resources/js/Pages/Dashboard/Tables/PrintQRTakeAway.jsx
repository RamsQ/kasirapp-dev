import React from "react";
import { Head } from "@inertiajs/react";
import { QRCodeSVG } from "qrcode.react";
import { 
    IconShoppingCart, 
    IconScan, 
    IconClick, 
    IconPackage, 
    IconPrinter,
    IconArrowLeft
} from "@tabler/icons-react";

const PrintQRTakeAway = () => {
    const appUrl = window.location.origin;
    const takeAwayUrl = `${appUrl}/menu?type=takeaway`;

    return (
        <>
            <Head title="Cetak QR Take Away | POS SYSTEM AJA" />
            
            <div className="min-h-screen bg-slate-50 dark:bg-slate-950 flex flex-col items-center justify-center p-6 md:p-10 font-sans">
                
                {/* TOMBOL KEMBALI & CETAK (Hidden saat Print) */}
                <div className="fixed top-6 left-6 right-6 flex justify-between items-center print:hidden">
                    <button 
                        onClick={() => window.history.back()}
                        className="flex items-center gap-2 px-5 py-2.5 bg-white dark:bg-slate-900 text-slate-600 dark:text-slate-400 rounded-2xl font-bold text-xs uppercase tracking-widest shadow-sm hover:bg-slate-100 transition-all border border-slate-200 dark:border-slate-800"
                    >
                        <IconArrowLeft size={18} /> Kembali
                    </button>
                    
                    <button 
                        onClick={() => window.print()}
                        className="flex items-center gap-2 px-6 py-3 bg-emerald-600 text-white rounded-2xl font-black text-xs uppercase tracking-[0.2em] shadow-xl shadow-emerald-500/20 hover:bg-emerald-700 transition-all active:scale-95"
                    >
                        <IconPrinter size={18} /> Cetak QR Stand
                    </button>
                </div>

                {/* KARTU QR (Area Utama Cetak) */}
                <div className="w-full max-w-[420px] bg-white rounded-[3.5rem] shadow-2xl overflow-hidden border-[12px] border-emerald-500 relative print:shadow-none print:border-[8px]">
                    
                    {/* Header: Brand & Label */}
                    <div className="bg-emerald-500 p-10 text-center text-white relative overflow-hidden">
                        {/* Ornamen Background */}
                        <div className="absolute -right-4 -top-4 opacity-20">
                            <IconShoppingCart size={120} stroke={1} />
                        </div>
                        
                        <div className="relative z-10">
                            <div className="w-16 h-16 bg-white/20 rounded-[1.5rem] flex items-center justify-center mx-auto mb-5 backdrop-blur-md border border-white/10 shadow-inner">
                                <IconShoppingCart size={36} stroke={2.5} />
                            </div>
                            <h1 className="text-4xl font-black uppercase tracking-tighter leading-none italic">
                                TAKE AWAY
                            </h1>
                            <p className="text-[10px] font-black uppercase tracking-[0.4em] opacity-80 mt-2">
                                Digital Menu Order
                            </p>
                        </div>
                    </div>

                    {/* Body: QR Code & Call to Action */}
                    <div className="p-12 text-center flex flex-col items-center bg-white">
                        <div className="bg-emerald-50 p-7 rounded-[3rem] border-2 border-dashed border-emerald-200 relative">
                            <div className="bg-white p-5 rounded-[2rem] shadow-sm">
                                <QRCodeSVG 
                                    value={takeAwayUrl} 
                                    size={220}
                                    level="H" // High Error Correction
                                    includeMargin={false}
                                />
                            </div>
                            {/* Floating Badge */}
                            <div className="absolute -bottom-4 left-1/2 -translate-x-1/2 bg-slate-900 text-white text-[9px] font-black px-6 py-2 rounded-full uppercase tracking-[0.2em] shadow-xl whitespace-nowrap">
                                Scan Untuk Pesan
                            </div>
                        </div>

                        <div className="mt-14 w-full">
                            <p className="text-slate-400 text-[10px] font-black uppercase tracking-widest mb-6">3 Langkah Mudah</p>
                            
                            <div className="grid grid-cols-3 gap-4">
                                <div className="flex flex-col items-center">
                                    <div className="w-10 h-10 rounded-2xl bg-emerald-100 text-emerald-600 flex items-center justify-center mb-2 shadow-sm border border-emerald-200/50">
                                        <IconScan size={20} />
                                    </div>
                                    <span className="text-[8px] font-black uppercase text-slate-500 leading-tight">Scan<br/>Menu</span>
                                </div>
                                <div className="flex flex-col items-center">
                                    <div className="w-10 h-10 rounded-2xl bg-emerald-100 text-emerald-600 flex items-center justify-center mb-2 shadow-sm border border-emerald-200/50">
                                        <IconClick size={20} />
                                    </div>
                                    <span className="text-[8px] font-black uppercase text-slate-500 leading-tight">Pilih<br/>Menu</span>
                                </div>
                                <div className="flex flex-col items-center">
                                    <div className="w-10 h-10 rounded-2xl bg-emerald-100 text-emerald-600 flex items-center justify-center mb-2 shadow-sm border border-emerald-200/50">
                                        <IconPackage size={20} />
                                    </div>
                                    <span className="text-[8px] font-black uppercase text-slate-500 leading-tight">Ambil<br/>Pesanan</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Footer: Brand POS */}
                    <div className="bg-slate-50 py-8 text-center border-t border-slate-100">
                        <div className="flex flex-col items-center gap-1">
                            <span className="text-[9px] font-black text-slate-400 uppercase tracking-[0.3em]">Powered by</span>
                            <h2 className="text-lg font-black text-slate-800 uppercase tracking-tighter italic">
                                POS SYSTEM <span className="text-emerald-600">AJA</span>
                            </h2>
                        </div>
                    </div>

                    {/* Background Accents (Sembunyi saat Print agar hemat tinta) */}
                    <div className="absolute -left-10 top-1/2 w-32 h-32 bg-emerald-500/5 rounded-full blur-3xl print:hidden"></div>
                    <div className="absolute -right-10 bottom-20 w-32 h-32 bg-emerald-500/5 rounded-full blur-3xl print:hidden"></div>
                </div>

                {/* Footer Info Cetak */}
                <p className="mt-8 text-slate-400 text-[10px] font-medium uppercase tracking-[0.2em] print:hidden">
                    Disarankan mencetak dengan kertas Photo/Art Paper untuk hasil maksimal.
                </p>
            </div>

            <style>{`
                @media print {
                    body { background: white !important; }
                    .min-h-screen { padding: 0 !important; background: white !important; }
                    @page { margin: 0; }
                }
            `}</style>
        </>
    );
};

export default PrintQRTakeAway;