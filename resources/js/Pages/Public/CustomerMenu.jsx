import React, { useState, useMemo, useEffect, useCallback } from "react";
import { Head } from "@inertiajs/react";
import { 
    IconShoppingCart, IconPlus, IconMinus, IconToolsKitchen2, 
    IconSearch, IconBoxSeam, IconUser, IconChevronLeft,
    IconCash, IconSpeakerphone, IconRosetteDiscount, IconGift,
    IconCircleFilled, IconChevronRight, IconCheck
} from "@tabler/icons-react";
import axios from "axios";
import Swal from "sweetalert2";
import confetti from 'canvas-confetti';
import toast, { Toaster } from 'react-hot-toast';

// --- KOMPONEN BANNER CAROUSEL ---
const PromoBanner = ({ activePromos, formatPrice }) => {
    const [currentIndex, setCurrentIndex] = useState(0);

    useEffect(() => {
        if (!activePromos || activePromos.length <= 1) return;
        const interval = setInterval(() => {
            setCurrentIndex((prev) => (prev + 1) % activePromos.length);
        }, 3000);
        return () => clearInterval(interval);
    }, [activePromos]);

    if (!activePromos || activePromos.length === 0) return null;

    const currentPromo = activePromos[currentIndex];

    const getPromoMessage = (promo) => {
        if (!promo.product_id) {
            return `DISKON ${promo.type === 'percentage' ? promo.value + '%' : formatPrice(promo.value)} UNTUK SEMUA MENU!`;
        } else if (promo.bonus_product_id) {
            return `BELI ${promo.minimum_item} ${promo.product?.title}, GRATIS ${promo.bonus_product?.title}!`;
        } else {
            return `PROMO GROSIR: BELI MIN. ${promo.minimum_item} ${promo.product?.title} LEBIH MURAH!`;
        }
    };

    return (
        <div className="px-4 py-3">
            <div className="overflow-hidden rounded-[2rem] bg-gradient-to-br from-indigo-600 via-purple-600 to-fuchsia-600 shadow-xl shadow-indigo-500/30 relative min-h-[90px] flex items-center">
                <div className="absolute -right-6 -bottom-6 opacity-10 rotate-12">
                    <IconRosetteDiscount size={120} strokeWidth={1} />
                </div>
                <div className="relative z-10 p-5 w-full">
                    <div className="flex items-center gap-4">
                        <div className="p-3 bg-white/20 rounded-2xl backdrop-blur-xl text-white shadow-inner">
                            <IconSpeakerphone size={24} className="animate-bounce" />
                        </div>
                        <div className="flex-1 min-w-0">
                            <div className="flex items-center gap-2 mb-1">
                                <span className="text-[9px] font-black uppercase tracking-[0.3em] text-indigo-100 bg-white/10 px-2 py-0.5 rounded-full">Flash Deal</span>
                                {activePromos.length > 1 && (
                                    <div className="flex gap-1">
                                        {activePromos.map((_, i) => (
                                            <IconCircleFilled key={i} size={6} className={currentIndex === i ? "text-white" : "text-white/30"} />
                                        ))}
                                    </div>
                                )}
                            </div>
                            <div key={currentIndex} className="animate-in fade-in slide-in-from-right-4 duration-500">
                                <p className="text-sm font-black text-white leading-tight uppercase italic tracking-tight">{getPromoMessage(currentPromo)}</p>
                            </div>
                        </div>
                        <IconChevronRight size={20} className="text-white/40 shrink-0" />
                    </div>
                </div>
            </div>
        </div>
    );
};

