import React, { useCallback, useEffect, useMemo, useState } from "react";
import { Head } from "@inertiajs/react";
import axios from "axios";
import toast from "react-hot-toast";
import {
    IconArrowRight,
    IconChefHat,
    IconClock,
    IconCreditCard,
    IconLoader2,
    IconPlayerPlay,
    IconRefresh,
    IconToolsKitchen2,
} from "@tabler/icons-react";
import DashboardLayout from "@/Layouts/DashboardLayout";

const KITCHEN_STATUSES = [
    { value: "all", label: "Semua" },
    { value: "pending", label: "Menunggu" },
    { value: "preparing", label: "Diproses" },
    { value: "ready", label: "Siap Disajikan" },
];

const formatCurrency = (value = 0) =>
    new Intl.NumberFormat("id-ID", {
        style: "currency",
        currency: "IDR",
        minimumFractionDigits: 0,
    }).format(value || 0);

const formatTime = (value) => {
    if (!value) return "-";
    const date = new Date(value);
    return Number.isNaN(date.getTime())
        ? value
        : new Intl.DateTimeFormat("id-ID", { hour: "2-digit", minute: "2-digit" }).format(date);
};

const paymentLabel = (status) => {
    if (status === "paid") return "Sudah dibayar";
    return "Belum dibayar";
};

const paymentClass = (status) =>
    status === "paid"
        ? "bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-950/30 dark:text-emerald-300 dark:border-emerald-900"
        : "bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-950/30 dark:text-amber-300 dark:border-amber-900";

const KitchenIndex = ({ enabled = false, paymentFilter = "both", orders: initialOrders = [] }) => {
    const [orders, setOrders] = useState(initialOrders || []);
    const [isEnabled, setIsEnabled] = useState(enabled);
    const [currentPaymentFilter, setCurrentPaymentFilter] = useState(paymentFilter);
    const [activeStatus, setActiveStatus] = useState("all");
    const [isRefreshing, setIsRefreshing] = useState(false);

    const refreshOrders = useCallback(async () => {
        if (!enabled) return;

        try {
            setIsRefreshing(true);
            const response = await axios.get(route("kitchen.orders"));
            setIsEnabled(!!response.data.enabled);
            setCurrentPaymentFilter(response.data.payment_filter || "both");
            setOrders(response.data.orders || []);
        } catch (error) {
            console.error("Gagal memperbarui Kitchen Display", error);
        } finally {
            setIsRefreshing(false);
        }
    }, [enabled]);

    useEffect(() => {
        setOrders(initialOrders || []);
        setIsEnabled(enabled);
        setCurrentPaymentFilter(paymentFilter);
    }, [enabled, initialOrders, paymentFilter]);

    useEffect(() => {
        if (!enabled) return undefined;

        const interval = window.setInterval(refreshOrders, 5000);
        const echoChannel = window.Echo?.channel("orders");
        const handleRealtimeOrder = () => refreshOrders();

        echoChannel?.listen(".order.placed", handleRealtimeOrder);
        echoChannel?.listen(".order.updated", handleRealtimeOrder);
        echoChannel?.listen(".order.deleted", handleRealtimeOrder);

        return () => {
            window.clearInterval(interval);
            if (echoChannel) window.Echo.leave("orders");
        };
    }, [enabled, refreshOrders]);

    const visibleOrders = useMemo(
        () => orders.filter((order) => activeStatus === "all" || order.kitchen_status === activeStatus),
        [orders, activeStatus]
    );

    const updateStatus = async (order, status) => {
        try {
            const response = await axios.post(
                route("kitchen.status", { type: order.source_type, id: order.source_id }),
                { status }
            );

            if (status === "completed") {
                setOrders((current) => current.filter((item) => item.key !== order.key));
            } else {
                setOrders((current) =>
                    current.map((item) => (item.key === order.key ? response.data.order : item))
                );
            }
        } catch (error) {
            toast.error(error.response?.data?.message || "Status Kitchen Display gagal diperbarui.");
        }
    };

    if (!isEnabled) {
        return (
            <>
                <Head title="Kitchen Display | LakuPOS" />
                <div className="min-h-[60vh] flex items-center justify-center">
                    <div className="max-w-lg w-full p-10 rounded-[2rem] bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 text-center shadow-sm">
                        <div className="mx-auto w-20 h-20 rounded-3xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-slate-500 dark:text-slate-400 mb-6">
                            <IconToolsKitchen2 size={42} />
                        </div>
                        <h1 className="text-2xl font-black text-slate-900 dark:text-white">Kitchen Display Nonaktif</h1>
                        <p className="mt-3 text-sm text-slate-500 dark:text-slate-400 leading-relaxed">
                            Aktifkan Kitchen Display melalui Settings → System Core untuk menampilkan pesanan dapur.
                        </p>
                    </div>
                </div>
            </>
        );
    }

    return (
        <>
            <Head title="Kitchen Display | LakuPOS" />
            <div className="space-y-6 pb-10">
                <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                    <div className="flex items-center gap-4">
                        <div className="p-3 rounded-2xl bg-orange-500 dark:bg-orange-600 text-white shadow-lg shadow-orange-500/25">
                            <IconChefHat size={28} />
                        </div>
                        <div>
                            <h1 className="text-2xl font-black uppercase italic tracking-tighter text-slate-900 dark:text-white flex items-center gap-2">
                                Kitchen Display
                                {isRefreshing && <IconLoader2 size={17} className="animate-spin text-orange-500" />}
                            </h1>
                            <p className="text-sm text-slate-500 dark:text-slate-400 mt-1">
                                Monitor pesanan dapur secara real-time.
                            </p>
                        </div>
                    </div>
                    <div className="flex items-center gap-3">
                        <span className="px-3 py-2 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 text-[10px] font-black uppercase tracking-wider text-slate-500 dark:text-slate-400">
                            Filter bayar: {currentPaymentFilter === "unpaid" ? "Belum dibayar" : currentPaymentFilter === "paid" ? "Sudah dibayar" : "Semua status"}
                        </span>
                        <button
                            type="button"
                            onClick={refreshOrders}
                            className="p-3 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 text-slate-500 dark:text-slate-400 hover:text-orange-500 transition-colors"
                            title="Refresh sekarang"
                        >
                            <IconRefresh size={18} />
                        </button>
                    </div>
                </div>

                <div className="flex flex-wrap gap-2 p-2 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800">
                    {KITCHEN_STATUSES.map((status) => (
                        <button
                            type="button"
                            key={status.value}
                            onClick={() => setActiveStatus(status.value)}
                            className={`px-4 py-2 rounded-xl text-xs font-black uppercase tracking-wider transition-colors ${
                                activeStatus === status.value
                                    ? "bg-orange-500 dark:bg-orange-600 text-white shadow-md"
                                    : "text-slate-500 dark:text-slate-400 hover:bg-orange-50 dark:hover:bg-orange-950/30"
                            }`}
                        >
                            {status.label}
                            {status.value !== "all" && (
                                <span className="ml-2 opacity-70">
                                    {orders.filter((order) => order.kitchen_status === status.value).length}
                                </span>
                            )}
                        </button>
                    ))}
                </div>

                {visibleOrders.length === 0 ? (
                    <div className="py-20 rounded-[2rem] bg-white dark:bg-slate-900 border border-dashed border-slate-300 dark:border-slate-700 text-center">
                        <IconChefHat size={52} className="mx-auto text-slate-300 dark:text-slate-400 mb-4" />
                        <h2 className="text-lg font-black text-slate-700 dark:text-slate-200">Belum ada pesanan</h2>
                        <p className="text-sm text-slate-500 dark:text-slate-400 mt-1">Pesanan yang masuk akan otomatis muncul di layar ini.</p>
                    </div>
                ) : (
                    <div className="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
                        {visibleOrders.map((order) => (
                            <KitchenOrderCard key={order.key} order={order} onUpdateStatus={updateStatus} />
                        ))}
                    </div>
                )}
            </div>
        </>
    );
};

