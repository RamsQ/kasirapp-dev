import React, { useState, useMemo } from "react";
import { Head } from "@inertiajs/react";
import { 
    IconShoppingCart, IconPlus, IconMinus, IconToolsKitchen2, 
    IconTruck, IconSearch, IconBoxSeam, IconClick, IconUser, IconArmchair
} from "@tabler/icons-react";
import axios from "axios";
import Swal from "sweetalert2";
import "@/../../resources/css/customer-menu.css"; 

export default function CustomerMenu({ products, table, categories }) {
    const [cart, setCart] = useState([]);
    const [name, setName] = useState("");
    
    // Inisialisasi: Jika scan QR Meja, default 'table'. Jika tidak, 'takeaway'.
    const [orderType, setOrderType] = useState(table ? "table" : "takeaway");
    
    const [search, setSearch] = useState("");
    const [activeCategory, setActiveCategory] = useState("all");

    // Filter Produk berdasarkan kategori dan pencarian
    const filteredProducts = useMemo(() => {
        return products.filter(p => {
            const matchSearch = p.title.toLowerCase().includes(search.toLowerCase());
            const matchCategory = activeCategory === "all" || p.category_id == activeCategory;
            return matchSearch && matchCategory;
        });
    }, [search, activeCategory, products]);

    // Logika Tambah Ke Keranjang
    const addToCart = (p) => {
        const exist = cart.find(item => item.product_id === p.id);
        if (exist) {
            setCart(cart.map(item => item.product_id === p.id 
                ? { ...item, qty: item.qty + 1, price: (item.qty + 1) * p.sell_price } : item));
        } else {
            setCart([...cart, { 
                product_id: p.id, 
                product_title: p.title, 
                qty: 1, 
                price: p.sell_price, 
                sell_price: p.sell_price 
            }]);
        }
    };

    // Logika Update Qty (Tambah/Kurang)
    const updateQty = (id, delta) => {
        setCart(cart.map(item => (item.product_id === id) 
            ? { ...item, qty: Math.max(0, item.qty + delta), price: Math.max(0, item.qty + delta) * item.sell_price } : item
        ).filter(i => i.qty > 0));
    };

    const total = cart.reduce((acc, item) => acc + item.price, 0);

    // Proses Kirim Pesanan
    const submitOrder = async () => {
        if (!name || name.trim() === "") {
            return Swal.fire({
                icon: "warning",
                title: "Nama Kosong",
                text: "Silahkan masukkan nama Anda untuk memudahkan kasir memanggil pesanan.",
                confirmButtonColor: "#0ea5e9",
            });
        }

        if (cart.length === 0) {
            return Swal.fire({
                icon: "error",
                title: "Keranjang Kosong",
                text: "Pilih minimal satu menu terlebih dahulu.",
                confirmButtonColor: "#0ea5e9",
            });
        }

        try {
            Swal.fire({ 
                title: 'Mengirim Pesanan...', 
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading() 
            });

            // Kirim data ke backend
            const response = await axios.post(route('public.menu.order'), {
                customer_name: name,
                table_id: orderType === 'table' ? table?.id : null,
                order_type: orderType,
                cart_items: cart,
                total: total
            });

            // Tangkap nomor antrean dan kode verifikasi unik dari server
            const { unique_code, queue_number } = response.data;

            Swal.fire({
                title: `<span style="font-family: 'Inter', sans-serif; font-weight: 900; font-style: italic; color: #1e293b;">PESANAN TERKIRIM!</span>`,
                html: `
                    <div style="background: white; border: 1px solid #e2e8f0; border-radius: 24px; padding: 25px; margin-top: 15px; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);">
                        <div style="border-bottom: 2px dashed #cbd5e1; padding-bottom: 15px; margin-bottom: 15px;">
                            <p style="font-size: 10px; font-weight: 800; color: #64748b; text-transform: uppercase; margin: 0; letter-spacing: 0.1em;">Nomor Antrean</p>
                            <h2 style="font-size: 42px; font-weight: 900; color: #0ea5e9; margin: 5px 0 0 0; line-height: 1;">${queue_number}</h2>
                        </div>
                        
                        <div>
                            <p style="font-size: 10px; font-weight: 800; color: #64748b; text-transform: uppercase; margin: 0; letter-spacing: 0.1em;">Kode Pesanan</p>
                            <p style="font-size: 26px; font-weight: 900; color: #1e293b; margin: 5px 0 0 0; letter-spacing: 6px;">#${unique_code}</p>
                        </div>
                        
                        <div style="margin-top: 20px; padding: 10px; background: #f0f9ff; border-radius: 12px;">
                            <p style="font-size: 11px; font-weight: 700; color: #0369a1; margin: 0;">Silakan tunjukkan layar ini ke kasir untuk proses pembayaran.</p>
                        </div>
                    </div>
                    <p style="font-size: 11px; font-weight: 600; color: #94a3b8; margin-top: 20px;">Harap simpan kode ini atau screenshot layar Anda.</p>
                `,
                confirmButtonText: "SAYA MENGERTI",
                confirmButtonColor: "#0ea5e9",
                background: '#f8fafc',
                allowOutsideClick: false,
                width: '360px',
            }).then(() => {
                setCart([]);
                setName("");
                window.location.reload();
            });
        } catch (e) {
            Swal.close();
            Swal.fire("Gagal", e.response?.data?.message || "Terjadi kesalahan saat mengirim pesanan", "error");
        }
    };

    return (
        <div className="bg-slate-50 min-h-screen pb-48 font-sans text-slate-900">
            <Head title="E-Menu Digital" />
            
            {/* Header Sticky */}
            <div className="glass-header p-6 text-white rounded-b-[2.5rem] shadow-lg sticky top-0 z-30">
                <div className="flex justify-between items-center mb-4">
                    <div>
                        <h1 className="text-xl font-black uppercase italic tracking-tighter flex items-center gap-2">
                            <IconToolsKitchen2 size={24} /> E-MENU QR
                        </h1>
                        <p className="text-[10px] font-bold uppercase tracking-widest opacity-90 flex items-center gap-1">
                            {orderType === 'table' ? (
                                <><IconArmchair size={12} /> Meja: {table?.name || '?'}</>
                            ) : (
                                <><IconTruck size={12} /> Take Away</>
                            )}
                        </p>
                    </div>
                    <div className="bg-white/20 p-2 rounded-xl backdrop-blur-md">
                        <IconShoppingCart size={20} />
                    </div>
                </div>
                <div className="relative">
                    <IconSearch className="absolute left-4 top-1/2 -translate-y-1/2 text-sky-200" size={18} />
                    <input 
                        type="text" 
                        placeholder="Cari makanan favoritmu..." 
                        className="w-full pl-11 pr-4 py-3 rounded-2xl border-none bg-white/20 placeholder:text-sky-100 text-sm text-white font-bold focus:ring-2 focus:ring-white transition-all" 
                        value={search} 
                        onChange={(e) => setSearch(e.target.value)} 
                    />
                </div>
            </div>

            {/* Form Pilihan Tipe Pesanan & Nama */}
            <div className="px-4 mt-6 space-y-4">
                <div className="bg-white p-5 rounded-3xl shadow-sm border border-slate-100">
                    <p className="text-[10px] font-black text-slate-400 uppercase mb-3 tracking-widest flex items-center gap-1">
                        <IconClick size={14} /> Tipe Pesanan
                    </p>
                    <div className="grid grid-cols-2 gap-3">
                        <button 
                            onClick={() => setOrderType('table')}
                            disabled={!table} 
                            className={`flex flex-col items-center justify-center p-3 rounded-2xl border-2 transition-all ${
                                orderType === 'table' 
                                ? 'border-sky-500 bg-sky-50 text-sky-600' 
                                : 'border-slate-100 bg-slate-50 text-slate-400 opacity-50'
                            }`}
                        >
                            <IconArmchair size={20} />
                            <span className="text-[9px] font-black mt-1 uppercase">Makan Di Sini</span>
                        </button>
                        <button 
                            onClick={() => setOrderType('takeaway')}
                            className={`flex flex-col items-center justify-center p-3 rounded-2xl border-2 transition-all ${
                                orderType === 'takeaway' 
                                ? 'border-orange-500 bg-orange-50 text-orange-600' 
                                : 'border-slate-100 bg-slate-50 text-slate-400'
                            }`}
                        >
                            <IconTruck size={20} />
                            <span className="text-[9px] font-black mt-1 uppercase">Bawa Pulang</span>
                        </button>
                    </div>
                    {!table && (
                        <p className="text-[8px] text-slate-400 font-bold mt-2 text-center italic">
                            * Anda tidak menduduki meja spesifik. Otomatis Take Away.
                        </p>
                    )}
                </div>

                <div className="bg-white p-5 rounded-3xl shadow-sm border border-slate-100">
                    <p className="text-[10px] font-black text-slate-400 uppercase mb-3 tracking-widest flex items-center gap-1">
                        <IconUser size={14} /> Nama Pemesan <span className="text-red-500">*</span>
                    </p>
                    <input 
                        type="text" 
                        placeholder="Masukkan nama Anda..."
                        className={`w-full p-4 rounded-2xl bg-slate-50 border-none text-sm font-black focus:ring-2 transition-all ${
                            !name ? 'focus:ring-red-400 ring-1 ring-red-100' : 'focus:ring-sky-500'
                        }`} 
                        value={name} 
                        onChange={e => setName(e.target.value)} 
                    />
                </div>
            </div>

            {/* Kategori Tabs */}
            <div className="flex gap-2 overflow-x-auto p-4 scrollbar-hide sticky top-[145px] bg-slate-50/90 backdrop-blur-sm z-20">
                <button 
                    onClick={() => setActiveCategory("all")} 
                    className={`px-6 py-2.5 rounded-full text-[10px] font-black uppercase border transition-all shadow-sm ${activeCategory === "all" ? 'bg-slate-900 text-white border-slate-900' : 'bg-white text-slate-500 border-slate-200'}`}
                >
                    Semua
                </button>
                {categories?.map(cat => (
                    <button 
                        key={cat.id} 
                        onClick={() => setActiveCategory(cat.id)} 
                        className={`px-6 py-2.5 rounded-full text-[10px] font-black uppercase border transition-all shadow-sm ${activeCategory == cat.id ? 'bg-slate-900 text-white border-slate-900' : 'bg-white text-slate-500 border-slate-200'}`}
                    >
                        {cat.name}
                    </button>
                ))}
            </div>

            {/* Daftar Menu */}
            <div className="px-4 space-y-4">
                <div className="grid grid-cols-1 gap-4">
                    {filteredProducts.length > 0 ? filteredProducts.map(p => (
                        <div key={p.id} className="menu-card bg-white p-3 rounded-[1.5rem] flex gap-4 items-center shadow-sm border border-slate-50">
                            <div className="relative shrink-0">
                                <img 
                                    src={p.image ? `/storage/products/${p.image}` : `https://ui-avatars.com/api/?name=${p.title}&background=random`} 
                                    className="w-24 h-24 rounded-2xl object-cover shadow-inner" 
                                    alt={p.title}
                                />
                                {p.type === 'bundle' && (
                                    <div className="absolute -top-2 -left-2 bg-purple-500 text-white p-1 rounded-lg shadow-lg">
                                        <IconBoxSeam size={14} />
                                    </div>
                                )}
                            </div>
                            <div className="flex-1 min-w-0">
                                <h3 className="font-black uppercase text-[11px] text-slate-800 leading-tight mb-1 truncate">{p.title}</h3>
                                <p className="text-sky-600 font-black text-sm mb-2 tracking-tighter">Rp {p.sell_price.toLocaleString('id-ID')}</p>
                                <span className={`text-[8px] px-2 py-0.5 rounded-md font-bold uppercase ${p.stock <= 5 ? 'bg-red-50 text-red-500' : 'bg-green-50 text-green-500'}`}>
                                    Stok: {p.stock}
                                </span>
                            </div>
                            <button 
                                onClick={() => addToCart(p)} 
                                className="w-12 h-12 bg-sky-100 text-sky-600 rounded-2xl active:scale-90 transition-all flex items-center justify-center shadow-inner"
                            >
                                <IconPlus size={24} />
                            </button>
                        </div>
                    )) : (
                        <div className="text-center py-20 opacity-30 italic font-black uppercase text-xs">
                            Menu tidak ditemukan
                        </div>
                    )}
                </div>
            </div>

            {/* Floating Checkout Drawer */}
            {cart.length > 0 && (
                <div className="fixed bottom-0 inset-x-0 p-6 bg-white/95 backdrop-blur-xl border-t border-slate-100 rounded-t-[3rem] shadow-[0_-20px_50px_rgba(0,0,0,0.1)] z-50 animate-in slide-in-from-bottom duration-500">
                    <div className="max-w-md mx-auto">
                        <div className="flex gap-2 overflow-x-auto mb-6 scrollbar-hide py-1">
                            {cart.map(item => (
                                <div key={item.product_id} className="bg-slate-100 px-4 py-2 rounded-xl flex items-center gap-3 shrink-0 border border-slate-200">
                                    <span className="text-[10px] font-black uppercase max-w-[80px] truncate">{item.product_title}</span>
                                    <div className="flex items-center gap-2 bg-white px-2 py-1 rounded-lg shadow-sm border border-slate-200">
                                        <button onClick={() => updateQty(item.product_id, -1)} className="text-red-500"><IconMinus size={12}/></button>
                                        <span className="text-[10px] font-black min-w-[12px] text-center">{item.qty}</span>
                                        <button onClick={() => updateQty(item.product_id, 1)} className="text-sky-500"><IconPlus size={12}/></button>
                                    </div>
                                </div>
                            ))}
                        </div>

                        <div className="flex justify-between items-center mb-6">
                            <div>
                                <span className="text-[10px] font-black text-slate-400 uppercase tracking-widest block mb-1">Total Pembayaran</span>
                                <span className="text-2xl font-black text-sky-600 italic tracking-tighter">Rp {total.toLocaleString('id-ID')}</span>
                            </div>
                            <div className="bg-sky-50 px-4 py-2 rounded-2xl border border-sky-100 text-center">
                                <span className="text-xs font-black text-sky-600 uppercase block">{cart.length} Item</span>
                            </div>
                        </div>
                        
                        <button 
                            onClick={submitOrder} 
                            className="w-full py-4 bg-slate-900 text-white rounded-2xl font-black uppercase flex items-center justify-center gap-3 tracking-widest shadow-xl active:scale-95 transition-all"
                        >
                            <IconShoppingCart size={20} /> Kirim Pesanan Sekarang
                        </button>
                    </div>
                </div>
            )}

            <style>{`
                .scrollbar-hide::-webkit-scrollbar { display: none; }
                input:focus { outline: none; }
            `}</style>
        </div>
    );
}