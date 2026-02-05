import React, { useState, useEffect, useCallback, useMemo } from "react";
import { Head, router, Link, usePage, useForm } from "@inertiajs/react";
import axios from "axios"; 
import debounce from "lodash/debounce";
import { 
    IconSearch, IconShoppingCart, IconX, IconTicket, IconGift,
    IconLayoutDashboard, IconCash, IconSun, IconMoon,
    IconPower, IconPackage, IconQrcode, IconPrinter, IconTag, IconScale,
    IconDoorEnter, IconDoorExit, IconClockPause, IconRestore, IconTrash,
    IconCashOff, IconLayoutGrid, IconList, IconCategory, IconUser, IconLoader,
    IconChevronUp, IconChevronDown, IconArmchair, IconArrowsExchange, IconGitMerge, 
    IconDeviceFloppy, IconInfoCircle, IconBoxSeam
} from "@tabler/icons-react";
import Swal from "sweetalert2";
import ThermalReceipt from "@/Components/Receipt/ThermalReceipt";
import ShiftReceipt from "@/Components/Receipt/ShiftReceipt";

// --- HELPER FORMAT HARGA ---
const formatPrice = (value) =>
    new Intl.NumberFormat("id-ID", { style: "currency", currency: "IDR", minimumFractionDigits: 0 }).format(value || 0);

// --- KOMPONEN ITEM KERANJANG ---
const CartItem = ({ c, discounts, updateCartItem, deleteCart }) => {
    const [localQty, setLocalQty] = useState(c.qty);
    useEffect(() => { setLocalQty(c.qty); }, [c.qty]);

    const handleBlur = () => {
        const val = parseFloat(localQty);
        if (!isNaN(val) && val !== parseFloat(c.qty)) {
            updateCartItem(c.id, val, c.product_unit_id);
        } else { setLocalQty(c.qty); }
    };

    const itemDiscount = (discounts || []).find(d => d.product_id === c.product_id && d.type !== 'buy_get');
    let dPrice = parseFloat(c.price);
    if (itemDiscount) {
        dPrice = itemDiscount.type === 'percentage' 
            ? dPrice - (dPrice * (parseFloat(itemDiscount.value) / 100)) 
            : dPrice - (parseFloat(itemDiscount.value) * parseFloat(c.qty));
    }

    const buyGetPromo = (discounts || []).find(d => d.product_id === c.product_id && d.type === 'buy_get');
    const isPromoReached = buyGetPromo && parseFloat(c.qty) >= parseFloat(buyGetPromo.min_transaction);
    const neededForPromo = buyGetPromo ? Math.max(0, parseFloat(buyGetPromo.min_transaction) - parseFloat(c.qty)) : 0;

    return (
        <div className="flex flex-col gap-1 mb-3">
            <div className="flex flex-col p-2 bg-white dark:bg-slate-800/40 border dark:border-slate-700 rounded-xl shadow-sm group">
                <div className="flex items-center gap-2">
                    <div className="flex-1 min-w-0">
                        <h4 className="text-[10px] font-black uppercase truncate dark:text-white leading-tight flex items-center gap-1">
                            {c.product?.title}
                            {c.product?.type === 'bundle' && <IconBoxSeam size={12} className="text-purple-500" />}
                            {itemDiscount && <IconTag size={10} className="text-red-500 animate-pulse" />}
                        </h4>
                        <div className="flex items-center gap-1.5">
                             <p className="text-[9px] font-bold text-primary-600 dark:text-primary-400">{formatPrice(dPrice)}</p>
                             {itemDiscount && <span className="text-[7px] line-through text-slate-400">{formatPrice(c.price)}</span>}
                        </div>
                    </div>
                    
                    <select value={c.product_unit_id || ''} onChange={(e) => updateCartItem(c.id, c.qty, e.target.value || null)} className="bg-slate-50 dark:bg-slate-800 border-none text-[8px] font-black p-1 rounded-md focus:ring-0 uppercase cursor-pointer">
                        <option value="">UTAMA</option>
                        {c.product?.units?.map(u => <option key={u.id} value={u.id}>{u.unit_name}</option>)}
                    </select>

                    <div className="flex items-center bg-slate-100 dark:bg-slate-700 rounded-lg p-0.5 border dark:border-slate-600">
                        <button onClick={() => updateCartItem(c.id, parseFloat(c.qty) - 1, c.product_unit_id)} className="w-5 h-5 flex items-center justify-center text-xs font-bold text-slate-500">-</button>
                        <input type="number" step="0.01" value={localQty} onChange={(e) => setLocalQty(e.target.value)} onBlur={handleBlur} className="w-7 text-[9px] font-black text-center bg-transparent border-none p-0 dark:text-white focus:ring-0" />
                        <button onClick={() => updateCartItem(c.id, parseFloat(c.qty) + 1, c.product_unit_id)} className="w-5 h-5 flex items-center justify-center text-xs font-bold text-slate-500">+</button>
                    </div>
                    
                    <button onClick={() => deleteCart(c.id)} className="text-slate-300 hover:text-red-500 transition-colors"><IconX size={14} /></button>
                </div>

                {c.product?.type === 'bundle' && c.product?.bundle_items?.length > 0 && (
                    <div className="mt-2 pl-2 border-l-2 border-purple-200 dark:border-purple-900 flex flex-col gap-0.5">
                        {c.product.bundle_items.map((bi, idx) => (
                            <div key={idx} className="flex justify-between text-[8px] font-bold text-slate-400 uppercase italic">
                                <span>• {bi.title}</span>
                                <span>x{parseFloat(bi.pivot?.qty || 1) * c.qty}</span>
                            </div>
                        ))}
                    </div>
                )}
            </div>

            {buyGetPromo && (
                <div className={`flex items-center gap-1.5 px-3 py-1 rounded-lg border text-[8px] font-bold uppercase italic ${isPromoReached ? 'bg-emerald-50 border-emerald-200 text-emerald-600 animate-bounce' : 'bg-orange-50 border-orange-200 text-orange-600'}`}>
                    {isPromoReached ? (
                        <><IconGift size={12} /> {`Bonus: 1x ${buyGetPromo.bonus_product?.title || 'Hadiah'}`}</>
                    ) : (
                        <><IconInfoCircle size={12} /> {`Tambah ${neededForPromo} lagi untuk Gratis Item!`}</>
                    )}
                </div>
            )}
        </div>
    );
};

