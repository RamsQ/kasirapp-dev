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
    IconDeviceFloppy, IconInfoCircle, IconBoxSeam, IconCheck, IconWorld, IconPoint,
    IconToolsKitchen2
} from "@tabler/icons-react";
import Swal from "sweetalert2";
import ThermalReceipt from "@/Components/Receipt/ThermalReceipt";
import ShiftReceipt from "@/Components/Receipt/ShiftReceipt";
import { printBillUsb } from "@/Utils/UsbRawPrinter";
import { smartPrint } from "@/Utils/BluetoothHybridService"; 
import toast from "react-hot-toast";

// --- HELPER FORMAT HARGA ---
const formatPrice = (value) =>
    new Intl.NumberFormat("id-ID", { style: "currency", currency: "IDR", minimumFractionDigits: 0 }).format(value || 0);

// --- KOMPONEN ITEM KERANJANG ---
const CartItem = ({ c, updateCartItem, deleteCart }) => {
    const [localQty, setLocalQty] = useState(c.qty);
    const [showNoteInput, setShowNoteInput] = useState(false);
    const [noteValue, setNoteValue] = useState(c.notes || "");

    useEffect(() => { setLocalQty(c.qty); }, [c.qty]);
    useEffect(() => { setNoteValue(c.notes || ""); }, [c.notes]);

    const isBonus = c.notes === 'BONUS PROMO';

    const handleBlur = () => {
        if (isBonus) return;
        const val = parseFloat(localQty);
        if (!isNaN(val) && val !== parseFloat(c.qty)) {
            updateCartItem(c.id, val, c.product_unit_id, c.notes);
        } else { setLocalQty(c.qty); }
    };

    const handleSaveNote = () => {
        updateCartItem(c.id, c.qty, c.product_unit_id, noteValue);
        setShowNoteInput(false);
    };

    return (
        <div className="flex flex-col gap-1 mb-3">
            <div className={`flex flex-col p-3 border rounded-2xl shadow-sm group transition-all ${isBonus ? 'bg-emerald-50/50 dark:bg-emerald-900/10 border-emerald-200 dark:border-emerald-800' : 'bg-white dark:bg-slate-800/40 dark:border-slate-700'}`}>
                <div className="flex items-center gap-2">
                    <div className="flex-1 min-w-0">
                        <h4 className="text-[10px] font-black uppercase truncate dark:text-white leading-tight flex items-center gap-1">
                            {c.product?.title}
                            {isBonus && <span className="bg-emerald-500 text-white text-[7px] px-1 rounded ml-1">HADIAH</span>}
                            {c.product?.type === 'bundle' && <IconBoxSeam size={12} className="text-purple-500" />}
                            {c.product?.recipes?.length > 0 && <IconToolsKitchen2 size={12} className="text-emerald-500" />}
                        </h4>
                        <div className="flex items-center gap-1.5">
                             <p className={`text-[9px] font-bold ${isBonus ? 'text-emerald-600' : 'text-primary-600 dark:text-primary-400'}`}>
                                {isBonus ? 'GRATIS' : formatPrice(c.price)}
                             </p>
                        </div>
                    </div>
                    
                    {!isBonus && (
                        <>
                            <button type="button" onClick={() => setShowNoteInput(!showNoteInput)} className={`p-1 rounded-md transition-colors ${c.notes ? 'text-primary-500 bg-primary-50 dark:bg-primary-950/30' : 'text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-700'}`}><IconInfoCircle size={14} /></button>
                            <select value={c.product_unit_id || ''} onChange={(e) => updateCartItem(c.id, c.qty, e.target.value || null, c.notes)} className="bg-slate-50 dark:bg-slate-800 border-none text-[8px] font-black p-1 rounded-md focus:ring-0 uppercase cursor-pointer">
                                <option value="">{c.product?.unit || 'UTAMA'}</option>
                                {c.product?.units?.map(u => <option key={u.id} value={u.id}>{u.unit_name}</option>)}
                            </select>
                            <div className="flex items-center bg-slate-100 dark:bg-slate-700 rounded-lg p-0.5 border dark:border-slate-600">
                                <button type="button" onClick={() => updateCartItem(c.id, parseFloat(c.qty) - 1, c.product_unit_id, c.notes)} className="w-5 h-5 flex items-center justify-center text-xs font-bold text-slate-500">-</button>
                                <input type="number" step="0.01" value={localQty} onChange={(e) => setLocalQty(e.target.value)} onBlur={handleBlur} className="w-7 text-[9px] font-black text-center bg-transparent border-none p-0 dark:text-white focus:ring-0" />
                                <button type="button" onClick={() => updateCartItem(c.id, parseFloat(c.qty) + 1, c.product_unit_id, c.notes)} className="w-5 h-5 flex items-center justify-center text-xs font-bold text-slate-500">+</button>
                            </div>
                        </>
                    )}
                    <button type="button" onClick={() => deleteCart(c.id)} className="text-slate-300 hover:text-red-500 transition-colors ml-1"><IconX size={14} /></button>
                </div>

                {c.product?.type === 'bundle' && c.product?.bundle_items?.length > 0 && (
                    <div className="mt-2 pl-2 border-l-2 border-purple-200 dark:border-purple-900 flex flex-col gap-0.5 bg-purple-50/30 dark:bg-purple-900/10 p-1.5 rounded-r-lg shadow-inner">
                        <p className="text-[7px] font-black text-purple-500 uppercase tracking-tighter mb-0.5 flex items-center gap-1">
                            <IconPoint size={10}/> Isi Paket:
                        </p>
                        {c.product.bundle_items.map((bi, idx) => (
                            <div key={idx} className="flex justify-between text-[8px] font-bold text-slate-500 dark:text-slate-400 uppercase italic">
                                <span className="truncate pr-2">• {bi.title}</span>
                                <span className="shrink-0 whitespace-nowrap">x{parseFloat(bi.pivot?.qty || 1) * c.qty}</span>
                            </div>
                        ))}
                    </div>
                )}

                {showNoteInput && (
                    <div className="mt-2 flex gap-1 animate-in slide-in-from-top-1 duration-200">
                        <input type="text" value={noteValue} onChange={(e) => setNoteValue(e.target.value)} placeholder="Catatan..." className="flex-1 text-[9px] font-bold uppercase bg-slate-50 dark:bg-slate-900 border-none rounded-lg focus:ring-1 focus:ring-primary-500 p-2 dark:text-white" />
                        <button type="button" onClick={handleSaveNote} className="bg-primary-500 text-white p-1.5 rounded-lg active:scale-90 transition-transform"><IconCheck size={14} /></button>
                    </div>
                )}
            </div>
        </div>
    );
};

