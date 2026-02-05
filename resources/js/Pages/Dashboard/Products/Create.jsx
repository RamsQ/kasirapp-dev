import React, { useState } from "react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { Head, useForm, usePage, Link } from "@inertiajs/react";
import Input from "@/Components/Dashboard/Input";
import Textarea from "@/Components/Dashboard/TextArea";
import InputSelect from "@/Components/Dashboard/InputSelect";
import toast from "react-hot-toast";
import {
    IconPackage, IconDeviceFloppy, IconArrowLeft, IconPhoto,
    IconBarcode, IconCurrencyDollar, IconCalendar, IconPlus,
    IconTrash, IconScale, IconX, IconAlertCircle 
} from "@tabler/icons-react";

export default function Create({ categories, products }) {
    const { errors } = usePage().props;

    const { data, setData, post, processing } = useForm({
        image: "",
        barcode: "",
        title: "",
        category_id: "",
        description: "",
        buy_price: "",
        sell_price: "",
        stock: "", 
        unit: "Pcs", 
        expired_date: "",
        type: "single",
        bundle_items: [{ item_id: "", qty: 1, product_unit_id: "" }],
        units: [], 
    });

    const [selectedCategory, setSelectedCategory] = useState(null);
    const [imagePreview, setImagePreview] = useState(null);

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

    // --- FIX LOGIC AUTO CALCULATE MODAL (BUNDLING DENGAN MULTI-SATUAN) ---
    const calculateTotalModal = (items) => {
        let totalModal = 0;
        items.forEach(item => {
            const originalProduct = products.find(p => p.id == item.item_id);
            if (originalProduct) {
                // Cari data satuan untuk mendapatkan konversi
                let conversion = 1;
                if (item.product_unit_id) {
                    const unitData = originalProduct.units?.find(u => u.id == item.product_unit_id);
                    conversion = unitData ? parseFloat(unitData.conversion) : 1;
                }
                
                // RUMUS: Modal Dasar x Qty Input x Konversi Satuan
                // Contoh Telur: 500 x 1 (kg) x 8 (isi) = 4.000
                totalModal += parseFloat(originalProduct.buy_price) * parseFloat(item.qty || 0) * conversion;
            }
        });

        setData(prevData => ({
            ...prevData,
            buy_price: totalModal,
            bundle_items: items
        }));
    };

    const addBundleItem = () => {
        const newList = [...data.bundle_items, { item_id: "", qty: 1, product_unit_id: "" }];
        setData("bundle_items", newList);
    };

    const removeBundleItem = (index) => {
        const newList = [...data.bundle_items];
        newList.splice(index, 1);
        calculateTotalModal(newList);
    };

    const handleItemChange = (index, field, value) => {
        const newList = [...data.bundle_items];
        newList[index][field] = value;
        
        // Reset unit jika produk di baris tersebut diganti
        if (field === "item_id") {
            newList[index]["product_unit_id"] = "";
        }
        
        calculateTotalModal(newList);
    };

    // --- LOGIC MULTI-SATUAN ---
    const addUnitRow = () => {
        setData("units", [...data.units, { unit_name: "", conversion: 1, sell_price: 0 }]);
    };

    const removeUnitRow = (index) => {
        const newUnits = [...data.units];
        newUnits.splice(index, 1);
        setData("units", newUnits);
    };

    const updateUnitData = (index, field, value) => {
        const newUnits = [...data.units];
        newUnits[index][field] = value;
        setData("units", newUnits);
    };

    const submit = (e) => {
        e.preventDefault();
        post(route("products.store"), {
            onSuccess: () => toast.success("Produk berhasil ditambahkan"),
            onError: (err) => toast.error("Gagal menyimpan produk"),
        });
    };

    return (
        <>
            <Head title="Tambah Produk" />
            <div className="mb-6">
                <Link href={route("products.index")} className="inline-flex items-center gap-2 text-sm text-slate-500 hover:text-primary-600 mb-3 uppercase font-bold tracking-tight transition-colors">
                    <IconArrowLeft size={16} /> Kembali ke Produk
                </Link>
                <h1 className="text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2 uppercase tracking-tight">
                    <IconPackage size={28} className="text-primary-500" /> Tambah Produk Baru
                </h1>
            </div>

            <form onSubmit={submit}>
                <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <div className="lg:col-span-1 space-y-6">
                        <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 shadow-sm">
                            <h3 className="text-[10px] font-black uppercase text-slate-400 dark:text-slate-500 mb-4 flex items-center gap-2 tracking-widest">
                                <IconPhoto size={18} /> Gambar Produk
                            </h3>
                            <div className="aspect-square rounded-xl bg-slate-100 dark:bg-slate-800 border-2 border-dashed border-slate-300 dark:border-slate-700 flex items-center justify-center overflow-hidden mb-4 transition-all">
                                {imagePreview ? <img src={imagePreview} alt="Preview" className="w-full h-full object-cover" /> : (
                                    <div className="text-center p-6">
                                        <IconPhoto size={48} className="mx-auto text-slate-400 mb-2 opacity-50" />
                                        <p className="text-xs font-bold text-slate-500 uppercase">Belum ada gambar</p>
                                    </div>
                                )}
                            </div>
                            <Input type="file" label="Upload Gambar" onChange={handleImageChange} errors={errors.image} accept="image/*" />
                        </div>

                        <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 shadow-sm">
                            <h3 className="text-[10px] font-black uppercase text-slate-400 mb-4 tracking-widest flex items-center gap-2">
                                <IconAlertCircle size={18} /> Jenis Produk
                            </h3>
                            <div className="flex flex-col gap-3">
                                <label className={`flex items-center gap-3 p-3 border rounded-xl cursor-pointer transition-all ${data.type === 'single' ? 'bg-primary-50 border-primary-200 dark:bg-primary-900/20 dark:border-primary-800 ring-1 ring-primary-200' : 'border-slate-200 dark:border-slate-700'}`}>
                                    <input type="radio" name="type" value="single" checked={data.type === "single"} onChange={(e) => setData("type", e.target.value)} className="w-4 h-4 text-primary-600 focus:ring-primary-500" />
                                    <span className="text-xs font-bold uppercase text-slate-700 dark:text-slate-200">Retail / Single</span>
                                </label>
                                <label className={`flex items-center gap-3 p-3 border rounded-xl cursor-pointer transition-all ${data.type === 'bundle' ? 'bg-primary-50 border-primary-200 dark:bg-primary-900/20 dark:border-primary-800 ring-1 ring-primary-200' : 'border-slate-200 dark:border-slate-700'}`}>
                                    <input type="radio" name="type" value="bundle" checked={data.type === "bundle"} onChange={(e) => setData("type", e.target.value)} className="w-4 h-4 text-primary-600 focus:ring-primary-500" />
                                    <span className="text-xs font-bold uppercase text-slate-700 dark:text-slate-200">Paket Bundling</span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <div className="lg:col-span-2 space-y-6">
                        <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 shadow-sm">
                            <h3 className="text-[10px] font-black uppercase text-slate-400 mb-4 flex items-center gap-2 tracking-widest"><IconBarcode size={18} /> Informasi Dasar</h3>
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div className="md:col-span-2 text-slate-900 dark:text-white">
                                    <InputSelect label="Kategori" data={categories} selected={selectedCategory} setSelected={setSelectedCategoryHandler} placeholder="Pilih kategori" errors={errors.category_id} searchable={true} displayKey="name" />
                                </div>
                                <Input type="text" label="Barcode / SKU" value={data.barcode} onChange={(e) => setData("barcode", e.target.value)} errors={errors.barcode} placeholder="Scan atau ketik SKU..." />
                                <Input type="text" label="Nama Produk" value={data.title} onChange={(e) => setData("title", e.target.value)} errors={errors.title} placeholder="Contoh: Indomie Goreng" />
                                <Input type="text" label="Satuan Dasar" value={data.unit} onChange={(e) => setData("unit", e.target.value)} errors={errors.unit} placeholder="Misal: Pcs, Kg, Box" />
                                <div className="md:col-span-2"><Textarea label="Deskripsi" onChange={(e) => setData("description", e.target.value)} value={data.description} rows={3} placeholder="Penjelasan singkat produk..." /></div>
                            </div>
                        </div>

                        <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 shadow-sm">
                            <h3 className="text-[10px] font-black uppercase text-slate-400 mb-4 flex items-center gap-2 tracking-widest"><IconCurrencyDollar size={18} /> Harga & Stok Dasar</h3>
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <Input type="number" label={data.type === 'bundle' ? "Total Modal (Auto)" : "Harga Beli"} value={data.buy_price} onChange={(e) => setData("buy_price", e.target.value)} readOnly={data.type === 'bundle'} className={data.type === 'bundle' ? 'bg-slate-50 dark:bg-slate-800 font-bold text-primary-600' : ''} />
                                <Input type="number" label="Harga Jual" value={data.sell_price} onChange={(e) => setData("sell_price", e.target.value)} errors={errors.sell_price} />
                                {data.type === "single" && <Input type="number" label="Stok Tersedia" value={data.stock} onChange={(e) => setData("stock", e.target.value)} errors={errors.stock} />}
                                <Input type="date" label="Tanggal Kadaluarsa" value={data.expired_date} onChange={(e) => setData("expired_date", e.target.value)} />
                            </div>
                        </div>

                        {data.type === "single" && (
                            <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 shadow-sm">
                                <div className="flex justify-between items-center mb-4">
                                    <h3 className="text-[10px] font-black uppercase text-slate-400 flex items-center gap-2 tracking-widest"><IconScale size={18} /> Konversi Satuan Lain</h3>
                                    <button type="button" onClick={addUnitRow} className="text-[10px] font-black uppercase bg-primary-50 dark:bg-primary-900/30 text-primary-600 px-3 py-1.5 rounded-lg hover:bg-primary-100 transition-colors flex items-center gap-1">
                                        <IconPlus size={14} /> Tambah
                                    </button>
                                </div>
                                {data.units.map((unit, index) => (
                                    <div key={index} className="grid grid-cols-12 gap-3 p-3 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl mb-2 items-end animate-in fade-in slide-in-from-top-1">
                                        <div className="col-span-4">
                                            <label className="text-[9px] font-black uppercase text-slate-400 mb-1 block tracking-wide">Nama Satuan</label>
                                            <input type="text" placeholder="Lusin / Dus" value={unit.unit_name} onChange={(e) => updateUnitData(index, "unit_name", e.target.value)} className="w-full rounded-lg text-sm dark:bg-slate-900 dark:text-white border-slate-200 dark:border-slate-700 focus:ring-primary-500" />
                                        </div>
                                        <div className="col-span-3">
                                            <label className="text-[9px] font-black uppercase text-slate-400 mb-1 block tracking-wide">Isi (Qty)</label>
                                            <input type="number" placeholder="12" value={unit.conversion} onChange={(e) => updateUnitData(index, "conversion", e.target.value)} className="w-full rounded-lg text-sm dark:bg-slate-900 dark:text-white border-slate-200 dark:border-slate-700 focus:ring-primary-500" />
                                        </div>
                                        <div className="col-span-4">
                                            <label className="text-[9px] font-black uppercase text-slate-400 mb-1 block tracking-wide">Harga Jual</label>
                                            <input type="number" placeholder="Harga Jual" value={unit.sell_price} onChange={(e) => updateUnitData(index, "sell_price", e.target.value)} className="w-full rounded-lg text-sm dark:bg-slate-900 dark:text-white border-slate-200 dark:border-slate-700 focus:ring-primary-500" />
                                        </div>
                                        <div className="col-span-1 text-right">
                                            <button type="button" onClick={() => removeUnitRow(index)} className="text-red-500 hover:bg-red-50 dark:hover:bg-red-950 p-2 rounded-lg transition-colors"><IconX size={18} /></button>
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
                            <Link href={route("products.index")} className="px-6 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-400 hover:bg-slate-50 dark:hover:bg-slate-800 font-bold text-xs uppercase tracking-widest transition-all">
                                Batal
                            </Link>
                            <button type="submit" disabled={processing} className="px-6 py-2.5 rounded-xl bg-primary-500 hover:bg-primary-600 text-white font-bold text-xs uppercase tracking-widest shadow-lg shadow-primary-500/25 transition-all active:scale-95 disabled:opacity-50 disabled:cursor-not-allowed">
                                {processing ? "Sedang Memproses..." : "Simpan Produk"}
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </>
    );
}

Create.layout = (page) => <DashboardLayout children={page} />;