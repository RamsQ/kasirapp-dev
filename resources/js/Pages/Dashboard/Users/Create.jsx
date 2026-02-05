import React from "react";
import { Head, usePage, useForm, Link } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import {
    IconUserPlus,
    IconDeviceFloppy,
    IconArrowLeft,
    IconShield,
} from "@tabler/icons-react";
import Input from "@/Components/Dashboard/Input";
import Checkbox from "@/Components/Dashboard/Checkbox";
import toast from "react-hot-toast";

export default function Create() {
    const { roles } = usePage().props;

    const { data, setData, post, errors, processing } = useForm({
        name: "",
        email: "",
        password: "",
        password_confirmation: "",
        selectedRoles: [],
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
        post(route("users.store"), {
            onSuccess: () => toast.success("Pengguna berhasil ditambahkan"),
            onError: () => toast.error("Gagal menyimpan pengguna"),
        });
    };

    return (
        <>
            <Head title="Tambah Pengguna" />

            <div className="mb-6">
                <Link
                    href={route("users.index")}
                    className="inline-flex items-center gap-2 text-sm text-slate-500 hover:text-primary-600 mb-3"
                >
                    <IconArrowLeft size={16} />
                    Kembali ke Pengguna
                </Link>
                <h1 className="text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2">
                    <IconUserPlus size={28} className="text-primary-500" />
                    Tambah Pengguna Baru
                </h1>
            </div>

            <form onSubmit={submit}>
                <div className="max-w-2xl space-y-6">
                    {/* Account Info */}
                    <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                        <h3 className="text-sm font-semibold text-slate-700 dark:text-slate-300 mb-4">
                            Informasi Akun
                        </h3>
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <Input
                                type="text"
                                label="Nama Lengkap"
                                placeholder="Masukkan nama"
                                value={data.name}
                                onChange={(e) =>
                                    setData("name", e.target.value)
                                }
                                errors={errors.name}
                            />
                            <Input
                                type="email"
                                label="Email"
                                placeholder="email@example.com"
                                value={data.email}
                                onChange={(e) =>
                                    setData("email", e.target.value)
                                }
                                errors={errors.email}
                            />
                            <Input
                                type="password"
                                label="Kata Sandi"
                                placeholder="Minimal 8 karakter"
                                value={data.password}
                                onChange={(e) =>
                                    setData("password", e.target.value)
                                }
                                errors={errors.password}
                            />
                            <Input
                                type="password"
                                label="Konfirmasi Kata Sandi"
                                placeholder="Ulangi kata sandi"
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

                    {/* Roles */}
                    <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                        <h3 className="text-sm font-semibold text-slate-700 dark:text-slate-300 mb-4 flex items-center gap-2">
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
                            className="px-5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 font-medium transition-colors"
                        >
                            Batal
                        </Link>
                        <button
                            type="submit"
                            disabled={processing}
                            className="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-primary-500 hover:bg-primary-600 text-white font-medium transition-colors disabled:opacity-50"
                        >
                            <IconDeviceFloppy size={18} />
                            {processing ? "Menyimpan..." : "Simpan"}
                        </button>
                    </div>
                </div>
            </form>
        </>
    );
}

Create.layout = (page) => <DashboardLayout children={page} />;
