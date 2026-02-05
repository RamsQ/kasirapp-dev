import React, { useState } from "react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { Head, useForm, usePage, Link } from "@inertiajs/react";
import Input from "@/Components/Dashboard/Input";
import Textarea from "@/Components/Dashboard/TextArea";
import toast from "react-hot-toast";
import {
    IconCategory,
    IconDeviceFloppy,
    IconArrowLeft,
    IconPhoto,
} from "@tabler/icons-react";

export default function Create() {
    const { errors } = usePage().props;

    const { data, setData, post, processing } = useForm({
        name: "",
        description: "",
        image: "",
    });

    const [imagePreview, setImagePreview] = useState(null);

    const handleImageChange = (e) => {
        const file = e.target.files[0];
        if (file) {
            setData("image", file);
            setImagePreview(URL.createObjectURL(file));
        }
    };

    const submit = (e) => {
        e.preventDefault();
        post(route("categories.store"), {
            onSuccess: () => toast.success("Kategori berhasil ditambahkan"),
            onError: (err) => toast.error("Gagal menyimpan kategori"),
        });
    };

    return (
        <>
            <Head title="Tambah Kategori" />

            <div className="mb-6">
                <Link
                    href={route("categories.index")}
                    className="inline-flex items-center gap-2 text-sm text-slate-500 hover:text-primary-600 mb-3 transition-colors uppercase font-bold tracking-tight"
                >
                    <IconArrowLeft size={16} />
                    Kembali ke Kategori
                </Link>
                <h1 className="text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2 uppercase tracking-tight">
                    <IconCategory size={28} className="text-primary-500" />
                    Tambah Kategori Baru
                </h1>
            </div>

            <form onSubmit={submit}>
                <div className="max-w-2xl">
                    <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6 shadow-sm">
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                            
                            {/* Image Section - Sekarang Opsional */}
                            <div>
                                <h3 className="text-[10px] font-black uppercase text-slate-400 dark:text-slate-500 mb-3 flex items-center gap-2 tracking-widest">
                                    <IconPhoto size={16} />
                                    Gambar (Opsional)
                                </h3>
                                <div className="aspect-video rounded-xl bg-slate-100 dark:bg-slate-800 border-2 border-dashed border-slate-300 dark:border-slate-700 flex items-center justify-center overflow-hidden mb-3 transition-all hover:border-primary-300">
                                    {imagePreview ? (
                                        <img
                                            src={imagePreview}
                                            alt="Preview"
                                            className="w-full h-full object-cover"
                                        />
                                    ) : (
                                        <div className="text-center">
                                            <IconPhoto
                                                size={32}
                                                className="text-slate-400 mx-auto mb-1"
                                            />
                                            <p className="text-[10px] font-bold text-slate-400 uppercase">Preview</p>
                                        </div>
                                    )}
                                </div>
                                <Input
                                    type="file"
                                    label="Pilih File Gambar"
                                    onChange={handleImageChange}
                                    errors={errors.image}
                                    accept="image/*"
                                />
                                <p className="mt-2 text-[10px] text-slate-400 italic">*Format: JPG, PNG, JPEG. Max: 2MB</p>
                            </div>

                            {/* Info Section */}
                            <div className="space-y-4">
                                <Input
                                    type="text"
                                    label="Nama Kategori"
                                    placeholder="Contoh: Makanan / Elektronik"
                                    errors={errors.name}
                                    onChange={(e) =>
                                        setData("name", e.target.value)
                                    }
                                    value={data.name}
                                    required
                                />

                                {/* Description Section - Sekarang Opsional */}
                                <Textarea
                                    label="Deskripsi (Opsional)"
                                    placeholder="Masukkan penjelasan singkat kategori jika diperlukan..."
                                    errors={errors.description}
                                    onChange={(e) =>
                                        setData("description", e.target.value)
                                    }
                                    value={data.description}
                                    rows={4}
                                />
                            </div>
                        </div>

                        {/* Footer Actions */}
                        <div className="flex justify-end gap-3 mt-6 pt-6 border-t border-slate-100 dark:border-slate-800">
                            <Link
                                href={route("categories.index")}
                                className="px-5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 font-bold text-xs uppercase tracking-widest transition-all"
                            >
                                Batal
                            </Link>
                            <button
                                type="submit"
                                disabled={processing}
                                className="inline-flex items-center gap-2 px-6 py-2.5 rounded-xl bg-primary-500 hover:bg-primary-600 text-white font-bold text-xs uppercase tracking-widest transition-all shadow-lg shadow-primary-500/25 active:scale-95 disabled:opacity-50"
                            >
                                <IconDeviceFloppy size={18} />
                                {processing ? "Menyimpan..." : "Simpan Kategori"}
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </>
    );
}

Create.layout = (page) => <DashboardLayout children={page} />;