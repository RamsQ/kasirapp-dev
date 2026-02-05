import React, { useEffect, useState } from "react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { Head, useForm, usePage, Link, router } from "@inertiajs/react";
import Input from "@/Components/Dashboard/Input";
import Textarea from "@/Components/Dashboard/TextArea";
import InputSelect from "@/Components/Dashboard/InputSelect";
import toast from "react-hot-toast";
import Swal from "sweetalert2";
import {
    IconPackage, IconDeviceFloppy, IconArrowLeft, IconPhoto,
    IconBarcode, IconCurrencyDollar, IconCalendar, IconPlus,
    IconTrash, IconScale, IconX, IconDatabase, IconCalendarTime, IconAlertCircle
} from "@tabler/icons-react";

export default function Edit({ categories, product, products }) {
    const { errors } = usePage().props;

    // Transformasi data bundle items dari pivot
    const initialBundleItems = product.bundle_items?.map((item) => ({
        item_id: item.id,
        qty: item.pivot.qty,
        product_unit_id: item.pivot.product_unit_id || "", 
    })) || [{ item_id: "", qty: 1, product_unit_id: "" }];

    // Transformasi data multi-satuan
    const initialUnits = product.units?.map((u) => ({
        unit_name: u.unit_name,
        conversion: u.conversion,
        sell_price: u.sell_price,
    })) || [];

    const { data, setData, post, processing } = useForm({
        image: "",
        barcode: product.barcode || "",
        title: product.title,
        category_id: product.category_id,
        description: product.description,
        buy_price: product.buy_price,
        sell_price: product.sell_price,
        stock: product.stock,
        unit: product.unit || "Pcs",
        expired_date: product.expired_date ?? "",
        type: product.type, 
        bundle_items: initialBundleItems,
        units: initialUnits,
        _method: "PUT",
    });

    const [selectedCategory, setSelectedCategory] = useState(null);
    const [imagePreview, setImagePreview] = useState(product.image || null);

    useEffect(() => {
        if (product.category_id) {
            setSelectedCategory(categories.find((cat) => cat.id === product.category_id));
        }
    }, [product.category_id, categories]);

    const setSelectedCategoryHandler = (value) => {
        setSelectedCategory(value);
        setData("category_id", value?.id || "");
    };

    const handleImageChange = (e) => {
        const file = e.target.files[0];
        if (file) {
            setData("image", file);
            setImagePreview(URL.createObjectURL(file));
        }
    };

    // --- FITUR ADJUSTMENT STOK PER BATCH ---
    const handleAdjustment = (batchId, currentQty) => {
        Swal.fire({
            title: 'Penyesuaian Stok',
            text: `Masukkan jumlah stok baru untuk batch ini (Sisa saat ini: ${currentQty})`,
            input: 'number',
            inputAttributes: { min: 0, step: 'any' },
            showCancelButton: true,
            confirmButtonText: 'Update Stok',
            confirmButtonColor: '#6366f1',
            customClass: { popup: 'rounded-3xl dark:bg-slate-900 dark:text-white' }
        }).then((result) => {
            if (result.isConfirmed) {
                router.post(route('stock_opnames.store'), {
                    product_id: product.id,
                    stock_actual: result.value,
                    batch_id: batchId,
                    notes: "Penyesuaian manual via Edit Produk"
                }, {
                    onSuccess: () => toast.success("Stok batch berhasil disesuaikan"),
                });
            }
        });
    };

    const calculateTotalModal = (items) => {
        if (data.type !== 'bundle') return;
        let totalModal = 0;
        items.forEach(item => {
            const originalProduct = products.find(p => p.id == item.item_id);
            if (originalProduct) {
                let conversion = 1;
                if (item.product_unit_id) {
                    const unitData = originalProduct.units?.find(u => u.id == item.product_unit_id);
                    conversion = unitData ? parseFloat(unitData.conversion) : 1;
                }
                totalModal += parseFloat(originalProduct.buy_price) * parseFloat(item.qty || 0) * conversion;
            }
        });
        setData(prevData => ({ ...prevData, buy_price: totalModal, bundle_items: items }));
    };

    const addBundleItem = () => {
        setData("bundle_items", [...data.bundle_items, { item_id: "", qty: 1, product_unit_id: "" }]);
    };

    const removeBundleItem = (index) => {
        const list = [...data.bundle_items];
        list.splice(index, 1);
        data.type === 'bundle' ? calculateTotalModal(list) : setData("bundle_items", list);
    };

    const handleItemChange = (index, field, value) => {
        const list = [...data.bundle_items];
        list[index][field] = value;
        if (field === "item_id") list[index]["product_unit_id"] = "";
        data.type === 'bundle' ? calculateTotalModal(list) : setData("bundle_items", list);
    };

    const addUnitRow = () => setData("units", [...data.units, { unit_name: "", conversion: 1, sell_price: 0 }]);
    const removeUnitRow = (index) => setData("units", data.units.filter((_, i) => i !== index));
    const updateUnitData = (index, field, value) => {
        const newUnits = [...data.units];
        newUnits[index][field] = value;
        setData("units", newUnits);
    };

    const submit = (e) => {
        e.preventDefault();
        post(route("products.update", product.id), {
            onSuccess: () => toast.success("Produk berhasil diperbarui"),
            onError: () => toast.error("Gagal memperbarui produk"),
        });
    };

    return (
        <>
            <Head title="Edit Produk" />
            <div className="mb-6">
                <Link href={route("products.index")} className="inline-flex items-center gap-2 text-sm text-slate-500 hover:text-primary-600 mb-3 uppercase font-bold tracking-tight">
                    <IconArrowLeft size={16} /> Kembali ke Produk
                </Link>
                <h1 className="text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2 uppercase tracking-tight">
                    <IconPackage size={28} className="text-primary-500" /> Edit Produk
                </h1>
            </div>

            <form onSubmit={submit}>
                <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    {/* Left Column */}
                    <div className="lg:col-span-1 space-y-6">
                        <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 shadow-sm">
                            <h3 className="text-[10px] font-black uppercase text-slate-400 mb-4 flex items-center gap-2 tracking-widest"><IconPhoto size={18} /> Gambar Produk</h3>
                            <div className="aspect-square rounded-xl bg-slate-100 dark:bg-slate-800 border-2 border-dashed border-slate-300 dark:border-slate-700 flex items-center justify-center overflow-hidden mb-4 text-center">
                                {imagePreview ? (
                                    <img 
                                        src={imagePreview} 
                                        alt="Preview" 
                                        className="w-full h-full object-cover" 
                                        onError={(e) => { e.target.src = `https://ui-avatars.com/api/?name=${encodeURIComponent(product.title)}&background=random` }}
                                    />
                                ) : (
                                    <IconPhoto size={48} className="text-slate-400" />
                                )}
                            </div>
                            <Input type="file" label="Ganti Gambar" onChange={handleImageChange} errors={errors.image} accept="image/*" />
                        </div>
                        <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 shadow-sm">
                            <h3 className="text-[10px] font-black uppercase text-slate-400 mb-2 tracking-widest">Jenis Produk</h3>
                            <div className={`px-4 py-3 rounded-xl text-xs font-bold uppercase flex items-center gap-2 ${data.type === 'bundle' ? 'bg-purple-50 text-purple-700 dark:bg-purple-900/20 dark:text-purple-400' : 'bg-blue-50 text-blue-700 dark:bg-blue-900/20 dark:text-blue-400'}`}>
                                <IconPackage size={18} /> {data.type === 'single' ? 'Produk Biasa (Retail)' : 'Paket Bundling'}
                            </div>
                        </div>
                    </div>

                    {/* Right Column */}
                    <div className="lg:col-span-2 space-y-6">
                        <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 shadow-sm">
                            <h3 className="text-[10px] font-black uppercase text-slate-400 mb-4 flex items-center gap-2 tracking-widest"><IconBarcode size={18} /> Informasi Dasar</h3>
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div className="md:col-span-2"><InputSelect label="Kategori" data={categories} selected={selectedCategory} setSelected={setSelectedCategoryHandler} displayKey="name" searchable /></div>
                                <Input label="Barcode" value={data.barcode} onChange={(e) => setData("barcode", e.target.value)} errors={errors.barcode} />
                                <Input label="Nama Produk" value={data.title} onChange={(e) => setData("title", e.target.value)} errors={errors.title} />
                                <Input label="Satuan Dasar" value={data.unit} onChange={(e) => setData("unit", e.target.value)} errors={errors.unit} placeholder="Pcs" />
                                <div className="md:col-span-2"><Textarea label="Deskripsi" value={data.description} onChange={(e) => setData("description", e.target.value)} /></div>
                            </div>
                        </div>

                        <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 shadow-sm">
                            <h3 className="text-[10px] font-black uppercase text-slate-400 mb-4 flex items-center gap-2 tracking-widest"><IconCurrencyDollar size={18} /> Harga & Stok</h3>
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <Input label={data.type === 'bundle' ? "Total Modal (Auto)" : "Harga Beli"} value={data.buy_price} readOnly={data.type === 'bundle'} className={data.type === 'bundle' ? 'bg-slate-50 dark:bg-slate-800 font-bold text-primary-600' : ''} onChange={(e) => setData("buy_price", e.target.value)} />
                                <Input label="Harga Jual" value={data.sell_price} onChange={(e) => setData("sell_price", e.target.value)} errors={errors.sell_price} />
                                {data.type === "single" && <Input label="Stok Saat Ini (Total)" value={data.stock} onChange={(e) => setData("stock", e.target.value)} errors={errors.stock} />}
                                <Input type="date" label="Kadaluarsa" value={data.expired_date} onChange={(e) => setData("expired_date", e.target.value)} />
                            </div>
                        </div>

                        {/* --- TABEL RIWAYAT BATCH (FIFO/LIFO) --- */}
                        {data.type === "single" && (
                            <div className="bg-white dark:bg-slate-900 rounded-[2rem] border border-slate-200 dark:border-slate-800 p-6 shadow-sm">
                                <div className="flex items-center justify-between mb-6">
                                    <div className="flex items-center gap-2">
                                        <div className="p-2 bg-primary-100 dark:bg-primary-900/30 rounded-xl text-primary-600">
                                            <IconDatabase size={20} />
                                        </div>
                                        <div>
                                            <h3 className="text-[10px] font-black uppercase text-slate-400 tracking-widest leading-none">Manajemen Batch</h3>
                                            <p className="text-sm font-black dark:text-white uppercase tracking-tight">Rincian Modal per Kedatangan</p>
                                        </div>
                                    </div>
                                </div>
                                
                                <div className="overflow-x-auto">
                                    <table className="w-full text-left border-separate border-spacing-y-2">
                                        <thead>
                                            <tr className="text-[9px] font-black uppercase tracking-widest text-slate-400">
                                                <th className="px-4 py-2">Tgl Masuk</th>
                                                <th className="px-4 py-2">Sisa / Total</th>
                                                <th className="px-4 py-2 text-right">Harga Modal</th>
                                                <th className="px-4 py-2 text-right">Subtotal</th>
                                                <th className="px-4 py-2 text-center">Aksi</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {product.stock_batches && product.stock_batches.length > 0 ? (
                                                product.stock_batches.map((batch, index) => (
                                                    <tr key={index} className="bg-slate-50 dark:bg-slate-800/50 rounded-2xl group transition-all">
                                                        <td className="px-4 py-4 text-[11px] font-bold dark:text-white first:rounded-l-2xl">
                                                            <div className="flex items-center gap-2">
                                                                <IconCalendarTime size={16} className="text-slate-400" />
                                                                {new Date(batch.created_at).toLocaleDateString('id-ID', {day:'2-digit', month:'short', year:'numeric'})}
                                                            </div>
                                                        </td>
                                                        <td className="px-4 py-4 text-[11px] font-black">
                                                            <span className="text-primary-600 text-sm">{batch.qty_remaining}</span>
                                                            <span className="text-slate-400 ml-1">/ {batch.qty_in} {product.unit}</span>
                                                        </td>
                                                        <td className="px-4 py-4 text-[11px] font-bold text-right dark:text-white">
                                                            Rp {parseFloat(batch.buy_price).toLocaleString()}
                                                        </td>
                                                        <td className="px-4 py-4 text-[11px] font-black text-right text-emerald-600">
                                                            Rp {(batch.qty_remaining * batch.buy_price).toLocaleString()}
                                                        </td>
                                                        <td className="px-4 py-4 text-center last:rounded-r-2xl">
                                                            <button 
                                                                type="button"
                                                                onClick={() => handleAdjustment(batch.id, batch.qty_remaining)}
                                                                className="p-2 text-slate-400 hover:text-primary-600 hover:bg-white dark:hover:bg-slate-900 rounded-xl transition-all"
                                                                title="Penyesuaian Stok Batch"
                                                            >
                                                                <IconScale size={18} />
                                                            </button>
                                                        </td>
                                                    </tr>
                                                ))
                                            ) : (
                                                <tr>
                                                    <td colSpan="5" className="text-center py-10 bg-slate-50 dark:bg-slate-800/30 rounded-3xl">
                                                        <IconAlertCircle size={32} className="mx-auto text-slate-300 mb-2" />
                                                        <p className="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Belum ada data batch aktif</p>
                                                        <p className="text-[9px] text-slate-400 mt-1 italic">Silakan tambah stok di atas untuk membuat batch baru</p>
                                                    </td>
                                                </tr>
                                            )}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        )}

                        {data.type === "single" && (
                            <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 shadow-sm">
                                <div className="flex justify-between items-center mb-4">
                                    <h3 className="text-[10px] font-black uppercase text-slate-400 flex items-center gap-2 tracking-widest"><IconScale size={18} /> Konversi Satuan Luar</h3>
                                    <button type="button" onClick={addUnitRow} className="bg-primary-50 text-primary-600 px-3 py-1.5 rounded-lg text-[10px] uppercase font-black hover:bg-primary-100 transition-colors flex items-center gap-1"><IconPlus size={14} /> Tambah Satuan</button>
                                </div>
                                {data.units.map((unit, index) => (
                                    <div key={index} className="grid grid-cols-12 gap-3 p-3 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl mb-2 items-end">
                                        <div className="col-span-4">
                                            <label className="text-[9px] font-black uppercase text-slate-400 mb-1 block tracking-wide">Nama Satuan</label>
                                            <input placeholder="Kg / Dus" value={unit.unit_name} onChange={(e) => updateUnitData(index, "unit_name", e.target.value)} className="w-full rounded-lg text-sm dark:bg-slate-900 dark:text-white border-slate-200 dark:border-slate-700" />
                                        </div>
                                        <div className="col-span-3">
                                            <label className="text-[9px] font-black uppercase text-slate-400 mb-1 block tracking-wide">Isi (Qty)</label>
                                            <input type="number" placeholder="Konv" value={unit.conversion} onChange={(e) => updateUnitData(index, "conversion", e.target.value)} className="w-full rounded-lg text-sm dark:bg-slate-900 dark:text-white border-slate-200 dark:border-slate-700" />
                                        </div>
                                        <div className="col-span-4">
                                            <label className="text-[9px] font-black uppercase text-slate-400 mb-1 block tracking-wide">Harga Jual</label>
                                            <input type="number" placeholder="Harga" value={unit.sell_price} onChange={(e) => updateUnitData(index, "sell_price", e.target.value)} className="w-full rounded-lg text-sm dark:bg-slate-900 dark:text-white border-slate-200 dark:border-slate-700" />
                                        </div>
                                        <div className="col-span-1 text-right">
                                            <button type="button" onClick={() => removeUnitRow(index)} className="text-red-500 hover:bg-red-50 p-2 rounded-lg transition-colors"><IconX size={18} /></button>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}

                        {data.type === "bundle" && (
                            <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 shadow-sm">
                                <h3 className="text-[10px] font-black uppercase text-slate-400 mb-4 flex items-center gap-2 tracking-widest"><IconPackage size={18} /> Komposisi Paket</h3>
                                <div className="space-y-3">
                                    {data.bundle_items.map((item, index) => {
                                        const selectedProd = products.find(p => p.id == item.item_id);
                                        return (
                                            <div key={index} className="flex flex-wrap items-end gap-3 p-3 bg-slate-50 dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 transition-all">
                                                <div className="flex-1 min-w-[150px]">
                                                    <label className="text-[9px] font-black uppercase text-slate-400 mb-1 block">Produk</label>
                                                    <select className="w-full rounded-xl border-slate-200 dark:border-slate-700 dark:bg-slate-900 dark:text-white text-sm focus:ring-primary-500" value={item.item_id} onChange={(e) => handleItemChange(index, "item_id", e.target.value)}>
                                                        <option value="">-- Pilih Produk --</option>
                                                        {products.map((p) => <option key={p.id} value={p.id}>{p.title}</option>)}
                                                    </select>
                                                </div>
                                                <div className="w-40">
                                                    <label className="text-[9px] font-black uppercase text-slate-400 mb-1 block">Satuan Item</label>
                                                    <select className="w-full rounded-xl border-slate-200 dark:border-slate-700 dark:bg-slate-900 dark:text-white text-sm focus:ring-primary-500" value={item.product_unit_id} onChange={(e) => handleItemChange(index, "product_unit_id", e.target.value)}>
                                                        <option value="">Dasar ({selectedProd?.unit || 'Pcs'})</option>
                                                        {selectedProd?.units?.map(u => <option key={u.id} value={u.id}>{u.unit_name} (Isi: {u.conversion})</option>)}
                                                    </select>
                                                </div>
                                                <div className="w-20">
                                                    <label className="text-[9px] font-black uppercase text-slate-400 mb-1 block text-center">Qty</label>
                                                    <input type="number" step="0.01" className="w-full text-center rounded-xl border-slate-200 dark:border-slate-700 dark:bg-slate-900 dark:text-white text-sm focus:ring-primary-500" value={item.qty} onChange={(e) => handleItemChange(index, "qty", e.target.value)} />
                                                </div>
                                                <button type="button" onClick={() => removeBundleItem(index)} className="p-2.5 bg-red-50 dark:bg-red-950 text-red-600 rounded-lg hover:bg-red-100 transition-colors shadow-sm"><IconTrash size={18} /></button>
                                            </div>
                                        );
                                    })}
                                </div>
                                <button type="button" onClick={addBundleItem} className="mt-4 flex items-center gap-2 text-[10px] font-black uppercase text-primary-600 hover:text-primary-700 transition-colors">
                                    <IconPlus size={18} /> Tambah Produk Ke Paket
                                </button>
                            </div>
                        )}

                        <div className="flex justify-end gap-3 pt-4">
                            <Link href={route("products.index")} className="px-6 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-400 hover:bg-slate-50 dark:hover:bg-slate-800 font-bold text-xs uppercase tracking-widest transition-all">Batal</Link>
                            <button type="submit" disabled={processing} className="px-6 py-2.5 rounded-xl bg-primary-500 hover:bg-primary-600 text-white font-bold text-xs uppercase tracking-widest shadow-lg shadow-primary-500/25 transition-all active:scale-95 disabled:opacity-50">
                                {processing ? "Sedang Memperbarui..." : "Simpan Perubahan"}
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </>
    );
}

Edit.layout = (page) => <DashboardLayout children={page} />;