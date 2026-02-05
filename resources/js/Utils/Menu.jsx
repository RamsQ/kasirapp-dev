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
    IconToolsKitchen2 // Import Icon Baru untuk Resep
} from "@tabler/icons-react";
import hasAnyPermission from "./Permission";
import React from "react";

export default function Menu() {
    // define use page
    const { url } = usePage();

    // define menu navigations
    const menuNavigation = [
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
            ],
        },
        // [1] DATA MANAGEMENT
        {
            title: "Data Management",
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
                    title: "Master Meja",
                    href: route("tables.index"),
                    active: url === "/dashboard/tables" ? true : false,
                    icon: <IconArmchair size={20} strokeWidth={1.5} className="text-orange-500" />,
                    permissions: hasAnyPermission(["dashboard-access"]), 
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
                    permissions: hasAnyPermission(["dashboard-access"]), 
                },
            ],
        },
        // [2] INVENTORY INTELLIGENCE
        {
            title: "Inventory Intelligence",
            details: [
                {
                    title: "Bahan Baku",
                    href: route("ingredients.index"),
                    active: url.startsWith("/dashboard/ingredients"),
                    icon: <IconDatabaseImport size={20} strokeWidth={1.5} className="text-emerald-500" />,
                    permissions: hasAnyPermission(["products-access"]),
                },
                {
                    title: "Resep & HPP Menu", // [BARU]
                    href: route("recipes.index"),
                    active: url.startsWith("/dashboard/recipes"),
                    icon: <IconToolsKitchen2 size={20} strokeWidth={1.5} className="text-orange-500" />,
                    permissions: hasAnyPermission(["products-access"]),
                },
                {
                    title: "Stock In",
                    href: route("stock_in.index"),
                    active: url.startsWith("/dashboard/stock-in"),
                    icon: <IconPackageImport size={20} strokeWidth={1.5} className="text-rose-500" />,
                    permissions: hasAnyPermission(["products-access", "stock_in.index"]),
                },
                {
                    title: "Stock Opname",
                    href: route("stock_opnames.index"),
                    active: url.startsWith("/dashboard/stock-opnames"),
                    icon: <IconAdjustmentsHorizontal size={20} strokeWidth={1.5} className="text-amber-500" />,
                    permissions: hasAnyPermission(["products-access", "stock_opnames.index"]),
                },
            ],
        },
        {
            title: "Transaksi",
            details: [
                {
                    title: "Transaksi",
                    href: route("transactions.index"),
                    active: url === "/dashboard/transactions" ? true : false,
                    icon: <IconShoppingCart size={20} strokeWidth={1.5} />,
                    permissions: hasAnyPermission(["transactions-access"]),
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
        {
            title: "Laporan",
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
                    permissions: hasAnyPermission(["reports-access"]),
                },
                {
                    title: "Laporan Keuangan",
                    href: route("report.finance"),
                    active: url.startsWith("/dashboard/report/finance"),
                    icon: <IconBusinessplan size={20} strokeWidth={1.5} className="text-emerald-500" />,
                    permissions: hasAnyPermission(["reports-access"]),
                },
                {
                    title: "Laporan Keuntungan",
                    href: route("reports.profits.index"),
                    active: url.startsWith("/dashboard/reports/profits"),
                    icon: <IconChartBarPopular size={20} strokeWidth={1.5} />,
                    permissions: hasAnyPermission(["profits-access"]),
                },
                {
                    title: "Laporan Refund",
                    href: route("reports.refund"),
                    active: url.startsWith("/dashboard/reports/refund"),
                    icon: <IconRefresh size={20} strokeWidth={1.5} />,
                    permissions: hasAnyPermission(["reports-access"]),
                },
                {
                    title: "Laporan Shift",
                    href: route("shifts.index"),
                    active: url.startsWith("/dashboard/shifts"),
                    icon: <IconCash size={20} strokeWidth={1.5} />,
                    permissions: hasAnyPermission(["reports-access"]),
                },
            ],
        },
        {
            title: "User Management",
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
        {
            title: "Pengaturan",
            details: [
                {
                    title: "System Settings",
                    href: route("settings.index"),
                    active: url === "/dashboard/settings",
                    icon: <IconSettings size={20} strokeWidth={1.5} className="text-blue-500" />,
                    permissions: hasAnyPermission(["dashboard-access"]),
                },
                {
                    title: "Payment Gateway",
                    href: route("settings.payments.edit"),
                    active: url === "/dashboard/settings/payments",
                    icon: <IconCreditCard size={20} strokeWidth={1.5} />,
                    permissions: hasAnyPermission(["payment-settings-access"]),
                },
                {
                    title: "Setting Struk",
                    href: route("settings.receipt.index"),
                    active: url === "/dashboard/settings/receipt",
                    icon: <IconReceipt size={20} strokeWidth={1.5} />,
                    permissions: hasAnyPermission(["dashboard-access"]),
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