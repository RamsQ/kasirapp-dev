import React from "react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { Head, useForm, usePage, Link } from "@inertiajs/react";
import Input from "@/Components/Dashboard/Input";
import Textarea from "@/Components/Dashboard/TextArea";
import toast from "react-hot-toast";
import {
    IconUsers,
    IconDeviceFloppy,
    IconArrowLeft,
} from "@tabler/icons-react";

export default function Edit({ customer }) {
    const { errors } = usePage().props;

    const { data, setData, post, processing } = useForm({
        id: customer.id,
        name: customer.name,
        no_telp: customer.no_telp,
        address: customer.address,
        _method: "PUT",
    });

    const submit = (e) => {
        e.preventDefault();
        post(route("customers.update", customer.id), {
            onSuccess: () => toast.success("Pelanggan berhasil diperbarui"),
            onError: () => toast.error("Gagal memperbarui pelanggan"),
        });
    };

    return (
        <>
            <Head title="Edit Pelanggan" />

            <div className="mb-6">
                <Link
                    href={route("customers.index")}
                    className="inline-flex items-center gap-2 text-sm text-slate-500 hover:text-primary-600 mb-3"
                >
                    <IconArrowLeft size={16} />
                    Kembali ke Pelanggan
                </Link>
                <h1 className="text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2">
                    <IconUsers size={28} className="text-primary-500" />
                    Edit Pelanggan
                </h1>
                <p className="text-sm text-slate-500 mt-1">{customer.name}</p>
            </div>

            <form onSubmit={submit}>
                <div className="max-w-2xl">
                    <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                        <div className="space-y-4">
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <Input
                                    type="text"
                                    label="Nama Pelanggan"
                                    placeholder="Nama lengkap"
                                    errors={errors.name}
                                    onChange={(e) =>
                                        setData("name", e.target.value)
                                    }
                                    value={data.name}
                                />
                                <Input
                                    type="text"
                                    label="No. Handphone"
                                    placeholder="08xxxxxxxxxx"
                                    errors={errors.no_telp}
                                    onChange={(e) =>
                                        setData("no_telp", e.target.value)
                                    }
                                    value={data.no_telp}
                                />
                            </div>
                            <Textarea
                                label="Alamat"
                                placeholder="Alamat lengkap"
                                errors={errors.address}
                                onChange={(e) =>
                                    setData("address", e.target.value)
                                }
                                value={data.address}
                                rows={3}
                            />
                        </div>

                        <div className="flex justify-end gap-3 mt-6 pt-6 border-t border-slate-100 dark:border-slate-800">
                            <Link
                                href={route("customers.index")}
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
                                {processing
                                    ? "Menyimpan..."
                                    : "Simpan Perubahan"}
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </>
    );
}

Edit.layout = (page) => <DashboardLayout children={page} />;
