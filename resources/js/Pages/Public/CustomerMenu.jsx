import React, { useState, useMemo, useEffect, useCallback, useRef } from "react";
import { Head, router } from "@inertiajs/react"; // Menambahkan router
import { 
    IconShoppingCart, IconPlus, IconMinus, IconToolsKitchen2, 
    IconSearch, IconBoxSeam, IconUser, IconChevronLeft,
    IconCash, IconSpeakerphone, IconRosetteDiscount, IconGift,
    IconCircleFilled, IconChevronRight, IconCheck, IconPackage
} from "@tabler/icons-react";
import axios from "axios";
import Swal from "sweetalert2";
import confetti from 'canvas-confetti';
import toast, { Toaster } from 'react-hot-toast';
import { QRCodeSVG } from "qrcode.react";

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
                    </div>
                </div>
            </div>
        </div>
    );
};

export default function CustomerMenu({ products, table, categories, activePromos = [], isTakeAway, taxSetting = { enabled: false, rate: 0 }, qris = { enabled: false, image_url: null }, paymentGateways = [] }) {
    const [cart, setCart] = useState([]);
    const [name, setName] = useState("");
    // Inisialisasi orderType berdasarkan prop isTakeAway dari Controller
    const [orderType, setOrderType] = useState(isTakeAway ? "takeaway" : "table");
    const [search, setSearch] = useState("");
    const [activeCategory, setActiveCategory] = useState("all");
    const [showReview, setShowReview] = useState(false);
    // Minat bayar pelanggan. QRIS hanya ditawarkan kalau merchant sudah
    // mengunggah kode QRIS-nya, supaya tidak ada tombol yang tidak bisa dipakai.
    const [paymentMethod, setPaymentMethod] = useState("cash");

    // Pembayaran mandiri lewat gateway (Midtrans / Xendit).
    // Backend hanya mengirim gateway yang SUDAH siap dipakai, jadi tidak
    // perlu disaring lagi di sini. Dulu kode ini memfilter `g.enabled`,
    // padahal field itu tidak pernah dikirim, sehingga tombolnya selalu
    // hilang padahal Midtrans sebenarnya aktif.
    const gatewayOptions = paymentGateways || [];
    const canSelfPay = gatewayOptions.length > 0;
    const [selfPay, setSelfPay] = useState(null); // { provider, qr_string, payment_url, ref_number, status, amount }
    const [selfPayError, setSelfPayError] = useState("");
    const pollRef = useRef(null);
    // Struk digital disimpan di ref, bukan state, supaya callback polling bisa
    // membacanya tanpa membuat interval berhenti lalu mulai ulang.
    const receiptLinkRef = useRef(null);

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
        if (p.available_quantity !== undefined && newQty > Number(p.available_quantity)) {
            toast.error(`Stok ${p.title} hanya tersedia ${p.available_quantity} unit.`);
            return;
        }
        
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
                current_unit_price: unitPrice,
                notes: ''
            }]);
        }
    };

    const updateQty = (id, delta) => {
        const product = products.find((item) => Number(item.id) === Number(id));
        setCart(cart.map(item => {
            if (item.product_id === id) {
                const newQty = Math.max(0, item.qty + delta);
                if (product?.available_quantity !== undefined && newQty > Number(product.available_quantity)) {
                    toast.error(`Stok ${product.title} hanya tersedia ${product.available_quantity} unit.`);
                    return item;
                }
                const unitPrice = getDiscountedPrice(id, item.sell_price, newQty);
                return { ...item, qty: newQty, price: newQty * unitPrice, current_unit_price: unitPrice };
            }
            return item;
        }).filter(i => i.qty > 0));
    };

    const updateNote = (id, notes) => {
        setCart(current => current.map(item => item.product_id === id ? { ...item, notes } : item));
    };

    // --- KALKULASI TOTAL AKHIR ---
    const subtotalNormal = useMemo(() => cart.reduce((acc, item) => acc + (item.sell_price * item.qty), 0), [cart]);
    const totalDeal = useMemo(() => cart.reduce((acc, item) => acc + item.price, 0), [cart]);

    const globalPromo = useMemo(() => {
        return activePromos
            .filter(d => !d.product_id && totalDeal >= parseFloat(d.min_transaction || 0))
            .sort((a, b) => parseFloat(b.min_transaction) - parseFloat(a.min_transaction))[0];
    }, [totalDeal, activePromos]);

    const globalDiscountAmount = globalPromo
        ? Math.round(Math.min(totalDeal, Math.max(0, globalPromo.type === 'percentage'
            ? totalDeal * (parseFloat(globalPromo.value) / 100)
            : parseFloat(globalPromo.value))))
        : 0;
    const taxableTotal = Math.max(totalDeal - globalDiscountAmount, 0);
    const taxRate = parseFloat(taxSetting?.rate || 0);
    const taxAmount = taxSetting?.enabled ? Math.round(taxableTotal * taxRate / 100) : 0;
    const finalTotal = taxableTotal + taxAmount;
    const totalSaved = subtotalNormal - taxableTotal;

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
                order_type: orderType, // Mengirim 'takeaway' atau 'table' (dine_in)
                cart_items: cart,
                total: finalTotal,
                // Minat bayar, bukan pembayaran. Untuk gateway, kunci ke
                // provider yang dipilih supaya pesanan tidak dianggap tunai.
                requested_payment_method: paymentMethod,
            });
            receiptLinkRef.current = response.data.receipt_url || null;
            Swal.close();
            finishOrder(response.data);
        } catch (e) {
            Swal.close();
            Swal.fire("Gagal", e.response?.data?.message || "Terjadi kesalahan sistem", "error");
        }
    };

    // --- PEMBAYARAN MANDIRI (Midtrans / Xendit) ---
    const startSelfPay = async (provider) => {
        if (!name || name.trim() === "") {
            return Swal.fire({ icon: "warning", title: "Nama Kosong", text: "Isi nama Anda dulu sebelum membayar.", confirmButtonColor: "#4f46e5" });
        }
        if (cart.length === 0) return;

        setSelfPayError("");
        let order;

        try {
            Swal.fire({ title: 'Menyiapkan pembayaran...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
            const response = await axios.post(route('public.menu.order'), {
                customer_name: name,
                table_id: orderType === 'table' ? table?.id : null,
                order_type: orderType,
                cart_items: cart,
                total: finalTotal,
                requested_payment_method: provider,
            });
            order = response.data;
            // Disimpan sekarang supaya kalau QR gagal dibuat, pelanggan tetap
            // punya struk untuk pesanan yang sudah tercatat.
            receiptLinkRef.current = response.data.receipt_url || null;
            Swal.close();
        } catch (e) {
            Swal.close();
            return Swal.fire("Gagal", e.response?.data?.message || "Pesanan tidak bisa dibuat", "error");
        }

        try {
            const pay = await axios.post(route('payment.menu.start', order.hold_id), {
                provider,
            });
            setSelfPay({
                provider,
                qr_string: pay.data.qr_string,
                payment_url: pay.data.payment_url,
                ref_number: pay.data.ref_number,
                status: pay.data.status,
                amount: pay.data.amount,
            });
            startPolling(order.hold_id);
        } catch (e) {
            Swal.close();
            setSelfPayError(e.response?.data?.message || "Gagal membuat kode QRIS. Silakan bayar di kasir.");
            toast.error("Gagal membuat QRIS");
        }
    };

    const startPolling = (holdRef) => {
        stopPolling();
        pollRef.current = setInterval(async () => {
            try {
                const res = await axios.get(route('payment.menu.status', holdRef));
                const status = res.data.status;

                if (status === "paid") {
                    stopPolling();
                    setSelfPay((s) => (s ? { ...s, status } : s));
                    toast.success("Pembayaran diterima!");
                    // Struk digital adalah tujuan akhir alur ini: pelanggan
                    // menunjukkan layar itu ke kasir, dan struknya bisa
                    // disimpan di HP sebagai bukti.
                    const receiptUrl = receiptLinkRef.current;
                    Swal.fire({
                        title: "PEMBAYARAN SUKSES",
                        html: `<p style="font-weight:700;color:#065f46;margin:0;">Tunjukkan layar ini ke kasir. Kode pesanan <b>#${String(res.data.ref_number || "").slice(-4)}</b></p>`,
                        icon: "success",
                        showCancelButton: Boolean(receiptUrl),
                        confirmButtonText: receiptUrl ? "Lihat Struk Digital" : "OKE",
                        cancelButtonText: "Tutup",
                        confirmButtonColor: "#059669",
                    }).then((result) => {
                        if (result.isConfirmed && receiptUrl) {
                            window.location.href = receiptUrl;
                            return;
                        }
                        window.location.reload();
                    });
                } else if (status === "failed" || status === "expired") {
                    stopPolling();
                    setSelfPay((s) => (s ? { ...s, status } : s));
                    toast.error("Pembayaran gagal atau kedaluwarsa");
                }
            } catch {
                // Jaringan putus sesaat tidak boleh menghentikan polling;
                // pembayaran mungkin tetap masuk di gateway.
            }
        }, 3000);
    };

    const stopPolling = () => {
        if (pollRef.current) {
            clearInterval(pollRef.current);
            pollRef.current = null;
        }
    };

    useEffect(() => () => stopPolling(), []);

    const finishOrder = (data) => {
        const { queue_number, message, unique_code, requested_payment_method, receipt_url } = data;
        const displayCode = unique_code ? unique_code.toString().slice(-4) : "0000";
        const paidByQris = requested_payment_method === "qris_manual";
        const selfPaid = requested_payment_method === "midtrans" || requested_payment_method === "xendit";

        const guidance = selfPaid
            ? `<div style="margin-top: 18px; padding: 14px; background: #ecfdf5; border-radius: 14px; border: 2px solid #a7f3d0;">
                <p style="font-size: 10px; font-weight: 800; color: #047857; text-transform: uppercase; margin: 0 0 4px 0;">Sudah Lunas</p>
                <p style="font-size: 12px; font-weight: 700; color: #065f46; margin: 0; line-height: 1.5;">
                    Pembayaran QRIS Anda sudah diterima. Tunjukkan layar ini ke
                    kasir, tidak perlu membayar lagi.
                </p>
            </div>`
            : paidByQris
                ? `<div style="margin-top: 18px; padding: 14px; background: #eff6ff; border-radius: 14px; border: 2px solid #bfdbfe;">
                    <p style="font-size: 10px; font-weight: 800; color: #1d4ed8; text-transform: uppercase; margin: 0 0 4px 0;">Tanggung Jawab Anda</p>
                    <p style="font-size: 12px; font-weight: 700; color: #1e3a8a; margin: 0; line-height: 1.5;">
                        Tunjukkan layar ini ke kasir. Kasir akan mengecek
                        pembayaran QRIS Anda sebelum pesanan diserahkan.
                    </p>
                </div>`
                : `<div style="margin-top: 18px; padding: 14px; background: #f8fafc; border-radius: 14px; border: 2px solid #e2e8f0;">
                    <p style="font-size: 10px; font-weight: 800; color: #64748b; text-transform: uppercase; margin: 0 0 4px 0;">Bayar di Kasir</p>
                    <p style="font-size: 12px; font-weight: 700; color: #334155; margin: 0; line-height: 1.5;">
                        Tunjukkan layar ini ke kasir untuk pembayaran tunai.
                    </p>
                </div>`;

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
                    ${guidance}
                    <div style="margin-top: 20px; padding: 12px; background: #f0f9ff; border-radius: 12px; border: 1px solid #bae6fd;">
                        <p style="font-size: 12px; font-weight: 700; color: #0369a1; margin: 0;">${message}</p>
                    </div>
                </div>
            `,
            showCancelButton: Boolean(receipt_url),
            confirmButtonText: receipt_url ? "Lihat Struk Digital" : "OKE, SAYA MENGERTI",
            cancelButtonText: "Tutup",
            confirmButtonColor: paidByQris ? "#2563eb" : "#4f46e5",
            allowOutsideClick: false,
        }).then((result) => {
            // Struk digital dibuka di tab yang sama supaya tombol "kembali ke
            // menu" di struk itu bekerja dan pelanggan tidak tersesat ke
            // riwayat tab yang tidak ada isinya.
            if (result.isConfirmed && receipt_url) {
                window.location.href = receipt_url;
                return;
            }

            setCart([]);
            window.location.reload();
        });
    };

    return (
        <div className="bg-slate-50 min-h-screen pb-48 font-sans text-slate-900">
            <Head title={isTakeAway ? "Take Away Menu" : "E-Menu Digital"} />
            <Toaster />
            
            {!showReview ? (
                <div className="animate-in fade-in duration-500">
                    {/* Header Utama - Berubah warna jika Take Away */}
                    <div 
                        className="p-6 text-white rounded-b-[2.5rem] shadow-lg sticky top-0 z-30 transition-colors duration-500" 
                        style={{ background: isTakeAway ? 'linear-gradient(135deg, #059669 0%, #10b981 100%)' : 'linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%)' }}
                    >
                        <div className="flex justify-between items-center mb-4">
                            <div>
                                <h1 className="text-xl font-black uppercase italic tracking-tighter flex items-center gap-2">
                                    <IconToolsKitchen2 size={24} /> LAKUPOS
                                </h1>
                                <div className="mt-1 flex items-center gap-2">
                                    {isTakeAway ? (
                                        <div className="bg-white/20 px-3 py-1 rounded-full backdrop-blur-md flex items-center gap-1.5 border border-white/20">
                                            <IconPackage size={14} className="text-emerald-100" />
                                            <span className="text-[10px] font-black uppercase tracking-widest text-white">Mode Take Away</span>
                                        </div>
                                    ) : (
                                        <div className="bg-white/20 px-3 py-1 rounded-full backdrop-blur-md flex items-center gap-1.5 border border-white/20">
                                            <IconUser size={14} className="text-indigo-100" />
                                            <span className="text-[10px] font-black uppercase tracking-widest text-white">Meja: {table?.name || '-'}</span>
                                        </div>
                                    )}
                                </div>
                            </div>
                            <div className="bg-white/20 p-2 rounded-xl backdrop-blur-md relative border border-white/10 shadow-sm">
                                <IconShoppingCart size={20} />
                                {cart.length > 0 && (
                                    <span className="absolute -top-2 -right-2 bg-rose-500 text-white text-[10px] font-black w-5 h-5 flex items-center justify-center rounded-full border-2 border-emerald-500 shadow-md">
                                        {cart.length}
                                    </span>
                                )}
                            </div>
                        </div>
                        <div className="relative">
                            <IconSearch className="absolute left-4 top-1/2 -translate-y-1/2 text-white/50" size={18} />
                            <input 
                                type="text" 
                                placeholder="Cari menu favorit..." 
                                className="w-full pl-11 pr-4 py-3 rounded-2xl border-none bg-white/20 placeholder:text-white/40 text-sm text-white font-bold focus:ring-2 focus:ring-white/50 transition-all" 
                                value={search} 
                                onChange={(e) => setSearch(e.target.value)} 
                            />
                        </div>
                    </div>

                    <PromoBanner activePromos={activePromos} formatPrice={formatPrice} />

                    {/* Filter Kategori */}
                    <div className="flex gap-2 overflow-x-auto p-4 sticky top-[124px] sm:top-[145px] bg-slate-50/90 backdrop-blur-sm z-20 scrollbar-hide">
                        <button onClick={() => setActiveCategory("all")} className={`px-6 py-2.5 rounded-full text-[10px] font-black uppercase border transition-all ${activeCategory === "all" ? (isTakeAway ? 'bg-emerald-600 text-white shadow-lg border-emerald-600' : 'bg-slate-900 text-white shadow-lg border-slate-900') : 'bg-white text-slate-500 border-slate-200'}`}>Semua</button>
                        {categories?.map(cat => (
                            <button key={cat.id} onClick={() => setActiveCategory(cat.id)} className={`px-6 py-2.5 rounded-full text-[10px] font-black uppercase border transition-all whitespace-nowrap ${activeCategory == cat.id ? (isTakeAway ? 'bg-emerald-600 text-white shadow-lg border-emerald-600' : 'bg-slate-900 text-white shadow-lg border-slate-900') : 'bg-white text-slate-500 border-slate-200'}`}>{cat.name}</button>
                        ))}
                    </div>

                    {/* Daftar Produk */}
                    <div className="px-4 space-y-4 mt-2">
                        {filteredProducts.map(p => {
                            const promoProd = activePromos.find(pr => pr.product_id === p.id && !pr.bonus_product_id);
                            return (
                                <div
                                    key={p.id}
                                    title={!p.is_available && p.availability_shortages?.length
                                        ? `Kurang: ${p.availability_shortages.map((item) => item.name).join(', ')}`
                                        : undefined}
                                    className={`bg-white p-3 rounded-[1.5rem] flex gap-4 items-center shadow-sm border border-slate-50 relative ${!p.is_available ? 'opacity-70' : ''}`}
                                >
                                    <div className="relative shrink-0">
                                        <img src={p.image ? (p.image.startsWith('http') ? p.image : `/storage/products/${p.image}`) : `https://ui-avatars.com/api/?name=${p.title}&background=random`} className={`w-24 h-24 rounded-2xl object-cover shadow-inner ${!p.is_available ? 'grayscale' : ''}`} alt={p.title} />
                                        {p.type === 'bundle' && <div className="absolute -top-2 -left-2 bg-purple-500 text-white p-1 rounded-lg shadow-lg"><IconBoxSeam size={14} /></div>}
                                        {promoProd && p.is_available && <div className="absolute -top-2 -right-2 bg-emerald-500 text-white p-1.5 rounded-full shadow-lg animate-pulse"><IconRosetteDiscount size={14} /></div>}
                                    </div>
                                    <div className="flex-1 min-w-0">
                                        <h3 className="font-black uppercase text-[11px] text-slate-800 leading-tight mb-0.5 truncate">{p.title}</h3>
                                        <p className={`font-black text-sm mb-1 ${isTakeAway ? 'text-emerald-600' : 'text-indigo-600'}`}>{formatPrice(p.sell_price)}</p>
                                        <div className="flex items-center gap-2">
                                            <span className={`text-[8px] px-2 py-0.5 rounded-md font-bold uppercase ${!p.is_available ? 'bg-slate-100 text-slate-400' : (isTakeAway ? 'bg-emerald-50 text-emerald-600' : 'bg-indigo-50 text-indigo-600')}`}>
                                                {!p.is_available ? 'Habis' : (p.availability_status === 'low' ? `Sisa ${Number(p.available_quantity).toLocaleString('id-ID')}` : 'Tersedia')}
                                            </span>
                                            {promoProd && p.is_available && (
                                                <span className="text-[7px] font-black text-emerald-600 uppercase italic leading-none">Min. {promoProd.minimum_item} Pcs Disc!</span>
                                            )}
                                        </div>
                                    </div>
                                    <button onClick={() => p.is_available && addToCart(p)} disabled={!p.is_available} className={`w-12 h-12 rounded-2xl flex items-center justify-center transition-all ${p.is_available ? (isTakeAway ? 'bg-emerald-100 text-emerald-600 active:scale-90' : 'bg-indigo-100 text-indigo-600 active:scale-90 shadow-sm') : 'bg-slate-100 text-slate-300'}`}>
                                        <IconPlus size={24} />
                                    </button>
                                </div>
                            );
                        })}
                    </div>

                    {/* Bar Total Bawah.

                        Baris ini meluber di HP kecil. Di lebar 375px tersedia
                        327px setelah `p-6` di kiri dan kanan, sementara
                        tombol "Review Order" sendirian sudah memakan sekitar
                        206px (px-8 dua kali = 64px, teks ~110px, ikon 20px,
                        gap 12px) dan blok total butuh ~130px. Dengan
                        `justify-between` tanpa wrap, salah satunya terpotong
                        atau tombol terdorong keluar layar — di halaman yang
                        dipakai pelanggan, bukan kasir.

                        Karena itu: `flex-col` di layar sempit, `min-w-0` +
                        `truncate` supaya nominal panjang tidak mendorong
                        tombol, dan tombol selebar penuh saat bertumpuk. */}
                    {cart.length > 0 && (
                        <div className="fixed bottom-0 inset-x-0 p-4 sm:p-6 bg-white/95 backdrop-blur-xl border-t border-slate-100 rounded-t-[3rem] shadow-2xl z-50 animate-in slide-in-from-bottom-5">
                            <div className="max-w-md mx-auto flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                                <div className="min-w-0">
                                    <span className="text-[10px] font-black text-slate-400 uppercase block mb-1">Estimasi Total</span>
                                    <span className={`block text-2xl font-black italic tracking-tighter leading-none truncate ${isTakeAway ? 'text-emerald-600' : 'text-indigo-600'}`}>{formatPrice(finalTotal)}</span>
                                     {taxAmount > 0 && <span className="block text-[9px] font-black text-slate-400 uppercase mt-1">Termasuk pajak {taxRate}%</span>}
                                </div>
                                <button onClick={() => setShowReview(true)} className={`w-full sm:w-auto shrink-0 px-6 sm:px-8 py-4 min-h-touch text-white rounded-2xl font-black uppercase text-[12px] flex items-center justify-center gap-3 shadow-xl active:scale-95 transition-all ${isTakeAway ? 'bg-emerald-600' : 'bg-slate-900'}`}>
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
                        <input type="text" placeholder="Masukkan nama Anda..." className={`w-full p-4 rounded-2xl bg-slate-50 border-none text-sm font-black focus:ring-2 uppercase ${isTakeAway ? 'focus:ring-emerald-500' : 'focus:ring-indigo-500'}`} value={name} onChange={e => setName(e.target.value)} />
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
                                                <p className={`font-black text-sm ${isTakeAway ? 'text-emerald-600' : 'text-indigo-600'}`}>{formatPrice(item.current_unit_price)}</p>
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-3 bg-slate-50 p-1.5 rounded-2xl ml-4 border border-slate-100">
                                            <button onClick={() => updateQty(item.product_id, -1)} className="w-8 h-8 flex items-center justify-center bg-white rounded-xl text-red-500 shadow-sm"><IconMinus size={16}/></button>
                                            <span className="font-black text-sm w-4 text-center">{item.qty}</span>
                                            <button onClick={() => updateQty(item.product_id, 1)} className={`w-8 h-8 flex items-center justify-center bg-white rounded-xl shadow-sm ${isTakeAway ? 'text-emerald-600' : 'text-indigo-600'}`}><IconPlus size={16}/></button>
                                        </div>
                                    </div>
                                    <div className="mt-3 pt-3 border-t border-slate-100">
                                        <label className="block text-[9px] font-black uppercase tracking-wider text-slate-400 mb-1.5">Catatan tambahan</label>
                                        <input
                                            type="text"
                                            maxLength={200}
                                            value={item.notes || ''}
                                            onChange={(e) => updateNote(item.product_id, e.target.value)}
                                            placeholder="Contoh: tidak pedas, sambal terpisah"
                                            className="w-full px-3 py-2 rounded-xl border border-slate-200 bg-slate-50 text-xs font-bold text-slate-700 focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-400"
                                        />
                                    </div>
                                </div>
                             );
                        })}
                    </div>

                    <div className={`bg-white p-6 rounded-[2.5rem] mb-10 shadow-xl border-t-4 ${isTakeAway ? 'border-emerald-500' : 'border-indigo-500'}`}>
                        <div className="space-y-2 mb-4 border-b border-slate-50 pb-4">
                            <div className="flex justify-between text-xs font-bold text-slate-400 uppercase tracking-tighter"><span>Subtotal Produk</span><span>{formatPrice(subtotalNormal)}</span></div>
                            {totalSaved > 0 && (
                                <div className="flex justify-between text-xs font-black text-emerald-600 uppercase tracking-tighter animate-pulse"><span>Anda Hemat!</span><span>-{formatPrice(totalSaved)}</span></div>
                            )}
                        </div>
                        {taxAmount > 0 && (
                             <div className="flex justify-between text-xs font-black text-indigo-600 uppercase tracking-tighter"><span>Pajak ({taxRate}%)</span><span>+{formatPrice(taxAmount)}</span></div>
                         )}
                         <p className="text-[10px] font-black text-slate-400 uppercase tracking-widest text-center mb-1">
                             {paymentMethod === "gateway" ? "Total yang akan dibayar QRIS" : paymentMethod === "qris_manual" ? "Total yang akan dibayar via QRIS" : "Total yang dibayar di kasir"}
                         </p>
                        <h3 className={`text-4xl font-black italic tracking-tighter text-center leading-none ${isTakeAway ? 'text-emerald-600' : 'text-indigo-600'}`}>{formatPrice(finalTotal)}</h3>
                    </div>

                    {/* PILIHAN CARA BAYAR */}
                    <div className="bg-white p-5 rounded-[2rem] shadow-sm border border-slate-100 mb-6">
                        <p className="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-3 flex items-center gap-1.5">
                            <IconCash size={14} /> Cara Bayar
                        </p>
                        <div className={`grid gap-3 ${canSelfPay ? "grid-cols-1 sm:grid-cols-3" : "grid-cols-1 sm:grid-cols-2"}`}>
                            <button
                                type="button"
                                onClick={() => { stopPolling(); setSelfPay(null); setPaymentMethod("cash"); }}
                                className={`p-4 rounded-2xl border-2 text-left transition-all ${paymentMethod === "cash" ? "border-slate-800 bg-slate-50" : "border-slate-100 bg-white"}`}
                            >
                                <div className="text-2xl mb-1">💵</div>
                                <p className="text-xs font-black uppercase text-slate-800 leading-none">Tunai</p>
                                <p className="text-[9px] text-slate-400 mt-1 leading-tight">Bayar di kasir</p>
                            </button>
                            <button
                                type="button"
                                onClick={() => { stopPolling(); setSelfPay(null); setPaymentMethod("qris_manual"); }}
                                disabled={!qris.enabled}
                                className={`p-4 rounded-2xl border-2 text-left transition-all ${paymentMethod === "qris_manual" ? "border-blue-500 bg-blue-50" : "border-slate-100 bg-white"} ${!qris.enabled ? "opacity-40 cursor-not-allowed" : ""}`}
                            >
                                <div className="text-2xl mb-1">📱</div>
                                <p className="text-xs font-black uppercase text-slate-800 leading-none">QRIS</p>
                                <p className="text-[9px] text-slate-400 mt-1 leading-tight">
                                    {qris.enabled ? "Scan dari HP Anda" : "Belum tersedia"}
                                </p>
                            </button>
                            {canSelfPay && (
                                <button
                                    type="button"
                                    onClick={() => { stopPolling(); setSelfPay(null); setPaymentMethod("gateway"); }}
                                    className={`p-4 rounded-2xl border-2 text-left transition-all ${paymentMethod === "gateway" ? "border-emerald-500 bg-emerald-50" : "border-slate-100 bg-white"}`}
                                >
                                    <div className="text-2xl mb-1">⚡</div>
                                    <p className="text-xs font-black uppercase text-slate-800 leading-none">Bayar Sendiri</p>
                                    <p className="text-[9px] text-slate-400 mt-1 leading-tight">QRIS otomatis</p>
                                </button>
                            )}
                        </div>

                        {paymentMethod === "qris_manual" && (
                            <div className="mt-4 pt-4 border-t border-slate-100">
                                <p className="text-[10px] font-black text-slate-500 uppercase tracking-wide mb-3 text-center">
                                    Scan dengan aplikasi apa pun
                                </p>
                                <div className="flex justify-center">
                                    {qris.image_url ? (
                                        <img
                                            src={qris.image_url}
                                            alt="Kode QRIS"
                                            className="w-52 h-52 object-contain rounded-2xl border-2 border-slate-100 bg-white p-2"
                                        />
                                    ) : (
                                        <div className="w-52 h-52 rounded-2xl border-2 border-dashed border-slate-200 flex items-center justify-center text-center p-4">
                                            <p className="text-[10px] font-bold text-slate-400">Kode QRIS belum diatur. Silakan minta ke kasir.</p>
                                        </div>
                                    )}
                                </div>
                                <p className="text-[10px] text-slate-400 text-center mt-3 leading-relaxed">
                                    Bayar dulu, lalu tekan kirim pesanan. Kasir akan
                                    mengecek pembayaran Anda sebelum pesanan diserahkan.
                                </p>
                            </div>
                        )}

                        {paymentMethod === "gateway" && !selfPay && (
                            <div className="mt-4 pt-4 border-t border-slate-100">
                                <p className="text-[10px] text-slate-500 font-bold leading-relaxed mb-4 text-center">
                                    Pesanan langsung tercatat lunas begitu pembayaran
                                    diterima. Tidak perlu antre di kasir untuk membayar.
                                </p>
                                {selfPayError && (
                                    <p className="text-[10px] text-rose-600 font-bold text-center mb-3">{selfPayError}</p>
                                )}
                                <div className={`grid gap-3 ${gatewayOptions.length > 1 ? "grid-cols-2" : "grid-cols-1"}`}>
                                    {gatewayOptions.map((g) => (
                                        <button
                                            key={g.name}
                                            type="button"
                                            onClick={() => startSelfPay(g.name)}
                                            className="p-4 rounded-2xl bg-emerald-600 text-white font-black uppercase text-[10px] tracking-widest active:scale-95 transition-all"
                                        >
                                            Bayar via {g.label}
                                        </button>
                                    ))}
                                </div>
                            </div>
                        )}

                        {selfPay && (
                            <div className="mt-4 pt-4 border-t border-slate-100 text-center">
                                {selfPay.payment_url ? (
                                    <a
                                        href={selfPay.payment_url}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="block w-full p-4 rounded-2xl bg-emerald-600 text-white font-black uppercase text-[10px] tracking-widest active:scale-95 transition-all"
                                    >
                                        Buka Halaman Pembayaran
                                    </a>
                                ) : selfPay.qr_string ? (
                                    <>
                                        <p className="text-[10px] font-black text-slate-500 uppercase tracking-wide mb-3">
                                            Scan dengan aplikasi bank Anda
                                        </p>
                                        <div className="flex justify-center">
                                            <QRCodeSVG
                                                value={selfPay.qr_string}
                                                size={208}
                                                level="M"
                                                includeMargin
                                                className="rounded-2xl border-2 border-slate-100 bg-white p-2"
                                            />
                                        </div>
                                    </>
                                ) : null}

                                {selfPay.status === "paid" ? (
                                    <p className="text-sm font-black text-emerald-600 mt-4">✅ Pembayaran diterima</p>
                                ) : selfPay.status === "expired" || selfPay.status === "failed" ? (
                                    <p className="text-sm font-black text-rose-600 mt-4">
                                        {selfPay.status === "expired" ? "Kode QR sudah kedaluwarsa" : "Pembayaran gagal"}
                                    </p>
                                ) : (
                                    <p className="text-[10px] text-slate-400 mt-4 animate-pulse font-bold">
                                        Menunggu pembayaran… jangan tutup halaman ini
                                    </p>
                                )}

                                <p className="text-[10px] text-slate-400 mt-2">
                                    Kode pesanan <span className="font-black text-slate-600">{selfPay.ref_number}</span>
                                </p>
                            </div>
                        )}
                    </div>

                    {paymentMethod !== "gateway" && (
                        <button onClick={submitOrder} className={`w-full p-6 rounded-[2rem] flex items-center justify-center gap-4 active:scale-[0.98] transition-all shadow-2xl text-white group ${isTakeAway ? 'bg-emerald-600' : 'bg-slate-900'}`}>
                            <IconCash size={28} className={isTakeAway ? 'text-emerald-200' : 'text-indigo-400'} />
                            <span className="font-black uppercase tracking-widest leading-none">
                                {paymentMethod === "qris_manual" ? "Kirim & Sudah Bayar QRIS" : "Kirim Pesanan Sekarang"}
                            </span>
                        </button>
                    )}
                </div>
            )}
            <style dangerouslySetInnerHTML={{ __html: `.scrollbar-hide::-webkit-scrollbar { display: none; }` }} />
        </div>
    );
}