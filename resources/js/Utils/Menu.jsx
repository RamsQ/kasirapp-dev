import { usePage } from "@inertiajs/react";
import {
    IconBox,
    IconFolder,
    IconLayout2,
    IconShoppingCart,
    IconTable,
    IconUserBolt,
    IconUserShield,
    IconUsers,
    IconUsersPlus,
    IconRefresh,
    IconReceipt,
    IconTicket,
    IconPackageExport,
    IconCash,
    IconAdjustmentsHorizontal,
    IconAlertTriangle,
    IconBusinessplan,
    IconPigMoney,
    IconSettings,
    IconPackageImport,
    IconChartArrowsVertical,
    IconChartBarPopular,
    IconClockHour6,
    IconCirclePlus,
    IconCreditCard,
    IconBluetooth,
    IconArmchair,
    IconDatabaseImport, 
    IconScale,
    IconToolsKitchen2,
    IconWorld,
    IconRobot,
    IconBellRinging, // Tambahkan Icon Baru untuk Laporan Otomatis
    IconPresentationAnalytics // Analitik Grafis
} from "@tabler/icons-react";
import hasAnyPermission from "./Permission";
import React from "react";

export default function Menu() {
    // define use page
    const { url, props } = usePage();
    const kitchenDisplayEnabled = props?.kitchenDisplayEnabled ?? false;

    // define menu navigations
    const menuNavigation = [
        // [1] OVERVIEW
        {
            title: "Overview",
            details: [
                {
                    title: "Dashboard",
                    href: route("dashboard"),
                    active: url === "/dashboard" ? true : false,
                    icon: <IconLayout2 size={20} strokeWidth={1.5} />,
                    permissions: hasAnyPermission(["dashboard-access"]),
                },
                {
                    // Ringkasan omzet, HPP, laba, jam ramai, dan performa kasir.
                    //
                    // Diletakkan tepat di bawah Dashboard karena ini tujuan
                    // pertama orang membuka aplikasi: bukan "menuju laporan",
                    // tapi "toko saya hari ini bagaimana". Laporan penjualan
                    // di grup Laporan adalah rinciannya kalau perlu turun ke
                    // angka per transaksi.
                    title: "Analitik Grafis",
                    href: route("reports.analytics"),
                    active: url.startsWith("/dashboard/analytics"),
                    icon: <IconPresentationAnalytics size={20} strokeWidth={1.5} className="text-cyan-500" />,
                    permissions: hasAnyPermission(["analytics-access"]),
                },
                {
                    title: "AI Business Coach",
                    href: route("ai.index"),
                    active: url.startsWith("/dashboard/ai-coach"),
                    icon: <IconRobot size={20} strokeWidth={1.5} className="text-primary-500" />,
                    permissions: hasAnyPermission(["ai-access"]),
                },
            ],
        },
        // [2] DATA MANAGEMENT
        // `collapsible` membuat judul grup jadi tombol buka/tutup, bukan teks
        // pasif. Dipakai pada grup yang isinya panjang supaya sidebar tidak
        // memanjang ke bawah tanpa perlu.
        {
            title: "Data Management",
            collapsible: true,
            details: [
                {
                    title: "Kategori",
                    href: route("categories.index"),
                    active: url === "/dashboard/categories" ? true : false,
                    icon: <IconFolder size={20} strokeWidth={1.5} />,
                    permissions: hasAnyPermission(["categories-access"]),
                },
                {
                    title: "Produk",
                    href: route("products.index"),
                    active: url === "/dashboard/products" ? true : false,
                    icon: <IconBox size={20} strokeWidth={1.5} />,
                    permissions: hasAnyPermission(["products-access"]),
                },
                {
                    title: "Harga Online",
                    href: route("online_settings.index"),
                    active: url === "/dashboard/settings/online" ? true : false,
                    icon: <IconWorld size={20} strokeWidth={1.5} className="text-blue-500" />,
                    permissions: hasAnyPermission(["settings.index", "dashboard-access"]),
                },
                {
                    title: "Master Meja",
                    href: route("tables.index"),
                    active: url === "/dashboard/tables" ? true : false,
                    icon: <IconArmchair size={20} strokeWidth={1.5} className="text-orange-500" />,
                    permissions: hasAnyPermission(["tables-access", "products-access"]), 
                },
                {
                    title: "Pelanggan",
                    href: route("customers.index"),
                    active: url === "/dashboard/customers" ? true : false,
                    icon: <IconUsersPlus size={20} strokeWidth={1.5} />,
                    permissions: hasAnyPermission(["customers-access"]),
                },
                {
                    title: "Manajemen Diskon",
                    href: route("discounts.index"),
                    active: url.startsWith("/dashboard/discounts"),
                    icon: <IconTicket size={20} strokeWidth={1.5} />,
                    permissions: hasAnyPermission(["discounts.index", "dashboard-access"]), 
                },
                {
                    title: "CRM Campaigns",
                    href: route("crm.campaigns.index"),
                    active: url.startsWith("/dashboard/crm/campaigns"),
                    icon: <IconBellRinging size={20} strokeWidth={1.5} className="text-pink-500" />,
                    permissions: hasAnyPermission(["crm-access", "crm-manage"]),
                },
            ],
        },
        // [3] INVENTORY INTELLIGENCE
        {
            title: "Inventory Intelligence",
            collapsible: true,
            details: [
                {
                    title: "Bahan Baku",
                    href: route("ingredients.index"),
                    active: url.startsWith("/dashboard/ingredients"),
                    icon: <IconDatabaseImport size={20} strokeWidth={1.5} className="text-emerald-500" />,
                    permissions: hasAnyPermission(["ingredients.index", "products-access"]),
                },
                {
                    title: "Resep & HPP Menu",
                    href: route("recipes.index"),
                    active: url.startsWith("/dashboard/recipes"),
                    icon: <IconToolsKitchen2 size={20} strokeWidth={1.5} className="text-orange-500" />,
                    permissions: hasAnyPermission(["recipes.index", "products-access"]),
                },
                {
                    title: "Stock In",
                    href: route("stock_in.index"),
                    active: url.startsWith("/dashboard/stock-in"),
                    icon: <IconPackageImport size={20} strokeWidth={1.5} className="text-rose-500" />,
                    permissions: hasAnyPermission(["stock_in.index", "products-access"]),
                },
                {
                    title: "Stock Opname",
                    href: route("stock_opnames.index"),
                    active: url.startsWith("/dashboard/stock-opnames"),
                    icon: <IconAdjustmentsHorizontal size={20} strokeWidth={1.5} className="text-amber-500" />,
                    permissions: hasAnyPermission(["stock_opnames.index", "products-access"]),
                },
            ],
        },
        // [4] TRANSAKSI
        {
            title: "Transaksi",
            collapsible: true,
            details: [
                {
                    title: "Transaksi",
                    href: route("transactions.index"),
                    active: url === "/dashboard/transactions" ? true : false,
                    icon: <IconShoppingCart size={20} strokeWidth={1.5} />,
                    permissions: hasAnyPermission(["transactions-access"]),
                },
                {
                    title: "Kitchen Display",
                    href: route("kitchen.index"),
                    active: url.startsWith("/dashboard/kitchen"),
                    icon: <IconToolsKitchen2 size={20} strokeWidth={1.5} className="text-orange-500" />,
                    permissions: kitchenDisplayEnabled && hasAnyPermission(["kitchen-display.index"]),
                },
                {
                    title: "Riwayat Transaksi",
                    href: route("transactions.history"),
                    active: url === "/dashboard/transactions/history" ? true : false,
                    icon: <IconClockHour6 size={20} strokeWidth={1.5} />,
                    permissions: hasAnyPermission(["transactions-access"]),
                },
            ],
        },
        // [5] LAPORAN
        {
            title: "Laporan",
            collapsible: true,
            details: [
                {
                    title: "Laporan Penjualan",
                    href: route("reports.sales.index"),
                    active: url.startsWith("/dashboard/reports/sales"),
                    icon: <IconChartArrowsVertical size={20} strokeWidth={1.5} />,
                    permissions: hasAnyPermission(["reports-access"]),
                },
                {
                    title: "Laporan Produk",
                    href: route("reports.products.index"),
                    active: url.startsWith("/dashboard/reports/products"),
                    icon: <IconPackageExport size={20} strokeWidth={1.5} />,
                    permissions: hasAnyPermission(["reports-access"]),
                },
                {
                    title: "Kontrol Expired",
                    href: route("reports.expired.index"),
                    active: url.startsWith("/dashboard/reports/expired"),
                    icon: <IconAlertTriangle size={20} strokeWidth={1.5} className="text-red-500" />,
                    permissions: hasAnyPermission(["expired-access", "reports-access"]),
                },
                {
                    title: "Laporan Keuangan",
                    href: route("report.finance"),
                    active: url.startsWith("/dashboard/report/finance"),
                    icon: <IconBusinessplan size={20} strokeWidth={1.5} className="text-emerald-500" />,
                    permissions: hasAnyPermission(["finance-access", "reports-access"]),
                },
                {
                    title: "Laporan Keuntungan",
                    href: route("reports.profits.index"),
                    active: url.startsWith("/dashboard/reports/profits"),
                    icon: <IconChartBarPopular size={20} strokeWidth={1.5} />,
                    permissions: hasAnyPermission(["profits-access", "reports-access"]),
                },
                {
                    title: "Laporan Refund",
                    href: route("reports.refund"),
                    active: url.startsWith("/dashboard/reports/refund"),
                    icon: <IconRefresh size={20} strokeWidth={1.5} />,
                    permissions: hasAnyPermission(["reports-access"]),
                },
                {
                    title: "Modal Usaha",
                    href: route("capitals.index"),
                    active: url.startsWith("/dashboard/capitals"),
                    icon: <IconPigMoney size={20} strokeWidth={1.5} className="text-emerald-500" />,
                    permissions: hasAnyPermission(["capitals.index"]),
                },
                {
                    title: "Laporan Shift",
                    href: route("shifts.index"),
                    active: url.startsWith("/dashboard/shifts"),
                    icon: <IconCash size={20} strokeWidth={1.5} />,
                    permissions: hasAnyPermission(["shifts-access", "reports-access"]),
                },
                // BARU: Menu Managed Reports (Laporan Otomatis)
                {
                    title: "Laporan Otomatis",
                    href: route("report-settings.index"),
                    active: url.startsWith("/dashboard/reports/managed"),
                    icon: <IconBellRinging size={20} strokeWidth={1.5} className="text-primary-500" />,
                    permissions: hasAnyPermission(["report_settings.index", "reports-access"]),
                },
            ],
        },
        // [6] USER MANAGEMENT
        {
            title: "User Management",
            collapsible: true,
            details: [
                {
                    title: "Hak Akses",
                    href: route("permissions.index"),
                    active: url === "/dashboard/permissions" ? true : false,
                    icon: <IconUserBolt size={20} strokeWidth={1.5} />,
                    permissions: hasAnyPermission(["permissions-access"]),
                },
                {
                    title: "Akses Group",
                    href: route("roles.index"),
                    active: url === "/dashboard/roles" ? true : false,
                    icon: <IconUserShield size={20} strokeWidth={1.5} />,
                    permissions: hasAnyPermission(["roles-access"]),
                },
                {
                    title: "Pengguna",
                    icon: <IconUsers size={20} strokeWidth={1.5} />,
                    permissions: hasAnyPermission(["users-access"]),
                    subdetails: [
                        {
                            title: "Data Pengguna",
                            href: route("users.index"),
                            icon: <IconTable size={20} strokeWidth={1.5} />,
                            active: url === "/dashboard/users" ? true : false,
                            permissions: hasAnyPermission(["users-access"]),
                        },
                        {
                            title: "Tambah Data Pengguna",
                            href: route("users.create"),
                            icon: <IconCirclePlus size={20} strokeWidth={1.5} />,
                            active: url === "/dashboard/users/create" ? true : false,
                            permissions: hasAnyPermission(["users-create"]),
                        },
                    ],
                },
            ],
        },
        // [7] PENGATURAN
        {
            title: "Pengaturan",
            collapsible: true,
            details: [
                {
                    title: "System Settings",
                    href: route("settings.index"),
                    active: url === "/dashboard/settings",
                    icon: <IconSettings size={20} strokeWidth={1.5} className="text-blue-500" />,
                    permissions: hasAnyPermission(["settings.index", "dashboard-access"]),
                },
                {
                    title: "Payment Gateway",
                    href: route("settings.payments.edit"),
                    active: url === "/dashboard/settings/payments",
                    icon: <IconCreditCard size={20} strokeWidth={1.5} />,
                    permissions: hasAnyPermission(["payment_settings.index", "payment-settings-access"]),
                },
                {
                    title: "Settlement Center",
                    href: route("settings.payment-settlements.index"),
                    active: url.startsWith("/dashboard/settings/payment-settlements"),
                    icon: <IconRefresh size={20} strokeWidth={1.5} className="text-emerald-500" />,
                    permissions: hasAnyPermission(["settings.index", "payment-settings-access", "payment-settlements-access"]),
                },
                {
                    title: "Setting Struk",
                    href: route("settings.receipt.index"),
                    active: url === "/dashboard/settings/receipt",
                    icon: <IconReceipt size={20} strokeWidth={1.5} />,
                    permissions: hasAnyPermission(["receipt_settings.index", "dashboard-access"]),
                },
                {
                    title: "Printer Bluetooth",
                    href: route("settings.bluetooth"),
                    active: url === "/dashboard/settings/bluetooth",
                    icon: <IconBluetooth size={20} strokeWidth={1.5} className="text-emerald-500" />,
                    permissions: hasAnyPermission(["dashboard-access"]),
                },
            ],
        },
    ];

    return menuNavigation;
}