import React from "react";

const formatPrice = (value) =>
    new Intl.NumberFormat("id-ID", { style: "currency", currency: "IDR", minimumFractionDigits: 0 }).format(value || 0);

export default function ShiftReceipt({ shift, storeName }) {
    if (!shift) return null;

    return (
        <div className="p-4 bg-white text-black font-mono text-[12px] w-[80mm] mx-auto border border-gray-100">
            <div className="text-center mb-4">
                <h2 className="text-lg font-bold uppercase">{storeName || 'LAPORAN SHIFT'}</h2>
                <p className="text-[10px]">================================</p>
                <p className="font-bold">STRUK PENUTUPAN SHIFT</p>
            </div>

            <div className="space-y-1 mb-4 text-[11px]">
                <div className="flex justify-between"><span>KASIR</span><span className="font-bold">{shift.user?.name || '---'}</span></div>
                <div className="flex justify-between"><span>BUKA</span><span>{new Intl.DateTimeFormat('id-ID', {dateStyle: 'short', timeStyle: 'short'}).format(new Date(shift.opened_at))}</span></div>
                <div className="flex justify-between"><span>TUTUP</span><span>{new Intl.DateTimeFormat('id-ID', {dateStyle: 'short', timeStyle: 'short'}).format(new Date(shift.closed_at))}</span></div>
            </div>

            <p className="text-center opacity-30">--------------------------------</p>
            
            <div className="space-y-2 my-4">
                {/* MODAL & PENJUALAN */}
                <div className="flex justify-between"><span>MODAL AWAL</span><span>{formatPrice(shift.starting_cash)}</span></div>
                <div className="flex justify-between text-green-700"><span>TOTAL TUNAI (NET)</span><span>{formatPrice(shift.total_cash_expected - shift.starting_cash)}</span></div>
                <div className="flex justify-between text-purple-700 font-bold"><span>TOTAL QRIS</span><span>{formatPrice(shift.total_qris_sales || 0)}</span></div>
                
                {/* --- BAGIAN DISKON / PROMO (TERBARU) --- */}
                <div className="flex justify-between text-red-600 border-t border-dashed pt-1 mt-1">
                    <span>TOTAL DISKON/PROMO</span>
                    <span>-{formatPrice(shift.total_discounts || 0)}</span>
                </div>
            </div>

            <p className="text-center opacity-30">--------------------------------</p>

            <div className="space-y-2 my-4">
                <div className="flex justify-between font-bold text-sm">
                    <span>TOTAL SALDO SEHARUSNYA</span>
                    <span>{formatPrice(shift.total_cash_expected)}</span>
                </div>
                <div className="flex justify-between font-black text-lg border-t-2 border-black pt-2">
                    <span>TOTAL FISIK</span>
                    <span>{formatPrice(shift.total_cash_actual)}</span>
                </div>
                <div className={`flex justify-between font-bold italic ${shift.difference < 0 ? 'text-red-600' : 'text-blue-600'}`}>
                    <span>SELISIH</span>
                    <span>{formatPrice(shift.difference)}</span>
                </div>
            </div>

            <p className="text-center opacity-30">--------------------------------</p>

            <div className="mt-8 text-center">
                <p className="mb-12 text-[10px] uppercase">Tanda Tangan Kasir,</p>
                <div className="border-b border-black w-32 mx-auto"></div>
                <p className="mt-2 font-bold uppercase">{shift.user?.name}</p>
            </div>
            
            <div className="mt-6 text-center text-[9px] text-gray-400 italic">
                Dicetak pada: {new Date().toLocaleString('id-ID')}
                <br />
                PWA KASIR DIGITAL
            </div>
        </div>
    );
}