// --- MAIN PAGE COMPONENT ---
const Index = ({ carts = [], products: initialProducts, customers = [], discounts = [], paymentSetting = {}, activeShift = null, holds = [], tables = [], categories = [], filters = {} }) => {
    const { auth, receiptSetting, flash } = usePage().props;
    
    const [productList, setProductList] = useState(initialProducts.data || []);
    const [nextPageUrl, setNextPageUrl] = useState(initialProducts.next_page_url);
    const [loadingMore, setLoadingMore] = useState(false);
    const [search, setSearch] = useState(filters.search || "");
    const [selectedCategory, setSelectedCategory] = useState(filters.category_id || "all"); 
    const [viewMode, setViewMode] = useState("grid"); 
    const [cash, setCash] = useState(0);
    const [selectedCustomer, setSelectedCustomer] = useState(""); 
    const [showQrisModal, setShowQrisModal] = useState(false);
    const [isDarkMode, setIsDarkMode] = useState(false);
    const [showModalHold, setShowModalHold] = useState(false);
    const [showCashOut, setShowCashOut] = useState(false);
    const [showCloseShift, setShowCloseShift] = useState(false);
    const [activeHoldId, setActiveHoldId] = useState(null); 
    const [showCartDrawer, setShowCartDrawer] = useState(false);
    const [selectedTable, setSelectedTable] = useState("");
    const [searchHold, setSearchHold] = useState("");

    const { data: shiftData, setData: setShiftData, post: postShift } = useForm({ starting_cash: 0 });
    const { data: closeShiftData, setData: setCloseShiftData, post: postCloseShift, processing: processingCloseShift } = useForm({ total_cash_physical: 0 });
    const { data: cashOutData, setData: setCashOutData, post: postCashOut, reset: resetCashOut, processing: processingCashOut } = useForm({ name: '', amount: '' });

    const currentActiveHold = holds.find(h => h.id === activeHoldId);

    const filteredHolds = useMemo(() => {
        return holds.filter(h => 
            h.customer_name?.toLowerCase().includes(searchHold.toLowerCase()) ||
            h.queue_number?.toLowerCase().includes(searchHold.toLowerCase()) ||
            h.table?.name?.toLowerCase().includes(searchHold.toLowerCase())
        );
    }, [holds, searchHold]);

    // --- REALTIME LISTENER (SINKRONISASI MASUK & HAPUS) ---
    useEffect(() => {
        if (window.Echo && auth.user) {
            const channelName = `public-order.${auth.user.id}`;
            const channel = window.Echo.channel(channelName);
            
            console.log("Listening on channel:", channelName);

            // 1. Listen jika ada order baru (Event: order.placed)
            channel.listen('.order.placed', (data) => {
                console.log("Real-time: New Order Received");
                const audio = new Audio('https://assets.mixkit.co/active_storage/sfx/2869/2869-preview.mp3');
                audio.play().catch(err => console.log("Audio play blocked"));

                router.reload({ 
                    only: ['holds', 'tables'], 
                    preserveScroll: true,
                    onSuccess: () => {
                        Swal.fire({
                            title: 'ORDER QR MASUK!',
                            text: 'Antrean telah diperbarui secara real-time.',
                            icon: 'success',
                            toast: true,
                            position: 'top-end',
                            showConfirmButton: false,
                            timer: 3000
                        });
                    }
                });
            });

            // 2. Listen jika order dihapus/dibayar oleh kasir lain (Event: order.deleted)
            channel.listen('.order.deleted', (data) => {
                console.log("Real-time: Order Processed/Deleted");
                router.reload({ 
                    only: ['holds', 'tables'], 
                    preserveScroll: true 
                });
            });

            return () => {
                window.Echo.leave(channelName);
            };
        }
    }, [auth.user.id]); 

    const calculateSubtotal = () => {
        return (carts || []).reduce((acc, c) => {
            const itemDiscount = (discounts || []).find(d => d.product_id === c.product_id && d.type !== 'buy_get');
            let dPrice = parseFloat(c.price);
            if (itemDiscount) {
                dPrice = itemDiscount.type === 'percentage' 
                    ? dPrice - (dPrice * (parseFloat(itemDiscount.value) / 100)) 
                    : dPrice - (parseFloat(itemDiscount.value) * parseFloat(c.qty));
            }
            return acc + dPrice;
        }, 0);
    };

    const grandTotal = Math.max(0, calculateSubtotal());
    const change = cash - grandTotal;

    const performSearch = (query, categoryId) => {
        router.get(route('transactions.index'), 
            { search: query, category_id: categoryId === 'all' ? '' : categoryId }, 
            { preserveState: true, preserveScroll: true, only: ['products', 'filters'] }
        );
    };

    const debouncedSearch = useCallback(debounce((query, category) => performSearch(query, category), 500), []);

    const handleSearchChange = (e) => {
        setSearch(e.target.value);
        debouncedSearch(e.target.value, selectedCategory);
    };

    const handleCategoryChange = (catId) => {
        setSelectedCategory(catId);
        performSearch(search, catId);
    };

    const addToCart = (product) => {
        if (!product?.id) return;
        router.post(route("transactions.addToCart"), { product_id: product.id, qty: 1 }, { preserveScroll: true });
    };

    const updateCartItem = (id, qty, unitId = null) => {
        const val = parseFloat(qty);
        if (isNaN(val) || val < 0) return;
        if (val === 0) return deleteCart(id);
        router.patch(route("transactions.updateCart", id), { qty: val, product_unit_id: unitId }, { preserveScroll: true });
    };

    const deleteCart = (id) => router.delete(route("transactions.destroyCart", id), { preserveScroll: true });

    const handleSaveOrder = () => {
        if (carts.length === 0) return Swal.fire("Peringatan", "Keranjang kosong!", "warning");
        if (!selectedTable) return Swal.fire("Peringatan", "Pilih Meja atau Bawa Pulang!", "warning");

        Swal.fire({ title: 'Menyimpan...', didOpen: () => Swal.showLoading(), allowOutsideClick: false });

        router.post(route('transactions.hold'), { 
            hold_id: activeHoldId, 
            ref_number: currentActiveHold?.ref_number || `ORDER-${Date.now().toString().slice(-4)}`, 
            customer_name: selectedCustomer ? customers.find(c => c.id == selectedCustomer)?.name : (currentActiveHold?.customer_name || "Pelanggan"),
            table_id: selectedTable === "take_away" ? null : selectedTable,
            cart_items: carts, 
            total: grandTotal 
        }, {
            onSuccess: () => {
                setActiveHoldId(null); setSelectedTable(""); setSelectedCustomer(""); setCash(0); setShowCartDrawer(false);
                router.reload({ only: ['carts', 'holds', 'tables'] }); 
                Swal.fire({ icon: "success", title: "Berhasil Diperbarui", timer: 1500, showConfirmButton: false });
            }
        });
    };

    const handleResumeHold = (holdId) => {
        const hold = holds.find(h => h.id === holdId);
        router.post(route('transactions.resume', holdId), {}, {
            onSuccess: () => {
                setActiveHoldId(holdId);
                setSelectedTable(hold.table_id ? hold.table_id.toString() : "take_away");
                setShowModalHold(false);
                if (window.innerWidth < 1024) setShowCartDrawer(true);
            }
        });
    };

    const submitTransaction = (method, paidAmount) => {
        if (carts.length === 0) return;
        if (!selectedTable) return Swal.fire("Peringatan", "Pilih Tipe Pesanan!", "warning");

        const queueNumber = currentActiveHold ? currentActiveHold.queue_number : null;
        const tableName = selectedTable === "take_away" ? "TAKE AWAY" : tables.find(t => t.id == selectedTable)?.name;

        router.post(route("transactions.store"), {
            customer_id: selectedCustomer || null,
            grand_total: grandTotal, 
            cash: paidAmount,
            change: method === 'qris' ? 0 : (paidAmount - grandTotal),
            payment_gateway: method, 
            hold_id: activeHoldId,
            queue_number: queueNumber,
            table_name: tableName 
        }, {
            onSuccess: () => { 
                setCash(0); setShowQrisModal(false); setSearch(""); 
                setSelectedCustomer(""); setActiveHoldId(null); setShowCartDrawer(false);
                setSelectedTable("");
            },
        });
    };

    const toggleTheme = () => {
        const newTheme = !isDarkMode;
        setIsDarkMode(newTheme);
        newTheme ? document.documentElement.classList.add('dark') : document.documentElement.classList.remove('dark');
        localStorage.setItem('theme', newTheme ? 'dark' : 'light');
    };

    const loadMoreProducts = async () => {
        if (!nextPageUrl || loadingMore) return;
        setLoadingMore(true);
        try {
            const response = await axios.get(nextPageUrl, { params: { search, category_id: selectedCategory === 'all' ? '' : selectedCategory } });
            setProductList(prev => [...prev, ...response.data.data]);
            setNextPageUrl(response.data.next_page_url);
        } catch (error) { console.error(error); } finally { setLoadingMore(false); }
    };

    useEffect(() => {
        const savedTheme = localStorage.getItem('theme') === 'dark';
        setIsDarkMode(savedTheme);
        if (savedTheme) document.documentElement.classList.add('dark');
    }, []);

    useEffect(() => {
        setProductList(initialProducts.data || []);
        setNextPageUrl(initialProducts.next_page_url);
    }, [initialProducts]);

    return (
        <div className="bg-slate-50 dark:bg-slate-950 min-h-screen">
            <Head title="Kasir Toko" />
            
            {!activeShift && (
                <div className="fixed inset-0 z-[200] flex items-center justify-center bg-slate-900/60 backdrop-blur-md p-4 text-center">
                    <div className="bg-white dark:bg-slate-900 w-full max-w-md rounded-3xl p-8 shadow-2xl">
                        <IconDoorEnter size={48} className="mx-auto text-primary-500 mb-4" />
                        <h2 className="text-xl font-black dark:text-white uppercase mb-6 tracking-tighter">Buka Shift Kasir</h2>
                        <div className="relative mb-6">
                            <span className="absolute top-2 left-4 text-[8px] font-black text-slate-400 uppercase">Modal Awal (Tunai)</span>
                            <input type="number" className="w-full pt-6 pb-4 bg-slate-50 dark:bg-slate-800 rounded-2xl text-center text-xl font-black border-none shadow-inner focus:ring-0 dark:text-white" value={shiftData.starting_cash} onChange={e => setShiftData('starting_cash', e.target.value)} required />
                        </div>
                        <button onClick={(e) => { e.preventDefault(); postShift(route('shifts.store')); }} className="w-full py-4 bg-primary-600 text-white rounded-2xl font-black uppercase shadow-lg hover:bg-primary-700 transition-all active:scale-95">Mulai Bertugas</button>
                    </div>
                </div>
            )}

            <div id="main-app-content" className={`flex flex-col h-[100dvh] w-full transition-colors ${isDarkMode ? 'bg-slate-950 text-slate-200' : 'bg-slate-100 text-slate-800'} overflow-hidden print:hidden ${!activeShift ? 'blur-xl pointer-events-none' : ''}`}>
                <header className="h-16 bg-white dark:bg-slate-900 border-b dark:border-slate-800 flex items-center justify-between px-4 md:px-6 shrink-0 z-20 shadow-sm">
                    <div className="flex items-center gap-3">
                        <Link href={route('dashboard')} className="p-2 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-500 hover:text-primary-500 transition-transform active:scale-90"><IconLayoutDashboard size={22} /></Link>
                        <h1 className="text-sm md:text-lg font-black dark:text-white uppercase tracking-tighter italic">{auth?.user?.name}</h1>
                    </div>
                    <div className="flex items-center gap-2 md:gap-3">
                        <button onClick={() => setShowCashOut(true)} className="flex px-4 py-2 bg-orange-100 text-orange-600 dark:bg-orange-950/30 dark:text-orange-400 rounded-xl text-[10px] font-black uppercase items-center gap-2 border border-orange-200 dark:border-orange-900/50 hover:bg-orange-200 transition-colors shadow-sm">
                            <IconCashOff size={16}/> <span className="hidden sm:inline">Kas Keluar</span>
                        </button>
                        <button onClick={() => setShowModalHold(true)} className="relative p-2.5 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400 border dark:border-slate-700">
                            <IconClockPause size={20} />
                            {holds?.length > 0 && <span className="absolute -top-1 -right-1 h-5 w-5 bg-red-500 text-white text-[9px] font-black flex items-center justify-center rounded-full shadow-md animate-bounce">{holds.length}</span>}
                        </button>
                        <button onClick={toggleTheme} className="p-2.5 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400 border dark:border-slate-700">{isDarkMode ? <IconSun size={20} className="text-yellow-400" /> : <IconMoon size={20} />}</button>
                        <button onClick={() => carts.length > 0 ? Swal.fire("Peringatan", "Kosongkan keranjang dulu!", "warning") : setShowCloseShift(true)} className="p-2.5 rounded-xl bg-red-50 dark:bg-red-950/30 text-red-500 border border-red-100 dark:border-red-900/50"><IconPower size={20} /></button>
                    </div>
                </header>

                <main className="flex flex-1 overflow-hidden lg:flex-row flex-col relative">
                    <div className="flex-1 flex flex-col min-w-0">
                        <div className="p-3 md:p-4 bg-white/50 dark:bg-slate-900/50 border-b dark:border-slate-800 flex items-center justify-between gap-3 backdrop-blur-sm">
                            <div className="relative flex-1 max-w-xl">
                                <IconSearch className="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400" size={18} />
                                <input type="text" value={search} onChange={handleSearchChange} placeholder="Cari Produk..." className="w-full pl-11 pr-4 py-3 text-sm rounded-2xl border-none bg-white dark:bg-slate-800 dark:text-white shadow-sm ring-1 ring-slate-200 dark:ring-slate-700 focus:ring-2 focus:ring-primary-500" />
                            </div>
                            <div className="flex bg-white dark:bg-slate-800 p-1 rounded-2xl ring-1 ring-slate-200 dark:ring-slate-700 shadow-sm">
                                <button onClick={() => setViewMode('grid')} className={`p-2 rounded-xl transition-all ${viewMode === 'grid' ? 'bg-primary-500 text-white' : 'text-slate-400'}`}><IconLayoutGrid size={18} /></button>
                                <button onClick={() => setViewMode('list')} className={`p-2 rounded-xl transition-all ${viewMode === 'list' ? 'bg-primary-500 text-white' : 'text-slate-400'}`}><IconList size={18} /></button>
                            </div>
                        </div>

                        <div className="bg-white/30 dark:bg-slate-900/30 border-b dark:border-slate-800 p-2 flex items-center gap-2 overflow-x-auto scrollbar-hide shrink-0">
                            <button onClick={() => handleCategoryChange('all')} className={`px-5 py-2 rounded-2xl font-black uppercase text-[10px] whitespace-nowrap border transition-all ${selectedCategory === 'all' ? 'bg-primary-500 text-white' : 'bg-white dark:bg-slate-800 text-slate-500 border-slate-200 dark:border-slate-700'}`}>Semua</button>
                            {categories.map((cat) => (
                                <button key={cat.id} onClick={() => handleCategoryChange(cat.id.toString())} className={`px-5 py-2 rounded-2xl font-black uppercase text-[10px] whitespace-nowrap border transition-all ${selectedCategory === cat.id.toString() ? 'bg-primary-500 text-white' : 'bg-white dark:bg-slate-800 text-slate-500 border-slate-200 dark:border-slate-700'}`}>{cat.name}</button>
                            ))}
                        </div>

                        <div className="flex-1 overflow-y-auto p-3 md:p-4 custom-scrollbar">
                            <div className={viewMode === 'grid' ? "grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 xl:grid-cols-5 2xl:grid-cols-6 gap-3 md:gap-4" : "flex flex-col gap-2"}>
                                {productList.map((p) => {
                                    const promo = discounts.find(d => d.product_id === p.id && d.type === 'buy_get');
                                    return (
                                        <button key={p.id} onClick={() => addToCart(p)} className={`relative text-left group border dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm rounded-[1.5rem] p-2.5 transition-all active:scale-95 hover:shadow-xl ${viewMode === 'list' ? 'flex items-center gap-4' : 'flex flex-col'}`}>
                                            {p.type === 'bundle' && (
                                                 <div className="absolute top-2 right-2 z-10 bg-purple-500 text-white p-1 rounded-lg shadow-md"><IconBoxSeam size={12} /></div>
                                            )}
                                            {promo && (
                                                <div className="absolute -top-1 -left-1 z-10 bg-emerald-500 text-white p-1.5 rounded-xl shadow-lg border-2 border-white dark:border-slate-900 animate-pulse"><IconGift size={12} /></div>
                                            )}
                                            <div className={`relative overflow-hidden bg-slate-50 dark:bg-slate-800 shrink-0 ${viewMode === 'list' ? 'w-14 h-14 rounded-xl' : 'aspect-square rounded-xl md:rounded-2xl mb-2'}`}>
                                                <img src={p.image ? (p.image.startsWith('http') ? p.image : `/storage/products/${p.image}`) : `https://ui-avatars.com/api/?name=${p.title}`} className="w-full h-full object-cover transition-transform group-hover:scale-110" />
                                            </div>
                                            <div className="flex-1 min-w-0">
                                                <h3 className="font-black uppercase text-[10px] line-clamp-2 dark:text-white leading-tight">{p.title}</h3>
                                                {promo && <p className="text-[7px] text-emerald-600 font-black italic mt-1 uppercase leading-none">PROMO: BELI {promo.min_transaction} GRATIS ITEM</p>}
                                                <div className="flex justify-between items-center mt-2">
                                                    <p className="text-[8px] text-slate-400 font-bold uppercase">Stok: {p.stock}</p>
                                                    <p className="font-black text-primary-600 dark:text-primary-400 text-xs">{formatPrice(p.sell_price)}</p>
                                                </div>
                                            </div>
                                        </button>
                                    );
                                })}
                            </div>
                            {nextPageUrl && (
                                <button onClick={loadMoreProducts} disabled={loadingMore} className="my-8 mx-auto flex items-center gap-2 px-8 py-3 bg-white dark:bg-slate-800 dark:text-white rounded-full text-[10px] font-black uppercase shadow-md border dark:border-slate-700 active:scale-95 transition-all">{loadingMore ? <IconLoader className="animate-spin" size={14} /> : 'Muat Lebih Banyak'}</button>
                            )}
                        </div>
                    </div>

                    <aside className={`
                        fixed inset-x-0 bottom-0 z-40 lg:relative lg:inset-auto lg:z-auto
                        w-full lg:w-[380px] xl:w-[420px] bg-white dark:bg-slate-900 border-t lg:border-t-0 lg:border-l dark:border-slate-800 
                        flex flex-col shadow-[0_-20px_40px_rgba(0,0,0,0.1)] transition-all duration-500 ease-in-out
                        ${showCartDrawer ? 'h-[90vh]' : 'h-16 lg:h-full'}
                    `}>
                        <div onClick={() => window.innerWidth < 1024 && setShowCartDrawer(!showCartDrawer)} className="h-16 p-4 border-b dark:border-slate-800 flex justify-between items-center bg-slate-50/80 dark:bg-slate-800/50 cursor-pointer lg:cursor-default shrink-0">
                             <div className="flex items-center gap-3">
                                <IconShoppingCart size={24} className="text-primary-500" />
                                <div>
                                    <span className="font-black dark:text-white uppercase text-xs italic">Ringkasan Pesanan</span>
                                    {currentActiveHold && <p className="text-[10px] font-black text-orange-500 leading-none">
                                        {currentActiveHold.queue_number.includes('SELF') ? 'QR ORDER' : `UPDATE: Meja ${currentActiveHold.table?.name || ''}`}
                                    </p>}
                                </div>
                             </div>
                             {!showCartDrawer && <div className="lg:hidden font-black text-primary-600">{formatPrice(grandTotal)}</div>}
                             <div className="lg:hidden">{showCartDrawer ? <IconChevronDown /> : <IconChevronUp className="animate-bounce" />}</div>
                        </div>

                        <div className={`flex flex-col flex-1 overflow-hidden transition-opacity duration-300 ${!showCartDrawer && window.innerWidth < 1024 ? 'opacity-0 invisible' : 'opacity-100 visible'}`}>
                            <div className="p-4 border-b dark:border-slate-800 space-y-3 bg-slate-50/50 dark:bg-slate-800/30">
                                <select value={selectedCustomer} onChange={(e) => setSelectedCustomer(e.target.value)} className="w-full pl-4 py-2 bg-white dark:bg-slate-800 border-none rounded-xl text-[10px] font-black uppercase focus:ring-2 focus:ring-primary-500 shadow-sm">
                                    <option value="">Pelanggan Umum</option>
                                    {customers.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                                </select>
                                <select value={selectedTable} onChange={(e) => setSelectedTable(e.target.value)} className={`w-full pl-4 py-2 border-none rounded-xl text-[10px] font-black uppercase focus:ring-2 focus:ring-primary-500 shadow-sm ${selectedTable ? 'bg-primary-50 text-primary-600 font-black' : 'bg-white dark:bg-slate-800'}`}>
                                    <option value="">-- Pilih Tipe Pesanan --</option>
                                    <option value="take_away">Bawa Pulang</option>
                                    {(tables || []).filter(t => t.status === 'available' || t.id == currentActiveHold?.table_id).map((t) => (
                                        <option key={t.id} value={t.id}>Meja: {t.name}</option>
                                    ))}
                                </select>
                            </div>

                            <div className="flex-1 overflow-y-auto p-4 custom-scrollbar">
                                {carts.length === 0 ? <div className="h-full flex items-center justify-center opacity-10 font-black uppercase text-xs italic text-center">Keranjang Kosong</div> : carts.map((c) => <CartItem key={c.id} c={c} discounts={discounts} updateCartItem={updateCartItem} deleteCart={deleteCart} />)}
                            </div>

                            <div className="p-4 md:p-6 bg-white dark:bg-slate-950 border-t dark:border-slate-800 space-y-4 shadow-xl shrink-0">
                                <div className="flex justify-between items-end"><span className="text-[10px] font-black text-slate-400 uppercase tracking-widest">Grand Total</span><span className="text-2xl md:text-3xl font-black text-primary-600 italic tracking-tighter">{formatPrice(grandTotal)}</span></div>
                                <div className="grid grid-cols-2 gap-3">
                                    <div className="relative">
                                        <span className="absolute top-2 left-3 text-[7px] font-black text-slate-400 uppercase leading-none">Bayar</span>
                                        <input type="number" value={cash || ''} onChange={(e) => setCash(Number(e.target.value))} placeholder="0" className="w-full pt-5 pb-2 px-3 text-base font-black rounded-2xl border-none bg-slate-100 dark:bg-slate-800 dark:text-white focus:ring-0" />
                                    </div>
                                    <div className="bg-slate-50 dark:bg-slate-800 rounded-2xl p-2 text-center flex flex-col justify-center border border-slate-100 dark:border-slate-700 leading-none">
                                        <span className="text-[7px] font-black text-slate-400 uppercase mb-1 leading-none">Kembali</span>
                                        <span className={`text-xs font-black truncate leading-none ${change >= 0 ? 'text-green-500' : 'text-red-500'}`}>{formatPrice(change)}</span>
                                    </div>
                                </div>
                                <div className="grid grid-cols-2 gap-3">
                                    <button onClick={() => submitTransaction('cash', cash)} disabled={carts.length === 0 || cash < grandTotal} className="py-4 bg-slate-900 dark:bg-slate-800 text-white font-black text-[10px] rounded-2xl uppercase active:scale-95 disabled:opacity-50 transition-all hover:bg-black flex items-center justify-center gap-2 shadow-lg"><IconCash size={18} /> TUNAI</button>
                                    <button onClick={() => submitTransaction('qris', grandTotal)} disabled={carts.length === 0} className="py-4 bg-primary-600 text-white font-black text-[10px] rounded-2xl uppercase active:scale-95 disabled:opacity-50 transition-all hover:bg-primary-700 flex items-center justify-center gap-2 shadow-lg"><IconQrcode size={18} /> QRIS</button>
                                </div>
                                <button onClick={handleSaveOrder} disabled={carts.length === 0 || !selectedTable} className="w-full py-3 bg-white dark:bg-slate-800 border-2 border-primary-500 text-primary-600 font-black text-[10px] rounded-2xl uppercase transition-all flex items-center justify-center gap-2 hover:bg-primary-500 hover:text-white active:scale-95 disabled:opacity-30 shadow-sm">
                                    <IconDeviceFloppy size={18} /> {activeHoldId ? "PERBARUI PESANAN" : "SIMPAN PESANAN"}
                                </button>
                            </div>
                        </div>
                    </aside>
                </main>
            </div>

            {/* MODAL DAFTAR SIMPAN PESANAN - FULLSCREEN & SEARCHABLE */}
            {showModalHold && (
                <div className="fixed inset-0 z-[150] flex flex-col bg-slate-100 dark:bg-slate-950 animate-in fade-in duration-200">
                    <div className="h-20 bg-white dark:bg-slate-900 border-b dark:border-slate-800 flex items-center justify-between px-6 md:px-10 shrink-0 shadow-sm">
                        <div className="flex items-center gap-4">
                            <div className="p-3 bg-primary-50 dark:bg-primary-950/30 text-primary-600 rounded-2xl">
                                <IconClockPause size={28} />
                            </div>
                            <div>
                                <h3 className="text-xl font-black uppercase dark:text-white italic tracking-tighter">Antrean Pesanan Aktif</h3>
                                <p className="text-[10px] font-black text-slate-400 uppercase tracking-widest leading-none mt-1">{filteredHolds.length} Pesanan Ditemukan</p>
                            </div>
                        </div>
                        
                        <div className="hidden md:flex relative w-full max-w-md mx-8">
                            <IconSearch className="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400" size={18} />
                            <input 
                                type="text" 
                                placeholder="Cari Nama / No Antrean / Meja..." 
                                className="w-full pl-11 pr-4 py-3 rounded-2xl border-none bg-slate-50 dark:bg-slate-800 dark:text-white focus:ring-2 focus:ring-primary-500 transition-all"
                                value={searchHold}
                                onChange={(e) => setSearchHold(e.target.value)}
                            />
                        </div>

                        <button onClick={() => setShowModalHold(false)} className="p-3 bg-red-50 dark:bg-red-950/30 text-red-500 rounded-2xl hover:bg-red-100 transition-colors">
                            <IconX size={24} />
                        </button>
                    </div>

                    <div className="md:hidden p-4 bg-white dark:bg-slate-900 border-b dark:border-slate-800">
                        <div className="relative">
                            <IconSearch className="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400" size={18} />
                            <input 
                                type="text" 
                                placeholder="Cari pesanan..." 
                                className="w-full pl-11 pr-4 py-3 rounded-xl border-none bg-slate-50 dark:bg-slate-800 dark:text-white"
                                value={searchHold}
                                onChange={(e) => setSearchHold(e.target.value)}
                            />
                        </div>
                    </div>

                    <div className="flex-1 overflow-y-auto p-6 md:p-10 custom-scrollbar">
                        {filteredHolds.length === 0 ? (
                            <div className="h-full flex flex-col items-center justify-center text-center opacity-30">
                                <IconPackage size={80} stroke={1} className="mb-4" />
                                <p className="font-black uppercase text-sm italic tracking-widest dark:text-white">Data tidak ditemukan</p>
                            </div>
                        ) : (
                            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5 gap-6">
                                {filteredHolds.map((h) => (
                                    <div key={h.id} className={`group flex flex-col justify-between p-6 rounded-[2.5rem] border-2 bg-white dark:bg-slate-900 shadow-sm transition-all hover:shadow-xl hover:-translate-y-1 ${h.queue_number.includes('SELF') ? 'border-sky-400 dark:border-sky-800 shadow-sky-100 dark:shadow-none' : 'border-slate-100 dark:border-slate-800'}`}>
                                        
                                        <div>
                                            <div className="flex justify-between items-start mb-4">
                                                <div className={`px-3 py-1 rounded-lg text-[9px] font-black uppercase tracking-tighter ${h.queue_number.includes('SELF') ? 'bg-sky-500 text-white' : 'bg-slate-200 text-slate-600 dark:bg-slate-700 dark:text-slate-300'}`}>
                                                    #{h.queue_number}
                                                </div>
                                                <p className="text-sm font-black text-primary-600 dark:text-primary-400 italic">{formatPrice(h.total)}</p>
                                            </div>

                                            <div className="mb-4">
                                                <h4 className="text-base font-black text-slate-900 dark:text-white uppercase leading-tight line-clamp-2">
                                                    {h.customer_name}
                                                </h4>
                                                <div className="flex items-center gap-1.5 mt-2 text-slate-400">
                                                    {h.table_id ? <IconArmchair size={14} className="text-orange-500" /> : <IconBoxSeam size={14} className="text-primary-500" />}
                                                    <span className="text-[10px] font-black uppercase tracking-widest italic">
                                                        {h.table?.name || 'BAWA PULANG'}
                                                    </span>
                                                </div>
                                            </div>

                                            <div className="py-3 border-y border-slate-50 dark:border-slate-800 mb-6 flex flex-wrap gap-1.5">
                                                {h.cart_data?.slice(0, 4).map((item, i) => (
                                                    <span key={i} className="text-[8px] font-bold bg-slate-50 dark:bg-slate-800 text-slate-500 dark:text-slate-400 border dark:border-slate-700 px-2 py-1 rounded-md">
                                                        {item.qty}x {item.product_title}
                                                    </span>
                                                ))}
                                                {h.cart_data?.length > 4 && <span className="text-[8px] font-black text-primary-500 ml-1">+{h.cart_data.length - 4} lainnya</span>}
                                            </div>
                                        </div>

                                        <div className="flex gap-2">
                                            <button onClick={() => handleResumeHold(h.id)} className="flex-1 py-4 bg-slate-900 dark:bg-primary-600 text-white rounded-2xl font-black text-[10px] uppercase shadow-lg active:scale-95 transition-all">
                                                Bayar
                                            </button>
                                            <button onClick={() => { Swal.fire({ title: 'Hapus Pesanan?', icon: 'warning', showCancelButton: true, confirmButtonColor: '#ef4444' }).then(res => { if(res.isConfirmed) router.delete(route('holds.destroy', h.id)) }) }} className="p-4 bg-red-50 dark:bg-red-950/30 text-red-600 rounded-2xl hover:bg-red-500 hover:text-white transition-all">
                                                <IconTrash size={18}/>
                                            </button>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                </div>
            )}

            {/* MODAL LAINNYA */}
            {showCashOut && (
                <div className="fixed inset-0 z-[150] flex items-center justify-center p-4 bg-black/80 backdrop-blur-md animate-in fade-in duration-300">
                    <div className="bg-white dark:bg-slate-900 rounded-[2.5rem] p-8 max-w-md w-full border dark:border-slate-800 shadow-2xl">
                        <div className="flex items-center gap-4 mb-6">
                             <div className="p-3 bg-orange-100 dark:bg-orange-900/30 text-orange-600 rounded-2xl"><IconCashOff size={24}/></div>
                             <h3 className="text-lg font-black uppercase dark:text-white italic tracking-tighter">Catat Kas Keluar</h3>
                        </div>
                        <div className="space-y-4">
                            <div className="relative"><span className="absolute top-2 left-4 text-[7px] font-black text-slate-400 uppercase">Keterangan</span><input type="text" placeholder="Contoh: Bayar Listrik" className="w-full pt-6 pb-3 px-4 bg-slate-50 dark:bg-slate-800 rounded-2xl border-none dark:text-white text-sm font-bold focus:ring-primary-500" value={cashOutData.name} onChange={e => setCashOutData('name', e.target.value)} /></div>
                            <div className="relative"><span className="absolute top-2 left-4 text-[7px] font-black text-slate-400 uppercase">Nominal (Rp)</span><input type="number" placeholder="0" className="w-full pt-6 pb-3 px-4 bg-slate-50 dark:bg-slate-800 rounded-2xl border-none dark:text-white text-sm font-black focus:ring-primary-500" value={cashOutData.amount} onChange={e => setCashOutData('amount', e.target.value)} /></div>
                            <div className="flex gap-3 mt-4">
                                <button onClick={() => setShowCashOut(false)} className="flex-1 py-4 font-black uppercase text-[10px] text-slate-400">Batal</button>
                                <button onClick={() => postCashOut(route('expenses.store'), { onSuccess: () => { setShowCashOut(false); resetCashOut(); Swal.fire('Berhasil', 'Kas keluar dicatat', 'success'); } })} disabled={processingCashOut || !cashOutData.name || !cashOutData.amount} className="flex-[2] py-4 bg-orange-500 text-white rounded-2xl font-black uppercase text-[10px] shadow-lg">Simpan</button>
                            </div>
                        </div>
                    </div>
                </div>
            )}

            {showCloseShift && (
                <div className="fixed inset-0 z-[150] flex items-center justify-center p-4 bg-black/80 backdrop-blur-md animate-in fade-in duration-300">
                    <div className="bg-white dark:bg-slate-900 rounded-[3rem] p-10 max-w-md w-full border dark:border-slate-800 shadow-2xl text-center">
                        <IconDoorExit size={56} className="mx-auto text-red-500 mb-6" />
                        <h3 className="text-xl font-black uppercase mb-2 dark:text-white tracking-tighter">Tutup Kasir?</h3>
                        <p className="text-[10px] text-slate-500 mb-8 font-black uppercase">Sistem akan mencetak laporan penutupan shift Anda.</p>
                        <div className="relative mb-6">
                            <span className="absolute top-2 left-4 text-[7px] font-black text-slate-400 uppercase">Uang Fisik di Laci (Rp)</span>
                            <input type="number" className="w-full pt-6 pb-4 bg-slate-50 dark:bg-slate-800 rounded-2xl text-center text-xl font-black border-none focus:ring-0 dark:text-white" value={closeShiftData.total_cash_physical} onChange={e => setCloseShiftData('total_cash_physical', e.target.value)} required />
                        </div>
                        <button onClick={(e) => { e.preventDefault(); postCloseShift(route('shifts.close')); }} disabled={processingCloseShift} className="w-full py-4 bg-red-600 text-white rounded-2xl font-black uppercase text-[11px] shadow-xl flex items-center justify-center gap-2 hover:bg-red-700 transition-all">
                             {processingCloseShift ? <IconLoader size={18} className="animate-spin" /> : <><IconPower size={18} /> Tutup & Cetak Laporan</>}
                        </button>
                        <button onClick={() => setShowCloseShift(false)} className="text-slate-400 text-[10px] font-black uppercase mt-6 tracking-widest block mx-auto">Batalkan</button>
                    </div>
                </div>
            )}

            <div id="print-area" className="hidden print:block">
                {flash.print_invoice && (
                    <ThermalReceipt 
                        transaction={{ 
                            ...flash.print_invoice, 
                            queue_number: flash.print_invoice.queue_number || null,
                            details: flash.print_invoice.details?.map(d => ({ ...d, product_title: d.product?.title, unit_name: d.product_unit?.unit_name || d.unit || 'PCS' })) 
                        }} 
                        storeName={receiptSetting?.store_name} 
                        storeAddress={receiptSetting?.store_address} 
                    />
                )}
                {flash.print_shift && <ShiftReceipt shift={flash.print_shift} storeName={receiptSetting?.store_name} />}
            </div>

            <style>{`
                .custom-scrollbar::-webkit-scrollbar { width: 4px; height: 4px; }
                .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 20px; }
                .dark .custom-scrollbar::-webkit-scrollbar-thumb { background: #334155; }
                .scrollbar-hide::-webkit-scrollbar { display: none; }
                @media print {
                    body * { visibility: hidden !important; }
                    #print-area, #print-area * { visibility: visible !important; }
                    #print-area { position: absolute; left: 0; top: 0; width: 100%; display: flex; justify-content: center; }
                    @page { margin: 0; }
                }
            `}</style>
        </div>
    );
};

Index.layout = (page) => page;
export default Index;