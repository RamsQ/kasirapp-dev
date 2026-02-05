import React, { useState, useRef } from "react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { Head, Link, router } from "@inertiajs/react";
import Pagination from "@/Components/Dashboard/Pagination";
import {
    IconPencil,
    IconTrash,
    IconPlus,
    IconSearch,
    IconPackage,
    IconFileTypeXls,
    IconDownload,
    IconBarcode,
    IconInbox,
    IconCalculator
} from "@tabler/icons-react";

export default function Index({ products, search }) {
    const [searchQuery, setSearchQuery] = useState(search || "");
    const [selectedIds, setSelectedIds] = useState([]);
    
    const fileInputRef = useRef(null);

    const handleSearch = (e) => {
        e.preventDefault();
        router.get(route("products.index"), { search: searchQuery }, { preserveState: true });
    };

    const handleCheckAll = (e) => {
        if (e.target.checked) {
            const allIds = products.data.map((product) => product.id);
            setSelectedIds(allIds);
        } else {
            setSelectedIds([]);
        }
    };

    const handleCheckOne = (id) => {
        if (selectedIds.includes(id)) {
            setSelectedIds(selectedIds.filter((itemId) => itemId !== id));
        } else {
            setSelectedIds([...selectedIds, id]);
        }
    };

    const deleteProduct = (id) => {
        if (confirm("Hapus satu produk ini?")) {
            router.delete(route("products.destroy", id));
        }
    };

    const handleBulkDelete = () => {
        if (selectedIds.length === 0) return;
        if (confirm(`Yakin hapus ${selectedIds.length} produk terpilih?`)) {
            router.post(route("products.bulk_destroy"), { ids: selectedIds }, {
                onSuccess: () => setSelectedIds([])
            });
        }
    };

    const handleImportClick = () => {
        fileInputRef.current.click(); 
    };

    const handleFileChange = (e) => {
        const file = e.target.files[0];
        if (file) {
            const formData = new FormData();
            formData.append("file", file);
            
            router.post(route("products.import"), formData, {
                forceFormData: true,
                onSuccess: () => {
                    fileInputRef.current.value = ""; 
                    alert("Import berhasil!");
                },
                onError: () => {
                    fileInputRef.current.value = "";
                    alert("Gagal import, cek format excel Anda.");
                }
            });
        }
    };

    return (
        <>
            <Head title="Manajemen Produk" />

            <input 
                type="file" 
                ref={fileInputRef} 
                onChange={handleFileChange} 
                className="hidden" 
                accept=".xlsx, .xls"
            />

            <div className="mb-6 flex flex-col xl:flex-row xl:items-center justify-between gap-4">
                <div className="flex flex-col">
                    <h1 className="text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2 uppercase tracking-tight">
                        <IconPackage size={28} className="text-primary-500" />
                        Manajemen Produk
                    </h1>
                    <p className="text-sm text-slate-500 dark:text-slate-400 font-medium uppercase tracking-wider">Kelola Inventaris & Stok Barang</p>
                </div>

                <div className="flex flex-wrap items-center gap-3">
                    <button
                        onClick={handleBulkDelete}
                        disabled={selectedIds.length === 0}
                        className={`flex items-center gap-2 px-4 py-2.5 rounded-xl text-xs font-bold uppercase transition-all ${
                            selectedIds.length > 0
                                ? "bg-red-500 hover:bg-red-600 text-white shadow-md shadow-red-200 dark:shadow-none"
                                : "bg-slate-200 text-slate-400 cursor-not-allowed dark:bg-slate-800 dark:text-slate-600"
                        }`}
                    >
                        <IconTrash size={18} />
                        {selectedIds.length > 0 ? `Hapus (${selectedIds.length})` : "Hapus Banyak"}
                    </button>

                    <a
                        href={route("products.template")}
                        className="bg-green-50 dark:bg-green-900/20 text-green-600 dark:text-green-400 border border-green-200 dark:border-green-800 hover:bg-green-100 px-4 py-2.5 rounded-xl text-xs font-bold uppercase flex items-center gap-2 transition-colors"
                    >
                        <IconDownload size={18} />
                        Template
                    </a>

                    <button
                        onClick={handleImportClick}
                        className="bg-green-600 hover:bg-green-700 text-white px-4 py-2.5 rounded-xl shadow-sm transition-colors flex items-center gap-2 text-xs font-bold uppercase tracking-wide"
                    >
                        <IconCalculator size={18} />
                        Import
                    </button>

                    <Link
                        href={route("products.create")}
                        className="bg-primary-500 hover:bg-primary-600 text-white px-5 py-2.5 rounded-xl shadow-lg shadow-primary-200 dark:shadow-none transition-colors flex items-center gap-2 text-xs font-bold uppercase tracking-wide"
                    >
                        <IconPlus size={18} />
                        Tambah Produk
                    </Link>
                </div>
            </div>

            <div className="bg-white dark:bg-slate-900 p-4 rounded-2xl border border-slate-200 dark:border-slate-800 mb-6 shadow-sm">
                <form onSubmit={handleSearch} className="relative w-full">
                    <IconSearch size={18} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                    <input
                        type="text"
                        value={searchQuery}
                        onChange={(e) => setSearchQuery(e.target.value)}
                        className="pl-10 w-full rounded-xl border-slate-200 dark:border-slate-700 dark:bg-slate-800 text-slate-900 dark:text-white text-sm focus:ring-primary-500 focus:border-primary-500 transition-all font-medium"
                        placeholder="Cari produk berdasarkan nama atau scan barcode..."
                    />
                </form>
            </div>

            <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden shadow-sm">
                <div className="overflow-x-auto">
                    <table className="w-full text-sm text-left">
                        <thead className="text-[10px] text-slate-500 dark:text-slate-400 uppercase bg-slate-50 dark:bg-slate-800 border-b border-slate-200 dark:border-slate-800 font-black tracking-widest">
                            <tr>
                                <th scope="col" className="px-4 py-4 w-10 text-center">
                                    <input 
                                        type="checkbox" 
                                        className="w-4 h-4 text-primary-600 bg-gray-100 border-gray-300 rounded focus:ring-primary-500 dark:focus:ring-primary-600 dark:ring-offset-gray-800 focus:ring-2 dark:bg-gray-700 dark:border-gray-600 cursor-pointer"
                                        onChange={handleCheckAll}
                                        checked={products.data.length > 0 && selectedIds.length === products.data.length}
                                    />
                                </th>
                                <th scope="col" className="px-6 py-4">Produk</th>
                                <th scope="col" className="px-6 py-4">Informasi Produk</th>
                                <th scope="col" className="px-6 py-4 text-center">Stok & Satuan</th>
                                <th scope="col" className="px-6 py-4">Modal (HPP)</th> 
                                <th scope="col" className="px-6 py-4">Harga Jual</th>
                                <th scope="col" className="px-6 py-4 text-center">Aksi</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                            {products.data.length > 0 ? products.data.map((product) => {
                                // Logika HPP: Gunakan cost_price (resep) jika ada, jika tidak gunakan buy_price (harga beli)
                                const finalHpp = product.cost_price > 0 ? product.cost_price : (product.buy_price || 0);
                                const hppLabel = product.cost_price > 0 ? "HPP (Resep)" : "HPP (Harga Beli)";

                                return (
                                    <tr key={product.id} className={`hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors ${selectedIds.includes(product.id) ? 'bg-primary-50/50 dark:bg-primary-900/10' : ''}`}>
                                        <td className="px-4 py-4 text-center">
                                            <input 
                                                type="checkbox" 
                                                className="w-4 h-4 text-primary-600 bg-gray-100 border-gray-300 rounded focus:ring-primary-500 dark:focus:ring-primary-600 dark:ring-offset-gray-800 focus:ring-2 dark:bg-gray-700 dark:border-gray-600 cursor-pointer"
                                                checked={selectedIds.includes(product.id)}
                                                onChange={() => handleCheckOne(product.id)}
                                            />
                                        </td>
                                        <td className="px-6 py-4">
                                            <div className="w-12 h-12 rounded-xl overflow-hidden border border-slate-200 dark:border-slate-700 shadow-sm bg-slate-50 dark:bg-slate-800">
                                                <img 
                                                    src={product.image 
                                                        ? `/storage/products/${product.image}` 
                                                        : `https://ui-avatars.com/api/?name=${encodeURIComponent(product.title)}&background=random`} 
                                                    className="w-full h-full object-cover"
                                                    alt={product.title}
                                                    onError={(e) => { e.target.src = `https://ui-avatars.com/api/?name=${encodeURIComponent(product.title)}&background=random` }}
                                                />
                                            </div>
                                        </td>
                                        <td className="px-6 py-4">
                                            <div className="flex flex-col gap-0.5">
                                                <div className="font-bold text-slate-900 dark:text-white uppercase leading-tight">{product.title}</div>
                                                <div className="flex items-center gap-1.5 text-[10px] font-mono text-slate-400 dark:text-slate-500">
                                                    <IconBarcode size={12} /> {product.barcode || 'TANPA BARCODE'}
                                                </div>
                                                {product.expired_date && (
                                                    <div className="mt-1 text-[10px] font-black uppercase text-red-500 bg-red-50 dark:bg-red-900/20 px-2 py-0.5 rounded-md inline-block w-fit">
                                                        EXP: {product.expired_date}
                                                    </div>
                                                )}
                                            </div>
                                        </td>
                                        <td className="px-6 py-4 text-center">
                                            <div className="inline-flex flex-col items-center">
                                                <span className={`text-sm font-black ${product.stock <= 5 ? 'text-red-500 animate-pulse' : 'text-slate-700 dark:text-slate-300'}`}>
                                                    {product.stock}
                                                </span>
                                                <span className="text-[10px] font-bold uppercase text-slate-400 dark:text-slate-500 tracking-tighter">
                                                    {product.unit || 'Pcs'}
                                                </span>
                                            </div>
                                        </td>
                                        <td className="px-6 py-4 font-bold text-emerald-600 dark:text-emerald-400 italic">
                                            <div className="flex flex-col">
                                                <span className="text-[9px] text-slate-400 dark:text-slate-500 uppercase not-italic font-black leading-none mb-1">{hppLabel}</span>
                                                <div>
                                                    <span className="text-xs mr-1 uppercase">Rp</span>
                                                    {new Intl.NumberFormat("id-ID").format(finalHpp)}
                                                </div>
                                            </div>
                                        </td>
                                        <td className="px-6 py-4 font-bold text-slate-900 dark:text-slate-200">
                                            <div className="flex flex-col">
                                                <span className="text-[9px] text-slate-400 dark:text-slate-500 uppercase font-black leading-none mb-1">Harga Jual</span>
                                                <div>
                                                    <span className="text-xs mr-1">Rp</span>
                                                    {new Intl.NumberFormat("id-ID").format(product.sell_price)}
                                                </div>
                                            </div>
                                        </td>
                                        <td className="px-6 py-4 text-center">
                                            <div className="flex justify-center gap-2">
                                                <Link 
                                                    href={route("products.edit", product.id)} 
                                                    className="p-2 bg-yellow-50 dark:bg-yellow-900/20 text-yellow-600 dark:text-yellow-400 rounded-xl hover:bg-yellow-100 transition-all active:scale-90"
                                                    title="Edit Produk"
                                                >
                                                    <IconPencil size={18} />
                                                </Link>
                                                <button 
                                                    onClick={() => deleteProduct(product.id)} 
                                                    className="p-2 bg-red-50 dark:bg-red-900/20 text-red-600 dark:text-red-400 rounded-xl hover:bg-red-100 transition-all active:scale-90"
                                                    title="Hapus Produk"
                                                >
                                                    <IconTrash size={18} />
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                );
                            }) : (
                                <tr>
                                    <td colSpan="7" className="p-20 text-center">
                                        <div className="flex flex-col items-center gap-2 opacity-20">
                                            <IconInbox size={64} className="text-slate-400" />
                                            <p className="text-xs font-black uppercase tracking-widest text-slate-500">Produk Tidak Ditemukan</p>
                                        </div>
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
                <div className="px-6 py-4 border-t border-slate-200 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-800/30">
                    <Pagination links={products.links} />
                </div>
            </div>
        </>
    );
}

Index.layout = (page) => <DashboardLayout children={page} />;