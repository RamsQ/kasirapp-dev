import React from "react";
import { Head, usePage, useForm, Link } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import {
    IconUserEdit,
    IconDeviceFloppy,
    IconArrowLeft,
    IconShield,
    IconFaceId,
    IconShieldLock,
} from "@tabler/icons-react";
import Input from "@/Components/Dashboard/Input";
import Checkbox from "@/Components/Dashboard/Checkbox";
import toast from "react-hot-toast";

export default function Edit() {
    const { roles, user } = usePage().props;

    const { data, setData, post, errors, processing } = useForm({
        name: user.name,
        email: user.email,
        password: "",
        password_confirmation: "",
        selectedRoles: user.roles.map((role) => role.name),
        is_face_mandatory: user.is_face_mandatory ?? false, // State untuk Mandatory Face ID
        _method: "PUT",
    });

    const setSelectedRoles = (e) => {
        let items = [...data.selectedRoles];
        if (items.includes(e.target.value)) {
            items = items.filter((name) => name !== e.target.value);
        } else {
            items.push(e.target.value);
        }
        setData("selectedRoles", items);
    };

    const submit = (e) => {
        e.preventDefault();
        post(route("users.update", user.id), {
            onSuccess: () => toast.success("Pengguna berhasil diperbarui"),
            onError: () => toast.error("Gagal memperbarui pengguna"),
        });
    };

    return (
        <>
            <Head title="Edit Pengguna" />

            <div className="mb-6">
                <Link
                    href={route("users.index")}
                    className="inline-flex items-center gap-2 text-sm text-slate-500 hover:text-primary-600 mb-3"
                >
                    <IconArrowLeft size={16} />
                    Kembali ke Pengguna
                </Link>
                <h1 className="text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2">
                    <IconUserEdit size={28} className="text-primary-500" />
                    Edit Pengguna
                </h1>
                <p className="text-sm text-slate-500 mt-1">
                    {user.name} • {user.email}
                </p>
            </div>

            <form onSubmit={submit}>
                <div className="max-w-2xl space-y-6">
                    {/* Account Info */}
                    <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6 shadow-sm">
                        <h3 className="text-sm font-bold text-slate-700 dark:text-slate-300 mb-4 uppercase tracking-wider">
                            Informasi Akun
                        </h3>
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <Input
                                type="text"
                                label="Nama Lengkap"
                                placeholder="Nama pengguna"
                                value={data.name}
                                onChange={(e) =>
                                    setData("name", e.target.value)
                                }
                                errors={errors.name}
                            />
                            <Input
                                type="email"
                                label="Email"
                                value={data.email}
                                onChange={(e) =>
                                    setData("email", e.target.value)
                                }
                                errors={errors.email}
                                disabled
                                className="opacity-60 bg-slate-50 dark:bg-slate-800/50"
                            />
                            <Input
                                type="password"
                                label="Kata Sandi Baru"
                                placeholder="Kosongkan jika tidak diubah"
                                value={data.password}
                                onChange={(e) =>
                                    setData("password", e.target.value)
                                }
                                errors={errors.password}
                            />
                            <Input
                                type="password"
                                label="Konfirmasi Kata Sandi"
                                placeholder="Ulangi kata sandi baru"
                                value={data.password_confirmation}
                                onChange={(e) =>
                                    setData(
                                        "password_confirmation",
                                        e.target.value
                                    )
                                }
                                errors={errors.password_confirmation}
                            />
                        </div>
                    </div>

                    {/* [BARU] Biometric Security Setting */}
                    <div className="bg-indigo-50/50 dark:bg-indigo-900/10 rounded-2xl border border-indigo-100 dark:border-indigo-800/50 p-6">
                        <div className="flex items-center gap-3 mb-4">
                            <IconShieldLock className="text-indigo-600 dark:text-indigo-400" size={20} />
                            <h3 className="text-sm font-bold text-indigo-900 dark:text-indigo-200 uppercase tracking-wider">
                                Keamanan Biometrik
                            </h3>
                        </div>
                        
                        <label className="flex items-center justify-between cursor-pointer group bg-white dark:bg-slate-900 p-4 rounded-xl border border-indigo-100 dark:border-indigo-900/50 shadow-sm transition-all hover:shadow-md">
                            <div className="flex items-center gap-4">
                                <div className="p-2 bg-indigo-100 dark:bg-indigo-900/50 rounded-lg text-indigo-600 dark:text-indigo-400">
                                    <IconFaceId size={24} />
                                </div>
                                <div>
                                    <span className="text-sm font-black text-indigo-900 dark:text-indigo-100 block uppercase tracking-tight">
                                        Wajibkan Verifikasi Wajah
                                    </span>
                                    <p className="text-[10px] text-slate-500 dark:text-slate-400 font-bold leading-tight mt-1 uppercase">
                                        User dilarang login menggunakan password jika fitur ini aktif.
                                    </p>
                                </div>
                            </div>
                            <div className="relative">
                                <input 
                                    type="checkbox"
                                    className="sr-only peer"
                                    checked={data.is_face_mandatory}
                                    onChange={(e) => setData('is_face_mandatory', e.target.checked)}
                                />
                                <div className="w-11 h-6 bg-slate-200 dark:bg-slate-700 rounded-full peer peer-checked:bg-indigo-600 transition-all after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:after:translate-x-5 shadow-inner"></div>
                            </div>
                        </label>

                        {user.face_data ? (
                            <p className="text-[10px] text-green-600 dark:text-green-400 font-black uppercase mt-3 flex items-center gap-1 ml-1">
                                ● Face ID sudah terdaftar oleh pengguna
                            </p>
                        ) : (
                            <p className="text-[10px] text-amber-600 dark:text-amber-400 font-black uppercase mt-3 flex items-center gap-1 ml-1">
                                ● Perhatian: Pengguna belum mendaftarkan wajah di profil
                            </p>
                        )}
                    </div>

                    {/* Roles */}
                    <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6 shadow-sm">
                        <h3 className="text-sm font-bold text-slate-700 dark:text-slate-300 mb-4 flex items-center gap-2 uppercase tracking-wider">
                            <IconShield size={16} />
                            Akses Group
                        </h3>
                        <div className="flex flex-wrap gap-4">
                            {roles.map((role, i) => (
                                <label
                                    key={i}
                                    className={`flex items-center gap-2.5 px-4 py-3 rounded-xl border cursor-pointer transition-all ${
                                        data.selectedRoles.includes(role.name)
                                            ? "border-primary-500 bg-primary-50 dark:bg-primary-950/50"
                                            : "border-slate-200 dark:border-slate-700 hover:border-primary-300"
                                    }`}
                                >
                                    <Checkbox
                                        value={role.name}
                                        onChange={setSelectedRoles}
                                        checked={data.selectedRoles.includes(
                                            role.name
                                        )}
                                    />
                                    <span className="text-sm font-medium text-slate-700 dark:text-slate-300 capitalize">
                                        {role.name}
                                    </span>
                                </label>
                            ))}
                        </div>
                        {errors.selectedRoles && (
                            <p className="text-xs text-danger-500 mt-3">
                                {errors.selectedRoles}
                            </p>
                        )}
                    </div>

                    {/* Submit */}
                    <div className="flex justify-end gap-3">
                        <Link
                            href={route("users.index")}
                            className="px-5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 font-bold uppercase text-[11px] transition-all"
                        >
                            Batal
                        </Link>
                        <button
                            type="submit"
                            disabled={processing}
                            className="inline-flex items-center gap-2 px-6 py-2.5 rounded-xl bg-primary-600 hover:bg-primary-700 text-white font-black uppercase text-[11px] transition-all shadow-lg shadow-primary-500/20 disabled:opacity-50"
                        >
                            <IconDeviceFloppy size={18} />
                            {processing ? "Menyimpan..." : "Simpan Perubahan"}
                        </button>
                    </div>
                </div>
            </form>
        </>
    );
}

Edit.layout = (page) => <DashboardLayout children={page} />;