export default function CustomerMenu({ products, table, categories, activePromos = [] }) {
    const [cart, setCart] = useState([]);
    const [name, setName] = useState("");
    const [orderType, setOrderType] = useState(table ? "table" : "takeaway");
    const [search, setSearch] = useState("");
    const [activeCategory, setActiveCategory] = useState("all");
    const [showReview, setShowReview] = useState(false);

    const formatPrice = (value) =>
        new Intl.NumberFormat("id-ID", { style: "currency", currency: "IDR", minimumFractionDigits: 0 }).format(value || 0);

    const triggerConfetti = () => {
        const end = Date.now() + 2 * 1000;
        const colors = ['#4f46e5', '#a855f7', '#10b981'];
        (function frame() {
            confetti({ particleCount: 3, angle: 60, spread: 55, origin: { x: 0 }, colors: colors });
            confetti({ particleCount: 3, angle: 120, spread: 55, origin: { x: 1 }, colors: colors });
            if (Date.now() < end) requestAnimationFrame(frame);
        }());
    };

    // --- LOGIKA HARGA BERDASARKAN QTY (SINKRON DENGAN DATABASE) ---
    const getDiscountedPrice = useCallback((productId, basePrice, qty) => {
        const promo = activePromos.find(p => 
            Number(p.product_id) === Number(productId) && 
            !p.bonus_product_id && 
            qty >= parseInt(p.minimum_item)
        );

        if (promo) {
            if (promo.type === 'percentage') {
                return basePrice - (basePrice * (parseFloat(promo.value) / 100));
            }
            return basePrice - parseFloat(promo.value);
        }
        return basePrice;
    }, [activePromos]);

    const addToCart = (p) => {
        if (!p.is_available) return;

        const existingItem = cart.find(item => item.product_id === p.id);
        const newQty = (existingItem?.qty || 0) + 1;
        
        // Cek Pemicu Selebrasi (Hanya saat tepat mencapai angka promo)
        const promoGrosir = activePromos.find(pr => pr.product_id === p.id && !pr.bonus_product_id);
        if (promoGrosir && newQty === parseInt(promoGrosir.minimum_item)) {
            triggerConfetti();
            toast.success(`HURRAY! DAPAT HARGA PROMO!`, { 
                icon: '🎉',
                position: 'top-center', 
                style: { borderRadius: '20px', background: '#1e293b', color: '#fff', fontWeight: '900' } 
            });
        }

        if (existingItem) {
            setCart(cart.map(item => {
                if (item.product_id === p.id) {
                    const updatedQty = item.qty + 1;
                    const unitPrice = getDiscountedPrice(p.id, p.sell_price, updatedQty);
                    return { ...item, qty: updatedQty, price: updatedQty * unitPrice, current_unit_price: unitPrice };
                }
                return item;
            }));
        } else {
            const unitPrice = getDiscountedPrice(p.id, p.sell_price, 1);
            setCart([...cart, { 
                product_id: p.id, product_title: p.title, 
                qty: 1, price: unitPrice, sell_price: p.sell_price, 
                current_unit_price: unitPrice 
            }]);
        }
    };

    const updateQty = (id, delta) => {
        setCart(cart.map(item => {
            if (item.product_id === id) {
                const newQty = Math.max(0, item.qty + delta);
                const unitPrice = getDiscountedPrice(id, item.sell_price, newQty);
                return { ...item, qty: newQty, price: newQty * unitPrice, current_unit_price: unitPrice };
            }
            return item;
        }).filter(i => i.qty > 0));
    };

    // --- KALKULASI TOTAL AKHIR ---
    const subtotalNormal = useMemo(() => cart.reduce((acc, item) => acc + (item.sell_price * item.qty), 0), [cart]);
    const totalDeal = useMemo(() => cart.reduce((acc, item) => acc + item.price, 0), [cart]);

    const globalPromo = useMemo(() => {
        return activePromos
            .filter(d => !d.product_id && totalDeal >= parseFloat(d.min_transaction || 0))
            .sort((a, b) => parseFloat(b.min_transaction) - parseFloat(a.min_transaction))[0];
    }, [totalDeal, activePromos]);

    const globalDiscountAmount = globalPromo ? (globalPromo.type === 'percentage' ? totalDeal * (parseFloat(globalPromo.value) / 100) : parseFloat(globalPromo.value)) : 0;
    const finalTotal = totalDeal - globalDiscountAmount;
    const totalSaved = subtotalNormal - finalTotal;

    const filteredProducts = useMemo(() => {
        return products.filter(p => {
            const matchSearch = p.title.toLowerCase().includes(search.toLowerCase());
            const matchCategory = activeCategory === "all" || p.category_id == activeCategory;
            return matchSearch && matchCategory;
        });
    }, [search, activeCategory, products]);

    const submitOrder = async () => {
        if (!name || name.trim() === "") {
            return Swal.fire({ icon: "warning", title: "Nama Kosong", text: "Silakan isi nama Anda.", confirmButtonColor: "#4f46e5" });
        }
        if (cart.length === 0) return;

        try {
            Swal.fire({ title: 'Mengirim Pesanan...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
            const response = await axios.post(route('public.menu.order'), {
                customer_name: name,
                table_id: orderType === 'table' ? table?.id : null,
                order_type: orderType,
                cart_items: cart,
                total: finalTotal,
                payment_method: 'cash'
            });
            finishOrder(response.data);
        } catch (e) {
            Swal.close();
            Swal.fire("Gagal", "Terjadi kesalahan sistem", "error");
        }
    };

    const finishOrder = (data) => {
        const { queue_number, message, unique_code } = data;
        const displayCode = unique_code ? unique_code.toString().slice(-4) : "0000";
        Swal.fire({
            title: `<span style="font-family: 'Inter', sans-serif; font-weight: 900; font-style: italic;">PESANAN TERKIRIM!</span>`,
            html: `
                <div style="background: white; border: 1px solid #e2e8f0; border-radius: 24px; padding: 25px; margin-top: 15px;">
                    <div style="border-bottom: 2px dashed #cbd5e1; padding-bottom: 15px; margin-bottom: 15px;">
                        <p style="font-size: 10px; font-weight: 800; color: #64748b; text-transform: uppercase; margin: 0;">Kode Pesanan</p>
                        <h2 style="font-size: 42px; font-weight: 900; color: #1e293b; margin: 5px 0 0 0;">#${displayCode}</h2>
                    </div>
                    <div>
                        <p style="font-size: 10px; font-weight: 800; color: #64748b; text-transform: uppercase; margin: 0;">Nomor Antrean</p>
                        <p style="font-size: 26px; font-weight: 900; color: #4f46e5; margin: 5px 0 0 0;">${queue_number}</p>
                    </div>
                    <div style="margin-top: 20px; padding: 12px; background: #f0f9ff; border-radius: 12px; border: 1px solid #bae6fd;">
                        <p style="font-size: 12px; font-weight: 700; color: #0369a1; margin: 0;">${message}</p>
                    </div>
                </div>
            `,
            confirmButtonText: "OKE, SAYA MENGERTI",
            confirmButtonColor: "#4f46e5",
            allowOutsideClick: false,
        }).then(() => {
            setCart([]);
            window.location.reload();
        });
    };

    return (
        <div className="bg-slate-50 min-h-screen pb-48 font-sans text-slate-900">
            <Head title="E-Menu Digital" />
            <Toaster />
            
            {!showReview ? (
                <div className="animate-in fade-in duration-500">
                    {/* Header Utama */}
                    <div className="p-6 text-white rounded-b-[2.5rem] shadow-lg sticky top-0 z-30" style={{ background: 'linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%)' }}>
                        <div className="flex justify-between items-center mb-4">
                            <div>
                                <h1 className="text-xl font-black uppercase italic tracking-tighter flex items-center gap-2">
                                    <IconToolsKitchen2 size={24} /> MANGKU JAGAD
                                </h1>
                                <p className="text-[10px] font-bold uppercase opacity-90">{orderType === 'table' ? `Meja: ${table?.name}` : 'Take Away'}</p>
                            </div>
                            <div className="bg-white/20 p-2 rounded-xl backdrop-blur-md relative">
                                <IconShoppingCart size={20} />
                                {cart.length > 0 && <span className="absolute -top-1 -right-1 bg-red-500 text-white text-[8px] font-bold px-1.5 py-0.5 rounded-full">{cart.length}</span>}
                            </div>
                        </div>
                        <div className="relative">
                            <IconSearch className="absolute left-4 top-1/2 -translate-y-1/2 text-indigo-200" size={18} />
                            <input type="text" placeholder="Cari menu favorit..." className="w-full pl-11 pr-4 py-3 rounded-2xl border-none bg-white/20 placeholder:text-indigo-100 text-sm text-white font-bold focus:ring-2 focus:ring-white transition-all" value={search} onChange={(e) => setSearch(e.target.value)} />
                        </div>
                    </div>

                    <PromoBanner activePromos={activePromos} formatPrice={formatPrice} />

                    {/* Filter Kategori */}
                    <div className="flex gap-2 overflow-x-auto p-4 sticky top-[145px] bg-slate-50/90 backdrop-blur-sm z-20 scrollbar-hide">
                        <button onClick={() => setActiveCategory("all")} className={`px-6 py-2.5 rounded-full text-[10px] font-black uppercase border transition-all ${activeCategory === "all" ? 'bg-slate-900 text-white shadow-lg' : 'bg-white text-slate-500'}`}>Semua</button>
                        {categories?.map(cat => (
                            <button key={cat.id} onClick={() => setActiveCategory(cat.id)} className={`px-6 py-2.5 rounded-full text-[10px] font-black uppercase border transition-all ${activeCategory == cat.id ? 'bg-slate-900 text-white shadow-lg' : 'bg-white text-slate-500'}`}>{cat.name}</button>
                        ))}
                    </div>

                    {/* Daftar Produk */}
                    <div className="px-4 space-y-4 mt-2">
                        {filteredProducts.map(p => {
                            const promoProd = activePromos.find(pr => pr.product_id === p.id && !pr.bonus_product_id);
                            return (
                                <div key={p.id} className={`bg-white p-3 rounded-[1.5rem] flex gap-4 items-center shadow-sm border border-slate-50 relative ${!p.is_available ? 'opacity-70' : ''}`}>
                                    <div className="relative shrink-0">
                                        <img src={p.image ? (p.image.startsWith('http') ? p.image : `/storage/products/${p.image}`) : `https://ui-avatars.com/api/?name=${p.title}&background=random`} className={`w-24 h-24 rounded-2xl object-cover shadow-inner ${!p.is_available ? 'grayscale' : ''}`} alt={p.title} />
                                        {p.type === 'bundle' && <div className="absolute -top-2 -left-2 bg-purple-500 text-white p-1 rounded-lg shadow-lg"><IconBoxSeam size={14} /></div>}
                                        {promoProd && p.is_available && <div className="absolute -top-2 -right-2 bg-emerald-500 text-white p-1.5 rounded-full shadow-lg animate-pulse"><IconRosetteDiscount size={14} /></div>}
                                    </div>
                                    <div className="flex-1 min-w-0">
                                        <h3 className="font-black uppercase text-[11px] text-slate-800 leading-tight mb-0.5 truncate">{p.title}</h3>
                                        <p className="text-indigo-600 font-black text-sm mb-1">{formatPrice(p.sell_price)}</p>
                                        <div className="flex items-center gap-2">
                                            <span className={`text-[8px] px-2 py-0.5 rounded-md font-bold uppercase ${!p.is_available ? 'bg-slate-100 text-slate-400' : 'bg-indigo-50 text-indigo-600'}`}>
                                                {!p.is_available ? 'Habis' : 'Tersedia'}
                                            </span>
                                            {promoProd && p.is_available && (
                                                <span className="text-[7px] font-black text-emerald-600 uppercase italic leading-none">Min. {promoProd.minimum_item} Pcs Disc!</span>
                                            )}
                                        </div>
                                    </div>
                                    <button onClick={() => p.is_available && addToCart(p)} disabled={!p.is_available} className={`w-12 h-12 rounded-2xl flex items-center justify-center ${p.is_available ? 'bg-indigo-100 text-indigo-600 active:scale-90 shadow-sm' : 'bg-slate-100 text-slate-300'}`}>
                                        <IconPlus size={24} />
                                    </button>
                                </div>
                            );
                        })}
                    </div>

                    {/* Bar Total Bawah */}
                    {cart.length > 0 && (
                        <div className="fixed bottom-0 inset-x-0 p-6 bg-white/95 backdrop-blur-xl border-t border-slate-100 rounded-t-[3rem] shadow-2xl z-50 animate-in slide-in-from-bottom-5">
                            <div className="max-w-md mx-auto flex justify-between items-center">
                                <div>
                                    <span className="text-[10px] font-black text-slate-400 uppercase block mb-1">Estimasi Total</span>
                                    <span className="text-2xl font-black text-indigo-600 italic tracking-tighter leading-none">{formatPrice(finalTotal)}</span>
                                </div>
                                <button onClick={() => setShowReview(true)} className="px-8 py-4 bg-slate-900 text-white rounded-2xl font-black uppercase text-[12px] flex items-center gap-3 shadow-xl active:scale-95 transition-all">
                                     Review Order <IconCheck size={20} />
                                </button>
                            </div>
                        </div>
                    )}
                </div>
            ) : (
                /* --- MODE REVIEW PESANAN --- */
                <div className="p-6 animate-in slide-in-from-bottom-10 duration-500">
                    <button onClick={() => setShowReview(false)} className="mb-6 flex items-center gap-2 text-slate-400 font-black uppercase text-[10px] tracking-widest"><IconChevronLeft size={16} /> Kembali</button>
                    <h2 className="text-3xl font-black italic uppercase tracking-tighter mb-6 text-slate-800">Cek Pesanan</h2>
                    
                    <div className="bg-white p-5 rounded-3xl shadow-sm border border-slate-100 mb-6">
                        <p className="text-[10px] font-black text-slate-400 uppercase mb-3 tracking-widest flex items-center gap-1"><IconUser size={14} /> Nama Pemesan</p>
                        <input type="text" placeholder="Masukkan nama Anda..." className="w-full p-4 rounded-2xl bg-slate-50 border-none text-sm font-black focus:ring-2 focus:ring-indigo-500 uppercase" value={name} onChange={e => setName(e.target.value)} />
                    </div>

                    <div className="space-y-4 mb-8">
                        {cart.map((item) => {
                             const isDisc = item.current_unit_price < item.sell_price;
                             return (
                                <div key={item.product_id} className="bg-white p-4 rounded-3xl border border-slate-100 shadow-sm relative overflow-hidden">
                                    {isDisc && <div className="absolute top-0 right-0 bg-emerald-500 text-white text-[7px] font-black px-3 py-1 rounded-bl-xl uppercase tracking-tighter">Harga Promo</div>}
                                    <div className="flex justify-between items-center">
                                        <div className="min-w-0 flex-1">
                                            <h4 className="font-black text-[11px] uppercase text-slate-800 truncate">{item.product_title}</h4>
                                            <div className="flex items-center gap-2">
                                                {isDisc && <span className="text-[9px] text-slate-300 line-through font-bold">{formatPrice(item.sell_price)}</span>}
                                                <p className="text-indigo-600 font-black text-sm">{formatPrice(item.current_unit_price)}</p>
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-3 bg-slate-50 p-1.5 rounded-2xl ml-4">
                                            <button onClick={() => updateQty(item.product_id, -1)} className="w-8 h-8 flex items-center justify-center bg-white rounded-xl text-red-500 shadow-sm"><IconMinus size={16}/></button>
                                            <span className="font-black text-sm w-4 text-center">{item.qty}</span>
                                            <button onClick={() => updateQty(item.product_id, 1)} className="w-8 h-8 flex items-center justify-center bg-white rounded-xl text-indigo-500 shadow-sm"><IconPlus size={16}/></button>
                                        </div>
                                    </div>
                                </div>
                             );
                        })}
                    </div>

                    <div className="bg-white p-6 rounded-[2.5rem] mb-10 shadow-xl border-t-4 border-indigo-500">
                        <div className="space-y-2 mb-4 border-b border-slate-50 pb-4">
                            <div className="flex justify-between text-xs font-bold text-slate-400 uppercase tracking-tighter"><span>Subtotal Produk</span><span>{formatPrice(subtotalNormal)}</span></div>
                            {totalSaved > 0 && (
                                <div className="flex justify-between text-xs font-black text-emerald-600 uppercase tracking-tighter animate-pulse"><span>Anda Hemat!</span><span>-{formatPrice(totalSaved)}</span></div>
                            )}
                        </div>
                        <p className="text-[10px] font-black text-slate-400 uppercase tracking-widest text-center mb-1">Total yang dibayar di kasir</p>
                        <h3 className="text-4xl font-black text-indigo-600 italic tracking-tighter text-center leading-none">{formatPrice(finalTotal)}</h3>
                    </div>

                    <button onClick={submitOrder} className="w-full bg-slate-900 p-6 rounded-[2rem] flex items-center justify-center gap-4 active:scale-[0.98] transition-all shadow-2xl text-white group">
                        <IconCash size={28} className="text-indigo-400" />
                        <span className="font-black uppercase tracking-widest leading-none">Kirim Pesanan Sekarang</span>
                    </button>
                </div>
            )}
            <style dangerouslySetInnerHTML={{ __html: `.scrollbar-hide::-webkit-scrollbar { display: none; }` }} />
        </div>
    );
}