import React, { useState } from "react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { Head, router, usePage } from "@inertiajs/react";
import Table from "@/Components/Dashboard/Table";
import Pagination from "@/Components/Dashboard/Pagination";
import ShiftReceipt from "@/Components/Receipt/ShiftReceipt";
import { smartPrint } from "@/Utils/BluetoothHybridService"; // INTEGRASI BLUETOOTH
import toast from "react-hot-toast";
import { 
    IconCash, 
    IconUser, 
    IconClock, 
    IconCheck, 
    IconCalendar,
    IconSearch,
    IconRefresh,
    IconPrinter,
    IconQrcode,
    IconLoader,
    IconCreditCard,
    IconAlertCircle,
    IconReportAnalytics
} from "@tabler/icons-react";

// --- HELPER FORMAT HARGA ---
const formatPrice = (value) =>
    new Intl.NumberFormat("id-ID", { 
        style: "currency", 
        currency: "IDR", 
        minimumFractionDigits: 0 
    }).format(value || 0);

export default function Index({ shifts, filters, receiptSetting }) {
    const { auth } = usePage().props;
    const [date, setDate] = useState(filters.date || "");
    const [selectedShiftPrint, setSelectedShiftPrint] = useState(null);
    const [isPrinting, setIsPrinting] = useState(false);

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

    /**
     * FUNGSI CETAK ULANG (HYBRID)
     * - Jika di APK: Langsung cetak via Bluetooth.
     * - Jika di WEB: Redirect ke halaman Preview (Print Terminal).
     */
    const handleReprint = async (shift) => {
        const isAPK = typeof window !== 'undefined' && !!window.bluetoothSerial;
        
        if (isAPK) {
            // Mapping data agar konsisten dengan format printer untuk Bluetooth
            const dataForPrinter = {
                ...shift,
                total_cash_sales: parseFloat(shift.total_cash_sales || 0),
                total_qris_sales: parseFloat(shift.total_qris_sales || 0),
                total_discounts: parseFloat(shift.total_discounts || 0),
                // Petty Cash Out = Pengeluaran yang dicatat selama shift
                petty_cash_out: parseFloat(shift.total_expense || 0),
            };

            toast.promise(smartPrint(dataForPrinter, receiptSetting, 'shift'), {
                loading: 'Menghubungkan ke Printer Bluetooth...',
                success: 'Laporan Shift Dicetak!',
                error: (err) => `Gagal cetak: ${err}`
            });
        } else {
            /**
             * JIKA DI WINDOWS/WEB: 
             * Arahkan ke rute print agar muncul halaman preview terminal
             */
            router.get(route('shifts.print', shift.id));
        }
    };

    return (
        <DashboardLayout>
            <Head title="Laporan Shift Kasir" />
            
            <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6 print:hidden">
                <div>
                    <h1 className="text-2xl font-black text-slate-900 dark:text-white uppercase tracking-tight italic flex items-center gap-2">
                        <IconReportAnalytics size={28} className="text-primary-500" />
                        Laporan Shift Kasir
                    </h1>
                    <p className="text-sm text-slate-500 font-medium">
                        Audit modal laci, penjualan tunai, dan validasi saldo digital.
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
                            <Table.Th>Target Tunai (Sistem)</Table.Th>
                            <Table.Th>Setoran Fisik (Laci)</Table.Th>
                            <Table.Th>Digital (QR/Bank)</Table.Th>
                            <Table.Th>Selisih Laci</Table.Th>
                            <Table.Th className="text-center">Aksi</Table.Th>
                        </tr>
                    </Table.Thead>
                    <Table.Tbody>
                        {shifts.data.length > 0 ? (
                            shifts.data.map((shift, i) => (
                                <tr key={shift.id} className="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors group">
                                    <Table.Td className="text-center text-slate-400 font-medium">
                                        {++i + (shifts.current_page - 1) * shifts.per_page}
                                    </Table.Td>
                                    <Table.Td>
                                        <div className="flex items-center gap-3">
                                            <div className="w-8 h-8 rounded-full bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-slate-500 group-hover:bg-primary-500 group-hover:text-white transition-colors shadow-sm">
                                                <IconUser size={16} />
                                            </div>
                                            <span className="font-bold text-slate-700 dark:text-slate-200">
                                                {shift.user?.name}
                                            </span>
                                        </div>
                                    </Table.Td>
                                    <Table.Td>
                                        <div className="flex flex-col gap-1">
                                            <div className="flex items-center gap-1.5 text-[10px] font-black text-green-600 uppercase tracking-tighter italic">
                                                <IconClock size={12} /> Buka: {new Date(shift.opened_at).toLocaleString('id-ID', { dateStyle: 'short', timeStyle: 'short' })}
                                            </div>
                                            <div className="flex items-center gap-1.5 text-[10px] font-black text-red-500 uppercase tracking-tighter italic">
                                                <IconClock size={12} /> Tutup: {shift.closed_at ? new Date(shift.closed_at).toLocaleString('id-ID', { dateStyle: 'short', timeStyle: 'short' }) : 'KASIR MASIH AKTIF'}
                                            </div>
                                        </div>
                                    </Table.Td>
                                    <Table.Td className="text-[11px] text-slate-500 font-bold uppercase tracking-widest">
                                        {formatPrice(shift.starting_cash)}
                                    </Table.Td>
                                    <Table.Td className="font-bold text-slate-900 dark:text-white italic">
                                        {formatPrice(shift.total_cash_expected)}
                                    </Table.Td>
                                    <Table.Td className="font-black text-primary-600">
                                        {shift.total_cash_actual !== null ? formatPrice(shift.total_cash_actual) : '-'}
                                    </Table.Td>
                                    <Table.Td>
                                        <div className="flex flex-col gap-0.5">
                                            <div className="flex items-center gap-1 text-[10px] font-bold text-blue-600 uppercase italic">
                                                <IconQrcode size={12} /> QR: {formatPrice(shift.total_qris_sales || 0)}
                                            </div>
                                            <div className="flex items-center gap-1 text-[10px] font-bold text-purple-600 uppercase italic">
                                                <IconCreditCard size={12} /> TRF: {formatPrice(shift.total_transfer_sales || 0)}
                                            </div>
                                        </div>
                                    </Table.Td>
                                    <Table.Td>
                                        {shift.status === 'closed' ? (
                                            <span className={`px-3 py-1 rounded-lg text-[10px] font-black uppercase tracking-tighter shadow-sm border ${
                                                shift.difference < 0 
                                                ? 'bg-red-50 text-red-600 border-red-100 dark:bg-red-900/20 dark:text-red-400 dark:border-red-900' 
                                                : shift.difference > 0 
                                                ? 'bg-blue-50 text-blue-600 border-blue-100 dark:bg-blue-900/20 dark:text-blue-400 dark:border-blue-900'
                                                : 'bg-green-50 text-green-600 border-green-100 dark:bg-green-900/20 dark:text-green-400 dark:border-green-900'
                                            }`}>
                                                {shift.difference === 0 ? '✓ MATCH' : formatPrice(shift.difference)}
                                            </span>
                                        ) : (
                                            <div className="inline-flex items-center gap-1 px-2 py-1 bg-amber-50 text-amber-600 border border-amber-100 rounded-lg text-[9px] font-black animate-pulse uppercase italic shadow-sm">
                                                In Progress
                                            </div>
                                        )}
                                    </Table.Td>
                                    <Table.Td className="text-center">
                                        {shift.status === 'closed' && (
                                            <button 
                                                onClick={() => handleReprint(shift)}
                                                className="p-2.5 bg-white dark:bg-slate-800 text-slate-600 hover:text-primary-600 border border-slate-200 dark:border-slate-700 rounded-xl transition-all active:scale-90 shadow-sm"
                                                title="Cetak Ulang Laporan Shift"
                                                disabled={isPrinting}
                                            >
                                                {isPrinting && selectedShiftPrint?.id === shift.id ? <IconLoader className="animate-spin" size={18} /> : <IconPrinter size={18} />}
                                            </button>
                                        )}
                                    </Table.Td>
                                </tr>
                            ))
                        ) : (
                            <tr>
                                <Table.Td colSpan="9" className="text-center py-20 text-slate-400 italic font-medium">
                                    <IconAlertCircle size={48} className="mx-auto mb-2 opacity-20" />
                                    Belum ada data shift untuk periode ini.
                                </Table.Td>
                            </tr>
                        )}
                    </Table.Tbody>
                </Table>
            </Table.Card>

            <div className="mt-6 print:hidden">
                <Pagination links={shifts.links} />
            </div>

            {/* Panel Audit Ringkasan */}
            {shifts.data.length > 0 && (
                <div className="mt-8 p-8 bg-slate-900 rounded-[2.5rem] text-white flex flex-col md:flex-row justify-between items-center gap-6 print:hidden shadow-2xl relative overflow-hidden border border-slate-800">
                    <div className="absolute top-0 left-0 w-2 h-full bg-primary-500" />
                    <div className="flex items-center gap-4">
                         <div className="p-4 bg-primary-500/20 rounded-2xl border border-primary-500/30">
                            <IconReportAnalytics size={32} className="text-primary-500" />
                         </div>
                         <div>
                            <h4 className="text-lg font-black uppercase tracking-widest italic">Audit Selisih Laci</h4>
                            <p className="text-slate-400 text-xs font-medium opacity-80 uppercase tracking-tighter">Total Akumulasi Selisih Fisik vs Sistem di halaman ini.</p>
                         </div>
                    </div>
                    <div className={`text-4xl font-black tracking-tighter ${shifts.data.reduce((acc, curr) => acc + (curr.difference || 0), 0) < 0 ? 'text-red-400' : 'text-primary-400'}`}>
                        {formatPrice(shifts.data.reduce((acc, curr) => acc + (curr.difference || 0), 0))}
                    </div>
                </div>
            )}

            {/* AREA CETAK (SHIFT RECEIPT VISUAL) */}
            <div id="print-shift-section" className="hidden print:block">
                {selectedShiftPrint && (
                    <ShiftReceipt 
                        shift={{
                            ...selectedShiftPrint,
                            total_cash_sales: parseFloat(selectedShiftPrint.total_cash_sales || 0),
                            total_qris_sales: parseFloat(selectedShiftPrint.total_qris_sales || 0),
                            total_discounts: parseFloat(selectedShiftPrint.total_discounts || 0),
                        }} 
                        storeName={receiptSetting?.store_name} 
                    />
                )}
            </div>

            <style dangerouslySetInnerHTML={{ __html: `
                .custom-scrollbar::-webkit-scrollbar { width: 4px; }
                .custom-scrollbar::-webkit-scrollbar-thumb { background: #334155; border-radius: 10px; }
                @media print {
                    body * { visibility: hidden !important; }
                    #print-shift-section, #print-shift-section * { visibility: visible !important; }
                    #print-shift-section { 
                        position: absolute; 
                        left: 0; 
                        top: 0; 
                        width: 100%; 
                        display: flex !important; 
                        justify-content: center;
                        background: white;
                    }
                    @page { size: auto; margin: 0mm; }
                }
            ` }} />
        </DashboardLayout>
    );
}

Index.layout = (page) => page;