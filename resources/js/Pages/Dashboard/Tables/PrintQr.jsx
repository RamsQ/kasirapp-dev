import React from "react";
import { Head, Link } from "@inertiajs/react";
import { IconPrinter, IconArrowLeft, IconQrcode } from "@tabler/icons-react";

export default function PrintQr({ tables, storeName, baseUrl }) {
    
    const handlePrint = () => {
        window.print();
    };

    return (
        <div className="min-h-screen bg-slate-100 p-8 print:bg-white print:p-0 font-sans">
            <Head title="Cetak QR Meja" />
            
            {/* Navigasi - Hilang saat print */}
            <div className="max-w-5xl mx-auto mb-8 flex justify-between items-center print:hidden">
                <Link href={route('tables.index')} className="flex items-center gap-2 text-slate-600 font-bold uppercase text-xs hover:text-sky-600 transition-colors">
                    <IconArrowLeft size={18}/> Kembali
                </Link>
                <div className="flex gap-3">
                    <button onClick={handlePrint} className="bg-sky-600 text-white px-6 py-3 rounded-2xl font-black uppercase text-xs flex items-center gap-2 shadow-lg active:scale-95 transition-all">
                        <IconPrinter size={18}/> Cetak Kartu QR
                    </button>
                </div>
            </div>

            {/* Area Kartu QR */}
            <div className="max-w-5xl mx-auto grid grid-cols-2 md:grid-cols-3 gap-6 print:grid-cols-3 print:gap-4">
                {tables.map((table) => {
                    const qrUrl = `${baseUrl}/${table.id}`;
                    
                    // MENGGUNAKAN API QUICKCHART (Lebih stabil dan resolusi tinggi)
                    const qrImage = `https://quickchart.io/qr?text=${encodeURIComponent(qrUrl)}&size=300&margin=2&dark=0284c7`;

                    return (
                        <div key={table.id} className="bg-white border-2 border-slate-200 rounded-[2.5rem] p-8 text-center shadow-sm flex flex-col items-center print:shadow-none print:border-slate-300 print:rounded-[2rem] print:mb-4 break-inside-avoid">
                            <h2 className="text-sky-600 font-black text-xl uppercase tracking-tighter mb-1 italic">{storeName}</h2>
                            <p className="text-[9px] font-black text-slate-400 uppercase mb-6 tracking-[0.2em] border-b pb-2 w-full">Scan Menu & Pesan</p>
                            
                            {/* Container QR dengan CSS khusus agar tidak pecah */}
                            <div className="bg-white p-4 rounded-[2rem] mb-6 border-2 border-sky-100 shadow-inner relative group">
                                <img 
                                    src={qrImage} 
                                    alt={`QR Meja ${table.name}`} 
                                    className="w-44 h-44 object-contain image-render-pixel"
                                    onError={(e) => {
                                        e.target.src = `https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=${encodeURIComponent(qrUrl)}`;
                                    }}
                                />
                                <div className="absolute inset-0 flex items-center justify-center opacity-10">
                                    <IconQrcode size={100} />
                                </div>
                            </div>

                            <div className="bg-slate-900 text-white px-8 py-2.5 rounded-2xl inline-block shadow-lg">
                                <span className="text-sm font-black uppercase tracking-widest text-white">MEJA : {table.name}</span>
                            </div>
                            
                            <p className="mt-6 text-[7px] text-slate-300 italic font-mono truncate w-full opacity-50">{qrUrl}</p>
                        </div>
                    );
                })}
            </div>

            {/* CSS Khusus untuk Resolusi Print */}
            <style>{`
                @media print {
                    @page { 
                        margin: 0.5cm; 
                        size: auto; 
                    }
                    body { 
                        background: white !important; 
                        -webkit-print-color-adjust: exact; 
                        print-color-adjust: exact;
                    }
                    .image-render-pixel {
                        image-rendering: -webkit-optimize-contrast;
                        image-rendering: crisp-edges;
                    }
                }
                .image-render-pixel {
                    image-rendering: auto;
                }
            `}</style>
        </div>
    );
}