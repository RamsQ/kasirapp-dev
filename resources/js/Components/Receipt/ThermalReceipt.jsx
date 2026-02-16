import React from "react";

// Helper format uang khusus struk
const formatPriceReceipt = (value) => {
    const val = Math.abs(parseFloat(value) || 0);
    return new Intl.NumberFormat("id-ID", {
        style: "currency",
        currency: "IDR",
        minimumFractionDigits: 0,
    }).format(val).replace('Rp', '').trim();
};

export default function ThermalReceipt(props) {
    return (
        <div className="bg-white text-black font-mono shadow-sm mx-auto p-2 leading-tight w-[80mm] text-[12px]">
            <ReceiptContent size="80mm" {...props} />
        </div>
    );
}

export function ThermalReceipt58mm(props) {
    return (
        /* PERBAIKAN STRATEGIS: 
           Menggunakan w-[52mm] untuk mencegah browser melakukan zoom-out (pengecilan teks).
           p-0 memastikan teks bisa mepet ke pinggir kertas fisik.
        */
        <div className="bg-white text-black font-mono mx-auto p-0 leading-tight w-[52mm] print:w-full text-[11px]">
            <ReceiptContent size="58mm" {...props} />
        </div>
    );
}

function ReceiptContent({ size, transaction, storeName, storeAddress, footerMessage, storeLogo, qrisImage, isTemporary }) {
    
    // --- DEBUGGING AREA (Cek Console F12) ---
    console.log(`=== DEBUG RECEIPT CONTENT (${size}) ===`);
    console.log("Transaction Data:", transaction);
    console.log("Details:", transaction?.details);

    const isSmall = size === "58mm";

    // Guard jika transaksi belum termuat
    if (!transaction) return <div className="text-center p-4">Memuat Data...</div>;

    // --- LOGIKA DETEKSI PESANAN MANDIRI (QR) ---
    const isSelfOrder = transaction.queue_number?.includes('SELF');

    // --- LOGIKA SPLIT NAMA & KODE UNIK ---
    const rawName = transaction.customer_name || "";
    const hasUniqueCode = rawName.includes('#');
    
    const customerDisplayName = hasUniqueCode 
        ? rawName.split('#')[0].replace('[TAKEAWAY]', '').trim() 
        : rawName.replace('[TAKEAWAY]', '').trim();

    const uniqueVerificationCode = hasUniqueCode 
        ? rawName.split('#')[1]?.trim().split(' ')[0] 
        : null;

    // --- LOGIKA HITUNG HEMAT TOTAL ---
    const calculateFinalSavings = () => {
        let totalHargaNormalBarang = 0;
        (transaction.details || []).forEach((item) => {
            const qty = parseFloat(item.qty) || 0;
            const priceNormal = parseFloat(item.buy_price || item.product?.sell_price || (parseFloat(item.price) / qty));
            totalHargaNormalBarang += (priceNormal * qty);
        });
        const grandTotal = parseFloat(transaction.grand_total) || 0;
        const totalHemat = totalHargaNormalBarang - grandTotal;
        return totalHemat > 0 ? Math.round(totalHemat) : 0;
    };

    const totalSavings = calculateFinalSavings();
    const platformFeeTotal = (parseFloat(transaction.total_markup) || 0) + (parseFloat(transaction.total_fee) || 0);

    return (
        <div className={isSmall ? "p-0" : "p-1"}>
            {/* HEADER AREA */}
            <div className="text-center mb-2 border-b border-black pb-2">
                {storeLogo && <img src={storeLogo} className="w-12 h-12 mx-auto mb-1 grayscale" alt="logo" />}
                <h1 className={`${isSmall ? 'text-[14px]' : 'text-lg'} font-bold uppercase leading-tight mb-1`}>{storeName}</h1>
                <p className="uppercase text-[9px] leading-tight px-1">{storeAddress}</p>
                
                {transaction.queue_number && (
                    <div className="mt-2 flex flex-col items-center">
                        <div className="border border-black inline-block px-4 py-1">
                            <h2 className={`${isSmall ? 'text-[16px]' : 'text-xl'} font-black leading-none`}>#{transaction.queue_number}</h2>
                        </div>
                        {isSelfOrder && (
                            <div className="mt-1 bg-black text-white text-[9px] px-2 py-0.5 font-bold uppercase italic">
                                *** Pesanan Mandiri (QR) ***
                            </div>
                        )}
                    </div>
                )}
            </div>

            {/* INFO TRANSAKSI */}
            <div className="border-b border-black border-dashed py-1.5 mb-1.5 uppercase text-[10px] space-y-0.5">
                {/* Penanda Belum Bayar */}
                {(isTemporary || !transaction.cash) && (
                    <div className="text-center bg-black text-white py-1 my-1 font-black text-[10px] tracking-widest">
                        *** BELUM LUNAS ***
                    </div>
                )}

                <div className="flex justify-between gap-1">
                    <span className="truncate flex-1">No: {transaction.invoice || 'DRAFT'}</span>
                    <span className="font-bold shrink-0">{transaction.table_name || 'TAKE AWAY'}</span>
                </div>
                <div className="flex justify-between">
                    <span>Tgl: {transaction.created_at}</span>
                </div>

                {/* INTEGRASI PLATFORM ONLINE */}
                {transaction.online_platform && (
                    <div className="flex justify-between font-black border-t border-black mt-1 pt-1 italic bg-gray-100 px-1">
                        <span>PLATFORM:</span>
                        <span>{transaction.online_platform.toUpperCase()}</span>
                    </div>
                )}

                {customerDisplayName && (
                    <div className="flex justify-between font-bold border-t border-gray-100 mt-1 pt-1 italic">
                        <span>PEMESAN:</span>
                        <span className="truncate max-w-[120px]">{customerDisplayName}</span>
                    </div>
                )}

                {uniqueVerificationCode && (
                    <div className="flex justify-between items-center bg-gray-100 px-1 py-1 my-1 border border-black border-dotted">
                        <span className="font-bold text-[9px]">KODE:</span>
                        <span className="text-sm font-black tracking-[0.2em]">*{uniqueVerificationCode}*</span>
                    </div>
                )}

                <div className="flex justify-between border-t border-gray-100 pt-1">
                    <span>KSR: {transaction.cashier?.name?.split(' ')[0]}</span>
                </div>
            </div>

            {/* DAFTAR ITEM */}
            <div className="mb-2">
                {transaction.details?.map((item, index) => {
                    const pricePaid = parseFloat(item.price) || 0;
                    const qty = parseFloat(item.qty) || 0;
                    const actualPricePerUnit = qty > 0 ? pricePaid / qty : 0;
                    const bundleItems = item.product?.bundle_items || [];

                    return (
                        <div key={index} className="mb-2 border-b border-gray-50 pb-1 leading-tight">
                            <div className="uppercase font-bold flex justify-between text-[11px]">
                                <span className="flex-1 mr-2">{item.product?.title || item.product_title}</span>
                                {pricePaid === 0 && <span className="text-[9px] italic font-black text-right shrink-0">[GRATIS]</span>}
                            </div>
                            
                            {/* LOGIKA BUNDLE */}
                            {item.product?.type === 'bundle' && bundleItems.length > 0 && (
                                <div className="pl-2 mb-1 border-l border-black border-dotted ml-1 opacity-70">
                                    {bundleItems.map((bi, idx) => (
                                        <div key={idx} className="text-[9px] italic flex justify-between uppercase">
                                            <span>- {bi.title}</span>
                                            <span>x{parseFloat(bi.pivot?.qty || 1) * qty}</span>
                                        </div>
                                    ))}
                                </div>
                            )}

                            {/* CATATAN PRODUK */}
                            {item.notes && (
                                <div className="pl-2 text-[8px] font-bold italic text-gray-600 uppercase">
                                    ** {item.notes}
                                </div>
                            )}
                            
                            {/* HARGA & TOTAL PER ITEM */}
                            <div className="flex justify-between pl-2 text-[10px] mt-0.5">
                                <span>{qty} x {formatPriceReceipt(actualPricePerUnit)}</span>
                                <span className="font-bold">{formatPriceReceipt(pricePaid)}</span>
                            </div>
                        </div>
                    );
                })}
            </div>

            {/* RINGKASAN PEMBAYARAN */}
            <div className="border-t border-black border-dashed pt-1.5 space-y-1">
                {platformFeeTotal > 0 && (
                    <div className="flex justify-between text-[10px] italic">
                        <span>LAYANAN APP</span>
                        <span>{formatPriceReceipt(platformFeeTotal)}</span>
                    </div>
                )}

                <div className="flex justify-between font-bold border-y border-black py-1.5 my-1">
                    <span className="text-[11px]">TOTAL AKHIR</span>
                    <span className={isSmall ? 'text-[14px]' : 'text-base'}>
                        Rp {new Intl.NumberFormat("id-ID").format(transaction.grand_total)}
                    </span>
                </div>

                {!isTemporary && (transaction.cash > 0 || transaction.payment_method !== 'cash') ? (
                    <div className="text-[10px] space-y-0.5">
                        <div className="flex justify-between">
                            <span>BAYAR</span>
                            <span>{formatPriceReceipt(transaction.cash || transaction.grand_total)}</span>
                        </div>
                        <div className="flex justify-between font-bold">
                            <span>KEMBALI</span>
                            <span>{formatPriceReceipt(transaction.change || 0)}</span>
                        </div>
                        
                        {totalSavings > 0 && (
                            <div className="flex justify-between mt-2 font-bold italic text-[10px] border-t border-black border-dotted pt-1 uppercase tracking-tighter bg-gray-50 px-1">
                                <span>ANDA HEMAT:</span>
                                <span>{formatPriceReceipt(totalSavings)}</span>
                            </div>
                        )}

                        <div className="text-right text-[8px] mt-1.5 uppercase opacity-70">
                            Metode: {transaction.payment_method?.toUpperCase()}
                        </div>
                    </div>
                ) : (
                    <div className="text-center py-1 border border-black my-1 font-bold uppercase italic text-[10px]">
                        *** Bill Sementara ***
                    </div>
                )}
            </div>

            {/* SCAN QRIS */}
            {!isTemporary && qrisImage && (transaction.payment_method === 'qris') && (
                <div className="text-center my-4 border-t border-black border-dashed pt-4">
                    <p className="text-[9px] font-bold mb-2 uppercase tracking-widest">Bukti Bayar QRIS</p>
                    <img src={qrisImage} className="w-32 h-32 mx-auto grayscale" alt="QRIS" />
                </div>
            )}

            {/* FOOTER AREA */}
            <div className="text-center mt-4 border-t border-black border-dashed pt-2 pb-2">
                <p className="uppercase font-bold text-[10px] italic leading-tight px-1">{footerMessage || 'Terima Kasih'}</p>
                {isSelfOrder && (
                    <p className="text-[8px] mt-1 font-bold italic border border-black inline-block px-2 uppercase tracking-tighter">e-Menu Verified Order</p>
                )}
                <p className="text-[7px] mt-1.5 opacity-40">{new Date().toLocaleString('id-ID')}</p>
            </div>
        </div>
    );
}