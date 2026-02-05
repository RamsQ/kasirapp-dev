import DashboardLayout from "@/Layouts/DashboardLayout";
import React, { useState } from "react";
import { Head, useForm, usePage, Link } from "@inertiajs/react";
import Button from "@/Components/Dashboard/Button";
import {
    IconDatabaseOff,
    IconCirclePlus,
    IconTrash,
    IconPencilCog,
    IconUser,
    IconShield,
    IconMail,
    IconLayoutGrid,
    IconList,
    IconFaceId, // Tambahan
    IconShieldCheck, // Tambahan
    IconShieldX // Tambahan
} from "@tabler/icons-react";
import Search from "@/Components/Dashboard/Search";
import Table from "@/Components/Dashboard/Table";
import Checkbox from "@/Components/Dashboard/Checkbox";
import Pagination from "@/Components/Dashboard/Pagination";
import Swal from "sweetalert2";

// User Card for Grid View
function UserCard({ user, isSelected, onSelect, onDelete, isSuperAdmin }) {
    return (
        <div
            className={`
            group bg-white dark:bg-slate-900 rounded-2xl border-2
            ${
                isSelected
                    ? "border-primary-500 dark:border-primary-600"
                    : "border-slate-200 dark:border-slate-800"
            }
            overflow-hidden hover:shadow-lg hover:border-primary-300 dark:hover:border-primary-700 transition-all duration-200
        `}
        >
            <div className="p-4 flex items-start justify-between">
                <div className="flex items-center gap-3">
                    <div className="w-12 h-12 rounded-full bg-gradient-to-br from-primary-400 to-primary-600 flex items-center justify-center text-white text-lg font-bold overflow-hidden">
                        {user.image ? (
                            <img src={`/storage/users/${user.image}`} className="w-full h-full object-cover" />
                        ) : (
                            user.name.charAt(0).toUpperCase()
                        )}
                    </div>
                    <div>
                        <h3 className="text-base font-semibold text-slate-800 dark:text-slate-200">
                            {user.name}
                        </h3>
                        <p className="text-sm text-slate-500 dark:text-slate-400 flex items-center gap-1">
                            <IconMail size={14} />
                            {user.email}
                        </p>
                    </div>
                </div>
                {isSuperAdmin && (
                    <Checkbox
                        value={user.id}
                        onChange={onSelect}
                        checked={isSelected}
                    />
                )}
            </div>

            <div className="px-4 pb-2 flex flex-wrap gap-1.5">
                {user.roles.map((role, index) => (
                    <span
                        key={index}
                        className="inline-flex items-center gap-1 px-2 py-0.5 text-[10px] font-bold rounded-full bg-accent-100 dark:bg-accent-900/50 text-accent-700 dark:text-accent-400 uppercase"
                    >
                        <IconShield size={10} />
                        {role.name}
                    </span>
                ))}
            </div>

            {/* Indikator Face ID di Grid Mode */}
            <div className="px-4 pb-3 flex gap-2">
                {user.is_face_mandatory ? (
                    <span className="text-[9px] font-black text-indigo-600 dark:text-indigo-400 flex items-center gap-1 uppercase bg-indigo-50 dark:bg-indigo-900/30 px-2 py-0.5 rounded-md border border-indigo-100 dark:border-indigo-800">
                        <IconShieldCheck size={12} /> Wajib Face ID
                    </span>
                ) : (
                    <span className="text-[9px] font-black text-slate-400 flex items-center gap-1 uppercase bg-slate-50 dark:bg-slate-800 px-2 py-0.5 rounded-md border border-slate-100 dark:border-slate-700">
                         Standard Login
                    </span>
                )}
                {user.face_data && (
                    <span className="text-[9px] font-black text-green-600 dark:text-green-400 flex items-center gap-1 uppercase bg-green-50 dark:bg-green-900/30 px-2 py-0.5 rounded-md border border-green-100 dark:border-green-800">
                        <IconFaceId size={12} /> Aktif
                    </span>
                )}
            </div>

            {isSuperAdmin && (
                <div className="flex border-t border-slate-100 dark:border-slate-800">
                    <Link
                        href={route("users.edit", user.id)}
                        className="flex-1 flex items-center justify-center gap-1.5 py-3 text-warning-600 hover:bg-warning-50 dark:hover:bg-warning-950/50 text-sm font-medium transition-colors"
                    >
                        <IconPencilCog size={16} />
                        <span>Edit</span>
                    </Link>
                    <div className="w-px bg-slate-100 dark:bg-slate-800" />
                    <button
                        onClick={() => onDelete(user.id)}
                        className="flex-1 flex items-center justify-center gap-1.5 py-3 text-danger-600 hover:bg-danger-50 dark:hover:bg-danger-950/50 text-sm font-medium transition-colors"
                    >
                        <IconTrash size={16} />
                        <span>Hapus</span>
                    </button>
                </div>
            )}
        </div>
    );
}

