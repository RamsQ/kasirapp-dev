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
        <div className="bg-white text-black font-mono shadow-sm mx-auto p-1 leading-tight w-[58mm] text-[10px]">
            <ReceiptContent size="58mm" {...props} />
        </div>
    );
}

function ReceiptContent({ size, transaction, storeName, storeAddress, footerMessage, storeLogo, qrisImage, isTemporary }) {
    const isSmall = size === "58mm";

    // --- LOGIKA DETEKSI PESANAN MANDIRI (QR) ---
    const isSelfOrder = transaction.queue_number?.includes('SELF');

    // --- LOGIKA SPLIT NAMA & KODE UNIK (PROSES DARI DATABASE) ---
    const rawName = transaction.customer_name || "";
    const hasUniqueCode = rawName.includes('#');
    
    // Ambil nama (hilangkan tag takeaway agar bersih di struk)
    const customerDisplayName = hasUniqueCode 
        ? rawName.split('#')[0].replace('[TAKEAWAY]', '').trim() 
        : rawName.replace('[TAKEAWAY]', '').trim();

    // Ambil kode unik 4 digit
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

    return (
        <>
            {/* HEADER AREA */}
            <div className="text-center mb-2 border-b border-black pb-2">
                {storeLogo && <img src={storeLogo} className="w-12 h-12 mx-auto mb-1 grayscale" alt="logo" />}
                <h1 className={`${isSmall ? 'text-sm' : 'text-lg'} font-bold uppercase`}>{storeName}</h1>
                <p className="uppercase text-[9px] leading-tight">{storeAddress}</p>
                
                {transaction.queue_number && (
                    <div className="mt-2 flex flex-col items-center">
                        <div className="border border-black inline-block px-4 py-1">
                            <h2 className={`${isSmall ? 'text-lg' : 'text-xl'} font-black leading-none`}>#{transaction.queue_number}</h2>
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
            <div className="border-b border-black border-dashed py-2 mb-2 uppercase text-[10px] space-y-0.5">
                <div className="flex justify-between">
                    <span>No: {transaction.invoice || 'DRAFT'}</span>
                    <span className="font-bold">{transaction.table_name || 'TAKE AWAY'}</span>
                </div>
                <div className="flex justify-between">
                    <span>Tgl: {transaction.created_at}</span>
                </div>

                {/* --- DISPLAY NAMA PEMESAN --- */}
                {customerDisplayName && (
                    <div className="flex justify-between font-bold border-t border-gray-100 mt-1 pt-1 italic">
                        <span>PEMESAN:</span>
                        <span>{customerDisplayName}</span>
                    </div>
                )}

                {/* --- DISPLAY KODE VERIFIKASI (TAMPILAN BOX) --- */}
                {uniqueVerificationCode && (
                    <div className="flex justify-between items-center bg-gray-100 px-1 py-1 my-1 border border-black border-dotted">
                        <span className="font-bold text-[9px]">Kode Pesanan:</span>
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
                    const priceNormal = parseFloat(item.buy_price || 0);
                    const actualPricePerUnit = qty > 0 ? pricePaid / qty : 0;
                    const isDiscounted = priceNormal > actualPricePerUnit && pricePaid > 0;
                    const bundleItems = item.product?.bundle_items || [];

                    return (
                        <div key={index} className="mb-2 border-b border-gray-50 pb-1">
                            <div className="uppercase font-bold flex justify-between">
                                <span className="max-w-[180px]">{item.product?.title || item.product_title}</span>
                                {pricePaid === 0 && <span className="text-[9px] italic font-black">[GRATIS]</span>}
                            </div>
                            
                            {/* LOGIKA BUNDLE */}
                            {item.product?.type === 'bundle' && bundleItems.length > 0 && (
                                <div className="pl-3 mb-1 border-l border-black border-dotted ml-1 opacity-70">
                                    {bundleItems.map((bi, idx) => (
                                        <div key={idx} className="text-[9px] italic flex justify-between uppercase">
                                            <span>- {bi.title}</span>
                                            <span>x{parseFloat(bi.pivot?.qty || 1) * qty}</span>
                                        </div>
                                    ))}
                                </div>
                            )}

                            {/* CATATAN PRODUK (LINE ITEM NOTE) */}
                            {item.notes && (
                                <div className="pl-2 text-[8px] font-bold italic text-gray-600 uppercase">
                                    ** {item.notes}
                                </div>
                            )}
                            
                            {/* HARGA NORMAL (Coret jika diskon) */}
                            {isDiscounted && (
                                <div className="flex justify-between pl-2 text-[9px] text-gray-500 italic line-through">
                                    <span>{qty} x {formatPriceReceipt(priceNormal)}</span>
                                </div>
                            )}

                            {/* RINCIAN HARGA FINAL */}
                            <div className="flex justify-between pl-2">
                                <span>{qty} {item.unit_name || item.unit || 'PCS'} x {formatPriceReceipt(actualPricePerUnit)}</span>
                                <span>{formatPriceReceipt(pricePaid)}</span>
                            </div>
                        </div>
                    );
                })}
            </div>

            {/* RINGKASAN PEMBAYARAN */}
            <div className="border-t border-black border-dashed pt-2 space-y-1">
                <div className="flex justify-between font-bold border-y border-black py-1 my-1">
                    <span>TOTAL AKHIR</span>
                    <span className={isSmall ? 'text-sm' : 'text-base'}>
                        Rp {new Intl.NumberFormat("id-ID").format(transaction.grand_total)}
                    </span>
                </div>

                {!isTemporary ? (
                    <>
                        <div className="flex justify-between">
                            <span>BAYAR</span>
                            <span>{formatPriceReceipt(transaction.cash)}</span>
                        </div>
                        <div className="flex justify-between font-medium">
                            <span>KEMBALI</span>
                            <span>{formatPriceReceipt(transaction.change)}</span>
                        </div>
                        
                        {totalSavings > 0 && (
                            <div className="flex justify-between mt-2 font-bold italic text-[11px] border-t border-black border-dotted pt-1 uppercase tracking-tighter bg-gray-50">
                                <span>ANDA HEMAT:</span>
                                <span>{formatPriceReceipt(totalSavings)}</span>
                            </div>
                        )}

                        <div className="flex justify-between text-[9px] mt-2 uppercase pt-1 border-t border-black border-dotted opacity-70">
                            <span>Metode: {transaction.payment_method?.toUpperCase()}</span>
                        </div>
                    </>
                ) : (
                    <div className="text-center py-1 border border-black my-1 font-bold uppercase italic">
                        *** Bill Sementara ***
                    </div>
                )}
            </div>

            {/* SCAN QRIS JIKA ADA */}
            {!isTemporary && qrisImage && (transaction.payment_method === 'qris') && (
                <div className="text-center my-4 border-t border-black border-dashed pt-4">
                    <p className="text-[9px] font-bold mb-2 uppercase">Bukti Bayar QRIS</p>
                    <img src={qrisImage} className="w-32 h-32 mx-auto grayscale" alt="QRIS" />
                </div>
            )}

            {/* FOOTER */}
            <div className="text-center mt-4 border-t border-black border-dashed pt-2">
                <p className="uppercase font-bold text-[10px] italic tracking-widest">{footerMessage || 'Terima Kasih Atas Kunjungan Anda'}</p>
                {isSelfOrder && (
                    <p className="text-[8px] mt-1 font-bold italic border border-black inline-block px-2 uppercase">e-Menu Verified Order</p>
                )}
                <p className="text-[7px] mt-1 opacity-40">{new Date().toLocaleString('id-ID')}</p>
            </div>
        </>
    );
}