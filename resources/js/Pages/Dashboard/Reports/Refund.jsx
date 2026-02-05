import React, { useState } from "react";
import { Head, router } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import Pagination from "@/Components/Dashboard/Pagination";
import {
    IconRefresh,
    IconCalendar,
    IconSearch,
    IconFileDescription,
    IconUser
} from "@tabler/icons-react";

const formatCurrency = (value) =>
    new Intl.NumberFormat("id-ID", {
        style: "currency",
        currency: "IDR",
        minimumFractionDigits: 0,
    }).format(value);

const RefundReport = ({ refunds, totalRefund, filters }) => {
    const [startDate, setStartDate] = useState(filters.start_date || "");
    const [endDate, setEndDate] = useState(filters.end_date || "");

    const handleFilter = (e) => {
        e.preventDefault();
        router.get(
            route("reports.refund"),
            { start_date: startDate, end_date: endDate },
            { preserveState: true, replace: true }
        );
    };

    return (
        <>
            <Head title="Laporan Refund" />
            <div className="space-y-6">
                
                {/* 1. Header & Filter */}
                <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2">
                            <IconRefresh className="text-red-500" size={28} />
                            Laporan Refund
                        </h1>
                        <p className="text-slate-500 dark:text-slate-400 text-sm">
                            Riwayat pengembalian dana & barang.
                        </p>
                    </div>

                    <form onSubmit={handleFilter} className="flex flex-wrap items-end gap-2 bg-white dark:bg-slate-900 p-2 rounded-xl border border-slate-200 dark:border-slate-800 shadow-sm">
                        <div className="flex items-center gap-2 px-2">
                            <IconCalendar size={18} className="text-slate-500" />
                            <input
                                type="date"
                                className="border-none bg-transparent text-sm focus:ring-0 p-0 text-slate-700 dark:text-slate-300"
                                value={startDate}
                                onChange={(e) => setStartDate(e.target.value)}
                            />
                            <span className="text-slate-400">-</span>
                            <input
                                type="date"
                                className="border-none bg-transparent text-sm focus:ring-0 p-0 text-slate-700 dark:text-slate-300"
                                value={endDate}
                                onChange={(e) => setEndDate(e.target.value)}
                            />
                        </div>
                        <button
                            type="submit"
                            className="bg-primary-500 hover:bg-primary-600 text-white p-2 rounded-lg transition-colors"
                            title="Filter Data"
                        >
                            <IconSearch size={18} />
                        </button>
                    </form>
                </div>

                {/* 2. Kartu Ringkasan Total */}
                <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div className="bg-red-50 dark:bg-red-900/20 p-5 rounded-2xl border border-red-100 dark:border-red-800 flex items-center gap-4">
                        <div className="p-3 bg-red-500 rounded-xl text-white shadow-lg shadow-red-500/30">
                            <IconRefresh size={24} />
                        </div>
                        <div>
                            <p className="text-sm font-medium text-red-600 dark:text-red-300">Total Nilai Refund</p>
                            <h3 className="text-2xl font-bold text-slate-900 dark:text-white mt-1">
                                {formatCurrency(totalRefund)}
                            </h3>
                        </div>
                    </div>
                </div>

                {/* 3. Tabel Data */}
                <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm text-left">
                            <thead className="bg-slate-50 dark:bg-slate-800 text-slate-500 dark:text-slate-400 uppercase font-semibold text-xs">
                                <tr>
                                    <th className="px-6 py-4">Waktu Refund</th>
                                    <th className="px-6 py-4">Invoice</th>
                                    <th className="px-6 py-4">Kasir / User</th>
                                    <th className="px-6 py-4">Alasan</th>
                                    <th className="px-6 py-4 text-right">Nominal</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                {refunds.data.length > 0 ? (
                                    refunds.data.map((item) => (
                                        <tr key={item.id} className="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors">
                                            <td className="px-6 py-4 text-slate-600 dark:text-slate-300">
                                                <div className="font-medium">
                                                    {new Date(item.updated_at).toLocaleDateString("id-ID", {
                                                        day: "numeric", month: "short", year: "numeric"
                                                    })}
                                                </div>
                                                <div className="text-xs text-slate-400">
                                                    {new Date(item.updated_at).toLocaleTimeString("id-ID", {
                                                        hour: "2-digit", minute:"2-digit"
                                                    })}
                                                </div>
                                            </td>
                                            <td className="px-6 py-4 font-bold text-slate-900 dark:text-white">
                                                {item.invoice}
                                            </td>
                                            <td className="px-6 py-4 text-slate-600 dark:text-slate-400">
                                                <div className="flex items-center gap-2">
                                                    <IconUser size={16} className="text-slate-400"/>
                                                    {item.cashier?.name || '-'}
                                                </div>
                                            </td>
                                            <td className="px-6 py-4">
                                                <div className="flex items-start gap-2 text-slate-600 dark:text-slate-300 bg-slate-100 dark:bg-slate-800 px-3 py-2 rounded-lg max-w-xs">
                                                    <IconFileDescription size={16} className="mt-0.5 text-slate-400 flex-shrink-0" />
                                                    <span className="italic">{item.refund_reason || 'Tidak ada keterangan'}</span>
                                                </div>
                                            </td>
                                            <td className="px-6 py-4 text-right font-bold text-red-600">
                                                {formatCurrency(item.grand_total)}
                                            </td>
                                        </tr>
                                    ))
                                ) : (
                                    <tr>
                                        <td colSpan="5" className="px-6 py-12 text-center text-slate-500">
                                            <div className="flex flex-col items-center justify-center">
                                                <IconRefresh size={48} className="text-slate-300 mb-2 opacity-50" />
                                                <p>Tidak ada data refund pada periode ini.</p>
                                            </div>
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>

                {/* 4. Pagination */}
                {refunds.links && <Pagination links={refunds.links} />}
            </div>
        </>
    );
};

RefundReport.layout = (page) => <DashboardLayout children={page} />;

export default RefundReport;