export default function Index() {
    const { users, auth } = usePage().props;
    const [viewMode, setViewMode] = useState("grid");
    const isSuperAdmin = auth.user.roles.some(role => role.name === 'super-admin');

    const {
        data,
        setData,
        delete: destroy,
    } = useForm({
        selectedUser: [],
    });

    const setSelectedUser = (e) => {
        let items = [...data.selectedUser];
        if (items.some((id) => id === e.target.value.toString()))
            items = items.filter((id) => id !== e.target.value.toString());
        else items.push(e.target.value.toString());
        setData("selectedUser", items);
    };

    const deleteData = async (id) => {
        if (!isSuperAdmin) return;
        Swal.fire({
            title: "Hapus Pengguna?",
            text: "Data yang dihapus akan dipindahkan ke tempat sampah (Soft Delete)!",
            icon: "warning",
            showCancelButton: true,
            confirmButtonColor: "#ef4444",
            cancelButtonColor: "#64748b",
            confirmButtonText: "Ya, Hapus!",
            cancelButtonText: "Batal",
        }).then((result) => {
            if (result.isConfirmed) {
                destroy(route("users.destroy", [id]), {
                    onSuccess: () => {
                        Swal.fire({
                            title: "Berhasil!",
                            text: "Data berhasil dihapus!",
                            icon: "success",
                            showConfirmButton: false,
                            timer: 1500,
                        });
                        setData("selectedUser", []);
                    }
                });
            }
        });
    };

    return (
        <>
            <Head title="Pengguna" />

            {/* Header */}
            <div className="mb-6">
                <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                    <div>
                        <h1 className="text-2xl font-bold text-slate-900 dark:text-white uppercase tracking-tight">
                            Manajemen Pengguna
                        </h1>
                        <p className="text-sm text-slate-500 dark:text-slate-400 font-medium">
                            Total {users.total || 0} akun terdaftar dalam sistem.
                        </p>
                    </div>
                    <div className="flex gap-2">
                        {isSuperAdmin && data.selectedUser.length > 0 && (
                            <Button
                                type={"bulk"}
                                icon={<IconTrash size={18} />}
                                className={"bg-danger-500 hover:bg-danger-600 text-white shadow-lg shadow-danger-500/30"}
                                label={`Hapus ${data.selectedUser.length}`}
                                onClick={() => deleteData(data.selectedUser)}
                            />
                        )}
                        {isSuperAdmin && (
                            <Button
                                type={"link"}
                                href={route("users.create")}
                                icon={<IconCirclePlus size={18} strokeWidth={1.5} />}
                                className={"bg-primary-500 hover:bg-primary-600 text-white shadow-lg shadow-primary-500/30 font-bold uppercase text-[10px] tracking-widest"}
                                label={"Tambah User"}
                            />
                        )}
                    </div>
                </div>
            </div>

            {/* Toolbar */}
            <div className="mb-4 flex flex-col sm:flex-row justify-between items-stretch sm:items-center gap-3">
                <div className="w-full sm:w-80">
                    <Search url={route("users.index")} placeholder="Cari berdasarkan nama atau email..." />
                </div>
                <div className="flex items-center gap-2 bg-white dark:bg-slate-900 p-1.5 rounded-xl border border-slate-200 dark:border-slate-800 shadow-sm">
                    <button
                        onClick={() => setViewMode("grid")}
                        className={`p-2 rounded-lg transition-all ${
                            viewMode === "grid"
                                ? "bg-primary-500 text-white shadow-md"
                                : "text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800"
                        }`}
                    >
                        <IconLayoutGrid size={20} />
                    </button>
                    <button
                        onClick={() => setViewMode("list")}
                        className={`p-2 rounded-lg transition-all ${
                            viewMode === "list"
                                ? "bg-primary-500 text-white shadow-md"
                                : "text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800"
                        }`}
                    >
                        <IconList size={20} />
                    </button>
                </div>
            </div>

            {/* Content */}
            {users.data.length > 0 ? (
                viewMode === "grid" ? (
                    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4 animate-in fade-in duration-500">
                        {users.data.map((user) => (
                            <UserCard
                                key={user.id}
                                user={user}
                                isSuperAdmin={isSuperAdmin}
                                isSelected={data.selectedUser.includes(user.id.toString())}
                                onSelect={setSelectedUser}
                                onDelete={deleteData}
                            />
                        ))}
                    </div>
                ) : (
                    <Table.Card title={"Daftar Akun Pengguna"}>
                        <Table>
                            <Table.Thead>
                                <tr>
                                    {isSuperAdmin && (
                                        <Table.Th className={"w-10"}>
                                            <Checkbox
                                                onChange={(e) => {
                                                    const allUserIds = users.data.map((user) => user.id.toString());
                                                    setData("selectedUser", e.target.checked ? allUserIds : []);
                                                }}
                                                checked={data.selectedUser.length === users.data.length && users.data.length > 0}
                                            />
                                        </Table.Th>
                                    )}
                                    <Table.Th className={"w-10"}>No</Table.Th>
                                    <Table.Th>Informasi Pengguna</Table.Th>
                                    <Table.Th>Peran / Role</Table.Th>
                                    <Table.Th>Security / Face ID</Table.Th>
                                    {isSuperAdmin && <Table.Th className="text-right">Aksi</Table.Th>}
                                </tr>
                            </Table.Thead>
                            <Table.Tbody>
                                {users.data.map((user, i) => (
                                    <tr className="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors group" key={user.id}>
                                        {isSuperAdmin && (
                                            <Table.Td>
                                                <Checkbox
                                                    value={user.id}
                                                    onChange={setSelectedUser}
                                                    checked={data.selectedUser.includes(user.id.toString())}
                                                />
                                            </Table.Td>
                                        )}
                                        <Table.Td className={"text-center font-bold text-slate-400"}>
                                            {++i + (users.current_page - 1) * users.per_page}
                                        </Table.Td>
                                        <Table.Td>
                                            <div className="flex items-center gap-3">
                                                <div className="w-10 h-10 rounded-full bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-primary-600 font-black overflow-hidden border border-slate-200 dark:border-slate-700">
                                                    {user.image ? (
                                                        <img src={`/storage/users/${user.image}`} className="w-full h-full object-cover" />
                                                    ) : (
                                                        user.name.charAt(0).toUpperCase()
                                                    )}
                                                </div>
                                                <div>
                                                    <p className="text-sm font-bold text-slate-800 dark:text-slate-200">{user.name}</p>
                                                    <p className="text-[11px] text-slate-500 font-medium">{user.email}</p>
                                                </div>
                                            </div>
                                        </Table.Td>
                                        <Table.Td>
                                            <div className="flex flex-wrap gap-1">
                                                {user.roles.map((role, index) => (
                                                    <span key={index} className="px-2 py-0.5 text-[10px] font-bold bg-accent-100 dark:bg-accent-900/50 text-accent-700 dark:text-accent-400 rounded-full uppercase">
                                                        {role.name}
                                                    </span>
                                                ))}
                                            </div>
                                        </Table.Td>
                                        <Table.Td>
                                            <div className="flex flex-col gap-1">
                                                {user.is_face_mandatory ? (
                                                    <span className="inline-flex items-center gap-1 text-[10px] font-black text-indigo-600 uppercase">
                                                        <IconShieldCheck size={14} /> Mandatory
                                                    </span>
                                                ) : (
                                                    <span className="inline-flex items-center gap-1 text-[10px] font-bold text-slate-400 uppercase">
                                                        <IconShieldX size={14} /> Optional
                                                    </span>
                                                )}
                                                {user.face_data ? (
                                                    <span className="text-[9px] font-black text-green-500 uppercase">✓ Face ID Aktif</span>
                                                ) : (
                                                    <span className="text-[9px] font-bold text-slate-300 uppercase italic">× Belum Setup</span>
                                                )}
                                            </div>
                                        </Table.Td>
                                        {isSuperAdmin && (
                                            <Table.Td className="text-right">
                                                <div className="flex justify-end gap-2">
                                                    <Button
                                                        type={"edit"}
                                                        icon={<IconPencilCog size={16} />}
                                                        className={"bg-warning-100 text-warning-600 hover:bg-warning-200 dark:bg-warning-900/30 dark:text-warning-400"}
                                                        href={route("users.edit", user.id)}
                                                    />
                                                    <Button
                                                        type={"delete"}
                                                        icon={<IconTrash size={16} />}
                                                        className={"bg-danger-100 text-danger-600 hover:bg-danger-200 dark:bg-danger-900/30 dark:text-danger-400"}
                                                        onClick={() => deleteData(user.id)}
                                                    />
                                                </div>
                                            </Table.Td>
                                        )}
                                    </tr>
                                ))}
                            </Table.Tbody>
                        </Table>
                    </Table.Card>
                )
            ) : (
                <div className="flex flex-col items-center justify-center py-16 bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800">
                    <div className="w-16 h-16 rounded-full bg-slate-100 dark:bg-slate-800 flex items-center justify-center mb-4">
                        <IconDatabaseOff size={32} className="text-slate-400" strokeWidth={1.5} />
                    </div>
                    <h3 className="text-lg font-medium text-slate-800 dark:text-slate-200 mb-1">
                        Belum Ada Pengguna
                    </h3>
                    <p className="text-sm text-slate-500 dark:text-slate-400 mb-4">
                        {isSuperAdmin ? "Tambahkan pengguna pertama Anda." : "Tidak ada data pengguna yang ditemukan."}
                    </p>
                    {isSuperAdmin && (
                        <Button
                            type={"link"}
                            icon={<IconCirclePlus size={18} />}
                            className={"bg-primary-500 hover:bg-primary-600 text-white shadow-lg shadow-primary-500/30"}
                            label={"Tambah User"}
                            href={route("users.create")}
                        />
                    )}
                </div>
            )}

            {users.last_page !== 1 && <Pagination links={users.links} />}
        </>
    );
}

Index.layout = (page) => <DashboardLayout children={page} />;