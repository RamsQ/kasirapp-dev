import React from "react";
import { Head, Link } from "@inertiajs/react";
import { QRCodeSVG } from "qrcode.react";
import { 
    IconArmchair, 
    IconScan, 
    IconClick, 
    IconToolsKitchen2, 
    IconPrinter,
    IconArrowLeft
} from "@tabler/icons-react";

export default function PrintQr({ tables, storeName, baseUrl }) {
    
    const handlePrint = () => {
        window.print();
    };

    return (
        <>
            <Head title={`Cetak QR Meja | ${storeName}`} />
            
            <div className="min-h-screen bg-slate-50 dark:bg-slate-950 flex flex-col items-center p-6 md:p-10 font-sans">
                
                {/* NAVIGATION (Hidden saat Print) */}
                <div className="fixed top-6 left-6 right-6 flex justify-between items-center print:hidden z-50">
                    <Link 
                        href={route('tables.index')}
                        className="flex items-center gap-2 px-5 py-2.5 bg-white dark:bg-slate-900 text-slate-600 dark:text-slate-400 rounded-2xl font-bold text-xs uppercase tracking-widest shadow-sm hover:bg-slate-100 transition-all border border-slate-200 dark:border-slate-800"
                    >
                        <IconArrowLeft size={18} /> Kembali
                    </Link>
                    
                    <button 
                        onClick={handlePrint}
                        className="flex items-center gap-2 px-6 py-3 bg-indigo-600 text-white rounded-2xl font-black text-xs uppercase tracking-[0.2em] shadow-xl shadow-indigo-500/20 hover:bg-indigo-700 transition-all active:scale-95"
                    >
                        <IconPrinter size={18} /> Cetak Semua Meja
                    </button>
                </div>

                {/* LOOPING KARTU MEJA */}
                <div className="flex flex-col gap-16 items-center w-full mt-16 print:mt-0 print:gap-0">
                    {tables.map((table) => (
                        <div key={table.id} className="page-break w-full max-w-[420px] bg-white rounded-[3.5rem] shadow-2xl overflow-hidden border-[12px] border-indigo-600 relative print:shadow-none print:border-[8px] mb-10 print:mb-0">
                            
                            {/* Header: Indigo Theme */}
                            <div className="bg-indigo-600 p-10 text-center text-white relative overflow-hidden">
                                <div className="absolute -right-4 -top-4 opacity-20">
                                    <IconArmchair size={120} stroke={1} />
                                </div>
                                
                                <div className="relative z-10">
                                    <div className="w-16 h-16 bg-white/20 rounded-[1.5rem] flex items-center justify-center mx-auto mb-5 backdrop-blur-md border border-white/10 shadow-inner">
                                        <IconArmchair size={36} stroke={2.5} />
                                    </div>
                                    <h1 className="text-4xl font-black uppercase tracking-tighter leading-none italic">
                                        {table.name}
                                    </h1>
                                    <p className="text-[10px] font-black uppercase tracking-[0.4em] opacity-80 mt-2">
                                        Dine-In Digital Menu
                                    </p>
                                </div>
                            </div>

                            {/* Body: QR Code & CTA */}
                            <div className="p-12 text-center flex flex-col items-center bg-white">
                                <div className="bg-indigo-50 p-7 rounded-[3rem] border-2 border-dashed border-indigo-200 relative">
                                    <div className="bg-white p-5 rounded-[2rem] shadow-sm">
                                        <QRCodeSVG 
                                            value={`${baseUrl}/${table.id}`} 
                                            size={220}
                                            level="H"
                                            includeMargin={false}
                                        />
                                    </div>
                                    <div className="absolute -bottom-4 left-1/2 -translate-x-1/2 bg-slate-900 text-white text-[9px] font-black px-6 py-2 rounded-full uppercase tracking-[0.2em] shadow-xl whitespace-nowrap">
                                        Scan di Meja
                                    </div>
                                </div>

                                <div className="mt-14 w-full">
                                    <p className="text-slate-400 text-[10px] font-black uppercase tracking-widest mb-6">3 Langkah Pesan</p>
                                    
                                    <div className="grid grid-cols-3 gap-4">
                                        <div className="flex flex-col items-center">
                                            <div className="w-10 h-10 rounded-2xl bg-indigo-100 text-indigo-600 flex items-center justify-center mb-2 shadow-sm border border-indigo-200/50">
                                                <IconScan size={20} />
                                            </div>
                                            <span className="text-[8px] font-black uppercase text-slate-500 leading-tight text-center">Scan<br/>Menu</span>
                                        </div>
                                        <div className="flex flex-col items-center">
                                            <div className="w-10 h-10 rounded-2xl bg-indigo-100 text-indigo-600 flex items-center justify-center mb-2 shadow-sm border border-indigo-200/50">
                                                <IconClick size={20} />
                                            </div>
                                            <span className="text-[8px] font-black uppercase text-slate-500 leading-tight text-center">Pilih<br/>Menu</span>
                                        </div>
                                        <div className="flex flex-col items-center">
                                            <div className="w-10 h-10 rounded-2xl bg-indigo-100 text-indigo-600 flex items-center justify-center mb-2 shadow-sm border border-indigo-200/50">
                                                <IconToolsKitchen2 size={20} />
                                            </div>
                                            <span className="text-[8px] font-black uppercase text-slate-500 leading-tight text-center">Pesanan<br/>Diantar</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {/* Footer */}
                            <div className="bg-slate-50 py-8 text-center border-t border-slate-100">
                                <div className="flex flex-col items-center gap-1">
                                    <span className="text-[9px] font-black text-slate-400 uppercase tracking-[0.3em]">Powered by</span>
                                    <h2 className="text-lg font-black text-slate-800 uppercase tracking-tighter italic">
                                        {storeName}
                                    </h2>
                                </div>
                            </div>
                        </div>
                    ))}
                </div>
            </div>

            {/* CSS Khusus untuk Resolusi Print */}
            <style>{`
                @media print {
                    body { background: white !important; }
                    .min-h-screen { padding: 0 !important; background: white !important; }
                    .page-break { 
                        page-break-after: always; 
                        margin-bottom: 0 !important; 
                        border-width: 8px !important;
                    }
                    @page { 
                        margin: 0.5cm; 
                        size: auto;
                    }
                    .fixed { display: none !important; }
                }
            `}</style>
        </>
    );
}