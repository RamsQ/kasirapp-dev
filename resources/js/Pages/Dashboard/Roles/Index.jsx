import React, { useMemo } from "react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { Head, useForm, usePage } from "@inertiajs/react";
import Button from "@/Components/Dashboard/Button";
import Input from "@/Components/Dashboard/Input";
import Modal from "@/Components/Dashboard/Modal";
import Search from "@/Components/Dashboard/Search";
import Pagination from "@/Components/Dashboard/Pagination";
import Swal from "sweetalert2";
import {
    IconDatabaseOff,
    IconCirclePlus,
    IconTrash,
    IconUserShield,
    IconPencilCog,
    IconPencilCheck,
    IconShield,
    IconCheck,
    IconCategory,
} from "@tabler/icons-react";

// Role Card Component (Legacy Support & Clean UI)
function RoleCard({ role, onEdit, onDelete }) {
    return (
        <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden hover:shadow-lg transition-all flex flex-col h-full group">
            <div className="p-5 border-b border-slate-100 dark:border-slate-800">
                <div className="flex items-center gap-3">
                    <div className="w-12 h-12 rounded-xl bg-gradient-to-br from-primary-400 to-primary-600 flex items-center justify-center text-white shrink-0 shadow-lg shadow-primary-500/20 group-hover:scale-110 transition-transform">
                        <IconUserShield size={24} />
                    </div>
                    <div className="min-w-0">
                        <h3 className="text-lg font-bold text-slate-800 dark:text-slate-200 capitalize truncate">
                            {role.name}
                        </h3>
                        <p className="text-[10px] font-black uppercase tracking-widest text-slate-400">
                            {role.permissions?.length || 0} Hak Akses Terpasang
                        </p>
                    </div>
                </div>
            </div>

            <div className="p-4 bg-slate-50 dark:bg-slate-800/20 flex-1">
                <div className="flex flex-wrap gap-1.5 max-h-36 overflow-y-auto custom-scrollbar pr-1">
                    {role.permissions?.slice(0, 15).map((permission, index) => (
                        <span key={index} className="inline-flex items-center gap-1 px-2 py-0.5 text-[9px] font-black uppercase tracking-tighter rounded bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-500 dark:text-slate-400 shadow-sm">
                            <IconShield size={10} className="text-primary-500" />
                            {permission.name.replace(/[.-]/g, ' ')}
                        </span>
                    ))}
                    {role.permissions?.length > 15 && (
                        <span className="px-2 py-1 text-[10px] font-bold text-slate-400 italic">
                            +{role.permissions.length - 15} lainnya...
                        </span>
                    )}
                </div>
            </div>

            <div className="flex border-t border-slate-100 dark:border-slate-800 mt-auto">
                <button onClick={onEdit} className="flex-1 flex items-center justify-center gap-1.5 py-4 text-warning-600 hover:bg-warning-50 dark:hover:bg-warning-950/30 text-[10px] font-black uppercase transition-colors">
                    <IconPencilCog size={16} /> <span>Ubah</span>
                </button>
                <div className="w-px bg-slate-100 dark:bg-slate-800" />
                <button onClick={onDelete} className="flex-1 flex items-center justify-center gap-1.5 py-4 text-danger-600 hover:bg-danger-50 dark:hover:bg-danger-950/30 text-[10px] font-black uppercase transition-colors">
                    <IconTrash size={16} /> <span>Hapus</span>
                </button>
            </div>
        </div>
    );
}

