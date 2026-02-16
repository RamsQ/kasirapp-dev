import React from "react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { Head, usePage, router } from "@inertiajs/react";
import { IconDatabaseOff, IconKey, IconShield, IconListNumbers } from "@tabler/icons-react";
import Search from "@/Components/Dashboard/Search";
import Pagination from "@/Components/Dashboard/Pagination";

export default function Index() {
    const { permissions, filters } = usePage().props;

    // Fungsi untuk mengubah jumlah tampilan per halaman
    const handlePerPageChange = (e) => {
        router.get(
            route("permissions.index"),
            { ...filters, per_page: e.target.value },
            { preserveState: true, preserveScroll: true }
        );
    };

    return (
        <>
            <Head title="Hak Akses" />

            {/* Header */}
            <div className="mb-6">
                <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                    <div>
                        <h1 className="text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2">
                            <IconKey size={28} className="text-primary-500" />
                            Hak Akses Sistem
                        </h1>
                        <p className="text-sm text-slate-500 dark:text-slate-400">
                            Menampilkan {permissions.from || 0} - {permissions.to || 0} dari {permissions.total} data
                        </p>
                    </div>
                </div>
            </div>

            {/* Toolbar Section */}
            <div className="mb-6 flex flex-col md:flex-row gap-4 justify-between items-center bg-white dark:bg-slate-900 p-4 rounded-xl border border-slate-200 dark:border-slate-800 shadow-sm">
                <div className="w-full md:w-80">
                    <Search
                        url={route("permissions.index")}
                        placeholder="Cari hak akses..."
                    />
                </div>
                
                <div className="flex items-center gap-3 w-full md:w-auto justify-end">
                    <div className="flex items-center gap-2">
                        <IconListNumbers size={18} className="text-slate-400" />
                        <span className="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wider">
                            Tampilkan:
                        </span>
                        <select 
                            value={permissions.per_page}
                            onChange={handlePerPageChange}
                            className="bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-700 dark:text-slate-200 text-xs rounded-lg focus:ring-primary-500 focus:border-primary-500 p-1.5 px-3 transition-all outline-none"
                        >
                            <option value="10">10 Baris</option>
                            <option value="25">25 Baris</option>
                            <option value="50">50 Baris</option>
                            <option value="100">100 Baris</option>
                        </select>
                    </div>
                </div>
            </div>

            {/* Permissions Rows */}
            <div className="space-y-2">
                {permissions.data.length > 0 ? (
                    <div className="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 overflow-hidden shadow-sm">
                        <div className="overflow-x-auto">
                            <table className="w-full text-left border-collapse">
                                <thead>
                                    <tr className="bg-slate-50 dark:bg-slate-800/50 border-b border-slate-200 dark:border-slate-800">
                                        <th className="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider w-16 text-center">No.</th>
                                        <th className="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider">Nama Permission</th>
                                        <th className="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider text-right">ID Guard</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                    {permissions.data.map((permission, i) => (
                                        <tr 
                                            key={permission.id || i}
                                            className="hover:bg-primary-50/30 dark:hover:bg-primary-900/10 transition-colors group"
                                        >
                                            <td className="px-6 py-4 text-sm text-slate-400 dark:text-slate-500 font-mono text-center">
                                                {String(i + permissions.from).padStart(2, '0')}
                                            </td>
                                            <td className="px-6 py-4">
                                                <div className="flex items-center gap-3">
                                                    <div className="w-8 h-8 rounded-lg bg-primary-50 dark:bg-primary-900/30 flex items-center justify-center group-hover:bg-primary-500 group-hover:text-white transition-all duration-200">
                                                        <IconShield size={16} />
                                                    </div>
                                                    <span className="text-sm font-semibold text-slate-700 dark:text-slate-200 uppercase tracking-tight">
                                                        {permission.name}
                                                    </span>
                                                </div>
                                            </td>
                                            <td className="px-6 py-4 text-right">
                                                <span className="inline-flex items-center px-2.5 py-0.5 rounded text-[10px] font-black uppercase bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400 border border-slate-200 dark:border-slate-700">
                                                    {permission.guard_name || 'web'}
                                                </span>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                ) : (
                    <div className="flex flex-col items-center justify-center py-16 bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800">
                        <IconDatabaseOff size={32} strokeWidth={1.5} className="text-slate-400 mb-4" />
                        <h3 className="text-lg font-medium text-slate-800 dark:text-slate-200 mb-1">Data Kosong</h3>
                        <p className="text-sm text-slate-500">Tidak ada hak akses yang ditemukan.</p>
                    </div>
                )}
            </div>

            {/* Pagination */}
            <div className="mt-6 mb-10">
                {permissions.last_page > 1 && (
                    <div className="flex flex-col md:flex-row justify-between items-center gap-4">
                        <span className="text-xs text-slate-500 dark:text-slate-400">
                            Menampilkan <b>{permissions.data.length}</b> data per halaman
                        </span>
                        <Pagination links={permissions.links} />
                    </div>
                )}
            </div>
        </>
    );
}

Index.layout = (page) => <DashboardLayout children={page} />;