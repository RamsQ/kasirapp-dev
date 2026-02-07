import React, { useState, useEffect } from 'react';
import { Head, Link } from '@inertiajs/react';
import { IconPrinter, IconArrowLeft, IconReceipt2, IconInfoCircle } from "@tabler/icons-react";

export default function PrintBill({ hold, receiptSetting }) {
    // State untuk memilih tipe kertas (default 80mm)
    const [paperSize, setPaperSize] = useState('58'); // Default ke 58mm sesuai printer Panda Anda

    useEffect(() => {
        // Memicu dialog cetak otomatis saat halaman terbuka
        const timer = setTimeout(() => {
            window.print();
        }, 1000);
        
        return () => clearTimeout(timer);
    }, []);

    const formatPrice = (value = 0) =>
        new Intl.NumberFormat("id-ID", {
            minimumFractionDigits: 0,
        }).format(value);

    // --- LOGIKA SPLIT NAMA & KODE UNIK (SELARAS DENGAN THERMALRECEIPT) ---
    const rawName = hold.customer_name || "";
    const hasUniqueCode = rawName.includes('#');
    
    const customerDisplayName = hasUniqueCode 
        ? rawName.split('#')[0].replace('[TAKEAWAY]', '').trim() 
        : rawName.replace('[TAKEAWAY]', '').trim();

    const uniqueVerificationCode = hasUniqueCode 
        ? rawName.split('#')[1]?.trim().split(' ')[0] 
        : null;

    return (
        <div className="bg-slate-100 dark:bg-slate-950 min-h-screen py-6 px-4 transition-colors duration-300">
            <Head title={`Bill - ${hold.ref_number || hold.queue_number}`} />
            
            {/* Navigasi & Pengaturan (Sembunyi saat Cetak) */}
            <div className={`${paperSize === '80' ? 'max-w-[80mm]' : 'max-w-[58mm]'} mx-auto mb-6 print:hidden flex flex-col gap-4 transition-all duration-300`}>
                <div className="flex items-center justify-between">
                    <Link 
                        href={route('transactions.index')} 
                        className="p-2 rounded-xl bg-white dark:bg-slate-900 text-slate-500 hover:text-primary-500 shadow-sm transition-all active:scale-95"
                    >
                        <IconArrowLeft size={22} />
                    </Link>
                    <div className="flex bg-white dark:bg-slate-900 p-1 rounded-xl shadow-sm border dark:border-slate-800 font-black text-[10px]">
                        <button 
                            onClick={() => setPaperSize('80')}
                            className={`px-3 py-1 rounded-lg transition-all ${paperSize === '80' ? 'bg-primary-500 text-white shadow-md' : 'text-slate-400'}`}
                        >
                            80mm
                        </button>
                        <button 
                            onClick={() => setPaperSize('58')}
                            className={`px-3 py-1 rounded-lg transition-all ${paperSize === '58' ? 'bg-primary-500 text-white shadow-md' : 'text-slate-400'}`}
                        >
                            58mm
                        </button>
                    </div>
                </div>
            </div>
            
            {/* Tampilan Struk / Kartu Bill */}
            <div className={`
                ${paperSize === '80' ? 'max-w-[80mm]' : 'max-w-[58mm]'} 
                mx-auto bg-white text-slate-800 p-4 md:p-6 shadow-[0_20px_50px_rgba(0,0,0,0.1)] rounded-[1.5rem] 
                print:rounded-none print:shadow-none print:p-0 relative overflow-hidden transition-all duration-300 border border-slate-200/50 print:border-none
            `}>
                
                {/* Dekorasi Kiri */}
                <div className="absolute top-0 left-0 w-1 h-full bg-primary-500/20 print:hidden"></div>

                <div className="text-center mb-6">
                    <h2 className={`font-black uppercase tracking-tighter italic text-slate-900 ${paperSize === '80' ? 'text-xl' : 'text-lg'}`}>
                        {receiptSetting?.store_name || 'KASIR APP'}
                    </h2>
                    <p className="whitespace-pre-line text-[9px] font-bold text-slate-400 leading-tight uppercase mt-1">
                        {receiptSetting?.store_address || receiptSetting?.address}
                    </p>
                    
                    <div className="flex flex-col items-center mt-3">
                        <div className="border-2 border-slate-900 inline-block px-5 py-1 mb-1">
                             <h2 className="text-2xl font-black leading-none">#{hold.queue_number}</h2>
                        </div>
                        <span className="text-[8px] font-black text-slate-400 uppercase tracking-widest leading-none italic">Bill Sementara</span>
                    </div>
                </div>

                <div className="space-y-1 mb-6">
                    <div className="flex justify-between text-[9px] font-bold uppercase text-slate-400">
                        <span>Ref</span>
                        <span className="text-slate-900 font-black">{hold.ref_number || '-'}</span>
                    </div>
                    <div className="flex justify-between text-[9px] font-bold uppercase text-slate-400">
                        <span>Tipe</span>
                        <span className="text-primary-600 font-black italic">{hold.table?.name || 'BAWA PULANG'}</span>
                    </div>
                    
                    {customerDisplayName && (
                        <div className="flex justify-between text-[9px] font-bold uppercase text-slate-400 pt-1 border-t border-slate-50 mt-1">
                            <span>Pemesan</span>
                            <span className="text-slate-900 font-black">{customerDisplayName}</span>
                        </div>
                    )}

                    {uniqueVerificationCode && (
                        <div className="flex justify-between items-center bg-slate-50 border border-dashed border-slate-200 px-2 py-1 mt-1 rounded-lg">
                            <span className="text-[8px] font-black text-slate-400 uppercase">Kode Verif</span>
                            <span className="text-[11px] font-black tracking-widest text-primary-600">*{uniqueVerificationCode}*</span>
                        </div>
                    )}
                </div>

                <div className="border-b border-dashed border-slate-200 mb-6"></div>

                {/* Daftar Item Pesanan */}
                <div className="space-y-4">
                    {hold.cart_data && hold.cart_data.map((item, index) => (
                        <div key={index} className="flex flex-col border-b border-slate-50 pb-2 last:border-none">
                            <div className="flex justify-between gap-2">
                                <span className="flex-1 text-[10px] font-black uppercase text-slate-900 leading-tight">
                                    {item.product?.title || item.product_title || item.product_name || item.title || 'Produk'}
                                </span>
                                <span className="text-right text-[10px] font-black text-slate-900">
                                    {formatPrice(item.price * item.qty)}
                                </span>
                            </div>

                            {/* CATATAN PRODUK (LINE ITEM NOTE) */}
                            {item.notes && (
                                <div className="flex items-start gap-1 text-[8px] font-bold text-primary-600 uppercase italic mt-0.5">
                                    <IconInfoCircle size={10} stroke={3} />
                                    <span>Note: {item.notes}</span>
                                </div>
                            )}

                            <div className="text-[8px] font-bold text-slate-400 uppercase mt-0.5">
                                {item.qty} x {formatPrice(item.price)}
                            </div>
                        </div>
                    ))}
                </div>

                <div className="border-b-2 border-slate-100 my-6"></div>

                <div className="flex justify-between items-center bg-slate-50 p-3 rounded-xl font-black italic">
                    <span className="text-[8px] text-slate-400 uppercase tracking-widest">Estimasi Total</span>
                    <span className={`text-primary-600 tracking-tighter ${paperSize === '80' ? 'text-xl' : 'text-lg'}`}>
                        Rp {formatPrice(hold.total)}
                    </span>
                </div>

                <div className="text-center mt-8 space-y-2 opacity-60">
                    <p className="text-[9px] font-black uppercase tracking-[0.2em] text-slate-900">*** BELUM LUNAS ***</p>
                    <p className="text-[8px] font-bold text-slate-400 uppercase italic leading-tight">
                        Harap bawa bill ini ke kasir<br/>untuk melakukan pembayaran
                    </p>
                    <div className="pt-4 text-[7px] font-bold text-slate-300 uppercase font-mono">
                        Dicetak: {new Date().toLocaleString('id-ID')}
                    </div>
                </div>
            </div>
            
            {/* Navigasi Footer */}
            <div className={`${paperSize === '80' ? 'max-w-[80mm]' : 'max-w-[58mm]'} mx-auto mt-8 print:hidden grid grid-cols-2 gap-3`}>
                <button 
                    onClick={() => window.print()}
                    className="py-4 bg-slate-900 dark:bg-slate-800 text-white font-black text-[10px] rounded-2xl uppercase active:scale-95 transition-all hover:bg-black flex items-center justify-center gap-2 shadow-lg"
                >
                    <IconPrinter size={18} /> Cetak
                </button>
                <Link 
                    href={route('transactions.index')} 
                    className="py-4 bg-primary-600 text-white font-black text-[10px] rounded-2xl uppercase active:scale-95 transition-all hover:bg-primary-700 flex items-center justify-center gap-2 shadow-lg text-center"
                >
                    Kembali
                </Link>
            </div>

            <style>{`
                @media print {
                    body { background-color: white !important; }
                    .bg-slate-100, .dark\:bg-slate-950 { background-color: white !important; }
                    .print\:hidden { display: none !important; }
                    #main-app-content, aside, header { display: none !important; }
                    @page { 
                        margin: 0; 
                        size: ${paperSize === '80' ? '80mm auto' : '58mm auto'}; 
                    }
                }
            `}</style>
        </div>
    );
}