export default function Index() {
    const { roles, permissions, errors } = usePage().props;

    const { data, setData, transform, post, delete: destroy, processing } = useForm({
        id: "", name: "", selectedPermission: [], isUpdate: false, isOpen: false,
    });

    // --- LOGIKA GROUPING BERDASARKAN KEYWORD (Inventory, Kasir, Laporan, User) ---
    const groupedPermissions = useMemo(() => {
        const groups = {};
        const permsArray = Array.isArray(permissions) ? permissions : (permissions.data || []);

        permsArray.forEach((permission) => {
            const pName = permission.name.toLowerCase();
            let groupName = "LAINNYA";

            // 1. INVENTORY & STOK (Deteksi Stock, Ingredient, Bahan, Opname, Recipe)
            if (
                pName.includes('stock') || 
                pName.includes('stok') || 
                pName.includes('ingredient') || 
                pName.includes('bahan') || 
                pName.includes('recipe') || 
                pName.includes('resep') || 
                pName.includes('opname')
            ) {
                groupName = "INVENTORY & STOK";
            } 
            // 2. KATALOG PRODUK & KATEGORI
            else if (pName.includes('product') || pName.includes('produk') || pName.includes('categor')) {
                groupName = "KATALOG PRODUK";
            }
            // 3. OPERASIONAL KASIR & SHIFT
            else if (pName.includes('transaction') || pName.includes('shift') || pName.includes('order')) {
                groupName = "OPERASIONAL KASIR";
            }
            // 4. LAPORAN & ANALISA PROMO
            else if (pName.includes('report') || pName.includes('profit') || pName.includes('sales') || pName.includes('discount')) {
                groupName = "LAPORAN & PROMO";
            }
            // 5. MANAJEMEN PENGGUNA & AKSES
            else if (pName.includes('user') || pName.includes('role') || pName.includes('permission')) {
                groupName = "MANAJEMEN PENGGUNA";
            }
            // 6. DATABASE PELANGGAN
            else if (pName.includes('customer')) {
                groupName = "DATABASE PELANGGAN";
            }
            // 7. KONFIGURASI SISTEM
            else if (pName.includes('setting') || pName.includes('payment') || pName.includes('receipt')) {
                groupName = "KONFIGURASI SISTEM";
            }
            // 8. DASHBOARD
            else if (pName.includes('dashboard')) {
                groupName = "DASHBOARD";
            }
            // 9. Fallback (Ambil kata pertama jika tidak masuk kategori manapun)
            else {
                groupName = permission.name.split(/[.-]/)[0].toUpperCase();
            }

            if (!groups[groupName]) groups[groupName] = [];
            groups[groupName].push(permission);
        });

        // Urutkan grup secara alfabet agar rapi
        return Object.keys(groups).sort().reduce((acc, key) => {
            acc[key] = groups[key];
            return acc;
        }, {});
    }, [permissions]);

    const togglePermission = (permission) => {
        let current = [...data.selectedPermission];
        const index = current.findIndex(p => p.id === permission.id);
        index > -1 ? current.splice(index, 1) : current.push(permission);
        setData("selectedPermission", current);
    };

    const toggleGroup = (groupPermissions) => {
        const allInGroupSelected = groupPermissions.every(gp => data.selectedPermission.some(sp => sp.id === gp.id));
        let current = [...data.selectedPermission];
        if (allInGroupSelected) {
            current = current.filter(sp => !groupPermissions.some(gp => gp.id === sp.id));
        } else {
            groupPermissions.forEach(gp => {
                if (!current.some(sp => sp.id === gp.id)) current.push(gp);
            });
        }
        setData("selectedPermission", current);
    };

    transform((data) => ({
        ...data,
        selectedPermission: data.selectedPermission.map((p) => p.id),
        _method: data.isUpdate ? "put" : "post",
    }));

    const handleSubmit = (e) => {
        e.preventDefault();
        const url = data.isUpdate ? route("roles.update", data.id) : route("roles.store");
        post(url, { onSuccess: () => closeModal() });
    };

    const closeModal = () => setData({ id: "", name: "", selectedPermission: [], isUpdate: false, isOpen: false });

    const handleEdit = (role) => setData({
        id: role.id, selectedPermission: role.permissions, name: role.name, isUpdate: true, isOpen: true,
    });

    const handleDelete = (roleId) => {
        Swal.fire({
            title: 'Hapus Role?', text: "Tindakan ini tidak bisa dibatalkan!", icon: 'warning',
            showCancelButton: true, confirmButtonColor: '#ef4444', confirmButtonText: 'Ya, Hapus!'
        }).then((result) => { if (result.isConfirmed) destroy(route("roles.destroy", roleId)); });
    };

    return (
        <>
            <Head title="Hak Akses Group" />
            
            {/* Header */}
            <div className="mb-6 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                <div>
                    <h1 className="text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2 tracking-tighter">
                        <IconUserShield size={28} className="text-primary-500" /> Manajemen Peran & Akses
                    </h1>
                    <p className="text-sm text-slate-500 dark:text-slate-400">Kelola izin akses staf sesuai tanggung jawabnya.</p>
                </div>
                <Button type={"button"} icon={<IconCirclePlus size={18} />} className="bg-primary-500 hover:bg-primary-600 text-white shadow-lg font-bold uppercase text-xs" label={"Tambah Role"} onClick={() => setData("isOpen", true)} />
            </div>

            <div className="mb-8 w-full sm:w-96">
                <Search url={route("roles.index")} placeholder="Cari nama role..." />
            </div>

            {/* List Role */}
            {roles.data.length > 0 ? (
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
                    {roles.data.map((role) => (
                        <RoleCard key={role.id} role={role} onEdit={() => handleEdit(role)} onDelete={() => handleDelete(role.id)} />
                    ))}
                </div>
            ) : (
                <div className="text-center py-20 bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 shadow-sm">
                    <IconDatabaseOff size={48} className="mx-auto text-slate-300 mb-4" />
                    <h3 className="text-lg font-bold text-slate-400 uppercase tracking-tighter italic">Belum Ada Data Role</h3>
                </div>
            )}

            <div className="mt-8">
                {roles.last_page !== 1 && <Pagination links={roles.links} />}
            </div>

            {/* Modal Grouping */}
            <Modal show={data.isOpen} onClose={closeModal} maxWidth="5xl" title={data.isUpdate ? "Edit Hak Akses" : "Buat Role Baru"}>
                <form onSubmit={handleSubmit} className="p-2">
                    <div className="mb-8">
                        <Input label={"Nama Jabatan / Role"} value={data.name} onChange={(e) => setData("name", e.target.value)} errors={errors.name} placeholder="Contoh: Kasir Senior" />
                    </div>

                    <div className="mb-6">
                        <div className="flex justify-between items-center mb-6">
                            <label className="text-xs font-black uppercase text-slate-400 flex items-center gap-2"><IconCategory size={16} /> Konfigurasi Modul</label>
                            <span className="text-[10px] font-bold text-primary-500 bg-primary-50 px-3 py-1 rounded-full">{data.selectedPermission.length} Akses Dipilih</span>
                        </div>
                        
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-6 max-h-[55vh] overflow-y-auto pr-2 custom-scrollbar">
                            {Object.entries(groupedPermissions).map(([groupName, perms]) => {
                                const isAllSelected = perms.every(gp => data.selectedPermission.some(sp => sp.id === gp.id));
                                return (
                                    <div key={groupName} className="bg-slate-50 dark:bg-slate-950/50 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden shadow-sm transition-all duration-300">
                                        <div className="bg-white dark:bg-slate-900 px-5 py-3 border-b border-slate-200 dark:border-slate-800 flex justify-between items-center">
                                            <h4 className="text-[10px] font-black text-slate-800 dark:text-white tracking-widest">{groupName}</h4>
                                            <button type="button" onClick={() => toggleGroup(perms)} className={`text-[9px] font-black uppercase px-3 py-1 rounded-lg transition-colors ${isAllSelected ? 'bg-red-50 text-red-600 dark:bg-red-900/20' : 'bg-primary-50 text-primary-600 dark:bg-primary-900/20'}`}>
                                                {isAllSelected ? 'Batal Semua' : 'Pilih Semua'}
                                            </button>
                                        </div>
                                        <div className="p-4 grid grid-cols-1 gap-1.5 bg-white dark:bg-slate-900">
                                            {perms.map((permission) => {
                                                const isSelected = data.selectedPermission.some(p => p.id === permission.id);
                                                return (
                                                    <label key={permission.id} className={`flex items-center gap-3 p-3 rounded-xl cursor-pointer border-2 transition-all ${isSelected ? 'bg-primary-50 dark:bg-primary-900/10 border-primary-500 shadow-sm' : 'bg-transparent border-transparent hover:bg-slate-50 dark:hover:bg-slate-800'}`}>
                                                        <input type="checkbox" className="hidden" checked={isSelected} onChange={() => togglePermission(permission)} />
                                                        <div className={`w-5 h-5 rounded-md flex items-center justify-center border-2 transition-colors ${isSelected ? 'bg-primary-500 border-primary-500' : 'border-slate-300 dark:border-slate-600'}`}>
                                                            {isSelected && <IconCheck size={14} className="text-white" strokeWidth={4} />}
                                                        </div>
                                                        <span className={`text-[11px] font-bold uppercase tracking-tighter ${isSelected ? 'text-primary-700 dark:text-primary-400' : 'text-slate-500 dark:text-slate-400'}`}>
                                                            {permission.name.replace(/[.-]/g, ' ')}
                                                        </span>
                                                    </label>
                                                );
                                            })}
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </div>

                    <div className="flex justify-end gap-3 pt-6 border-t border-slate-100 dark:border-slate-800">
                        <Button type={"button"} label={"Batal"} className="bg-slate-100 dark:bg-slate-800 text-slate-500 font-bold uppercase text-[10px]" onClick={closeModal} />
                        <Button type={"submit"} processing={processing} icon={<IconPencilCheck size={18} />} className="bg-primary-600 hover:bg-primary-500 text-white px-10 font-black uppercase text-[10px] shadow-xl shadow-primary-500/10" label={data.isUpdate ? "Simpan Perubahan" : "Buat Role Baru"} />
                    </div>
                </form>
            </Modal>

            <style>{`.custom-scrollbar::-webkit-scrollbar { width: 5px; } .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; } .dark .custom-scrollbar::-webkit-scrollbar-thumb { background: #1e293b; }`}</style>
        </>
    );
}

Index.layout = (page) => <DashboardLayout children={page} />;