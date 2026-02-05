import React, { useState, useRef } from "react";
import DashboardLayout from "@/Layouts/DashboardLayout"; 
import { Head, useForm, router } from "@inertiajs/react";
import { 
    IconDatabaseImport, IconSearch, IconRefresh, 
    IconFileImport, IconAlertTriangle, IconDownload, 
    IconCash, IconTrash, IconEdit, IconPlus, IconX, IconPackages
} from "@tabler/icons-react";
import Swal from "sweetalert2";

export default function IngredientIndex({ auth, ingredients, filters }) {
    const [searchQuery, setSearchQuery] = useState(filters?.search || "");
    const [modalMode, setModalMode] = useState(null); 
    const [selectedId, setSelectedId] = useState(null);
    const fileInputRef = useRef(null);

    const { data, setData, post, put, processing, reset, errors } = useForm({
        name: "",
        unit: "", 
        buy_price: "",
        min_stock: "",
        stock: "" 
    });

    // --- MODAL CONTROL ---
    const openCreateModal = () => {
        reset(); 
        setModalMode('create');
    };

    const openEditModal = (item) => {
        setModalMode('edit');
        setSelectedId(item.id);
        setData({
            name: item.name || "",
            unit: item.unit || "",
            buy_price: item.buy_price || 0,
            min_stock: item.min_stock || 0,
            stock: item.stock || 0
        });
    };

    const closeModal = () => {
        setModalMode(null);
        reset();
    };

    // --- LOGIKA SEARCH ---
    const handleSearch = (e) => {
        e.preventDefault();
        router.get(route("ingredients.index"), { search: searchQuery }, { preserveState: true });
    };

    // --- LOGIKA TEMPLATE & IMPORT ---
    const handleDownloadTemplate = () => {
        window.open(route("ingredients.template"), "_blank");
    };

    const handleImportExcel = (e) => {
        const file = e.target.files[0];
        if (!file) return;

        const formData = new FormData();
        formData.append('file', file);

        router.post(route('ingredients.import'), formData, {
            forceFormData: true,
            onSuccess: () => {
                e.target.value = null;
                Swal.fire('BERHASIL', 'Data Bahan Baku telah diimpor.', 'success');
            },
            onError: () => {
                Swal.fire('GAGAL', 'Pastikan format file Excel benar.', 'error');
            }
        });
    };

    // --- LOGIKA CRUD ---
    const handleSubmit = (e) => {
        e.preventDefault();
        
        const options = {
            preserveScroll: true,
            onSuccess: () => {
                closeModal();
                Swal.fire({
                    title: 'BERHASIL',
                    text: modalMode === 'create' ? 'Bahan baku berhasil ditambah' : 'Data berhasil diperbarui',
                    icon: 'success',
                    confirmButtonColor: '#10b981'
                });
            },
            onError: (err) => {
                Swal.fire('GAGAL', 'Periksa kembali inputan Anda', 'error');
            }
        };

        if (modalMode === 'create') {
            post(route('ingredients.store'), options);
        } else {
            put(route('ingredients.update', selectedId), options);
        }
    };

    const destroyIngredient = (id) => {
        Swal.fire({
            title: 'HAPUS DATA?',
            text: "Data akan hilang permanen!",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#e11d48',
            confirmButtonText: 'YA, HAPUS!',
            cancelButtonText: 'BATAL'
        }).then((result) => {
            if (result.isConfirmed) {
                router.delete(route('ingredients.destroy', id), {
                    onSuccess: () => Swal.fire('TERHAPUS!', 'Data berhasil dihapus.', 'success')
                });
            }
        });
    };

    return (
        <DashboardLayout auth={auth}> 
            <Head title="Bahan Baku" />

            <div className="min-h-screen bg-transparent font-sans p-2 md:p-4">
                {/* HEADER */}
                <div className="mb-6 flex flex-col xl:flex-row xl:items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2 uppercase tracking-tight">
                            <IconDatabaseImport size={28} className="text-emerald-500" />
                            Bahan Baku
                        </h1>
                        <p className="text-sm text-slate-500 uppercase italic">Inventaris & Harga Modal</p>
                    </div>
                    
                    <div className="flex flex-wrap items-center gap-3">
                        <button onClick={openCreateModal} className="bg-emerald-600 hover:bg-emerald-700 text-white px-5 py-3 rounded-xl font-black text-xs uppercase flex items-center gap-2 shadow-lg transition-all active:scale-95">
                            <IconPlus size={18}/> Tambah Baru
                        </button>

                        <div className="flex bg-white dark:bg-slate-800 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 overflow-hidden">
                            <button 
                                onClick={handleDownloadTemplate} 
                                className="p-3 text-blue-600 hover:bg-blue-50 border-r border-slate-200 dark:border-slate-700 flex items-center gap-2 text-[10px] font-black uppercase"
                            >
                                <IconDownload size={18}/> Template
                            </button>
                            <button 
                                onClick={() => fileInputRef.current.click()} 
                                className="p-3 text-emerald-600 hover:bg-emerald-50 flex items-center gap-2 text-[10px] font-black uppercase"
                            >
                                <IconFileImport size={18}/> Import
                            </button>
                            <input type="file" ref={fileInputRef} onChange={handleImportExcel} className="hidden" accept=".xlsx, .xls" />
                        </div>
                    </div>
                </div>

                {/* SEARCH BAR */}
                <div className="bg-white dark:bg-slate-900 p-4 rounded-2xl border border-slate-200 dark:border-slate-800 mb-6 shadow-sm flex items-center gap-4">
                    <form onSubmit={handleSearch} className="relative flex-1">
                        <IconSearch size={18} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                        <input 
                            type="text" 
                            className="pl-10 w-full rounded-xl border-slate-200 dark:border-slate-700 dark:bg-slate-800 text-slate-900 dark:text-white text-sm focus:ring-emerald-500 font-bold uppercase" 
                            placeholder="Cari nama bahan baku..." 
                            value={searchQuery} 
                            onChange={e => setSearchQuery(e.target.value)} 
                        />
                    </form>
                    <button onClick={() => router.get(route("ingredients.index"))} className="p-3 rounded-xl bg-slate-100 text-slate-500 hover:bg-slate-200 transition-all border border-slate-200 dark:border-slate-700">
                        <IconRefresh size={20} />
                    </button>
                </div>

                {/* TABEL */}
                <div className="bg-white dark:bg-slate-900 rounded-3xl border border-slate-200 dark:border-slate-800 overflow-hidden shadow-sm">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm text-left border-collapse">
                            <thead className="text-[10px] text-slate-500 dark:text-slate-400 uppercase bg-slate-50 dark:bg-slate-800/50 border-b border-slate-200 dark:border-slate-800 font-black tracking-widest text-center">
                                <tr>
                                    <th className="px-6 py-5 text-left">Nama Bahan</th>
                                    <th className="px-6 py-5">Satuan</th>
                                    <th className="px-6 py-5">Harga Modal</th>
                                    <th className="px-6 py-5">Stok</th>
                                    <th className="px-6 py-5 text-right">Aksi</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                {ingredients.length > 0 ? ingredients.map((item) => (
                                    <tr key={item.id} className="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors text-center text-slate-900 dark:text-white font-medium">
                                        <td className="px-6 py-4 font-bold uppercase text-left">{item.name}</td>
                                        <td className="px-6 py-4 uppercase font-black text-slate-500">
                                            {item.unit || '-'}
                                        </td>
                                        <td className="px-6 py-4 font-black text-blue-600">Rp {Number(item.buy_price).toLocaleString()}</td>
                                        <td className="px-6 py-4 font-black">{item.stock}</td>
                                        <td className="px-6 py-4 text-right">
                                            <div className="flex justify-end gap-2">
                                                <button onClick={() => openEditModal(item)} className="p-2 text-amber-600 bg-amber-50 dark:bg-amber-900/20 rounded-xl transition-all border border-amber-100 dark:border-amber-800"><IconEdit size={18} /></button>
                                                <button onClick={() => destroyIngredient(item.id)} className="p-2 text-rose-600 bg-rose-50 dark:bg-rose-900/20 rounded-xl transition-all border border-rose-100 dark:border-rose-800"><IconTrash size={18} /></button>
                                            </div>
                                        </td>
                                    </tr>
                                )) : (
                                    <tr><td colSpan="5" className="p-10 text-center text-slate-400 uppercase font-bold text-xs italic">Belum ada data</td></tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>

                {/* MODAL FORM */}
                {modalMode && (
                    <div className="fixed inset-0 z-[99] flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm">
                        <div className="bg-white dark:bg-slate-900 w-full max-w-md rounded-3xl shadow-2xl border border-slate-200 dark:border-slate-800 overflow-hidden animate-in fade-in zoom-in duration-200">
                            <div className="p-6 border-b border-slate-100 dark:border-slate-800 flex justify-between items-center bg-slate-50 dark:bg-slate-800">
                                <h3 className="font-black uppercase text-slate-900 dark:text-white flex items-center gap-2">
                                    {modalMode === 'create' ? 'Tambah Bahan Baru' : 'Update Bahan Baku'}
                                </h3>
                                <button onClick={closeModal} className="text-slate-500 hover:text-rose-500"><IconX size={24}/></button>
                            </div>
                            
                            <form onSubmit={handleSubmit} className="p-6 space-y-4">
                                <div>
                                    <label className="text-[10px] font-black uppercase text-slate-500 dark:text-slate-400 mb-1 block">Nama Bahan Baku</label>
                                    <input 
                                        type="text" 
                                        className="w-full rounded-xl border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white font-bold uppercase text-sm focus:ring-emerald-500" 
                                        value={data.name} 
                                        onChange={e => setData('name', e.target.value)} 
                                        placeholder="INPUT NAMA BAHAN..." 
                                        required 
                                    />
                                    {errors.name && <p className="text-rose-500 text-[10px] font-bold mt-1 uppercase">{errors.name}</p>}
                                </div>

                                <div>
                                    <label className="text-[10px] font-black uppercase text-slate-500 dark:text-slate-400 mb-1 block">Satuan (TEKS BEBAS)</label>
                                    <input 
                                        type="text" 
                                        className="w-full rounded-xl border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white font-bold uppercase text-sm focus:ring-emerald-500" 
                                        value={data.unit} 
                                        onChange={e => setData('unit', e.target.value)} 
                                        placeholder="CONTOH: KG / GRAM / ML / PCS" 
                                        required 
                                    />
                                    {errors.unit && <p className="text-rose-500 text-[10px] font-bold mt-1 uppercase">{errors.unit}</p>}
                                </div>

                                <div className="grid grid-cols-2 gap-4">
                                    <div>
                                        <label className="text-[10px] font-black uppercase text-slate-500 dark:text-slate-400 mb-1 block">Harga Modal</label>
                                        <input 
                                            type="number" 
                                            className="w-full rounded-xl border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-blue-600 dark:text-blue-400 font-black text-sm focus:ring-blue-500" 
                                            value={data.buy_price} 
                                            onChange={e => setData('buy_price', e.target.value)} 
                                            required 
                                        />
                                    </div>
                                    <div>
                                        <label className="text-[10px] font-black uppercase text-slate-500 dark:text-slate-400 mb-1 block">Min. Stok</label>
                                        <input 
                                            type="number" 
                                            className="w-full rounded-xl border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-amber-600 dark:text-amber-400 font-black text-sm focus:ring-amber-500" 
                                            value={data.min_stock} 
                                            onChange={e => setData('min_stock', e.target.value)} 
                                            required 
                                        />
                                    </div>
                                </div>

                                <div>
                                    <label className="text-[10px] font-black uppercase text-slate-500 dark:text-slate-400 mb-1 block">Stok Saat Ini</label>
                                    <input 
                                        type="number" 
                                        className="w-full rounded-xl border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white font-black text-sm focus:ring-emerald-500" 
                                        value={data.stock} 
                                        onChange={e => setData('stock', e.target.value)} 
                                    />
                                </div>

                                <button 
                                    type="submit"
                                    disabled={processing} 
                                    className={`w-full py-4 rounded-2xl font-black uppercase shadow-lg text-white transition-all active:scale-95 ${modalMode === 'create' ? 'bg-emerald-600 hover:bg-emerald-700' : 'bg-amber-600 hover:bg-amber-700'}`}
                                >
                                    {processing ? "Memproses..." : "Simpan Data Bahan"}
                                </button>
                            </form>
                        </div>
                    </div>
                )}
            </div>
        </DashboardLayout>
    );
}