const KitchenOrderCard = ({ order, onUpdateStatus }) => {
    const nextAction = {
        pending: { label: "Mulai Proses", status: "preparing", icon: IconPlayerPlay },
        preparing: { label: "Tandai Siap", status: "ready", icon: IconToolsKitchen2 },
        ready: { label: "Selesai", status: "completed", icon: IconArrowRight },
    }[order.kitchen_status] || null;
    const ActionIcon = nextAction?.icon;

    return (
        <article className="rounded-[2rem] bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-sm overflow-hidden">
            <div className="p-5 bg-slate-50 dark:bg-slate-800/60 border-b border-slate-200 dark:border-slate-700 flex items-start justify-between gap-3">
                <div>
                    <p className="text-[10px] font-black uppercase tracking-[0.2em] text-orange-500">
                        {order.queue_number || order.invoice || "ORDER"}
                    </p>
                    <h2 className="mt-1 text-lg font-black text-slate-900 dark:text-white uppercase">
                        {order.table_name || "BAWA PULANG"}
                    </h2>
                    <p className="text-xs text-slate-500 dark:text-slate-400 mt-1">{order.customer_name}</p>
                </div>
                <div className="text-right">
                    <span className={`inline-flex items-center gap-1 px-2.5 py-1 rounded-lg border text-[9px] font-black uppercase tracking-wider ${paymentClass(order.payment_status)}`}>
                        <IconCreditCard size={12} /> {paymentLabel(order.payment_status)}
                    </span>
                    <p className="text-[10px] text-slate-500 dark:text-slate-400 font-bold mt-2 flex items-center justify-end gap-1">
                        <IconClock size={12} /> {formatTime(order.created_at)}
                    </p>
                </div>
            </div>

            <div className="p-5 space-y-4">
                <div className="space-y-2">
                    {(order.items || []).map((item, index) => (
                        <div key={`${order.key}-item-${index}`} className="flex items-start justify-between gap-3 text-sm">
                            <div className="min-w-0">
                                <p className="font-bold text-slate-800 dark:text-slate-200 uppercase">{item.name}</p>
                                {item.notes && <p className="text-[10px] text-orange-500 font-black uppercase mt-0.5">Catatan: {item.notes}</p>}
                            </div>
                            <span className="font-black text-slate-900 dark:text-white shrink-0">
                                {item.qty} {item.unit || ""}
                            </span>
                        </div>
                    ))}
                </div>

                <div className="border-t border-slate-100 dark:border-slate-800 pt-3 flex items-center justify-between text-xs">
                    <span className="text-slate-500 dark:text-slate-400">Total pelanggan</span>
                    <span className="font-black text-slate-900 dark:text-white">{formatCurrency(order.grand_total)}</span>
                </div>

                {nextAction && (
                    <button
                        type="button"
                        onClick={() => onUpdateStatus(order, nextAction.status)}
                        className={`w-full flex items-center justify-center gap-2 rounded-xl py-3 text-xs font-black uppercase tracking-wider transition-all active:scale-[0.98] ${
                            order.kitchen_status === "ready"
                                ? "bg-emerald-600 dark:bg-emerald-700 text-white hover:bg-emerald-700"
                                : "bg-orange-500 dark:bg-orange-600 text-white hover:bg-orange-600"
                        }`}
                    >
                        <ActionIcon size={17} /> {nextAction.label}
                    </button>
                )}
            </div>
        </article>
    );
};

KitchenIndex.layout = (page) => <DashboardLayout children={page} />;

export default KitchenIndex;
