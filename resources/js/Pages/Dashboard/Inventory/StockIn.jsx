import React, { useState, useEffect, useRef } from "react";
import DashboardLayout from "@/Layouts/DashboardLayout"; 
import { Head, useForm, router } from "@inertiajs/react";
import Pagination from "@/Components/Dashboard/Pagination";
import { 
    IconDeviceFloppy, IconSearch, IconRefresh, 
    IconHistory, IconPackageImport, IconFileImport, 
    IconDownload, IconDatabase, IconX
} from "@tabler/icons-react";
import Swal from "sweetalert2";
import axios from "axios";

export default function StockIn({ auth, products, ingredients, history, filters }) {
    const [searchQuery, setSearchQuery] = useState(filters.search || "");
    const [activeTab, setActiveTab] = useState("products"); 
    const fileInputRef = useRef(null);

    // State untuk Manajemen Batch & Analisis COGS
    const [batchInfo, setBatchInfo] = useState({ 
        item_name: '', 
        average_cost: 0, 
        total_stock: 0, 
        total_asset_value: 0, 
        batches: [] 
    });
    const [showBatchModal, setShowBatchModal] = useState(false);
    const [loadingBatch, setLoadingBatch] = useState(false);

    const { data, setData, reset, processing } = useForm({
        entries: []
    });

    useEffect(() => {
        const sourceData = activeTab === "products" ? products?.data : ingredients?.data;
        
        if (sourceData) {
            const initialData = sourceData.map(item => ({
                id: item.id,
                type: activeTab,
                title: item.title || item.name,
                barcode: item.barcode || `ING-${item.id}`,
                unit: item.unit || '-',
                stock_current: item.stock,
                qty_in: "", 
                buy_price: item.buy_price || "" 
            }));
            setData('entries', initialData);
        }
    }, [products, ingredients, activeTab]);

    const handleSearch = (e) => {
        e.preventDefault();
        router.get(route("stock_in.index"), { ...filters, search: searchQuery }, { preserveState: true });
    };

    const handleInputChange = (index, field, value) => {
        const newEntries = [...data.entries];
        newEntries[index][field] = value;
        setData('entries', newEntries);
    };

    /**
     * FETCH DETAIL BATCH & ANALISIS HITUNGAN HPP
     */
    const fetchBatchDetail = (id, type) => {
        setLoadingBatch(true);
        axios.get(route('stock_in.batch_detail', { id, type }))
            .then(res => {
                setBatchInfo(res.data);
                setShowBatchModal(true);
            })
            .catch(err => {
                console.error(err);
                Swal.fire("Error", "Gagal mengambil rincian modal batch.", "error");
            })
            .finally(() => setLoadingBatch(false));
    };

    const handleImportExcel = (e) => {
        const file = e.target.files[0];
        if (!file) return;

        Swal.fire({
            title: 'Memproses Excel...',
            text: 'Harap tunggu, sistem sedang memperbarui stok database.',
            allowOutsideClick: false,
            didOpen: () => { Swal.showLoading(); }
        });

        router.post(route('stock_in.parse_excel'), { file: file }, {
            forceFormData: true,
            onSuccess: () => { 
                e.target.value = null; 
                Swal.fire({
                    icon: 'success',
                    title: 'IMPORT BERHASIL',
                    text: 'Data Excel berhasil diproses dan stok telah diperbarui.',
                    confirmButtonColor: '#10b981',
                });
            },
            onError: (errors) => { 
                Swal.fire("Gagal", "Pastikan format file sesuai template.", "error"); 
            }
        });
    };

    const submitBulkStockIn = (e) => {
        e.preventDefault();
        const filledData = data.entries
            .filter(item => item.qty_in !== "" && parseFloat(item.qty_in) > 0)
            .map(item => ({
                id: item.id,
                type: item.type,
                qty_in: parseFloat(item.qty_in),
                buy_price: parseFloat(item.buy_price) || 0
            }));
        
        if (filledData.length === 0) {
            Swal.fire({ 
                icon: 'warning', 
                title: 'INPUT KOSONG', 
                text: 'Silakan isi kolom Qty Masuk terlebih dahulu.',
                confirmButtonColor: '#e11d48',
            });
            return;
        }

        router.post(route("stock_in.store"), { entries: filledData }, {
            preserveScroll: true,
            onSuccess: () => {
                reset(); 
                Swal.fire('BERHASIL', 'Stok manual berhasil ditambahkan.', 'success');
            },
        });
    };

    return (
        <DashboardLayout auth={auth}> 
            <Head title="Stock In - Inventory" />

            <div className="min-h-screen bg-transparent text-slate-900 dark:text-white font-sans p-2 md:p-6">
                
                {/* --- HEADER --- */}
                <div className="mb-6 flex flex-col xl:flex-row xl:items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-bold flex items-center gap-2 uppercase tracking-tight">
                            <IconPackageImport size={28} className="text-rose-500" />
                            Stock In
                        </h1>
                        <p className="text-sm text-slate-500 dark:text-slate-400 font-medium uppercase italic leading-none">
                            Update Stok & Manajemen Batch (FIFO, LIFO, AVERAGE)
                        </p>
                    </div>
                    
                    <div className="flex flex-wrap items-center gap-3">
                        <div className="flex bg-white dark:bg-slate-800 p-1 rounded-2xl shadow-sm border border-slate-200 dark:border-slate-700">
                            <button onClick={() => setActiveTab("products")} className={`px-4 py-2 rounded-xl text-[10px] font-black uppercase transition-all ${activeTab === "products" ? "bg-rose-500 text-white shadow-lg" : "text-slate-500 hover:bg-slate-50"}`}>
                                Produk Jadi
                            </button>
                            <button onClick={() => setActiveTab("ingredients")} className={`px-4 py-2 rounded-xl text-[10px] font-black uppercase transition-all ${activeTab === "ingredients" ? "bg-emerald-500 text-white shadow-lg" : "text-slate-500 hover:bg-slate-50"}`}>
                                Bahan Baku
                            </button>
                        </div>

                        <button onClick={() => window.open(route("stock_in.export"), "_blank")} className="flex items-center gap-2 bg-slate-700 text-white px-4 py-3 rounded-xl font-bold text-xs uppercase shadow-md hover:bg-slate-800 transition-all">
                            <IconHistory size={18} /> History
                        </button>

                        <button onClick={submitBulkStockIn} disabled={processing} className="flex items-center gap-2 bg-rose-600 hover:bg-rose-700 text-white px-6 py-3 rounded-xl font-black shadow-lg shadow-rose-200 dark:shadow-none uppercase text-xs transition-all active:scale-95 disabled:opacity-50">
                            <IconDeviceFloppy size={20} /> {processing ? "Proses..." : "Simpan Masuk Manual"}
                        </button>
                    </div>
                </div>

                {/* --- SEARCH & IMPORT BAR --- */}
                <div className="bg-white dark:bg-slate-900 p-4 rounded-2xl border border-slate-200 dark:border-slate-800 mb-6 shadow-sm flex flex-col lg:flex-row items-center gap-4">
                    <form onSubmit={handleSearch} className="relative flex-1 w-full text-slate-900">
                        <IconSearch size={18} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                        <input type="text" className="pl-10 w-full rounded-xl border-slate-200 dark:border-slate-700 dark:bg-slate-800 text-slate-900 dark:text-white text-sm focus:ring-rose-500 font-bold uppercase" placeholder={`Cari di kategori ${activeTab === 'products' ? 'produk' : 'bahan baku'}...`} value={searchQuery} onChange={e => setSearchQuery(e.target.value)} />
                    </form>
                    
                    <div className="flex flex-wrap items-center gap-3 w-full lg:w-auto">
                        <input type="file" ref={fileInputRef} onChange={handleImportExcel} className="hidden" accept=".xlsx, .xls, .csv" />
                        
                        <div className="flex flex-1 bg-white dark:bg-slate-800 rounded-xl overflow-hidden border border-slate-200 dark:border-slate-700 font-black uppercase text-[10px]">
                            <button 
                                onClick={() => window.open(activeTab === 'products' ? route("stock_in.template_product") : route("stock_in.template_ingredient"), "_blank")} 
                                className="flex-1 p-3 text-blue-600 border-r border-slate-200 dark:border-slate-700 flex items-center justify-center gap-2 hover:bg-blue-50 transition-colors"
                            >
                                <IconDownload size={16}/> Template {activeTab === 'products' ? 'Produk' : 'Bahan'}
                            </button>

                            <button 
                                onClick={() => fileInputRef.current.click()} 
                                className="flex-1 p-3 text-emerald-600 flex items-center justify-center gap-2 hover:bg-emerald-50 transition-colors"
                            >
                                <IconFileImport size={16}/> Import Excel
                            </button>
                        </div>

                        <button onClick={() => router.get(route("stock_in.index"))} className="p-3 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-500 border border-slate-200 dark:border-slate-700 hover:bg-slate-200 transition-all">
                            <IconRefresh size={20} />
                        </button>
                    </div>
                </div>

                {/* --- TABEL INPUT MASTER (TOMBOL DETAIL PINDAH KE SINI) --- */}
                <div className="bg-white dark:bg-slate-900 rounded-3xl border border-slate-200 dark:border-slate-800 overflow-hidden shadow-sm mb-12">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm text-left border-collapse">
                            <thead className="text-[10px] uppercase bg-slate-50 dark:bg-slate-800 font-black tracking-widest text-slate-500 border-b border-slate-200 dark:border-slate-800">
                                <tr>
                                    <th className="px-6 py-5">Informasi Item</th>
                                    <th className="px-6 py-5 text-center">Satuan</th>
                                    <th className="px-6 py-5 text-center font-bold text-slate-900 dark:text-white">Stok Sistem</th>
                                    <th className="px-6 py-5 text-center">Batch Info</th>
                                    <th className="px-6 py-5 text-center bg-rose-50/50 dark:bg-rose-900/10 text-rose-600">Qty Masuk</th>
                                    <th className="px-6 py-5 text-center bg-amber-50/50 dark:bg-amber-900/10 text-amber-600">Harga Modal Baru</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 dark:divide-slate-800 text-slate-900 dark:text-white">
                                {data.entries.length > 0 ? data.entries.map((item, index) => (
                                    <tr key={`${item.type}-${item.id}`} className="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors">
                                        <td className="px-6 py-4">
                                            <div className="font-bold uppercase text-xs flex items-center gap-2">
                                                {item.type === 'ingredients' ? <IconDatabase size={14} className="text-emerald-500" /> : <IconPackageImport size={14} className="text-rose-500" />}
                                                {item.title}
                                            </div>
                                            <span className="text-[10px] font-mono text-slate-400 font-normal tracking-tighter">{item.barcode}</span>
                                        </td>
                                        <td className="px-6 py-4 text-center">
                                            <span className="px-2 py-1 bg-slate-100 dark:bg-slate-800 rounded text-[10px] font-black text-slate-500 uppercase">{item.unit}</span>
                                        </td>
                                        <td className="px-6 py-4 text-center font-black text-slate-900 dark:text-white italic text-base">{item.stock_current}</td>
                                        
                                        {/* TOMBOL CEK BATCH DI TABEL MASTER */}
                                        <td className="px-6 py-4 text-center">
                                            <button 
                                                type="button"
                                                onClick={() => fetchBatchDetail(item.id, item.type)}
                                                className="p-2 bg-blue-50 dark:bg-blue-900/20 text-blue-600 rounded-xl hover:bg-blue-100 transition-colors"
                                                title="Klik untuk Analisis HPP & Sisa Batch"
                                            >
                                                <IconDatabase size={18} />
                                            </button>
                                        </td>

                                        <td className="px-6 py-4 bg-rose-50/20 dark:bg-rose-900/5 text-center">
                                            <input type="number" step="any" className="w-28 mx-auto rounded-xl border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-center font-black text-rose-600 focus:ring-rose-500 py-2 shadow-sm" value={item.qty_in} onChange={e => handleInputChange(index, 'qty_in', e.target.value)} placeholder="0" />
                                        </td>
                                        <td className="px-6 py-4 bg-amber-50/20 dark:bg-amber-900/5 text-center">
                                            <input type="number" className="w-40 mx-auto rounded-xl border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-center font-black text-amber-600 focus:ring-amber-500 py-2 shadow-sm" value={item.buy_price} onChange={e => handleInputChange(index, 'buy_price', e.target.value)} placeholder="0" />
                                        </td>
                                    </tr>
                                )) : (
                                    <tr><td colSpan="6" className="p-10 text-center text-slate-400 uppercase font-bold text-xs tracking-widest italic">Barang tidak ditemukan.</td></tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                    <div className="px-6 py-4 border-t border-slate-100 dark:border-slate-800 bg-slate-50/30 dark:bg-slate-800/30">
                        <Pagination links={activeTab === "products" ? products?.links : ingredients?.links} />
                    </div>
                </div>

                {/* --- SEKSI RIWAYAT --- */}
                <div className="mb-6 flex items-center gap-2 border-t pt-10 border-slate-200 dark:border-slate-800">
                    <IconHistory size={24} className="text-orange-500" />
                    <h2 className="text-xl font-bold text-slate-900 dark:text-white uppercase tracking-tight">Riwayat Masuk Barang</h2>
                </div>

                <div className="bg-white dark:bg-slate-900 rounded-3xl border border-slate-200 dark:border-slate-800 overflow-hidden shadow-sm mb-10">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm text-left">
                            <thead className="text-[10px] text-slate-400 uppercase bg-slate-50 dark:bg-slate-800 font-black tracking-widest border-b border-slate-200 dark:border-slate-800 text-center">
                                <tr>
                                    <th className="px-6 py-4 text-left">Waktu & Admin</th>
                                    <th className="px-6 py-4 text-left">Nama Item</th>
                                    <th className="px-6 py-4">Jumlah</th>
                                    <th className="px-6 py-4 text-right">Modal</th>
                                    <th className="px-6 py-4">Reference</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 dark:divide-slate-800 text-slate-700 dark:text-slate-300">
                                {history?.data?.length > 0 ? history.data.map((h) => (
                                    <tr key={h.id} className="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors text-center">
                                        <td className="px-6 py-4 text-left">
                                            <div className="font-bold text-slate-900 dark:text-white uppercase text-[11px]">{h.user?.name}</div>
                                            <div className="text-[10px] text-slate-400 italic">{new Date(h.created_at).toLocaleString('id-ID')}</div>
                                        </td>
                                        <td className="px-6 py-4 font-bold uppercase text-[11px] text-left">
                                            {h.product?.title || h.ingredient?.name}
                                            <span className={`ml-2 text-[9px] px-1.5 py-0.5 rounded font-black ${h.product_id ? 'bg-rose-100 text-rose-600' : 'bg-emerald-100 text-emerald-600'}`}>
                                                {h.product_id ? 'PRODUK' : 'BAHAN'}
                                            </span>
                                        </td>
                                        <td className="px-6 py-4 text-emerald-600 font-black">+{h.qty}</td>
                                        <td className="px-6 py-4 text-right font-black text-slate-900 dark:text-white">Rp {new Intl.NumberFormat('id-ID').format(h.price)}</td>
                                        <td className="px-6 py-4 font-mono text-[10px] text-slate-400 uppercase">{h.reference}</td>
                                    </tr>
                                )) : (
                                    <tr><td colSpan="5" className="p-10 text-center text-xs opacity-40 uppercase font-bold tracking-widest italic">Belum ada riwayat.</td></tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>

                {/* --- MODAL RINCIAN BATCH & ANALISIS HPP --- */}
                {showBatchModal && (
                    <div className="fixed inset-0 z-[99] flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm animate-in fade-in duration-200">
                        <div className="bg-white dark:bg-slate-900 w-full max-w-3xl rounded-3xl shadow-2xl overflow-hidden border border-slate-200 dark:border-slate-800">
                            
                            {/* Header Modal */}
                            <div className="p-6 border-b border-slate-100 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-800/50 flex justify-between items-start">
                                <div>
                                    <h3 className="font-black uppercase text-lg text-slate-900 dark:text-white leading-none">
                                        Manajemen Batch & Analisis COGS
                                    </h3>
                                    <p className="text-sm font-bold text-rose-500 uppercase mt-1">{batchInfo.item_name}</p>
                                </div>
                                <button onClick={() => setShowBatchModal(false)} className="p-2 hover:bg-rose-100 hover:text-rose-500 rounded-full transition-colors">
                                    <IconX size={20} />
                                </button>
                            </div>
                            
                            <div className="p-6 max-h-[40vh] overflow-y-auto">
                                
                                <table className="w-full text-sm">
                                    <thead className="sticky top-0 bg-white dark:bg-slate-900 shadow-sm">
                                        <tr className="text-[10px] uppercase font-black text-slate-400 border-b border-slate-100 dark:border-slate-800">
                                            <th className="py-3 text-left">Ref / Batch</th>
                                            <th className="py-3 text-center">Sisa Stok</th>
                                            <th className="py-3 text-right">Harga Modal</th>
                                            <th className="py-3 text-right">Subtotal Nilai</th>
                                            <th className="py-3 text-center">Target</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-50 dark:divide-slate-800 text-[11px]">
                                        {batchInfo.batches.length > 0 ? batchInfo.batches.map((batch, index) => (
                                            <tr key={batch.id} className="hover:bg-slate-50/50 dark:hover:bg-slate-800/30 transition-colors">
                                                <td className="py-4 font-mono text-slate-500 uppercase">
                                                    {batch.serial_number}
                                                    <div className="text-[9px] text-slate-400 normal-case mt-0.5">Masuk: {new Date(batch.created_at).toLocaleDateString('id-ID')}</div>
                                                </td>
                                                <td className="py-4 text-center">
                                                    <span className="font-black text-blue-600">{batch.qty_remaining}</span>
                                                    <div className="text-[9px] text-slate-400 font-bold uppercase">{batch.weight}% Stok</div>
                                                </td>
                                                <td className="py-4 text-right font-bold text-slate-400">
                                                    Rp {new Intl.NumberFormat('id-ID').format(batch.buy_price)}
                                                </td>
                                                <td className="py-4 text-right font-black text-emerald-600">
                                                    Rp {new Intl.NumberFormat('id-ID').format(batch.subtotal)}
                                                </td>
                                                <td className="py-4 text-center">
                                                    {index === 0 ? (
                                                        <span className="text-[9px] font-black bg-rose-500 text-white px-2 py-0.5 rounded shadow-sm">FIFO</span>
                                                    ) : index === batchInfo.batches.length - 1 ? (
                                                        <span className="text-[9px] font-black bg-orange-500 text-white px-2 py-0.5 rounded shadow-sm">LIFO</span>
                                                    ) : (
                                                        <span className="text-[9px] font-black text-slate-300 italic">QUEUE</span>
                                                    )}
                                                </td>
                                            </tr>
                                        )) : (
                                            <tr><td colSpan="5" className="py-12 text-center text-slate-400 uppercase font-black text-[10px] opacity-60 italic">Semua batch telah habis digunakan.</td></tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>

                            {/* --- SEKSI ANALISIS RUMUS REAL --- */}
                            <div className="p-6 bg-slate-50 dark:bg-slate-800/50 border-t border-slate-200 dark:border-slate-800">
                                <div className="flex items-center gap-2 mb-4">
                                    <IconRefresh size={16} className="text-blue-500" />
                                    <h4 className="text-[10px] font-black uppercase text-slate-900 dark:text-white tracking-widest">Logika Perhitungan Moving Average</h4>
                                </div>
                                
                                <div className="grid grid-cols-1 md:grid-cols-2 gap-4 font-mono text-[11px] mb-4">
                                    <div className="p-3 bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm">
                                        <span className="text-slate-400 block text-[9px] uppercase font-bold mb-1">Σ Total Nilai Aset</span>
                                        <span className="font-black text-slate-900 dark:text-white text-sm">
                                            Rp {new Intl.NumberFormat('id-ID').format(batchInfo.total_asset_value)}
                                        </span>
                                    </div>
                                    <div className="p-3 bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm">
                                        <span className="text-slate-400 block text-[9px] uppercase font-bold mb-1">Total Stok Fisik</span>
                                        <span className="font-black text-slate-900 dark:text-white text-sm">
                                            {batchInfo.total_stock} Unit
                                        </span>
                                    </div>
                                </div>

                                <div className="bg-blue-600 p-5 rounded-2xl text-white shadow-xl shadow-blue-200 dark:shadow-none">
                                    <p className="text-[9px] font-black uppercase tracking-widest opacity-80 mb-2 italic">Persamaan Matematika HPP (Average):</p>
                                    <p className="text-xs font-black tracking-tight leading-relaxed">
                                        Total Aset (Rp {new Intl.NumberFormat('id-ID').format(batchInfo.total_asset_value)}) ÷ Total Stok ({batchInfo.total_stock}) 
                                        <br />
                                        <span className="text-lg mt-1 block">
                                            = <span className="bg-white text-blue-600 px-3 py-0.5 rounded-lg border-2 border-white">Rp {new Intl.NumberFormat('id-ID').format(batchInfo.average_cost)} /unit</span>
                                        </span>
                                    </p>
                                </div>
                            </div>

                            <div className="p-4 flex justify-end bg-white dark:bg-slate-900 border-t dark:border-slate-800">
                                <button onClick={() => setShowBatchModal(false)} className="px-10 py-3 bg-slate-900 dark:bg-slate-700 text-white rounded-2xl text-[10px] font-black uppercase tracking-widest hover:bg-slate-800 active:scale-95 transition-all">
                                    Tutup Analisis
                                </button>
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </DashboardLayout>
    );
}