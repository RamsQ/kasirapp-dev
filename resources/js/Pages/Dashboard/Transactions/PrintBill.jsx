import React, { useState, useEffect } from "react";
import { Head, Link } from "@inertiajs/react";
import { IconArrowLeft, IconPrinter, IconUsb } from "@tabler/icons-react";
import { printUsbRaw } from "@/Utils/UsbRawPrinter"; 

export default function Print({ transaction, receiptSetting, isPublic = false, autoPrint = false }) {
    
    const C_WIDTH = 32; 

    const formatRow = (left, right) => {
        const lStr = left ? left.toString().trim() : "";
        const rStr = right ? right.toString().trim() : "";
        const spaceCount = C_WIDTH - (lStr.length + rStr.length);
        return lStr + " ".repeat(spaceCount > 0 ? spaceCount : 1) + rStr;
    };

    const details = Array.isArray(transaction.details) ? transaction.details : [];
    const displayCode = transaction.reference_code || (transaction.customer_name?.includes('#') ? transaction.customer_name.split('#').pop().trim() : "----");
    const displayQueue = transaction.queue_number || details[0]?.queue_number || "";

    useEffect(() => {
        if (autoPrint && transaction?.invoice) {
            const timer = setTimeout(() => { window.print(); }, 1000);
            return () => clearTimeout(timer);
        }
    }, [autoPrint, transaction]);

    return (
        <>
            <Head title={`Print #${displayCode}`} />
            <div className={`min-h-screen ${isPublic ? 'bg-white' : 'bg-slate-950'} p-4 print:bg-white`}>
                {!isPublic && (
                    <div className="max-w-5xl mx-auto mb-6 flex justify-between items-center print:hidden">
                        <Link href={route("transactions.index")} className="bg-slate-900 px-4 py-2 rounded-xl text-white flex items-center gap-2 text-xs font-bold uppercase"><IconArrowLeft size={18} /> Kembali</Link>
                        <div className="flex gap-2">
                            <button onClick={() => printUsbRaw(transaction, receiptSetting)} className="bg-orange-600 text-white px-4 py-2 rounded-xl flex items-center gap-2 text-xs font-bold uppercase shadow-lg"><IconUsb size={18} /> USB RAW</button>
                            <button onClick={() => window.print()} className="bg-primary-600 text-white px-4 py-2 rounded-xl flex items-center gap-2 text-xs font-bold uppercase shadow-lg"><IconPrinter size={18} /> BROWSER</button>
                        </div>
                    </div>
                )}

                <div className="flex justify-center">
                    <div className="bg-white p-2 text-black font-mono text-[11px] w-[54mm] leading-tight shadow-2xl print:shadow-none print:p-0">
                        
                        {/* HEADER */}
                        <div className="flex flex-col items-center text-center uppercase mb-1">
                            <div className="font-bold text-[13px]">{receiptSetting?.store_name || "Toko Ini Itu"}</div>
                            <div className="text-[10px]">{receiptSetting?.store_address || "bogor ciomas"}</div>
                            <div className="text-[10px]">TELP: {receiptSetting?.store_phone || "08787666"}</div>
                            <div className="w-full text-center">{"-".repeat(C_WIDTH)}</div>
                        </div>

                        {/* ANTREAN */}
                        {displayQueue && (
                            <div className="flex flex-col items-center text-center my-1">
                                <div className="text-[28px] font-bold leading-none">{displayQueue}</div>
                                <div className="w-full text-center">{"-".repeat(C_WIDTH)}</div>
                            </div>
                        )}

                        {/* METADATA */}
                        <pre className="whitespace-pre-wrap font-mono uppercase leading-tight">
                            {formatRow("Kode Pesan:", `#${displayCode}`) + "\n"}
                            {formatRow("Tgl:", (transaction.created_at || "").substring(0,16).replace('T', ' ')) + "\n"}
                            {formatRow("Plg:", (transaction.customer_name || "APAN #9698").substring(0, 20)) + "\n"}
                            {formatRow("Type:", (transaction.table_name || "MEJA 2")) + "\n"}
                            {formatRow("Kasir:", (transaction.cashier?.name?.split(' ')[0] || "ARYA")) + "\n"}
                            {"-".repeat(C_WIDTH) + "\n"}

                            {/* ITEMS */}
                            {details.map((item, i) => (
                                <React.Fragment key={i}>
                                    <div className="font-bold">{(item.product?.title || item.product_title).toUpperCase()}</div>
                                    {formatRow(`${parseFloat(item.qty).toFixed(2)} x ${Math.round(item.price/item.qty).toLocaleString('id-ID')}`, Math.round(item.price).toLocaleString('id-ID')) + "\n"}
                                    {item.product?.bundle_items?.map((bi, idx) => (
                                        <div key={idx} className="text-[10px] pl-2">- {bi.title.toUpperCase()}</div>
                                    ))}
                                </React.Fragment>
                            ))}
                            {"-".repeat(C_WIDTH) + "\n"}
                            
                            {/* TOTALS */}
                            {formatRow("TOTAL AKHIR", `Rp ${Math.round(transaction.grand_total).toLocaleString('id-ID')}`) + "\n"}
                            {formatRow("BAYAR", Math.round(transaction.cash || transaction.grand_total).toLocaleString('id-ID')) + "\n"}
                            {formatRow("KEMBALI", Math.round(transaction.change || 0).toLocaleString('id-ID')) + "\n"}
                            {formatRow("METODE", (transaction.payment_method || 'CASH')) + "\n"}
                            {"-".repeat(C_WIDTH) + "\n"}
                        </pre>

                        <div className="text-center mt-1 uppercase">
                            <div className="font-bold text-[10px]">{receiptSetting?.store_footer || "Terima Kasih ya"}</div>
                        </div>
                    </div>
                </div>
            </div>
        </>
    );
}