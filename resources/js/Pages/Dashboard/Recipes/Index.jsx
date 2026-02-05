import React, { useState, useEffect, useRef } from "react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { Head, useForm, router } from "@inertiajs/react";
import { 
    IconToolsKitchen2, IconPlus, IconTrash, 
    IconDeviceFloppy, IconChevronRight, 
    IconEdit, IconArrowBackUp, IconInfoCircle,
    IconDownload, IconFileTypeXls, IconRefresh, IconX,
    IconTrendingUp, IconChartPie, IconReceipt2, IconCategory, IconSearch,
    IconChevronDown
} from "@tabler/icons-react";
import Swal from "sweetalert2";
import Pagination from "@/Components/Dashboard/Pagination";

export default function Index({ auth, products, allProducts, ingredients, stats, filters }) {
    const [activeTab, setActiveTab] = useState("list");
    const [expandedProduct, setExpandedProduct] = useState(null);
    const [search, setSearch] = useState(filters.search || "");
    const fileInputRef = useRef(null);

    const { data, setData, post, processing, reset } = useForm({
        product_id: "",
        ingredients: [{ ingredient_id: "", qty_needed: "" }]
    });

    const [hppPreview, setHppPreview] = useState(0);

    // --- LOGIKA LABEL HPP ---
    const getHppCategory = (hpp, sellPrice) => {
        if (!sellPrice || sellPrice === 0) return { label: 'Harga 0', color: 'slate' };
        const ratio = (hpp / sellPrice) * 100;
        if (ratio > 50) return { label: 'HPP Tinggi', color: 'rose' };
        if (ratio >= 35) return { label: 'HPP Standar', color: 'emerald' };
        return { label: 'HPP Rendah', color: 'indigo' };
    };

    const handleSearch = (e) => {
        setSearch(e.target.value);
        router.get(route('recipes.index'), { search: e.target.value }, { preserveState: true, replace: true });
    };

    useEffect(() => {
        let total = 0;
        data.ingredients.forEach(item => {
            const ing = ingredients.find(i => i.id == item.ingredient_id);
            if (ing && item.qty_needed) {
                total += parseFloat(item.qty_needed) * (ing.buy_price || 0);
            }
        });
        setHppPreview(total);
    }, [data.ingredients, ingredients]);

    const handleAddRow = () => setData('ingredients', [...data.ingredients, { ingredient_id: "", qty_needed: "" }]);

    const handleRemoveRow = (index) => {
        if (data.ingredients.length > 1) {
            const newRows = [...data.ingredients];
            newRows.splice(index, 1);
            setData('ingredients', newRows);
        }
    };

    const handleChange = (index, field, value) => {
        const newRows = [...data.ingredients];
        newRows[index][field] = value;
        setData('ingredients', newRows);
    };

    const handleEditRecipe = (product) => {
        setData({
            product_id: product.id.toString(),
            ingredients: product.recipes.map(r => ({ ingredient_id: r.ingredient_id.toString(), qty_needed: r.qty_needed }))
        });
        setActiveTab("input");
        window.scrollTo({ top: 0, behavior: 'smooth' });
    };

    const handleDelete = (id, type, name = "") => {
        Swal.fire({
            title: 'Hapus Data?',
            text: type === 'massal' ? `Resep "${name}" akan dikosongkan.` : "Bahan akan dihapus dari resep.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            confirmButtonText: 'Ya, Hapus'
        }).then((result) => {
            if (result.isConfirmed) {
                router.delete(route('recipes.destroy', id), { data: { type: type } });
            }
        });
    };

    const submit = (e) => {
        e.preventDefault();
        post(route('recipes.store'), {
            onSuccess: () => { reset(); setActiveTab("list"); }
        });
    };

    return (
        <DashboardLayout auth={auth}>
            <Head title="Manajemen Resep" />
            <input type="file" ref={fileInputRef} onChange={(e) => {
                const formData = new FormData();
                formData.append("file", e.target.files[0]);
                router.post(route("recipes.import"), formData);
            }} className="hidden" accept=".xlsx, .xls" />

            <div className="p-2 md:p-6 max-w-7xl mx-auto space-y-6">
                
                {/* HEADER */}
                <div className="bg-white dark:bg-slate-900 p-6 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm transition-all">
                    <div className="flex flex-col xl:flex-row xl:items-center justify-between gap-6">
                        <div className="flex items-center gap-4">
                            <div className="p-3 bg-primary-500 rounded-xl text-white shadow-lg shadow-primary-500/20">
                                <IconToolsKitchen2 size={24} />
                            </div>
                            <div>
                                <h1 className="text-xl font-bold tracking-tight dark:text-white leading-none uppercase">Resep & Produksi</h1>
                                <p className="text-xs font-medium text-slate-400 mt-1 uppercase tracking-widest leading-none">Cost Control System</p>
                            </div>
                        </div>

                        <div className="flex flex-wrap items-center gap-3">
                            <div className="flex items-center gap-2 border-r dark:border-slate-800 pr-4">
                                <button onClick={() => router.get(route('recipes.template'))} className="p-2 bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400 rounded-lg hover:bg-emerald-50 hover:text-emerald-600 transition-all"><IconDownload size={20}/></button>
                                <button onClick={() => fileInputRef.current.click()} className="flex items-center gap-2 px-4 py-2 bg-emerald-600 text-white rounded-lg text-xs font-bold uppercase hover:bg-emerald-700 transition-all"><IconFileTypeXls size={18}/> Import</button>
                                <button onClick={() => router.post(route('recipes.sync_all'))} className="p-2 bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400 rounded-lg hover:bg-amber-50 hover:text-amber-600 transition-all"><IconRefresh size={20}/></button>
                            </div>
                            <div className="flex bg-slate-100 dark:bg-slate-800 p-1 rounded-xl">
                                <button onClick={() => setActiveTab("input")} className={`px-5 py-2 rounded-lg text-xs font-bold uppercase transition-all ${activeTab === 'input' ? 'bg-white dark:bg-slate-700 text-primary-600 shadow-sm' : 'text-slate-500'}`}>Konfigurasi</button>
                                <button onClick={() => setActiveTab("list")} className={`px-5 py-2 rounded-lg text-xs font-bold uppercase transition-all ${activeTab === 'list' ? 'bg-white dark:bg-slate-700 text-primary-600 shadow-sm' : 'text-slate-500'}`}>Daftar Resep</button>
                            </div>
                        </div>
                    </div>
                </div>

                {activeTab === "list" ? (
                    <div className="space-y-4">
                        {/* STAT CARDS */}
                        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div className="bg-white dark:bg-slate-900 p-5 rounded-2xl border border-slate-200 dark:border-slate-800 flex items-center gap-4 shadow-sm">
                                <div className="p-3 bg-primary-50 dark:bg-primary-900/20 text-primary-600 rounded-xl"><IconReceipt2/></div>
                                <div><p className="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Menu Resep</p><p className="text-xl font-bold dark:text-white leading-tight">{stats.total_menu} Item</p></div>
                            </div>
                            <div className="bg-white dark:bg-slate-900 p-5 rounded-2xl border border-slate-200 dark:border-slate-800 flex items-center gap-4 shadow-sm">
                                <div className="p-3 bg-emerald-50 dark:bg-emerald-900/20 text-emerald-600 rounded-xl"><IconChartPie/></div>
                                <div><p className="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Avg. Modal</p><p className="text-xl font-bold dark:text-white leading-tight">Rp {new Intl.NumberFormat('id-ID').format(stats.avg_hpp)}</p></div>
                            </div>
                            <div className="bg-white dark:bg-slate-900 p-5 rounded-2xl border border-slate-200 dark:border-slate-800 flex items-center gap-4 shadow-sm">
                                <div className="p-3 bg-rose-50 dark:bg-rose-900/20 text-rose-600 rounded-xl"><IconCategory/></div>
                                <div><p className="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Total Bahan</p><p className="text-xl font-bold dark:text-white leading-tight">{stats.total_ingredients} Item</p></div>
                            </div>
                        </div>

                        {/* DATA TABLE */}
                        <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm overflow-hidden">
                            <div className="p-4 border-b border-slate-100 dark:border-slate-800">
                                <div className="relative max-w-sm">
                                    <IconSearch className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" size={18} />
                                    <input type="text" placeholder="Cari menu resep..." value={search} onChange={handleSearch} className="w-full pl-10 pr-4 py-2 text-sm rounded-xl border-slate-200 dark:bg-slate-800 dark:border-slate-700 dark:text-white focus:ring-primary-500" />
                                </div>
                            </div>
                            
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm text-left table-auto">
                                    <thead className="bg-slate-50 dark:bg-slate-800/50 text-slate-500 dark:text-slate-400 font-bold uppercase text-[10px] tracking-widest border-b dark:border-slate-800">
                                        <tr>
                                            <th className="px-6 py-4 w-10">#</th>
                                            <th className="px-4 py-4 w-[35%]">Produk Jadi</th>
                                            <th className="px-4 py-4">Jumlah Bahan</th>
                                            <th className="px-4 py-4">Total Modal (HPP)</th>
                                            <th className="px-4 py-4 text-center">Status</th>
                                            <th className="px-6 py-4 text-right">Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                                        {products.data.length > 0 ? products.data.map((p) => {
                                            const hppStatus = getHppCategory(p.cost_price, p.sell_price);
                                            const isExpanded = expandedProduct === p.id;
                                            return (
                                                <React.Fragment key={p.id}>
                                                    <tr className={`hover:bg-slate-50/80 dark:hover:bg-slate-800/50 transition-colors ${isExpanded ? 'bg-primary-50/30 dark:bg-primary-900/10' : ''}`}>
                                                        <td className="px-6 py-4">
                                                            <button onClick={() => setExpandedProduct(isExpanded ? null : p.id)} className={`p-1 rounded-lg transition-all ${isExpanded ? 'bg-primary-500 text-white shadow-md' : 'bg-slate-100 dark:bg-slate-800 text-slate-500'}`}>
                                                                {isExpanded ? <IconChevronDown size={16} strokeWidth={3} /> : <IconChevronRight size={16} strokeWidth={3} />}
                                                            </button>
                                                        </td>
                                                        <td className="px-4 py-4">
                                                            <p className="font-bold text-slate-700 dark:text-slate-200 uppercase truncate max-w-[250px]">{p.title}</p>
                                                        </td>
                                                        <td className="px-4 py-4">
                                                            <span className="bg-slate-100 dark:bg-slate-800 px-2.5 py-1 rounded-lg text-[11px] font-bold text-slate-500 uppercase">{p.recipes.length} Bahan baku</span>
                                                        </td>
                                                        <td className="px-4 py-4">
                                                            <div className="flex flex-col">
                                                                <span className="font-mono font-bold text-primary-600 dark:text-primary-400 text-base">Rp {new Intl.NumberFormat('id-ID').format(p.cost_price || 0)}</span>
                                                            </div>
                                                        </td>
                                                        <td className="px-4 py-4 text-center">
                                                            <span className={`text-[10px] font-bold uppercase px-3 py-1 rounded-full border shadow-sm
                                                                ${hppStatus.color === 'rose' ? 'bg-rose-50 text-rose-600 border-rose-100 dark:bg-rose-900/20 dark:border-rose-800' : 
                                                                  hppStatus.color === 'amber' ? 'bg-amber-50 text-amber-600 border-amber-100 dark:bg-amber-900/20 dark:border-amber-800' : 
                                                                  hppStatus.color === 'indigo' ? 'bg-indigo-50 text-indigo-600 border-indigo-100 dark:bg-indigo-900/20 dark:border-indigo-800' :
                                                                  'bg-emerald-50 text-emerald-600 border-emerald-100 dark:bg-emerald-900/20 dark:border-emerald-800'}`}>
                                                                {hppStatus.label}
                                                            </span>
                                                        </td>
                                                        <td className="px-6 py-4 text-right">
                                                            <div className="flex justify-end gap-2">
                                                                <button onClick={() => handleEditRecipe(p)} className="p-2 bg-blue-50 dark:bg-blue-900/30 text-blue-600 rounded-xl hover:bg-blue-100 active:scale-95 transition-all"><IconEdit size={18} /></button>
                                                                <button onClick={() => handleDelete(p.id, 'massal', p.title)} className="p-2 bg-rose-50 dark:bg-rose-900/30 text-rose-600 rounded-xl hover:bg-rose-100 active:scale-95 transition-all"><IconTrash size={18} /></button>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                    {/* INNER TABLE FOR INGREDIENTS */}
                                                    {isExpanded && (
                                                        <tr className="animate-in fade-in zoom-in-95 duration-200">
                                                            <td colSpan="6" className="px-6 py-4 bg-slate-50/50 dark:bg-slate-950/50">
                                                                <div className="bg-white dark:bg-slate-800 rounded-2xl border border-primary-100 dark:border-primary-900/30 shadow-sm overflow-hidden ml-12">
                                                                    <table className="w-full">
                                                                        <thead className="bg-primary-50/50 dark:bg-primary-900/20 text-[10px] font-black text-primary-500 uppercase tracking-tighter">
                                                                            <tr>
                                                                                <th className="px-4 py-3 text-left">Item Bahan Baku</th>
                                                                                <th className="px-4 py-3 text-center">Takaran</th>
                                                                                <th className="px-4 py-3 text-right">Estimasi Biaya</th>
                                                                                <th className="px-4 py-3 w-10"></th>
                                                                            </tr>
                                                                        </thead>
                                                                        <tbody className="divide-y divide-slate-100 dark:divide-slate-700">
                                                                            {p.recipes.map((r, i) => {
                                                                                const itemCost = r.qty_needed * (r.ingredient?.buy_price || 0);
                                                                                const costWeight = p.cost_price > 0 ? (itemCost / p.cost_price) * 100 : 0;
                                                                                return (
                                                                                    <tr key={i} className="text-xs hover:bg-slate-50 dark:hover:bg-slate-700/50">
                                                                                        <td className="px-4 py-3">
                                                                                            <p className="font-bold text-slate-700 dark:text-slate-300 uppercase leading-none">{r.ingredient?.name}</p>
                                                                                            <div className="w-full h-1 bg-slate-100 dark:bg-slate-900 rounded-full mt-2 overflow-hidden flex max-w-[150px]">
                                                                                                <div className={`h-full rounded-full transition-all duration-700 ${costWeight > 50 ? 'bg-rose-500' : 'bg-primary-500'}`} style={{ width: `${costWeight}%` }}></div>
                                                                                            </div>
                                                                                        </td>
                                                                                        <td className="px-4 py-3 text-center font-bold text-slate-500">
                                                                                            <span className="font-mono">{r.qty_needed}</span> {r.ingredient?.unit}
                                                                                        </td>
                                                                                        <td className="px-4 py-3 text-right">
                                                                                            <p className="font-black dark:text-white text-sm">Rp {new Intl.NumberFormat('id-ID').format(itemCost)}</p>
                                                                                            <p className="text-[10px] text-slate-400 font-bold uppercase tracking-tighter">{costWeight.toFixed(1)}% beban</p>
                                                                                        </td>
                                                                                        <td className="px-4 py-3 text-center">
                                                                                            <button onClick={() => handleDelete(r.id, 'satuan')} className="p-1.5 text-rose-400 hover:text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-900/30 rounded-lg transition-all"><IconX size={14} /></button>
                                                                                        </td>
                                                                                    </tr>
                                                                                );
                                                                            })}
                                                                        </tbody>
                                                                    </table>
                                                                </div>
                                                                <div className="mt-4 ml-12 p-4 bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 flex items-start gap-3">
                                                                    <IconInfoCircle size={16} className="text-indigo-500 mt-0.5 shrink-0" />
                                                                    <p className="text-[10px] text-slate-500 dark:text-slate-400 leading-relaxed italic uppercase font-medium">Bar indikator menunjukkan bahan baku yang paling menguras modal. Perhatikan bahan dengan beban tinggi (&gt;50%) untuk optimasi profit.</p>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                    )}
                                                </React.Fragment>
                                            );
                                        }) : (
                                            <tr>
                                                <td colSpan="6" className="py-20 text-center">
                                                    <IconToolsKitchen2 size={48} className="mx-auto text-slate-200 mb-4" />
                                                    <p className="text-sm font-bold uppercase text-slate-400 tracking-widest">Belum Ada Resep Terdaftar</p>
                                                </td>
                                            </tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>
                            {products.links && products.data.length > 0 && (
                                <div className="p-4 border-t border-slate-100 dark:border-slate-800">
                                    <Pagination links={products.links} />
                                </div>
                            )}
                        </div>
                    </div>
                ) : (
                    /* TAB INPUT FORM */
                    <div className="bg-white dark:bg-slate-900 p-8 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm animate-in fade-in slide-in-from-bottom-4">
                        <form onSubmit={submit} className="max-w-3xl mx-auto space-y-8">
                            <div className="flex justify-between items-center pb-4 border-b border-slate-100 dark:border-slate-800">
                                <h2 className="font-bold text-sm uppercase dark:text-white tracking-widest flex items-center gap-3"><div className="w-2 h-6 bg-primary-500 rounded-full"></div> Susun Bahan Baku</h2>
                                {data.product_id && <button type="button" onClick={() => reset()} className="text-[10px] font-bold text-rose-500 uppercase flex items-center gap-1 hover:underline"><IconArrowBackUp size={14}/> Reset Form</button>}
                            </div>
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-8 items-end">
                                <div>
                                    <label className="block text-[10px] font-bold uppercase mb-3 text-slate-400 tracking-widest">Target Produk Jadi</label>
                                    <select className="w-full rounded-xl dark:bg-slate-800 dark:text-white border-slate-200 dark:border-slate-700 font-bold text-sm focus:ring-primary-500" value={data.product_id} onChange={e => setData('product_id', e.target.value)} required>
                                        <option value="">-- PILIH MENU --</option>
                                        {allProducts.map(p => <option key={p.id} value={p.id}>{p.title}</option>)}
                                    </select>
                                </div>
                                <div className="p-5 bg-primary-50 dark:bg-primary-900/10 rounded-xl flex justify-between items-center border border-primary-100 dark:border-primary-900/30 shadow-inner">
                                    <span className="text-[10px] font-bold uppercase text-primary-400 tracking-wider">Preview Modal (HPP):</span>
                                    <span className="text-xl font-bold text-primary-600 dark:text-primary-400 font-mono">Rp {new Intl.NumberFormat('id-ID').format(hppPreview)}</span>
                                </div>
                            </div>
                            <div className="space-y-4">
                                <label className="block text-[10px] font-bold uppercase text-slate-400 tracking-widest">Komposisi Bahan</label>
                                <div className="grid gap-3">
                                    {data.ingredients.map((row, index) => (
                                        <div key={index} className="flex gap-3 items-center bg-slate-50 dark:bg-slate-800/50 p-2 pl-4 rounded-xl border border-slate-200 dark:border-slate-700 transition-all focus-within:ring-2 focus-within:ring-primary-500/20">
                                            <select className="flex-1 bg-transparent border-none dark:text-white text-sm font-bold focus:ring-0" value={row.ingredient_id} onChange={e => handleChange(index, 'ingredient_id', e.target.value)} required>
                                                <option value="">Pilih Bahan</option>
                                                {ingredients.map(ing => <option key={ing.id} value={ing.id}>{ing.name} ({ing.unit})</option>)}
                                            </select>
                                            <div className="bg-white dark:bg-slate-900 px-4 py-2 rounded-lg border border-slate-200 dark:border-slate-700 shadow-sm flex items-center gap-2">
                                                <input type="number" step="any" className="w-16 border-none p-0 text-center text-sm font-bold bg-transparent dark:text-white focus:ring-0" value={row.qty_needed} onChange={e => handleChange(index, 'qty_needed', e.target.value)} required />
                                            </div>
                                            <button type="button" onClick={() => handleRemoveRow(index)} className="p-2 text-rose-500 hover:bg-rose-100 dark:hover:bg-rose-900/30 rounded-lg transition-all"><IconTrash size={18} /></button>
                                        </div>
                                    ))}
                                </div>
                                <button type="button" onClick={handleAddRow} className="flex items-center gap-2 text-[10px] font-bold uppercase text-primary-600 hover:bg-primary-50 dark:hover:bg-primary-900/20 px-4 py-2 rounded-lg transition-all border border-dashed border-primary-200 dark:border-primary-800"><IconPlus size={14} /> Tambah Bahan</button>
                            </div>
                            <button type="submit" disabled={processing} className="w-full bg-slate-900 dark:bg-primary-600 text-white py-4 rounded-xl font-bold uppercase tracking-widest shadow-xl shadow-primary-500/10 hover:opacity-90 active:scale-[0.98] transition-all disabled:opacity-50 flex items-center justify-center gap-2"><IconDeviceFloppy size={20} /> Simpan Struktur Resep</button>
                        </form>
                    </div>
                )}
            </div>
        </DashboardLayout>
    );
}