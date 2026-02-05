import React, { useEffect, useState } from "react";
import { Head, router, Link, usePage } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import Swal from "sweetalert2";
import Pagination from "@/Components/Dashboard/Pagination";
import {
    IconDatabaseOff,
    IconSearch,
    IconHistory,
    IconReceipt,
    IconPrinter,
    IconFilter,
    IconX,
    IconRefresh,
    IconTrash,
    IconArmchair,
    IconUser
} from "@tabler/icons-react";

const defaultFilters = {
    invoice: "",
    start_date: "",
    end_date: "",
};

const formatCurrency = (value = 0) =>
    new Intl.NumberFormat("id-ID", {
        style: "currency",
        currency: "IDR",
        minimumFractionDigits: 0,
    }).format(value);

const History = ({ transactions, filters }) => {
    const { auth } = usePage().props;
    const isSuperAdmin = auth.user.roles.some(role => role.name === 'super-admin');

    const [filterData, setFilterData] = useState({
        ...defaultFilters,
        ...filters,
    });
    const [showFilters, setShowFilters] = useState(false);

    useEffect(() => {
        setFilterData({
            ...defaultFilters,
            ...filters,
        });
    }, [filters]);

    const handleChange = (field, value) => {
        setFilterData((prev) => ({
            ...prev,
            [field]: value,
        }));
    };

    const applyFilters = (event) => {
        event.preventDefault();
        router.get(route("transactions.history"), filterData, {
            preserveScroll: true,
            preserveState: true,
        });
        setShowFilters(false);
    };

    const resetFilters = () => {
        setFilterData(defaultFilters);
        router.get(route("transactions.history"), defaultFilters, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
    };

    const handleRefund = (id) => {
        Swal.fire({
            title: "Otorisasi Refund",
            text: "Masukkan password Akun Anda untuk konfirmasi refund.",
            input: "password",
            icon: "warning",
            showCancelButton: true,
            confirmButtonText: "Verifikasi & Refund",
            showLoaderOnConfirm: true,
            preConfirm: (password) => {
                if (!password) Swal.showValidationMessage("Password wajib diisi!");
                return password;
            },
        }).then((result) => {
            if (result.isConfirmed) {
                router.post(route("transactions.refund", id), { password: result.value });
            }
        });
    };

    const handleReset = () => {
        Swal.fire({
            title: "BAHAYA! RESET DATA?",
            text: "Seluruh riwayat akan dihapus permanen!",
            icon: "error",
            input: "password",
            showCancelButton: true,
            confirmButtonColor: "#d33",
            confirmButtonText: "YA, RESET!",
            preConfirm: (password) => {
                if (!password) Swal.showValidationMessage("Password wajib diisi!");
                return password;
            },
        }).then((result) => {
            if (result.isConfirmed) {
                router.delete(route("transactions.reset"), {
                    data: { password: result.value }
                });
            }
        });
    };

    const rows = transactions?.data ?? [];
    const links = transactions?.links ?? [];
    const currentPage = transactions?.current_page ?? 1;
    const perPage = transactions?.per_page ? Number(transactions?.per_page) : rows.length || 1;
    const hasActiveFilters = filterData.invoice || filterData.start_date || filterData.end_date;

    return (
        <>
            <Head title="Riwayat Transaksi" />
            <div className="space-y-6">
                <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                    <div>
                        <h1 className="text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2">
                            <IconHistory size={28} className="text-primary-500" />
                            Riwayat Transaksi
                        </h1>
                        <p className="text-sm text-slate-500 dark:text-slate-400">
                            {transactions?.total || 0} transaksi tercatat
                        </p>
                    </div>
                    <div className="flex gap-2">
                        {isSuperAdmin && (
                            <button onClick={handleReset} className="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl border border-red-200 bg-red-50 text-red-600 hover:bg-red-100 transition-colors text-sm font-medium">
                                <IconTrash size={18} />
                                <span className="hidden sm:inline">Reset Data</span>
                            </button>
                        )}
                        <button onClick={() => setShowFilters(!showFilters)} className={`inline-flex items-center gap-2 px-4 py-2.5 rounded-xl border text-sm font-medium transition-colors ${showFilters || hasActiveFilters ? "bg-primary-50 border-primary-200 text-primary-700" : "bg-white"}`}>
                            <IconFilter size={18} />
                            <span>Filter</span>
                        </button>
                        <Link href={route("transactions.index")} className="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-primary-500 hover:bg-primary-600 text-white text-sm font-medium shadow-lg shadow-primary-500/30">
                            <IconReceipt size={18} />
                            <span>Kasir</span>
                        </Link>
                    </div>
                </div>

                {showFilters && (
                    <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 p-5 shadow-sm">
                        <form onSubmit={applyFilters} className="grid gap-4 md:grid-cols-4">
                            <input type="text" placeholder="Nomor Invoice" value={filterData.invoice} onChange={(e) => handleChange("invoice", e.target.value)} className="rounded-xl border-slate-200 dark:bg-slate-800 dark:border-slate-700" />
                            <input type="date" value={filterData.start_date} onChange={(e) => handleChange("start_date", e.target.value)} className="rounded-xl border-slate-200 dark:bg-slate-800 dark:border-slate-700" />
                            <input type="date" value={filterData.end_date} onChange={(e) => handleChange("end_date", e.target.value)} className="rounded-xl border-slate-200 dark:bg-slate-800 dark:border-slate-700" />
                            <button type="submit" className="bg-primary-500 text-white rounded-xl font-medium px-4 py-2">Cari</button>
                        </form>
                    </div>
                )}

                <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden shadow-sm">
                    <div className="overflow-x-auto">
                        <table className="w-full">
                            <thead>
                                <tr className="border-b border-slate-100 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-800/50">
                                    <th className="px-6 py-4 text-left text-xs font-bold text-slate-500 uppercase tracking-widest">No</th>
                                    <th className="px-6 py-4 text-left text-xs font-bold text-slate-500 uppercase tracking-widest">Invoice</th>
                                    <th className="px-6 py-4 text-left text-xs font-bold text-slate-500 uppercase tracking-widest">Tanggal</th>
                                    <th className="px-6 py-4 text-left text-xs font-bold text-slate-500 uppercase tracking-widest">Tipe / Pemesan</th>
                                    <th className="px-6 py-4 text-left text-xs font-bold text-slate-500 uppercase tracking-widest">Pelanggan</th>
                                    <th className="px-6 py-4 text-right text-xs font-bold text-slate-500 uppercase tracking-widest">Total</th>
                                    <th className="px-6 py-4 text-right text-xs font-bold text-slate-500 uppercase tracking-widest text-emerald-600">Profit</th>
                                    <th className="px-6 py-4 text-center text-xs font-bold text-slate-500 uppercase tracking-widest">Aksi</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                {rows.map((transaction, index) => (
                                    <tr key={transaction.id} className="hover:bg-slate-50/80 dark:hover:bg-slate-800/50 transition-colors">
                                        <td className="px-6 py-4 text-sm text-slate-600">{index + 1 + (currentPage - 1) * perPage}</td>
                                        <td className="px-6 py-4 font-bold text-slate-900 dark:text-white">
                                            <div className="flex flex-col">
                                                <span>{transaction.invoice}</span>
                                                <span className="text-[10px] font-medium text-slate-400 uppercase">KSR: {transaction.cashier?.name}</span>
                                            </div>
                                        </td>
                                        <td className="px-6 py-4 text-sm text-slate-500">{new Date(transaction.created_at).toLocaleString('id-ID')}</td>
                                        
                                        {/* KOLOM TIPE / PEMESAN (QR ORDER INFO) */}
                                        <td className="px-6 py-4">
                                            <div className="flex flex-col gap-1">
                                                <div className="flex items-center gap-1.5 text-[10px] font-bold text-primary-600 uppercase italic">
                                                    <IconArmchair size={14} />
                                                    {transaction.table_name || 'BAWA PULANG'}
                                                </div>
                                                {transaction.customer_name && (
                                                    <div className="flex items-center gap-1.5 text-xs font-black text-slate-700 dark:text-slate-200 uppercase">
                                                        <IconUser size={14} className="text-slate-400" />
                                                        {transaction.customer_name}
                                                    </div>
                                                )}
                                            </div>
                                        </td>

                                        <td className="px-6 py-4">
                                            <span className="px-2 py-1 bg-slate-100 dark:bg-slate-800 rounded text-xs">
                                                {transaction.customer?.name ?? "Umum"}
                                            </span>
                                        </td>
                                        <td className="px-6 py-4 text-right font-bold">{formatCurrency(transaction.grand_total)}</td>
                                        <td className="px-6 py-4 text-right font-black text-emerald-500 italic">
                                            {formatCurrency(transaction.total_profit ?? 0)}
                                        </td>
                                        <td className="px-6 py-4 text-center">
                                            <div className="flex justify-center gap-2">
                                                <Link href={route("transactions.print", transaction.invoice)} className="p-2 text-slate-400 hover:text-primary-500 transition-colors" title="Cetak Ulang Struk">
                                                    <IconPrinter size={18}/>
                                                </Link>
                                                {transaction.payment_status !== "refunded" && (
                                                    <button onClick={() => handleRefund(transaction.id)} className="p-2 text-slate-400 hover:text-orange-500 transition-colors" title="Refund Transaksi">
                                                        <IconRefresh size={18}/>
                                                    </button>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
                {links.length > 3 && <Pagination links={links} />}
            </div>
        </>
    );
};

History.layout = (page) => <DashboardLayout children={page} />;
export default History;