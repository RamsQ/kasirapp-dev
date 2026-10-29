import React, { useState } from "react";
import { Head, useForm, router } from "@inertiajs/react";
import {
    IconPigMoney,
    IconPlus,
    IconTrash,
    IconDeviceFloppy,
    IconX,
    IconInfoCircle,
} from "@tabler/icons-react";
import Swal from "sweetalert2";
import DashboardLayout from "@/Layouts/DashboardLayout";

const formatCurrency = (value) =>
    new Intl.NumberFormat("id-ID", {
        style: "currency",
        currency: "IDR",
        minimumFractionDigits: 0,
    }).format(value ?? 0);

/**
 * Modal usaha / setoran modal pemilik.
 *
 * Catatan penting untuk pembaca berikutnya: angka di halaman ini adalah
 * sisi EKUITAS pada neraca, bukan aset. Uang yang disetor sudah ada di
 * "Kas di Laci" atau di rekening bank, jadi sistem tidak menjumlahkannya
 * sebagai aset agar uang yang sama tidak terhitung dua kali.
 */
export default function Index({ auth, capitals, summary }) {
    const [showForm, setShowForm] = useState(false);

    const { data, setData, post, processing, errors, reset } = useForm({
        source: "",
        amount: "",
        date: new Date().toISOString().slice(0, 10),
    });

    const submit = (e) => {
        e.preventDefault();
        post(route("capitals.store"), {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                setShowForm(false);
            },
        });
    };

    const handleDelete = (id) => {
        Swal.fire({
            title: "Hapus setoran modal?",
            text: "Baris ini akan dihapus dari neraca.",
            icon: "warning",
            showCancelButton: true,
            confirmButtonText: "Ya, Hapus",
            cancelButtonText: "Batal",
            confirmButtonColor: "#e11d48",
        }).then((result) => {
            if (result.isConfirmed) {
                router.delete(route("capitals.destroy", id), { preserveScroll: true });
            }
        });
    };

    return (
        <DashboardLayout title="Modal Usaha" auth={auth}>
            <Head title="Modal Usaha" />

            <div className="space-y-6">
                {/* RINGKASAN */}
                <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm p-5 flex items-center gap-4">
                        <div className="p-3 bg-emerald-50 dark:bg-emerald-900/20 text-emerald-600 rounded-xl">
                            <IconPigMoney size={24} />
                        </div>
                        <div>
                            <p className="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Total Setoran</p>
                            <p className="text-xl font-bold text-slate-700 dark:text-white leading-tight">
                                {formatCurrency(summary.total_in)}
                            </p>
                        </div>
                    </div>
                    <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm p-5 flex items-center gap-4">
                        <div className="p-3 bg-primary-50 dark:bg-primary-900/20 text-primary-600 dark:text-primary-400 rounded-xl">
                            <IconPlus size={24} />
                        </div>
                        <div>
                            <p className="text-[10px] font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Jumlah Setoran</p>
                            <p className="text-xl font-bold text-slate-700 dark:text-white leading-tight">
                                {summary.count} kali
                            </p>
                        </div>
                    </div>
                    <div className="flex items-center">
                        <button
                            onClick={() => setShowForm((v) => !v)}
                            className="w-full bg-emerald-600 dark:bg-emerald-700 hover:bg-emerald-700 text-white px-5 py-4 rounded-xl font-black text-xs uppercase tracking-widest shadow-lg transition-all active:scale-95 flex items-center justify-center gap-2"
                        >
                            {showForm ? <IconX size={18} /> : <IconPlus size={18} />}
                            {showForm ? "Tutup Form" : "Catat Setoran Modal"}
                        </button>
                    </div>
                </div>

                {/* FORM */}
                {showForm && (
                    <form
                        onSubmit={submit}
                        className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm p-6 space-y-4 animate-in fade-in slide-in-from-bottom-4"
                    >
                        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label className="block text-[10px] font-bold uppercase text-slate-500 dark:text-slate-400 tracking-widest mb-2">
                                    Sumber / Keterangan
                                </label>
                                <input
                                    type="text"
                                    value={data.source}
                                    onChange={(e) => setData("source", e.target.value)}
                                    placeholder="Contoh: Setoran Modal Awal"
                                    className={`w-full rounded-xl dark:bg-slate-800 dark:text-white border-slate-200 dark:border-slate-700 text-sm font-bold focus:ring-emerald-500 ${errors.source ? "border-rose-400" : ""}`}
                                    required
                                />
                                {errors.source && (
                                    <p className="text-rose-500 text-xs mt-1 font-bold">{errors.source}</p>
                                )}
                            </div>
                            <div>
                                <label className="block text-[10px] font-bold uppercase text-slate-500 dark:text-slate-400 tracking-widest mb-2">
                                    Nominal (Rp)
                                </label>
                                <input
                                    type="number"
                                    step="any"
                                    min="0.01"
                                    value={data.amount}
                                    onChange={(e) => setData("amount", e.target.value)}
                                    placeholder="0"
                                    className={`w-full rounded-xl dark:bg-slate-800 dark:text-white border-slate-200 dark:border-slate-700 text-sm font-bold focus:ring-emerald-500 ${errors.amount ? "border-rose-400" : ""}`}
                                    required
                                />
                                {errors.amount && (
                                    <p className="text-rose-500 text-xs mt-1 font-bold">{errors.amount}</p>
                                )}
                            </div>
                            <div>
                                <label className="block text-[10px] font-bold uppercase text-slate-500 dark:text-slate-400 tracking-widest mb-2">
                                    Tanggal
                                </label>
                                <input
                                    type="date"
                                    value={data.date}
                                    onChange={(e) => setData("date", e.target.value)}
                                    className={`w-full rounded-xl dark:bg-slate-800 dark:text-white border-slate-200 dark:border-slate-700 text-sm font-bold focus:ring-emerald-500 ${errors.date ? "border-rose-400" : ""}`}
                                    required
                                />
                                {errors.date && (
                                    <p className="text-rose-500 text-xs mt-1 font-bold">{errors.date}</p>
                                )}
                            </div>
                        </div>

                        <div className="flex items-start gap-3 p-3 rounded-xl bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800">
                            <IconInfoCircle size={16} className="text-amber-600 shrink-0 mt-0.5" />
                            <p className="text-xs text-amber-800 dark:text-amber-300 leading-relaxed">
                                Setoran modal dicatat sebagai <strong>modal (ekuitas)</strong>, bukan aset.
                                Uang fisiknya tetap harus terlihat di <strong>Kas di Laci</strong> (modal awal
                                shift) atau di rekening bank. Penarikan modal dicatat di menu
                                <strong> Expenses</strong> dengan sumber &ldquo;Modal Luar&rdquo;.
                            </p>
                        </div>

                        <button
                            type="submit"
                            disabled={processing}
                            className="bg-slate-900 dark:bg-emerald-600 text-white px-6 py-3 rounded-xl font-black uppercase tracking-widest transition-all active:scale-95 disabled:opacity-50 flex items-center gap-2"
                        >
                            <IconDeviceFloppy size={18} /> Simpan Setoran
                        </button>
                    </form>
                )}

                {/* TABEL */}
                <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm overflow-hidden">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm text-left">
                            <thead className="bg-slate-50 dark:bg-slate-800/50 text-slate-500 dark:text-slate-400 font-bold uppercase text-[10px] tracking-widest border-b dark:border-slate-800">
                                <tr>
                                    <th className="px-6 py-4 w-12">#</th>
                                    <th className="px-4 py-4">Tanggal</th>
                                    <th className="px-4 py-4">Sumber / Keterangan</th>
                                    <th className="px-4 py-4 text-right">Nominal</th>
                                    <th className="px-6 py-4 text-right w-20">Aksi</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                {capitals.data.length > 0 ? (
                                    capitals.data.map((row, i) => (
                                        <tr key={row.id} className="hover:bg-slate-50/80 dark:hover:bg-slate-800/50">
                                            <td className="px-6 py-4 text-slate-500 dark:text-slate-400 font-bold">{i + 1}</td>
                                            <td className="px-4 py-4 text-slate-500 dark:text-slate-400 font-mono text-xs">
                                                {row.date}
                                            </td>
                                            <td className="px-4 py-4 font-bold text-slate-700 dark:text-slate-200 uppercase">
                                                {row.source}
                                            </td>
                                            <td className="px-4 py-4 text-right font-mono font-bold text-emerald-600 dark:text-emerald-400">
                                                {formatCurrency(row.amount)}
                                            </td>
                                            <td className="px-6 py-4 text-right">
                                                <button
                                                    onClick={() => handleDelete(row.id)}
                                                    className="p-2 bg-rose-50 dark:bg-rose-900/30 text-rose-600 rounded-xl hover:bg-rose-100 active:scale-95 transition-all"
                                                >
                                                    <IconTrash size={16} />
                                                </button>
                                            </td>
                                        </tr>
                                    ))
                                ) : (
                                    <tr>
                                        <td colSpan={5} className="py-20 text-center">
                                            <IconPigMoney size={48} className="mx-auto text-slate-200 mb-4" />
                                            <p className="text-sm font-bold uppercase text-slate-500 dark:text-slate-400 tracking-widest">
                                                Belum Ada Setoran Modal
                                            </p>
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </DashboardLayout>
    );
}
