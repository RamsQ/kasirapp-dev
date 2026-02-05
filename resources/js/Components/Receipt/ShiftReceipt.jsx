import React from "react";

const formatPrice = (value) =>
    new Intl.NumberFormat("id-ID", { style: "currency", currency: "IDR", minimumFractionDigits: 0 }).format(value || 0);

export default function ShiftReceipt({ shift, storeName }) {
    if (!shift) return null;

    return (
        <div className="p-4 bg-white text-black font-mono text-[12px] w-[80mm] mx-auto">
            <div className="text-center mb-4">
                <h2 className="text-lg font-bold uppercase">{storeName || 'LAPORAN SHIFT'}</h2>
                <p>--------------------------------</p>
                <p className="font-bold">STRUK PENUTUPAN SHIFT</p>
            </div>

            <div className="space-y-1 mb-4">
                <div className="flex justify-between"><span>KASIR</span><span>{shift.user?.name}</span></div>
                <div className="flex justify-between"><span>BUKA</span><span>{new Intl.DateTimeFormat('id-ID', {dateStyle: 'short', timeStyle: 'short'}).format(new Date(shift.opened_at))}</span></div>
                <div className="flex justify-between"><span>TUTUP</span><span>{new Intl.DateTimeFormat('id-ID', {dateStyle: 'short', timeStyle: 'short'}).format(new Date(shift.closed_at))}</span></div>
            </div>

            <p className="text-center">--------------------------------</p>
            
            <div className="space-y-2 my-4">
                <div className="flex justify-between font-bold"><span>MODAL AWAL</span><span>{formatPrice(shift.starting_cash)}</span></div>
                <div className="flex justify-between text-green-700"><span>TOTAL TUNAI (S)</span><span>{formatPrice(shift.total_cash_expected - shift.starting_cash)}</span></div>
                <div className="flex justify-between text-purple-700 font-bold"><span>TOTAL QRIS</span><span>{formatPrice(shift.total_qris_sales || 0)}</span></div>
            </div>

            <p className="text-center">--------------------------------</p>

            <div className="space-y-2 my-4">
                <div className="flex justify-between font-bold text-lg border-t pt-2">
                    <span>TOTAL FISIK</span><span>{formatPrice(shift.total_cash_actual)}</span>
                </div>
                <div className="flex justify-between italic">
                    <span>SELISIH</span><span>{formatPrice(shift.difference)}</span>
                </div>
            </div>

            <div className="mt-8 text-center">
                <p className="mb-10 text-[10px]">Tanda Tangan Kasir,</p>
                <p>( .................... )</p>
            </div>
            
            <div className="mt-4 text-center text-[10px] text-gray-400">
                Dicetak pada: {new Date().toLocaleString('id-ID')}
            </div>
        </div>
    );
}