const Index = ({ carts = [], products: initialProducts, customers = [], discounts = [], paymentSetting = {}, activeShift = null, holds = [], tables = [], categories = [], filters = {}, onlineSettings = [] }) => {
    const { auth, receiptSetting, flash } = usePage().props;
    const [productList, setProductList] = useState(initialProducts.data || []);
    const [nextPageUrl, setNextPageUrl] = useState(initialProducts.next_page_url);
    const [loadingMore, setLoadingMore] = useState(false);
    const [search, setSearch] = useState(filters.search || "");
    const [selectedCategory, setSelectedCategory] = useState(filters.category_id || "all"); 
    const [viewMode, setViewMode] = useState("grid"); 
    const [cash, setCash] = useState(0);

    const [selectedCustomer, setSelectedCustomer] = useState(""); 
    const [isDarkMode, setIsDarkMode] = useState(localStorage.getItem('theme') === 'dark');
    const [showModalHold, setShowModalHold] = useState(false);
    const [showCashOut, setShowCashOut] = useState(false);
    const [showCloseShift, setShowCloseShift] = useState(false);
    const [activeHoldId, setActiveHoldId] = useState(null); 
    const [showCartDrawer, setShowCartDrawer] = useState(false);
    const [selectedTable, setSelectedTable] = useState("");
    const [searchHold, setSearchHold] = useState("");
    const [selectedPlatform, setSelectedPlatform] = useState(null);

    // --- STATE PAYMENT GATEWAY ---
    const [showManualQrisModal, setShowManualQrisModal] = useState(false);
    const [isPaymentLoading, setIsPaymentLoading] = useState(false);

    const { data: shiftData, setData: setShiftData, post: postShift } = useForm({ starting_cash: 0 });
    const { data: closeShiftData, setData: setCloseShiftData, post: postCloseShift, processing: processingCloseShift } = useForm({ total_cash_physical: 0 });
    
    const [cashOutName, setCashOutName] = useState("");
    const [cashOutAmount, setCashOutAmount] = useState("");
    const [isCashOutLoading, setIsCashOutLoading] = useState(false);

    const filteredHolds = useMemo(() => {
        return (holds || []).filter(h => 
            h.customer_name?.toLowerCase().includes(searchHold.toLowerCase()) ||
            h.queue_number?.toLowerCase().includes(searchHold.toLowerCase()) ||
            (h.table?.name || "").toLowerCase().includes(searchHold.toLowerCase())
        );
    }, [holds, searchHold]);

    const currentActiveHold = useMemo(() => holds.find(h => h.id === activeHoldId), [holds, activeHoldId]);

    // --- INTEGRASI MIDTRANS SNAP SDK ---
    useEffect(() => {
        const snapScriptUrl = paymentSetting.midtrans_production 
            ? "https://app.midtrans.com/snap/snap.js" 
            : "https://app.sandbox.midtrans.com/snap/snap.js";

        const clientKey = paymentSetting.midtrans_client_key;
        
        if (clientKey) {
            const script = document.createElement('script');
            script.src = snapScriptUrl;
            script.setAttribute('data-client-key', clientKey);
            script.async = true;
            document.body.appendChild(script);
            return () => { if (document.body.contains(script)) document.body.removeChild(script); }
        }
    }, [paymentSetting.midtrans_client_key, paymentSetting.midtrans_production]);

    useEffect(() => {
        if (flash.print_invoice && flash.print_invoice.invoice) {
            const cleanTransaction = { 
                ...flash.print_invoice,
                customer_name: flash.print_invoice.customer_name || "UMUM",
                cashier: flash.print_invoice.cashier || { name: auth?.user?.name || "KASIR" },
                details: (flash.print_invoice.details || []).map(d => ({
                    ...d,
                    product_title: d.product?.title || d.product_title || "PRODUK",
                    price: parseFloat(d.price || 0),
                    qty: parseFloat(d.qty || 0),
                    product: d.product
                }))
            };

            const isAPK = typeof window !== 'undefined' && !!window.bluetoothSerial;

            if (isAPK) {
                const timer = setTimeout(() => {
                    toast.promise(smartPrint(cleanTransaction, receiptSetting, 'transaction'), {
                        loading: 'Cetak Struk Bluetooth...',
                        success: 'Selesai!',
                        error: (err) => `Printer: ${err}`
                    });
                }, 600);
                return () => clearTimeout(timer);
            } else {
                window.print();
            }
        }

        if (flash.print_shift) {
            const isAPK = typeof window !== 'undefined' && !!window.bluetoothSerial;
            if (isAPK) {
                const timer = setTimeout(() => {
                    toast.promise(smartPrint(flash.print_shift, receiptSetting, 'shift'), {
                        loading: 'Cetak Laporan Shift...',
                        success: 'Laporan Dicetak!',
                        error: (err) => `Printer: ${err}`
                    });
                }, 800);
                return () => clearTimeout(timer);
            }
        }
    }, [flash.print_invoice, flash.print_shift]);

    useEffect(() => {
        if (window.Echo) {
            const channel = window.Echo.channel('orders');
            const forceReload = () => {
                router.reload({ 
                    only: ['holds', 'tables'], 
                    preserveScroll: true,
                    preserveState: true 
                });
            };
            channel.listen('.order.placed', () => {
                forceReload();
                toast.success("Pesanan baru masuk!", { icon: '🔔' });
            });
            channel.listen('.order.deleted', () => {
                forceReload();
            });
            return () => { window.Echo.leave('orders'); };
        }
    }, []);

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

    const getFinalPrice = useCallback((product) => {
        let price = parseFloat(product.sell_price);
        if (selectedPlatform) {
            const markup = (price * parseFloat(selectedPlatform.markup_percent)) / 100;
            price = price + markup + parseFloat(selectedPlatform.additional_fee || 0);
        }
        const autoPromo = (discounts || []).find(d => d.product_id === product.id && !d.bonus_product_id);
        if (autoPromo) {
            if (autoPromo.type === 'percentage') {
                price = price - (price * (parseFloat(autoPromo.value) / 100));
            } else {
                price = price - parseFloat(autoPromo.value);
            }
        }
        return Math.round(price);
    }, [selectedPlatform, discounts]);

    const cartSubtotal = useMemo(() => (carts || []).reduce((acc, c) => acc + parseFloat(c.price || 0), 0), [carts]);

    const activeGlobalDiscount = useMemo(() => {
        const globalPromo = (discounts || [])
            .filter(d => d.product_id === null || d.product_id === "")
            .filter(d => cartSubtotal >= parseFloat(d.min_transaction || 0))
            .sort((a, b) => parseFloat(b.min_transaction) - parseFloat(a.min_transaction))[0];

        if (!globalPromo) return { amount: 0, name: null };
        let discountValue = globalPromo.type === 'percentage' ? cartSubtotal * (parseFloat(globalPromo.value) / 100) : parseFloat(globalPromo.value);
        return { amount: discountValue, name: globalPromo.name };
    }, [cartSubtotal, discounts]);

    const grandTotal = cartSubtotal - activeGlobalDiscount.amount;
    const change = cash - grandTotal;

    const addToCart = (product) => {
        if (!product?.id) return;
        const finalUnitPrice = getFinalPrice(product);
        router.post(route("transactions.addToCart"), { 
            product_id: product.id,
            price: finalUnitPrice,
            online_platform_id: selectedPlatform?.id || null
        }, { preserveScroll: true });
    };

    const updateCartItem = (id, qty, unitId = null, notes = null) => {
        if (qty <= 0) return deleteCart(id);
        router.patch(route("transactions.updateCart", id), { qty, product_unit_id: unitId, notes }, { preserveScroll: true });
    };

    const deleteCart = (id) => router.delete(route("transactions.destroyCart", id), { preserveScroll: true });

    // --- LOGIKA POLLING STATUS PEMBAYARAN ---
    const startPaymentPolling = (invoice) => {
        const interval = setInterval(async () => {
            try {
                const res = await axios.get(route('payment.check', invoice));
                if (res.data.status === 'paid') {
                    clearInterval(interval);
                    Swal.close();
                    setShowManualQrisModal(false);
                    toast.success("Pembayaran Terverifikasi!");
                    router.visit(route('transactions.print', invoice));
                }
            } catch (err) { console.error("Polling error", err); }
        }, 3000);
        return interval;
    };

    const submitTransaction = async (method, paidAmount) => {
        // --- 1. GUARD CLAUSE (PENGECEKAN AWAL) ---
        if (carts.length === 0) return;
        if (!selectedTable) return Swal.fire("Peringatan", "Pilih Tipe Pesanan!", "warning");
        
        // PENCEGAHAN DOUBLE KLIK (LOCK STATUS)
        if (isPaymentLoading) return;
        setIsPaymentLoading(true);

        // --- 2. HANDLING KHUSUS QRIS MANUAL ---
        if (method === 'qris_manual') {
            setIsPaymentLoading(false); // Buka lock karena kita pindah ke modal
            setShowManualQrisModal(true);
            return; 
        }

        // --- 3. LOGIKA PAYLOAD ---
        const finalHoldId = activeHoldId || localStorage.getItem('pending_hold_id');
        const queueNumber = holds.find(h => h.id === finalHoldId)?.queue_number || null;
        let finalCustomerName = typeof selectedCustomer === 'number' ? customers.find(c => c.id === selectedCustomer)?.name : selectedCustomer;

        let totalMarkup = 0;
        let totalFee = 0;
        if (selectedPlatform) {
            totalMarkup = carts.reduce((acc, c) => {
                const basePrice = parseFloat(c.product?.sell_price || 0);
                const markupPerUnit = (basePrice * parseFloat(selectedPlatform.markup_percent)) / 100;
                return acc + (markupPerUnit * parseFloat(c.qty));
            }, 0);
            totalFee = parseFloat(selectedPlatform.additional_fee || 0);
        }

        const payload = {
            customer_name: finalCustomerName,
            customer_id: typeof selectedCustomer === 'number' ? selectedCustomer : null,
            grand_total: grandTotal, 
            cash: paidAmount, 
            change: change, 
            payment_gateway: method, 
            hold_id: finalHoldId, 
            queue_number: queueNumber, 
            table_name: selectedTable === "take_away" ? "TAKE AWAY" : (tables.find(t => t.id == selectedTable)?.name || "DINE IN"),
            online_platform: selectedPlatform?.name || null,
            total_markup: totalMarkup,
            total_fee: totalFee
        };

        // --- 4. EKSEKUSI API ---
        try {
            const response = await axios.post(route("transactions.store"), payload);
            
            // JIKA BUKAN TUNAI (MIDTRANS/GATEWAY)
            if (method !== 'cash' && response.data.payment_url) {
                if (response.data.token && window.snap) {
                    window.snap.pay(response.data.token, {
                        onSuccess: (res) => { 
                            setIsPaymentLoading(false);
                            router.visit(route('transactions.print', response.data.invoice)); 
                        },
                        onPending: (res) => { 
                            setIsPaymentLoading(false);
                            Swal.fire({ title: 'Menunggu Pembayaran', text: 'Silahkan selesaikan transaksi di HP pelanggan.', icon: 'info', showConfirmButton: false });
                            startPaymentPolling(response.data.invoice);
                        },
                        onError: (res) => { setIsPaymentLoading(false); toast.error("Pembayaran Gagal"); },
                        onClose: () => { setIsPaymentLoading(false); toast("Selesaikan pembayaran untuk mencetak struk.", { icon: 'ℹ️' }); }
                    });
                } else {
                    setIsPaymentLoading(false);
                    window.location.href = response.data.payment_url;
                }
            } else {
                // JIKA TUNAI
                setIsPaymentLoading(false);
                toast.success("Transaksi Berhasil");
                router.visit(route('transactions.print', response.data.invoice || payload.invoice)); 
            }
        } catch (err) {
            setIsPaymentLoading(false);
            const msg = err.response?.data?.message || "Terjadi kesalahan pada server.";
            Swal.fire("Gagal", msg, "error");
        }
    };

    const handleSaveOrder = () => {
        if (carts.length === 0) return;
        router.post(route('transactions.hold'), { 
            hold_id: activeHoldId, 
            customer_name: typeof selectedCustomer === 'string' ? selectedCustomer : (currentActiveHold?.customer_name || "Pelanggan"),
            table_id: selectedTable === "take_away" ? null : selectedTable,
            cart_items: carts, 
            total: grandTotal,
            online_platform_id: selectedPlatform?.id || null
        }, {
            onSuccess: () => {
                setActiveHoldId(null); setSelectedTable(""); setShowCartDrawer(false);
                localStorage.removeItem('pending_hold_id');
                Swal.fire({ icon: "success", title: "Pesanan Disimpan", timer: 1500, showConfirmButton: false });
            }
        });
    };

    const handleCashOutSubmit = async (e) => {
        e.preventDefault();
        if(!cashOutName || !cashOutAmount) return toast.error("Isi data dengan lengkap!");
        setIsCashOutLoading(true);
        router.post(route('expenses.store'), {
            name: cashOutName, amount: cashOutAmount
        }, {
            onSuccess: () => {
                setShowCashOut(false); setCashOutName(""); setCashOutAmount("");
                Swal.fire('Berhasil', 'Kas keluar dicatat', 'success');
            },
            onFinish: () => setIsCashOutLoading(false)
        });
    };

    const handleResumeHold = (holdId) => {
        const hold = holds.find(h => h.id === holdId);
        if (!hold) return;
        setActiveHoldId(holdId); 
        router.post(route('transactions.resume', holdId), {}, {
            onSuccess: () => {
                setSelectedTable(hold.table_id ? hold.table_id.toString() : "take_away");
                setShowModalHold(false);
                if (window.innerWidth < 1024) setShowCartDrawer(true);
            }
        });
    };

    const toggleTheme = () => {
        const next = !isDarkMode;
        setIsDarkMode(next);
        document.documentElement.classList.toggle('dark', next);
        localStorage.setItem('theme', next ? 'dark' : 'light');
    };

    const loadMoreProducts = async () => {
        if (!nextPageUrl || loadingMore) return;
        setLoadingMore(true);
        try {
            const res = await axios.get(nextPageUrl, { params: { search, category_id: selectedCategory } });
            setProductList(prev => [...prev, ...res.data.data]);
            setNextPageUrl(res.data.next_page_url);
        } catch (e) { console.error(e); } finally { setLoadingMore(false); }
    };

    useEffect(() => { setProductList(initialProducts.data || []); setNextPageUrl(initialProducts.next_page_url); }, [initialProducts]);

    return (
        <div className="bg-slate-50 dark:bg-slate-950 min-h-screen">
            <Head title="Kasir Toko" />
            
            {!activeShift && (
                <div className="fixed inset-0 z-[200] flex items-center justify-center bg-slate-900/60 backdrop-blur-md p-4 text-center">
                    <div className="bg-white dark:bg-slate-900 w-full max-w-md rounded-3xl p-8 shadow-2xl animate-in zoom-in-95 duration-300">
                        <IconDoorEnter size={48} className="mx-auto text-primary-500 mb-4" />
                        <h2 className="text-xl font-black dark:text-white uppercase mb-6 tracking-tighter">Buka Shift Kasir</h2>
                        <input type="number" className="w-full pt-6 pb-4 bg-slate-50 dark:bg-slate-800 rounded-2xl text-center text-xl font-black border-none focus:ring-0 dark:text-white" value={shiftData.starting_cash} onChange={e => setShiftData('starting_cash', e.target.value)} required />
                        <button type="button" onClick={(e) => { e.preventDefault(); postShift(route('shifts.store')); }} className="w-full py-4 mt-6 bg-primary-600 text-white rounded-2xl font-black uppercase shadow-lg active:scale-95 transition-all">Mulai Bertugas</button>
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
                        <button type="button" onClick={() => setShowCashOut(true)} className="flex px-4 py-2 bg-orange-100 text-orange-600 dark:bg-orange-950/30 dark:text-orange-400 rounded-xl text-[10px] font-black uppercase items-center gap-2 border border-orange-200 shadow-sm hover:bg-orange-200 transition-colors"><IconCashOff size={16}/> <span className="hidden sm:inline">Kas Keluar</span></button>
                        <button type="button" onClick={() => setShowModalHold(true)} className="relative p-2.5 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-600 border dark:border-slate-700">
                            <IconClockPause size={20} />
                            {holds?.length > 0 && <span className="absolute -top-1 -right-1 h-5 w-5 bg-red-500 text-white text-[9px] font-black flex items-center justify-center rounded-full shadow-md animate-bounce">{holds.length}</span>}
                        </button>
                        <button type="button" onClick={toggleTheme} className="p-2.5 rounded-xl bg-slate-100 dark:bg-slate-800 border dark:border-slate-700">{isDarkMode ? <IconSun size={20} className="text-yellow-400" /> : <IconMoon size={20} />}</button>
                        <button type="button" onClick={() => carts.length > 0 ? Swal.fire("Peringatan", "Kosongkan keranjang dulu!", "warning") : setShowCloseShift(true)} className="p-2.5 rounded-xl bg-red-50 dark:bg-red-950/30 text-red-500 border border-red-100"><IconPower size={20} /></button>
                    </div>
                </header>

                <main className="flex flex-1 overflow-hidden lg:flex-row flex-col relative">
                    <div className="flex-1 flex flex-col min-w-0">
                        <div className="p-3 md:p-4 bg-white/50 dark:bg-slate-900/50 border-b dark:border-slate-800 flex flex-wrap items-center gap-4 backdrop-blur-sm">
                            <div className="relative flex-1 min-w-[200px] max-w-xl">
                                <IconSearch className="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400" size={18} />
                                <input type="text" value={search} onChange={handleSearchChange} placeholder="Cari Produk..." className="w-full pl-11 pr-4 py-2.5 rounded-xl border-none bg-white dark:bg-slate-800 dark:text-white shadow-sm ring-1 ring-slate-200 dark:ring-slate-700 focus:ring-2 focus:ring-primary-500" />
                            </div>
                            <div className={`flex items-center gap-2 px-3 py-2 bg-white dark:bg-slate-800 rounded-xl border shadow-sm transition-all ${selectedPlatform ? 'border-primary-500 ring-1 ring-primary-500' : 'dark:border-slate-700'}`}>
                                <IconWorld size={18} className={selectedPlatform ? "text-primary-500 animate-pulse" : "text-slate-400"} />
                                <select className="bg-transparent border-none text-[10px] font-black uppercase focus:ring-0 dark:text-white p-0 pr-8" value={selectedPlatform?.id || ''} onChange={(e) => setSelectedPlatform(onlineSettings.find(o => o.id == e.target.value) || null)}>
                                    <option value="">HARGA REGULER</option>
                                    {onlineSettings.map(opt => <option key={opt.id} value={opt.id}>{opt.name} (+{opt.markup_percent}%)</option>)}
                                </select>
                            </div>
                            <div className="flex bg-white dark:bg-slate-800 p-1 rounded-2xl ring-1 ring-slate-200 dark:ring-slate-700 shadow-sm shrink-0">
                                <button type="button" onClick={() => setViewMode('grid')} className={`p-2 rounded-xl transition-all ${viewMode === 'grid' ? 'bg-primary-500 text-white' : 'text-slate-400'}`}><IconLayoutGrid size={18} /></button>
                                <button type="button" onClick={() => setViewMode('list')} className={`p-2 rounded-xl transition-all ${viewMode === 'list' ? 'bg-primary-500 text-white' : 'text-slate-400'}`}><IconList size={18} /></button>
                            </div>
                        </div>

                        <div className="bg-white/30 dark:bg-slate-900/30 border-b dark:border-slate-800 p-2 flex items-center gap-2 overflow-x-auto scrollbar-hide shrink-0">
                            <button type="button" onClick={() => handleCategoryChange('all')} className={`px-5 py-2 rounded-2xl font-black uppercase text-[10px] border transition-all ${selectedCategory === 'all' ? 'bg-primary-600 text-white' : 'bg-white dark:bg-slate-800 text-slate-500 border-slate-200 dark:border-slate-700'}`}>Semua</button>
                            {categories.map((cat) => (
                                <button key={cat.id} type="button" onClick={() => handleCategoryChange(cat.id.toString())} className={`px-5 py-2 rounded-2xl font-black uppercase text-[10px] border transition-all ${selectedCategory === cat.id.toString() ? 'bg-primary-600 text-white' : 'bg-white dark:bg-slate-800 text-slate-500 border-slate-200 dark:border-slate-700'}`}>{cat.name}</button>
                            ))}
                        </div>

                        <div className="flex-1 overflow-y-auto p-4 custom-scrollbar">
                            <div className={viewMode === 'grid' ? "grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 xl:grid-cols-5 gap-4" : "flex flex-col gap-2"}>
                                {productList.map((p) => {
                                    const finalPrice = getFinalPrice(p);
                                    const isDisc = finalPrice < p.sell_price;
                                    const hasRecipe = p.recipes && p.recipes.length > 0;
                                    
                                    return (
                                        <button key={p.id} type="button" onClick={() => addToCart(p)} className={`relative text-left group border dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm rounded-[1.5rem] p-3 transition-all active:scale-95 hover:shadow-xl ${viewMode === 'list' ? 'flex items-center gap-4' : 'flex flex-col'}`}>
                                            <div className="absolute top-2 right-2 z-10 flex flex-col gap-1">
                                                {p.type === 'bundle' && <div className="bg-purple-500 text-white p-1 rounded-lg shadow-md"><IconBoxSeam size={12} /></div>}
                                                {hasRecipe && (
                                                    <div className="bg-emerald-500 text-white px-1.5 py-0.5 rounded-lg shadow-md flex items-center gap-1 border border-emerald-400">
                                                        <IconToolsKitchen2 size={10} />
                                                        <span className="text-[7px] font-black uppercase">Resep</span>
                                                    </div>
                                                )}
                                            </div>
                                            
                                            {isDisc && <div className="absolute -top-1 -left-1 z-10 bg-emerald-500 text-white p-1.5 rounded-xl shadow-lg border-2 border-white dark:border-slate-900 animate-pulse"><IconGift size={12} /></div>}
                                            
                                            <div className={`relative overflow-hidden bg-slate-50 dark:bg-slate-800 shrink-0 ${viewMode === 'list' ? 'w-14 h-14 rounded-xl' : 'aspect-square rounded-xl md:rounded-2xl mb-2'}`}>
                                                <img src={p.image ? (p.image.startsWith('http') ? p.image : `/storage/products/${p.image}`) : `https://ui-avatars.com/api/?name=${p.title}`} className="w-full h-full object-cover transition-transform group-hover:scale-110" />
                                            </div>
                                            <div className="flex-1 min-w-0">
                                                <h3 className="font-black dark:text-white uppercase text-[10px] line-clamp-1">{p.title}</h3>
                                                <div className="flex justify-between items-end mt-1">
                                                    <p className={`text-[8px] font-bold uppercase ${hasRecipe ? 'text-emerald-500 italic' : 'text-slate-400'}`}>
                                                        {hasRecipe ? 'Bahan Tersedia' : `Stok: ${p.stock}`}
                                                    </p>
                                                    <div className="text-right">
                                                        {isDisc && <p className="text-[7px] text-red-500 line-through leading-none mb-0.5">{formatPrice(p.sell_price)}</p>}
                                                        <p className={`font-black text-xs ${isDisc || selectedPlatform ? 'text-emerald-600' : 'text-primary-600'}`}>{formatPrice(finalPrice)}</p>
                                                    </div>
                                                </div>
                                            </div>
                                        </button>
                                    );
                                })}
                            </div>
                            {nextPageUrl && <button type="button" onClick={loadMoreProducts} disabled={loadingMore} className="my-8 mx-auto flex items-center gap-2 px-8 py-3 bg-white dark:bg-slate-800 dark:text-white rounded-full text-[10px] font-black uppercase shadow-md active:scale-95 transition-all">{loadingMore ? <IconLoader className="animate-spin" size={14} /> : 'Muat Lebih'}</button>}
                        </div>
                    </div>

                    <aside className={`fixed inset-x-0 bottom-0 z-40 lg:relative lg:inset-auto lg:z-auto w-full lg:w-[380px] xl:w-[420px] bg-white dark:bg-slate-900 border-t lg:border-t-0 lg:border-l dark:border-slate-800 flex flex-col shadow-2xl transition-all duration-500 ease-in-out ${showCartDrawer ? 'h-[90vh]' : 'h-16 lg:h-full'}`}>
                        <div onClick={() => window.innerWidth < 1024 && setShowCartDrawer(!showCartDrawer)} className="h-16 p-4 border-b dark:border-slate-800 flex justify-between items-center bg-slate-50/80 dark:bg-slate-800/50 cursor-pointer lg:cursor-default shrink-0">
                             <div className="flex items-center gap-3"><IconShoppingCart size={24} className="text-primary-500"/><span className="font-black dark:text-white uppercase text-xs italic">Keranjang Belanja</span></div>
                             {!showCartDrawer && <div className="lg:hidden font-black text-primary-600">{formatPrice(grandTotal)}</div>}
                             <div className="lg:hidden">{showCartDrawer ? <IconChevronDown /> : <IconChevronUp className="animate-bounce" />}</div>
                        </div>

                        <div className={`flex flex-col flex-1 overflow-hidden transition-opacity duration-300 ${!showCartDrawer && window.innerWidth < 1024 ? 'opacity-0 invisible' : 'opacity-100 visible'}`}>
                            <div className="p-4 border-b dark:border-slate-800 space-y-3 bg-slate-50/50 dark:bg-slate-800/30">
                                <select value={typeof selectedCustomer === 'number' ? selectedCustomer : ""} onChange={(e) => setSelectedCustomer(e.target.value ? Number(e.target.value) : "")} className="w-full pl-4 py-2 bg-white dark:bg-slate-800 border-none rounded-xl text-[10px] font-black uppercase shadow-sm focus:ring-primary-500">
                                    <option value="">Pelanggan Umum</option>
                                    {customers.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                                </select>
                                <select value={selectedTable} onChange={(e) => setSelectedTable(e.target.value)} className={`w-full pl-4 py-2 border-none rounded-xl text-[10px] font-black uppercase shadow-sm ${selectedTable ? 'bg-primary-50 text-primary-600 font-black' : 'bg-white dark:bg-slate-800'}`}>
                                    <option value="">-- Pilih Tipe Pesanan --</option>
                                    <option value="take_away">Bawa Pulang</option>
                                    {(tables || []).filter(t => t.status === 'available' || t.id == (currentActiveHold?.table_id)).map((t) => <option key={t.id} value={t.id}>Meja: {t.name}</option>)}
                                </select>
                            </div>

                            <div className="flex-1 overflow-y-auto p-4 custom-scrollbar">
                                {carts.length === 0 ? <div className="h-full flex items-center justify-center opacity-10 font-black text-xs italic text-center uppercase">Keranjang Kosong</div> : carts.map((c) => <CartItem key={c.id} c={c} updateCartItem={updateCartItem} deleteCart={deleteCart} />)}
                            </div>

                            <div className="p-4 md:p-6 bg-white dark:bg-slate-950 border-t dark:border-slate-800 space-y-4 shadow-xl shrink-0">
                                {activeGlobalDiscount.amount > 0 && (
                                    <div className="flex justify-between items-center p-3 bg-emerald-50 dark:bg-emerald-950/30 border border-emerald-100 dark:border-emerald-900 rounded-xl animate-pulse">
                                        <div className="flex items-center gap-2">
                                            <IconTag size={16} className="text-emerald-500" />
                                            <span className="text-[10px] font-black text-emerald-600 dark:text-emerald-400 uppercase tracking-tighter">Potongan Belanja</span>
                                        </div>
                                        <span className="text-[10px] font-black text-emerald-600 dark:text-emerald-400">-{formatPrice(activeGlobalDiscount.amount)}</span>
                                    </div>
                                )}

                                <div className="flex justify-between items-end">
                                    <span className="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1 leading-none">Total Bayar</span>
                                    <span className="text-3xl font-black text-primary-600 italic tracking-tighter">{formatPrice(grandTotal)}</span>
                                </div>
                                <div className="grid grid-cols-2 gap-3">
                                    <div className="relative"><span className="absolute top-2 left-3 text-[7px] font-black text-slate-400 uppercase leading-none">Bayar</span><input type="number" value={cash || ''} onChange={(e) => setCash(Number(e.target.value))} placeholder="0" className="w-full pt-5 pb-2 px-3 text-base font-black rounded-2xl border-none bg-slate-100 dark:bg-slate-800 dark:text-white focus:ring-0" /></div>
                                    <div className="bg-slate-50 dark:bg-slate-800 rounded-2xl p-2 text-center flex flex-col justify-center border dark:border-slate-700 leading-none"><span className="text-[7px] font-black text-slate-400 uppercase mb-1 leading-none">Kembali</span><span className={`text-xs font-black truncate leading-none ${change >= 0 ? 'text-green-500' : 'text-red-500'}`}>{formatPrice(change)}</span></div>
                                </div>
                                <div className="grid grid-cols-2 gap-3">
                                    <button 
                                        type="button" 
                                        onClick={(e) => { e.stopPropagation(); submitTransaction('cash', cash); }} 
                                        disabled={carts.length === 0 || cash < grandTotal || isPaymentLoading} 
                                        className="py-4 bg-slate-900 dark:bg-slate-800 text-white font-black text-[10px] rounded-2xl uppercase active:scale-95 disabled:opacity-50 transition-all shadow-lg hover:bg-black flex items-center justify-center gap-2"
                                    >
                                        <IconCash size={18} /> TUNAI
                                    </button>
                                    
                                    <button 
                                        type="button"
                                        onClick={(e) => { 
                                            e.stopPropagation();
                                            const gw = paymentSetting.default_gateway;
                                            submitTransaction(gw === 'cash' ? 'midtrans' : gw, grandTotal);
                                        }} 
                                        disabled={carts.length === 0 || isPaymentLoading} 
                                        className="py-4 bg-primary-600 text-white font-black text-[10px] rounded-2xl uppercase active:scale-95 disabled:opacity-50 transition-all shadow-lg hover:bg-primary-700 flex items-center justify-center gap-2"
                                    >
                                        {isPaymentLoading ? <IconLoader className="animate-spin" size={18} /> : <><IconQrcode size={18} /> Non Tunai</>}
                                    </button>
                                </div>
                                <button type="button" onClick={handleSaveOrder} disabled={carts.length === 0 || !selectedTable} className="w-full py-3 bg-white dark:bg-slate-800 border-2 border-primary-500 text-primary-600 font-black text-[10px] rounded-2xl transition-all flex items-center justify-center gap-2 hover:bg-primary-500 hover:text-white shadow-sm active:scale-95"><IconDeviceFloppy size={18} /> {activeHoldId ? "PERBARUI" : "SIMPAN"} PESANAN</button>
                            </div>
                        </div>
                    </aside>
                </main>
            </div>

            {/* MODAL HOLD ANTREAN */}
            {showModalHold && (
                <div className="fixed inset-0 z-[150] flex flex-col bg-slate-50/95 dark:bg-slate-950/95 backdrop-blur-md animate-in fade-in duration-300">
                    <div className="h-20 bg-white dark:bg-slate-900 border-b dark:border-slate-800 flex items-center justify-between px-6 md:px-10 shrink-0 shadow-sm">
                        <div className="flex items-center gap-4">
                            <div className="p-3 bg-primary-500 text-white rounded-2xl shadow-lg shadow-primary-500/20"> 
                                <IconClockPause size={28} /> 
                            </div>
                            <div>
                                <h3 className="text-xl font-black uppercase dark:text-white italic tracking-tighter">Antrean Pesanan Aktif</h3>
                                <p className="text-[10px] font-black text-slate-400 uppercase tracking-widest leading-none mt-1">{filteredHolds.length} Pesanan Ditemukan</p>
                            </div>
                        </div>
                        <div className="hidden md:flex relative w-full max-w-md mx-8">
                            <IconSearch className="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400" size={18} />
                            <input type="text" placeholder="Cari Nama / No Antrean / Meja..." className="w-full pl-11 pr-4 py-3 rounded-2xl border-none bg-slate-100 dark:bg-slate-800 dark:text-white focus:ring-2 focus:ring-primary-500 transition-all shadow-inner" value={searchHold} onChange={(e) => setSearchHold(e.target.value)} />
                        </div>
                        <button type="button" onClick={() => setShowModalHold(false)} className="p-3 bg-red-50 dark:bg-red-950/30 text-red-500 rounded-2xl hover:bg-red-500 hover:text-white transition-all"> <IconX size={24} /> </button>
                    </div>
                    
                    <div className="flex-1 overflow-y-auto p-6 md:p-10 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5 gap-6 custom-scrollbar">
                        {filteredHolds.map((h) => (
                            <div key={h.id} className="group flex flex-col bg-white dark:bg-slate-900 rounded-[2.5rem] border border-slate-200 dark:border-slate-800 shadow-sm transition-all hover:shadow-2xl hover:border-primary-500 relative overflow-hidden">
                                <div className="absolute top-0 left-0 w-full h-1.5 bg-primary-500 opacity-20 group-hover:opacity-100 transition-opacity" />
                                <div className="p-6 flex flex-col h-full">
                                    <div className="flex justify-between items-start mb-4">
                                        <span className="bg-slate-900 dark:bg-slate-800 text-white px-3 py-1 rounded-xl font-black text-[10px] tracking-tighter italic">#{h.queue_number}</span>
                                        <div className="text-right flex items-center gap-1 text-orange-500">
                                            <IconArmchair size={14} />
                                            <span className="text-[10px] font-black uppercase italic tracking-widest">{h.table?.name || 'TAKE AWAY'}</span>
                                        </div>
                                    </div>
                                    <h4 className="font-black dark:text-white uppercase mb-4 text-base line-clamp-1 italic group-hover:text-primary-500 transition-colors">{h.customer_name}</h4>
                                    <div className="flex-1 space-y-3 mb-6 bg-slate-50 dark:bg-slate-800/40 p-4 rounded-[1.5rem] border border-slate-100 dark:border-slate-700/50 overflow-y-auto max-h-[180px] custom-scrollbar shadow-inner">
                                        {(h.cart_data || []).map((item, idx) => (
                                            <div key={idx} className="flex flex-col border-b border-slate-200/50 dark:border-slate-700/50 last:border-none pb-2 mb-2 last:pb-0 last:mb-0">
                                                <div className="flex justify-between text-[10px] font-black dark:text-white uppercase leading-tight">
                                                    <span className="truncate max-w-[80%] flex items-center gap-1.5">
                                                        <div className="w-1.5 h-1.5 rounded-full bg-primary-500 shrink-0" />
                                                        {item.product?.title || item.product_title || "PRODUK"}
                                                        {item.product?.type === 'bundle' && <IconBoxSeam size={11} className="text-purple-500 shrink-0" />}
                                                        {item.product?.recipes?.length > 0 && <IconToolsKitchen2 size={11} className="text-emerald-500 shrink-0" />}
                                                    </span>
                                                    <span className="text-primary-500 font-black">x{item.qty}</span>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                    <div className="flex gap-2">
                                        <button type="button" onClick={() => {
                                            const billData = { ...h, details: (h.cart_data || []).map(item => ({ ...item, product_title: item.product?.title || "PRODUK", price: parseFloat(item.price), product: item.product })), grand_total: h.total };
                                            if (window.bluetoothSerial) {
                                                toast.promise(smartPrint(billData, receiptSetting, 'transaction'), { loading: 'Cetak Bill Bluetooth...', success: 'Selesai!', error: 'Gagal' });
                                            } else {
                                                toast.promise(printBillUsb(billData, receiptSetting), { loading: 'Cetak Bill USB...', success: 'Selesai!', error: 'Gagal' });
                                            }
                                        }} className="p-4 bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400 rounded-2xl hover:bg-primary-50 dark:hover:bg-primary-950/30 border dark:border-slate-700 transition-all active:scale-90"> <IconPrinter size={20}/> </button>
                                        <button type="button" onClick={() => handleResumeHold(h.id)} className="flex-1 py-4 bg-primary-600 text-white rounded-2xl font-black uppercase text-[11px] shadow-lg shadow-primary-500/20 active:scale-95 hover:bg-primary-700 transition-all flex items-center justify-center gap-2">
                                            <IconCash size={18} /> Bayar
                                        </button>
                                        <button type="button" onClick={() => { 
                                            Swal.fire({ title: 'Hapus Pesanan?', icon: 'warning', showCancelButton: true, confirmButtonColor: '#ef4444' }).then(res => { 
                                                if(res.isConfirmed) router.delete(route('transactions.destroyHold', h.id), {
                                                    onSuccess: () => router.reload({ only: ['holds', 'tables'] })
                                                });
                                            }) 
                                        }} className="p-4 bg-red-50 dark:bg-red-950/30 text-red-600 rounded-2xl hover:bg-red-500 hover:text-white transition-all active:scale-90"> <IconTrash size={20}/> </button>
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            )}

            {/* MODAL QRIS MANUAL */}
            {showManualQrisModal && (
                <div className="fixed inset-0 z-[150] flex items-center justify-center p-4 bg-black/80 backdrop-blur-md animate-in zoom-in-95 duration-300">
                    <div className="bg-white dark:bg-slate-900 rounded-[2.5rem] p-6 max-w-sm w-full border dark:border-slate-800 shadow-2xl text-center">
                        <div className="flex justify-between items-center mb-6">
                            <h3 className="text-lg font-black uppercase dark:text-white italic tracking-tighter">Scan QRIS Toko</h3>
                            <button type="button" onClick={() => setShowManualQrisModal(false)} className="text-slate-400 hover:text-red-500"><IconX /></button>
                        </div>
                        <div className="bg-slate-50 dark:bg-slate-800 p-4 rounded-3xl mb-6 shadow-inner border dark:border-slate-700 flex items-center justify-center">
                            {paymentSetting.qris_manual_image ? (
                                <img src={`/storage/payments/${paymentSetting.qris_manual_image}`} alt="QRIS MANUAL" className="w-full max-w-[240px] h-auto rounded-xl shadow-md border-4 border-white" />
                            ) : (
                                <div className="py-10 text-slate-300 flex flex-col items-center">
                                    <IconPhoto size={48} />
                                    <span className="text-[10px] font-bold mt-2">Gambar QRIS belum diunggah</span>
                                </div>
                            )}
                        </div>
                        <div className="mb-6 bg-primary-50 dark:bg-primary-950/20 py-3 rounded-2xl border border-primary-100 dark:border-primary-900">
                            <p className="text-[10px] font-black text-slate-400 uppercase tracking-widest leading-none mb-1">Total Bayar</p>
                            <h2 className="text-2xl font-black text-primary-600 italic tracking-tighter">{formatPrice(grandTotal)}</h2>
                        </div>
                        <button 
                            type="button"
                            onClick={() => {
                                Swal.fire({ title: 'Konfirmasi Bayar?', text: 'Klik OK jika Anda sudah menerima dana dari pelanggan.', icon: 'question', showCancelButton: true }).then(res => {
                                    if(res.isConfirmed) {
                                        submitTransaction('qris_manual', grandTotal);
                                        setShowManualQrisModal(false);
                                    }
                                })
                            }} 
                            className="w-full py-4 bg-emerald-500 hover:bg-emerald-600 text-white rounded-2xl font-black uppercase text-[10px] shadow-lg active:scale-95 transition-all flex items-center justify-center gap-2"
                        >
                            <IconCheck size={18} /> Konfirmasi Sudah Bayar
                        </button>
                    </div>
                </div>
            )}

            {/* MODAL KAS KELUAR */}
            {showCashOut && (
                <div className="fixed inset-0 z-[150] flex items-center justify-center p-4 bg-black/80 backdrop-blur-md animate-in fade-in duration-300">
                    <div className="bg-white dark:bg-slate-900 rounded-[2.5rem] p-8 max-w-md w-full border dark:border-slate-800 shadow-2xl">
                         <div className="flex items-center gap-4 mb-6"><div className="p-3 bg-orange-100 dark:bg-orange-900/30 text-orange-600 rounded-2xl"><IconCashOff size={24}/></div><h3 className="text-lg font-black uppercase dark:text-white italic tracking-tighter">Catat Kas Keluar</h3></div>
                         <div className="space-y-4">
                            <div>
                                <label className="block text-[8px] font-black uppercase text-slate-400 mb-1 ml-4 tracking-widest">Keterangan</label>
                                <input type="text" placeholder="Contoh: Beli Air Galon" className="w-full p-4 bg-slate-50 dark:bg-slate-800 rounded-2xl border-none dark:text-white text-sm font-bold focus:ring-primary-500 shadow-inner" value={cashOutName} onChange={e => setCashOutName(e.target.value)} />
                            </div>
                            <div>
                                <label className="block text-[8px] font-black uppercase text-slate-400 mb-1 ml-4 tracking-widest">Nominal (Rp)</label>
                                <input type="number" placeholder="0" className="w-full p-4 bg-slate-50 dark:bg-slate-800 rounded-2xl border-none dark:text-white text-sm font-black focus:ring-primary-500 shadow-inner" value={cashOutAmount} onChange={e => setCashOutAmount(e.target.value)} />
                            </div>
                            <div className="flex gap-3 mt-4">
                                <button type="button" onClick={() => setShowCashOut(false)} className="flex-1 py-4 font-black uppercase text-[10px] text-slate-400">Batal</button>
                                <button type="button" onClick={handleCashOutSubmit} disabled={isCashOutLoading || !cashOutName || !cashOutAmount} className="flex-[2] py-4 bg-orange-500 text-white rounded-2xl font-black uppercase text-[10px] shadow-lg transition-all active:scale-95 disabled:opacity-50">
                                    {isCashOutLoading ? <IconLoader className="animate-spin inline mr-2" size={18} /> : <IconCheck className="inline mr-2" size={18} />}
                                    Simpan Kas Keluar
                                </button>
                            </div>
                         </div>
                    </div>
                </div>
            )}

            {/* MODAL TUTUP SHIFT */}
            {showCloseShift && (
                <div className="fixed inset-0 z-[150] flex items-center justify-center p-4 bg-black/80 backdrop-blur-md animate-in fade-in duration-300">
                    <div className="bg-white dark:bg-slate-900 rounded-[3rem] p-10 max-w-md w-full border dark:border-slate-800 shadow-2xl text-center">
                        <IconDoorExit size={56} className="mx-auto text-red-500 mb-6" />
                        <h3 className="text-xl font-black uppercase mb-2 dark:text-white tracking-tighter">Tutup Kasir?</h3>
                        <p className="text-[10px] text-slate-500 mb-8 font-black uppercase italic leading-tight text-center px-4">Sistem akan mencetak laporan sesi Anda. Pastikan uang laci sudah dihitung.</p>
                        <div className="relative mb-6 text-left">
                            <span className="absolute top-2 left-4 text-[7px] font-black text-slate-400 uppercase tracking-widest">Uang Fisik di Laci (Rp)</span>
                            <input type="number" className="w-full pt-6 pb-4 bg-slate-50 dark:bg-slate-800 rounded-2xl text-center text-xl font-black border-none focus:ring-0 dark:text-white shadow-inner" value={closeShiftData.total_cash_physical} onChange={e => setCloseShiftData('total_cash_physical', e.target.value)} required />
                        </div>
                        <button type="button" onClick={(e) => { e.preventDefault(); postCloseShift(route('shifts.close')); }} disabled={processingCloseShift} className="w-full py-4 bg-red-600 text-white rounded-2xl font-black uppercase text-[11px] shadow-xl flex items-center justify-center gap-2 hover:bg-red-700 transition-all active:scale-95">{processingCloseShift ? <IconLoader size={18} className="animate-spin" /> : <><IconPower size={18} /> Tutup & Cetak Laporan</>}</button>
                        <button type="button" onClick={() => setShowCloseShift(false)} className="text-slate-400 text-[10px] font-black uppercase mt-6 tracking-widest block mx-auto transition-all active:scale-95">Batalkan</button>
                    </div>
                </div>
            )}

            <div id="print-area" className="hidden print:block">
                {flash.print_invoice && (
                    <ThermalReceipt 
                        transaction={{ 
                            ...flash.print_invoice, 
                            queue_number: flash.print_invoice.queue_number || null,
                            details: flash.print_invoice.details?.map(d => ({ 
                                ...d, 
                                product_title: d.product?.title || d.product_title, 
                                unit_name: d.product_unit?.unit_name || d.unit || 'PCS',
                                product: d.product
                            })) 
                        }} 
                        storeName={receiptSetting?.store_name} 
                        storeAddress={receiptSetting?.store_address} 
                    />
                )}
                {flash.print_shift && <ShiftReceipt shift={flash.print_shift} storeName={receiptSetting?.store_name} />}
            </div>

            <style>{`.custom-scrollbar::-webkit-scrollbar { width: 4px; height: 4px; } .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 20px; } .dark .custom-scrollbar::-webkit-scrollbar-thumb { background: #334155; } .scrollbar-hide::-webkit-scrollbar { display: none; } @media print { body * { visibility: hidden !important; } #print-area, #print-area * { visibility: visible !important; } #print-area { position: absolute; left: 0; top: 0; width: 100%; display: flex; justify-content: center; } @page { margin: 0; } }`}</style>
        </div>
    );
};

Index.layout = (page) => page;
export default Index;