import React, { useState, useEffect, useRef } from "react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { Head, useForm, router } from "@inertiajs/react";
import Pagination from "@/Components/Dashboard/Pagination";
import { 
    IconPackage, IconDeviceFloppy, IconSearch, IconRefresh, 
    IconFileTypeXls, IconUpload, IconFileText, IconCalendar, 
    IconUser, IconDatabase, IconAdjustmentsAlt, IconHistory
} from "@tabler/icons-react";
import Swal from "sweetalert2";

export default function Index({ auth, products, ingredients, history, filters }) {
    const [searchQuery, setSearchQuery] = useState(filters.search || "");
    
    // Logic untuk mendeteksi tab aktif dari URL agar saat pindah page tab tidak reset
    const [activeTab, setActiveTab] = useState(filters.tab || "products"); 
    const fileInput = useRef();

    const { data, setData, post, processing, reset } = useForm({
        adjustments: []
    });

    // Sync data untuk tab input (Produk & Bahan)
    useEffect(() => {
        const sourceData = activeTab === "products" ? products?.data : (activeTab === "ingredients" ? ingredients?.data : []);
        
        if (sourceData.length > 0) {
            const initialData = sourceData.map(item => ({
                id: item.id,
                type: activeTab,
                title: item.title || item.name,
                barcode: item.barcode || `ING-${item.id}`,
                stock_system: item.stock,
                stock_actual: "", 
                reason: ""        
            }));
            setData('adjustments', initialData);
        }
    }, [products, ingredients, activeTab]);

    const handleSearch = (e) => {
        e.preventDefault();
        router.get(route("stock_opnames.index"), { ...filters, search: searchQuery, tab: activeTab }, { preserveState: true });
    };

    const handleTabChange = (tabName) => {
        setActiveTab(tabName);
        // Reset search saat pindah tab agar tidak membingungkan
        router.get(route("stock_opnames.index"), { tab: tabName }, { preserveState: true });
    };

    const handleFilterDate = (field, value) => {
        router.get(route("stock_opnames.index"), { ...filters, [field]: value, tab: 'history' }, { preserveState: true });
    };

    const handleInputChange = (index, field, value) => {
        const newAdjustments = [...data.adjustments];
        newAdjustments[index][field] = value;
        setData('adjustments', newAdjustments);
    };

    const submitBulkOpname = (e) => {
        e.preventDefault();
        const filledData = data.adjustments.filter(item => item.stock_actual !== "");
        
        if (filledData.length === 0) {
            Swal.fire({ icon: 'warning', title: 'Input Kosong', text: 'Isi minimal satu kolom Stok Fisik.' });
            return;
        }

        post(route("stock_opnames.store"), {
            preserveScroll: true,
            onSuccess: () => {
                Swal.fire("Berhasil", "Stok fisik berhasil disesuaikan.", "success");
                reset();
            },
        });
    };

    const handleImport = (e) => {
        const file = e.target.files[0];
        if (!file) return;
        const formData = new FormData();
        formData.append('file', file);
        router.post(route('stock_opnames.import'), formData, {
            forceFormData: true,
            onSuccess: () => {
                Swal.fire("Berhasil", "Data Excel berhasil diimport!", "success");
                e.target.value = null;
            }
        });
    };

    return (
        <DashboardLayout auth={auth}>
            <Head title="Stock Opname" />

            <div className="min-h-screen p-2 md:p-6 text-slate-900 dark:text-white">
                
                {/* --- HEADER --- */}
                <div className="mb-6 flex flex-col xl:flex-row xl:items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-bold flex items-center gap-2 uppercase tracking-tight">
                            <IconAdjustmentsAlt size={28} className="text-indigo-500" />
                            Stock Opname
                        </h1>
                        <p className="text-sm text-slate-500 dark:text-slate-400 font-medium uppercase italic leading-none">
                            Audit Inventaris & Penyesuaian Stok
                        </p>
                    </div>
                    
                    <div className="flex flex-wrap items-center gap-3">
                        {/* Tab Switcher - 3 TAB SEKARANG */}
                        <div className="flex bg-white dark:bg-slate-800 p-1 rounded-2xl shadow-sm border border-slate-200 dark:border-slate-700">
                            <button onClick={() => handleTabChange("products")} className={`px-4 py-2 rounded-xl text-[10px] font-black uppercase transition-all ${activeTab === "products" ? "bg-indigo-500 text-white shadow-lg" : "text-slate-500 hover:bg-slate-50"}`}>
                                Produk Jadi
                            </button>
                            <button onClick={() => handleTabChange("ingredients")} className={`px-4 py-2 rounded-xl text-[10px] font-black uppercase transition-all ${activeTab === "ingredients" ? "bg-emerald-500 text-white shadow-lg" : "text-slate-500 hover:bg-slate-50"}`}>
                                Bahan Baku
                            </button>
                            <button onClick={() => handleTabChange("history")} className={`px-4 py-2 rounded-xl text-[10px] font-black uppercase transition-all ${activeTab === "history" ? "bg-orange-500 text-white shadow-lg" : "text-slate-500 hover:bg-slate-50"}`}>
                                <div className="flex items-center gap-1"><IconHistory size={14}/> Riwayat</div>
                            </button>
                        </div>

                        {activeTab !== 'history' && (
                            <button onClick={submitBulkOpname} disabled={processing} className="flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white px-6 py-3 rounded-xl font-black shadow-lg uppercase text-xs active:scale-95">
                                <IconDeviceFloppy size={20} /> Simpan Opname
                            </button>
                        )}
                    </div>
                </div>

                {/* --- TOOLBAR: SEARCH & EXCEL (Hanya muncul di tab input) --- */}
                {activeTab !== 'history' && (
                    <div className="bg-white dark:bg-slate-900 p-4 rounded-2xl border border-slate-200 dark:border-slate-800 mb-6 shadow-sm flex flex-col lg:flex-row items-center gap-4">
                        <form onSubmit={handleSearch} className="relative flex-1 w-full">
                            <IconSearch size={18} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                            <input type="text" className="pl-10 w-full rounded-xl border-slate-200 dark:border-slate-700 dark:bg-slate-800 text-sm font-bold uppercase focus:ring-indigo-500" placeholder={`Cari ${activeTab}...`} value={searchQuery} onChange={e => setSearchQuery(e.target.value)} />
                        </form>
                        
                        <div className="flex bg-white dark:bg-slate-800 rounded-xl overflow-hidden border border-slate-200 dark:border-slate-700 font-black uppercase text-[10px]">
                            <button onClick={() => window.open(activeTab === 'products' ? route("stock_opnames.template_product") : route("stock_opnames.template_ingredient"), "_blank")} className="p-3 text-blue-600 border-r border-slate-200 dark:border-slate-700 hover:bg-blue-50 transition-colors flex items-center gap-2">
                                <IconFileTypeXls size={16}/> Format Excel
                            </button>
                            <input type="file" ref={fileInput} className="hidden" onChange={handleImport} accept=".xlsx, .xls" />
                            <button onClick={() => fileInput.current.click()} className="p-3 text-emerald-600 hover:bg-emerald-50 transition-colors flex items-center gap-2">
                                <IconUpload size={16}/> Import Excel
                            </button>
                        </div>
                    </div>
                )}

                {/* --- TOOLBAR: DATE FILTER (Hanya muncul di tab history) --- */}
                {activeTab === 'history' && (
                    <div className="bg-white dark:bg-slate-900 p-4 rounded-2xl border border-slate-200 dark:border-slate-800 mb-6 shadow-sm flex flex-wrap items-center gap-4">
                        <div className="flex items-center gap-2 flex-1">
                            <IconCalendar size={20} className="text-orange-500" />
                            <span className="text-xs font-black uppercase tracking-widest">Filter Tanggal:</span>
                        </div>
                        <div className="flex gap-2">
                            <input type="date" className="rounded-xl border-slate-200 dark:border-slate-700 dark:bg-slate-800 text-xs" value={filters.start_date || ""} onChange={e => handleFilterDate('start_date', e.target.value)} />
                            <input type="date" className="rounded-xl border-slate-200 dark:border-slate-700 dark:bg-slate-800 text-xs" value={filters.end_date || ""} onChange={e => handleFilterDate('end_date', e.target.value)} />
                        </div>
                        <button onClick={() => router.get(route("stock_opnames.index"), { tab: 'history' })} className="p-2.5 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-500 border border-slate-200 dark:border-slate-700 hover:bg-slate-200 transition-all">
                            <IconRefresh size={18} />
                        </button>
                    </div>
                )}

                {/* --- MAIN CONTENT AREA --- */}
                <div className="bg-white dark:bg-slate-900 rounded-3xl border border-slate-200 dark:border-slate-800 overflow-hidden shadow-sm">
                    <div className="overflow-x-auto">
                        {activeTab === 'history' ? (
                            /* TABLE RIWAYAT */
                            <table className="w-full text-sm text-left">
                                <thead className="text-[10px] text-slate-500 dark:text-slate-400 uppercase bg-slate-50 dark:bg-slate-800 font-black tracking-widest border-b border-slate-200 dark:border-slate-800">
                                    <tr>
                                        <th className="px-6 py-5">Petugas & Waktu</th>
                                        <th className="px-6 py-5">Item</th>
                                        <th className="px-6 py-5 text-center">Sistem</th>
                                        <th className="px-6 py-5 text-center">Aktual</th>
                                        <th className="px-6 py-5 text-center">Selisih</th>
                                        <th className="px-6 py-5">Alasan</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                    {history.data.length > 0 ? history.data.map((h) => (
                                        <tr key={h.id} className="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors">
                                            <td className="px-6 py-4">
                                                <div className="font-bold text-slate-700 dark:text-slate-200 flex items-center gap-1 uppercase text-[11px]"><IconUser size={14}/> {h.user?.name}</div>
                                                <div className="text-[10px] text-slate-400 italic">{new Date(h.created_at).toLocaleString('id-ID')}</div>
                                            </td>
                                            <td className="px-6 py-4">
                                                <div className="font-black uppercase text-xs text-slate-900 dark:text-white">
                                                    {h.product?.title || h.ingredient?.name}
                                                </div>
                                                <span className={`text-[9px] px-1.5 py-0.5 rounded font-black ${h.product_id ? 'bg-indigo-100 text-indigo-600' : 'bg-emerald-100 text-emerald-600'}`}>
                                                    {h.product_id ? 'PRODUK' : 'BAHAN'}
                                                </span>
                                            </td>
                                            <td className="px-6 py-4 text-center text-slate-400">{h.stock_system}</td>
                                            <td className="px-6 py-4 text-center font-black text-slate-900 dark:text-white">{h.stock_actual}</td>
                                            <td className="px-6 py-4 text-center">
                                                <span className={`px-2 py-1 rounded-md text-[11px] font-black ${h.difference < 0 ? 'bg-red-100 text-red-600' : h.difference > 0 ? 'bg-green-100 text-green-600' : 'bg-slate-100 text-slate-500'}`}>
                                                    {h.difference > 0 ? `+${h.difference}` : h.difference}
                                                </span>
                                            </td>
                                            <td className="px-6 py-4 text-[11px] italic text-slate-500">{h.reason}</td>
                                        </tr>
                                    )) : (
                                        <tr><td colSpan="6" className="p-20 text-center text-slate-400 font-bold uppercase text-xs tracking-widest opacity-60">Belum ada riwayat opname.</td></tr>
                                    )}
                                </tbody>
                            </table>
                        ) : (
                            /* TABLE INPUT (PRODUK / BAHAN) */
                            <table className="w-full text-sm text-left">
                                <thead className="text-[10px] text-slate-500 dark:text-slate-400 uppercase bg-slate-50 dark:bg-slate-800 font-black tracking-widest border-b border-slate-200 dark:border-slate-800">
                                    <tr>
                                        <th className="px-6 py-5">Informasi Item</th>
                                        <th className="px-6 py-5 text-center">Stok Sistem</th>
                                        <th className="px-6 py-5 text-center w-48 bg-indigo-50/50 dark:bg-indigo-900/10 text-indigo-600 font-black italic">Stok Fisik (Aktual)</th>
                                        <th className="px-6 py-5 text-center">Selisih</th>
                                        <th className="px-6 py-5">Keterangan</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                    {data.adjustments.length > 0 ? data.adjustments.map((item, index) => {
                                        const diff = item.stock_actual !== "" ? (parseFloat(item.stock_actual) - item.stock_system) : 0;
                                        return (
                                            <tr key={`${item.type}-${item.id}`} className="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors">
                                                <td className="px-6 py-4">
                                                    <div className="font-bold uppercase text-xs flex items-center gap-2 text-slate-900 dark:text-white">
                                                        {item.type === 'ingredients' ? <IconDatabase size={14} className="text-emerald-500" /> : <IconPackage size={14} className="text-indigo-500" />}
                                                        {item.title}
                                                    </div>
                                                    <span className="text-[10px] font-mono text-slate-400 dark:text-slate-500">{item.barcode}</span>
                                                </td>
                                                <td className="px-6 py-4 text-center font-bold text-slate-400">{item.stock_system}</td>
                                                <td className="px-6 py-4 bg-indigo-50/20 dark:bg-indigo-900/5 text-center">
                                                    <input type="number" step="any" className="w-32 mx-auto rounded-xl border-slate-200 dark:border-slate-700 dark:bg-slate-800 text-center font-black text-indigo-600 focus:ring-indigo-500 py-2" value={item.stock_actual} onChange={e => handleInputChange(index, 'stock_actual', e.target.value)} placeholder={item.stock_system} />
                                                </td>
                                                <td className="px-6 py-4 text-center">
                                                    <span className={`text-xs font-black px-2 py-1 rounded-lg ${diff > 0 ? 'bg-emerald-100 text-emerald-600' : diff < 0 ? 'bg-rose-100 text-rose-600' : 'text-slate-300'}`}>
                                                        {diff > 0 ? `+${diff}` : diff}
                                                    </span>
                                                </td>
                                                <td className="px-6 py-4">
                                                    <input type="text" className="w-full rounded-xl border-slate-200 dark:border-slate-700 dark:bg-slate-800 text-slate-900 dark:text-white text-xs" placeholder="Alasan penyesuaian..." value={item.reason} onChange={e => handleInputChange(index, 'reason', e.target.value)} />
                                                </td>
                                            </tr>
                                        );
                                    }) : (
                                        <tr><td colSpan="5" className="p-20 text-center text-slate-400 font-bold uppercase text-xs italic tracking-widest">Data tidak ditemukan.</td></tr>
                                    )}
                                </tbody>
                            </table>
                        )}
                    </div>
                    
                    {/* PAGINATION DINAMIS */}
                    <div className="px-6 py-4 border-t border-slate-100 dark:border-slate-800 bg-slate-50/30 dark:bg-slate-800/30 text-center">
                        <Pagination 
                            links={
                                activeTab === "products" ? products?.links : 
                                (activeTab === "ingredients" ? ingredients?.links : history?.links)
                            } 
                        />
                    </div>
                </div>
            </div>
        </DashboardLayout>
    );
}