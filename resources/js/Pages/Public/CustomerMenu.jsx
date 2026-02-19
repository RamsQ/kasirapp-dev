import React, { useState, useMemo } from "react";
import { Head } from "@inertiajs/react";
import { 
    IconShoppingCart, IconPlus, IconMinus, IconToolsKitchen2, 
    IconSearch, IconBoxSeam, IconUser, IconChevronLeft,
    IconCash
} from "@tabler/icons-react";
import axios from "axios";
import Swal from "sweetalert2";

export default function CustomerMenu({ products, table, categories }) {
    const [cart, setCart] = useState([]);
    const [name, setName] = useState("");
    const [orderType, setOrderType] = useState(table ? "table" : "takeaway");
    const [search, setSearch] = useState("");
    const [activeCategory, setActiveCategory] = useState("all");
    const [showReview, setShowReview] = useState(false);

    // Helper Format Harga
    const formatPrice = (value) =>
        new Intl.NumberFormat("id-ID", { style: "currency", currency: "IDR", minimumFractionDigits: 0 }).format(value || 0);

    // Filter Produk
    const filteredProducts = useMemo(() => {
        return products.filter(p => {
            const matchSearch = p.title.toLowerCase().includes(search.toLowerCase());
            const matchCategory = activeCategory === "all" || p.category_id == activeCategory;
            return matchSearch && matchCategory;
        });
    }, [search, activeCategory, products]);

    const addToCart = (p) => {
        // Cek lagi di sisi client jika is_available false
        if (!p.is_available) return;

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

    const updateQty = (id, delta) => {
        setCart(cart.map(item => (item.product_id === id) 
            ? { ...item, qty: Math.max(0, item.qty + delta), price: Math.max(0, item.qty + delta) * item.sell_price } : item
        ).filter(i => i.qty > 0));
    };

    const total = cart.reduce((acc, item) => acc + item.price, 0);

    const submitOrder = async () => {
        if (!name || name.trim() === "") {
            return Swal.fire({ 
                icon: "warning", 
                title: "Nama Kosong", 
                text: "Silakan isi nama Anda untuk memproses pesanan.",
                confirmButtonColor: "#0ea5e9"
            });
        }
        if (cart.length === 0) return Swal.fire({ icon: "error", title: "Keranjang Kosong" });

        try {
            Swal.fire({ title: 'Mengirim Pesanan...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

            const response = await axios.post(route('public.menu.order'), {
                customer_name: name,
                table_id: orderType === 'table' ? table?.id : null,
                order_type: orderType,
                cart_items: cart,
                total: total,
                payment_method: 'cash'
            });

            finishOrder(response.data);
        } catch (e) {
            Swal.close();
            Swal.fire("Gagal", "Terjadi kesalahan sistem saat mengirim pesanan", "error");
        }
    };

    const finishOrder = (data) => {
        const { queue_number, message, unique_code } = data;
        const displayCode = unique_code ? unique_code.toString().slice(-4) : "0000";
        const isAdditional = message.toLowerCase().includes('tambahan') || message.toLowerCase().includes('gabung');

        Swal.fire({
            title: `<span style="font-family: 'Inter', sans-serif; font-weight: 900; font-style: italic;">${isAdditional ? 'TAMBAHAN TERKIRIM!' : 'PESANAN TERKIRIM!'}</span>`,
            html: `
                <div style="background: white; border: 1px solid #e2e8f0; border-radius: 24px; padding: 25px; margin-top: 15px;">
                    <div style="border-bottom: 2px dashed #cbd5e1; padding-bottom: 15px; margin-bottom: 15px;">
                        <p style="font-size: 10px; font-weight: 800; color: #64748b; text-transform: uppercase; margin: 0;">Kode Pesanan</p>
                        <h2 style="font-size: 42px; font-weight: 900; color: #1e293b; margin: 5px 0 0 0;">#${displayCode}</h2>
                    </div>
                    <div>
                        <p style="font-size: 10px; font-weight: 800; color: #64748b; text-transform: uppercase; margin: 0;">Nomor Antrean</p>
                        <p style="font-size: 26px; font-weight: 900; color: #0ea5e9; margin: 5px 0 0 0;">${queue_number}</p>
                    </div>
                    <div style="margin-top: 20px; padding: 12px; background: #f0f9ff; border-radius: 12px; border: 1px solid #bae6fd;">
                        <p style="font-size: 12px; font-weight: 700; color: #0369a1; margin: 0;">${message}</p>
                    </div>
                </div>
            `,
            confirmButtonText: "OKE, SAYA MENGERTI",
            confirmButtonColor: "#0ea5e9",
            allowOutsideClick: false,
        }).then(() => {
            setCart([]);
            window.location.reload();
        });
    };

    return (
        <div className="bg-slate-50 min-h-screen pb-48 font-sans text-slate-900">
            <Head title="E-Menu Digital" />
            
            {!showReview ? (
                /* --- MODE KATALOG MENU --- */
                <div className="animate-in fade-in duration-500">
                    <div className="p-6 text-white rounded-b-[2.5rem] shadow-lg sticky top-0 z-30" style={{ background: 'linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%)' }}>
                        <div className="flex justify-between items-center mb-4">
                            <div>
                                <h1 className="text-xl font-black uppercase italic tracking-tighter flex items-center gap-2">
                                    <IconToolsKitchen2 size={24} /> E-MENU QR
                                </h1>
                                <p className="text-[10px] font-bold uppercase opacity-90">
                                    {orderType === 'table' ? `Meja: ${table?.name}` : 'Take Away'}
                                </p>
                            </div>
                            <div className="bg-white/20 p-2 rounded-xl backdrop-blur-md relative">
                                <IconShoppingCart size={20} />
                                {cart.length > 0 && <span className="absolute -top-1 -right-1 bg-red-500 text-white text-[8px] font-bold px-1.5 py-0.5 rounded-full">{cart.length}</span>}
                            </div>
                        </div>
                        <div className="relative">
                            <IconSearch className="absolute left-4 top-1/2 -translate-y-1/2 text-sky-200" size={18} />
                            <input type="text" placeholder="Cari menu favorit..." className="w-full pl-11 pr-4 py-3 rounded-2xl border-none bg-white/20 placeholder:text-sky-100 text-sm text-white font-bold focus:ring-2 focus:ring-white transition-all" value={search} onChange={(e) => setSearch(e.target.value)} />
                        </div>
                    </div>

                    <div className="flex gap-2 overflow-x-auto p-4 sticky top-[145px] bg-slate-50/90 backdrop-blur-sm z-20 scrollbar-hide">
                        <button onClick={() => setActiveCategory("all")} className={`px-6 py-2.5 rounded-full text-[10px] font-black uppercase border transition-all ${activeCategory === "all" ? 'bg-slate-900 text-white' : 'bg-white text-slate-500'}`}>Semua</button>
                        {categories?.map(cat => (
                            <button key={cat.id} onClick={() => setActiveCategory(cat.id)} className={`px-6 py-2.5 rounded-full text-[10px] font-black uppercase border transition-all ${activeCategory == cat.id ? 'bg-slate-900 text-white' : 'bg-white text-slate-500'}`}>{cat.name}</button>
                        ))}
                    </div>

                    <div className="px-4 space-y-4 mt-2">
                        {filteredProducts.map(p => (
                            <div key={p.id} className={`bg-white p-3 rounded-[1.5rem] flex gap-4 items-center shadow-sm border border-slate-50 relative ${!p.is_available ? 'opacity-70' : ''}`}>
                                <div className="relative shrink-0">
                                    <img 
                                        src={p.image ? (p.image.startsWith('http') ? p.image : `/storage/products/${p.image}`) : `https://ui-avatars.com/api/?name=${p.title}&background=random`} 
                                        className={`w-24 h-24 rounded-2xl object-cover shadow-inner ${!p.is_available ? 'grayscale' : ''}`} 
                                        alt={p.title} 
                                    />
                                    {p.type === 'bundle' && <div className="absolute -top-2 -left-2 bg-purple-500 text-white p-1 rounded-lg shadow-lg"><IconBoxSeam size={14} /></div>}
                                    {!p.is_available && (
                                        <div className="absolute inset-0 bg-black/40 rounded-2xl flex items-center justify-center">
                                            <span className="text-[10px] font-black text-white uppercase tracking-tighter rotate-12 border-2 border-white px-1">Sold Out</span>
                                        </div>
                                    )}
                                </div>
                                <div className="flex-1 min-w-0">
                                    <h3 className="font-black uppercase text-[11px] text-slate-800 leading-tight mb-0.5 truncate">{p.title}</h3>
                                    <p className="text-sky-600 font-black text-sm mb-1">{formatPrice(p.sell_price)}</p>
                                    
                                    <span className={`text-[8px] px-2 py-0.5 rounded-md font-bold uppercase ${
                                        !p.is_available 
                                        ? 'bg-slate-100 text-slate-400' 
                                        : (p.stock > 0 ? (p.stock <= 5 ? 'bg-orange-50 text-orange-500' : 'bg-green-50 text-green-500') : 'bg-sky-50 text-sky-600')
                                    }`}>
                                        {!p.is_available ? 'Habis' : (p.stock > 0 ? `Stok: ${p.stock}` : 'Tersedia')}
                                    </span>
                                </div>
                                <button 
                                    onClick={() => p.is_available && addToCart(p)} 
                                    disabled={!p.is_available}
                                    className={`w-12 h-12 rounded-2xl transition-all flex items-center justify-center ${
                                        p.is_available 
                                        ? 'bg-sky-100 text-sky-600 active:scale-90 shadow-sm' 
                                        : 'bg-slate-100 text-slate-300 cursor-not-allowed'
                                    }`}
                                >
                                    {p.is_available ? <IconPlus size={24} /> : <IconMinus size={24} />}
                                </button>
                            </div>
                        ))}
                    </div>

                    {cart.length > 0 && (
                        <div className="fixed bottom-0 inset-x-0 p-6 bg-white/95 backdrop-blur-xl border-t border-slate-100 rounded-t-[3rem] shadow-2xl z-50">
                            <div className="max-w-md mx-auto flex justify-between items-center">
                                <div>
                                    <span className="text-[10px] font-black text-slate-400 uppercase block mb-1">Total Sementara</span>
                                    <span className="text-2xl font-black text-sky-600 italic tracking-tighter">{formatPrice(total)}</span>
                                </div>
                                <button onClick={() => setShowReview(true)} className="px-8 py-4 bg-slate-900 text-white rounded-2xl font-black uppercase text-[12px] flex items-center gap-3 shadow-xl active:scale-95 transition-all">
                                     Review Pesanan <IconChevronLeft className="rotate-180" size={20} />
                                </button>
                            </div>
                        </div>
                    )}
                </div>
            ) : (
                /* --- MODE REVIEW PESANAN --- */
                <div className="p-6 animate-in slide-in-from-bottom-10 duration-500">
                    <button onClick={() => setShowReview(false)} className="mb-6 flex items-center gap-2 text-slate-400 font-black uppercase text-[10px] tracking-widest">
                        <IconChevronLeft size={16} /> Kembali ke Menu
                    </button>

                    <h2 className="text-3xl font-black italic uppercase tracking-tighter mb-2 text-slate-800">Review Pesanan</h2>
                    
                    <div className="bg-white p-5 rounded-3xl shadow-sm border border-slate-100 mb-8 mt-4">
                        <p className="text-[10px] font-black text-slate-400 uppercase mb-3 tracking-widest flex items-center gap-1">
                            <IconUser size={14} /> Nama Pemesan
                        </p>
                        <input type="text" placeholder="Tulis nama Anda..." className="w-full p-4 rounded-2xl bg-slate-50 border-none text-sm font-black focus:ring-2 focus:ring-sky-500" value={name} onChange={e => setName(e.target.value)} />
                    </div>

                    <div className="space-y-4 mb-10">
                        <p className="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Daftar Belanja</p>
                        {cart.map((item) => (
                            <div key={item.product_id} className="bg-white p-4 rounded-3xl border border-slate-100 shadow-sm">
                                <div className="flex justify-between items-center">
                                    <div className="min-w-0 flex-1">
                                        <h4 className="font-black text-[11px] uppercase text-slate-800 truncate">{item.product_title}</h4>
                                        <p className="text-sky-600 font-black text-sm">{formatPrice(item.sell_price)}</p>
                                    </div>
                                    <div className="flex items-center gap-3 bg-slate-50 p-1.5 rounded-2xl ml-4">
                                        <button onClick={() => updateQty(item.product_id, -1)} className="w-8 h-8 flex items-center justify-center bg-white rounded-xl text-red-500 shadow-sm active:scale-90"><IconMinus size={16}/></button>
                                        <span className="font-black text-sm w-4 text-center">{item.qty}</span>
                                        <button onClick={() => updateQty(item.product_id, 1)} className="w-8 h-8 flex items-center justify-center bg-white rounded-xl text-sky-500 shadow-sm active:scale-90"><IconPlus size={16}/></button>
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>

                    <div className="bg-sky-50 p-6 rounded-[2rem] mb-10 border border-sky-100 text-center">
                        <p className="text-[10px] font-black text-sky-400 uppercase tracking-widest mb-1">Total Bayar di Kasir</p>
                        <h3 className="text-4xl font-black text-sky-600 italic tracking-tighter">{formatPrice(total)}</h3>
                    </div>

                    <button onClick={submitOrder} className="w-full bg-slate-900 p-6 rounded-[2rem] flex items-center justify-center gap-4 active:scale-[0.98] transition-all shadow-xl text-white group">
                        <IconCash size={28} className="text-sky-400" />
                        <span className="font-black uppercase tracking-widest">Kirim Pesanan Sekarang</span>
                    </button>
                    
                    <p className="text-center mt-6 text-[10px] font-bold text-slate-400 uppercase italic">
                        *Pesanan akan diproses setelah Anda melakukan pembayaran di kasir.
                    </p>
                </div>
            )}
        </div>
    );
}