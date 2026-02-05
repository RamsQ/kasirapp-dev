import React, { useState } from "react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { Head, router, usePage } from "@inertiajs/react";
import Table from "@/Components/Dashboard/Table";
import Pagination from "@/Components/Dashboard/Pagination";
import ShiftReceipt from "@/Components/Receipt/ShiftReceipt";
import { 
    IconCash, 
    IconUser, 
    IconClock, 
    IconCheck, 
    IconCalendar,
    IconSearch,
    IconRefresh,
    IconPrinter,
    IconQrcode
} from "@tabler/icons-react";

const formatPrice = (value) =>
    new Intl.NumberFormat("id-ID", { 
        style: "currency", 
        currency: "IDR", 
        minimumFractionDigits: 0 
    }).format(value || 0);

export default function Index({ shifts, filters }) {
    const { receiptSetting } = usePage().props;
    const [date, setDate] = useState(filters.date || "");
    const [selectedShiftPrint, setSelectedShiftPrint] = useState(null);

    // Fungsi untuk memfilter berdasarkan tanggal
    const handleFilter = (e) => {
        e.preventDefault();
        const params = {};
        if (date) params.date = date;

        router.get(route('shifts.index'), params, {
            preserveState: true,
            replace: true
        });
    };

    const resetFilter = () => {
        setDate("");
        router.get(route('shifts.index'));
    };

    // Fungsi Cetak Struk Shift
    const handlePrint = (shift) => {
        setSelectedShiftPrint(shift);
        // Berikan jeda sedikit agar state ter-update sebelum print dialog muncul
        setTimeout(() => {
            window.print();
            setSelectedShiftPrint(null);
        }, 500);
    };

    return (
        <DashboardLayout>
            <Head title="Laporan Shift Kasir" />
            
            <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6 print:hidden">
                <div>
                    <h1 className="text-2xl font-black text-slate-900 dark:text-white uppercase tracking-tight">
                        Laporan Shift Kasir
                    </h1>
                    <p className="text-sm text-slate-500 font-medium">
                        Riwayat sesi kerja, modal awal, dan audit selisih kas.
                    </p>
                </div>

                {/* Filter Tanggal */}
                <form onSubmit={handleFilter} className="flex items-center gap-2">
                    <div className="relative">
                        <IconCalendar className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" size={18} />
                        <input 
                            type="date" 
                            value={date}
                            onChange={(e) => setDate(e.target.value)}
                            className="pl-10 pr-4 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-xl text-sm focus:ring-primary-500 transition-all dark:text-white"
                        />
                    </div>
                    <button type="submit" className="p-2.5 bg-primary-600 text-white rounded-xl hover:bg-primary-700 transition-colors shadow-lg shadow-primary-500/30">
                        <IconSearch size={18} />
                    </button>
                    <button type="button" onClick={resetFilter} className="p-2.5 bg-slate-200 dark:bg-slate-800 text-slate-600 dark:text-slate-400 rounded-xl hover:bg-slate-300 transition-colors">
                        <IconRefresh size={18} />
                    </button>
                </form>
            </div>

            <Table.Card title="Semua Riwayat Shift" className="print:hidden">
                <Table>
                    <Table.Thead>
                        <tr>
                            <Table.Th className="w-10 text-center">No</Table.Th>
                            <Table.Th>Kasir</Table.Th>
                            <Table.Th>Waktu Operasional</Table.Th>
                            <Table.Th>Modal Awal</Table.Th>
                            <Table.Th>Total Tunai (S)</Table.Th>
                            <Table.Th>Total QRIS</Table.Th>
                            <Table.Th>Setoran Fisik</Table.Th>
                            <Table.Th>Selisih</Table.Th>
                            <Table.Th className="text-center">Aksi</Table.Th>
                        </tr>
                    </Table.Thead>
                    <Table.Tbody>
                        {shifts.data.length > 0 ? (
                            shifts.data.map((shift, i) => (
                                <tr key={shift.id} className="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors">
                                    <Table.Td className="text-center text-slate-400 font-medium">
                                        {++i + (shifts.current_page - 1) * shifts.per_page}
                                    </Table.Td>
                                    <Table.Td>
                                        <div className="flex items-center gap-3">
                                            <div className="w-8 h-8 rounded-full bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-slate-500">
                                                <IconUser size={16} />
                                            </div>
                                            <span className="font-bold text-slate-700 dark:text-slate-200">
                                                {shift.user?.name}
                                            </span>
                                        </div>
                                    </Table.Td>
                                    <Table.Td>
                                        <div className="flex flex-col gap-1">
                                            <div className="flex items-center gap-1.5 text-[10px] font-bold text-green-600 uppercase tracking-tighter">
                                                <IconClock size={12} /> Buka: {new Date(shift.opened_at).toLocaleString('id-ID')}
                                            </div>
                                            <div className="flex items-center gap-1.5 text-[10px] font-bold text-red-500 uppercase tracking-tighter">
                                                <IconClock size={12} /> Tutup: {shift.closed_at ? new Date(shift.closed_at).toLocaleString('id-ID') : 'AKTIF'}
                                            </div>
                                        </div>
                                    </Table.Td>
                                    <Table.Td className="text-[11px] text-slate-500">
                                        {formatPrice(shift.starting_cash)}
                                    </Table.Td>
                                    <Table.Td className="font-bold text-slate-900 dark:text-white">
                                        {formatPrice(shift.total_cash_expected)}
                                    </Table.Td>
                                    <Table.Td className="font-bold text-purple-600">
                                        <div className="flex items-center gap-1">
                                            <IconQrcode size={14} />
                                            {formatPrice(shift.total_qris_sales || 0)}
                                        </div>
                                    </Table.Td>
                                    <Table.Td className="font-black text-primary-600">
                                        {shift.total_cash_actual !== null ? formatPrice(shift.total_cash_actual) : '-'}
                                    </Table.Td>
                                    <Table.Td>
                                        {shift.status === 'closed' ? (
                                            <span className={`px-2 py-0.5 rounded-full text-[10px] font-black uppercase tracking-tighter ${
                                                shift.difference < 0 
                                                ? 'bg-red-100 text-red-600 dark:bg-red-900/30 dark:text-red-400' 
                                                : shift.difference > 0 
                                                ? 'bg-blue-100 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400'
                                                : 'bg-green-100 text-green-600 dark:bg-green-900/30 dark:text-green-400'
                                            }`}>
                                                {shift.difference === 0 ? 'PAS' : formatPrice(shift.difference)}
                                            </span>
                                        ) : (
                                            <div className="inline-flex items-center gap-1 px-2 py-0.5 bg-amber-100 text-amber-600 rounded-full text-[9px] font-black animate-pulse">
                                                AKTIF
                                            </div>
                                        )}
                                    </Table.Td>
                                    <Table.Td className="text-center">
                                        {shift.status === 'closed' && (
                                            <button 
                                                onClick={() => handlePrint(shift)}
                                                className="p-2 bg-slate-100 dark:bg-slate-800 text-slate-600 hover:bg-primary-500 hover:text-white rounded-xl transition-all"
                                                title="Cetak Struk Shift"
                                            >
                                                <IconPrinter size={18} />
                                            </button>
                                        )}
                                    </Table.Td>
                                </tr>
                            ))
                        ) : (
                            <tr>
                                <Table.Td colSpan="9" className="text-center py-20 text-slate-400 italic">
                                    Tidak ada data shift ditemukan.
                                </Table.Td>
                            </tr>
                        )}
                    </Table.Tbody>
                </Table>
            </Table.Card>

            <div className="mt-6 print:hidden">
                <Pagination links={shifts.links} />
            </div>

            {/* Panel Ringkasan Singkat */}
            {shifts.data.length > 0 && (
                <div className="mt-8 p-6 bg-slate-900 rounded-3xl text-white flex flex-col md:flex-row justify-between items-center gap-6 print:hidden shadow-xl">
                    <div>
                        <h4 className="text-lg font-black uppercase tracking-tight">Audit Selisih Halaman Ini</h4>
                        <p className="text-slate-400 text-sm italic font-medium opacity-80">Akumulasi selisih kasir (Fisik vs Sistem).</p>
                    </div>
                    <div className="text-3xl font-black tracking-tighter text-primary-400">
                        {formatPrice(shifts.data.reduce((acc, curr) => acc + (curr.difference || 0), 0))}
                    </div>
                </div>
            )}

            {/* AREA CETAK (HANYA MUNCUL SAAT PRINT) */}
            <div id="print-shift-section" className="hidden print:block">
                <ShiftReceipt 
                    shift={selectedShiftPrint} 
                    storeName={receiptSetting?.store_name} 
                />
            </div>

            <style dangerouslySetInnerHTML={{ __html: `
                @media print {
                    /* Sembunyikan semua elemen kecuali area print */
                    body * { visibility: hidden; }
                    #print-shift-section, #print-shift-section * { visibility: visible; }
                    #print-shift-section { 
                        position: absolute; 
                        left: 0; 
                        top: 0; 
                        width: 100%; 
                        display: flex !important; 
                        justify-content: center;
                    }
                    @page { size: auto; margin: 0mm; }
                }
            ` }} />
        </DashboardLayout>
    );
}

// Layout Persist
Index.layout = (page) => page;