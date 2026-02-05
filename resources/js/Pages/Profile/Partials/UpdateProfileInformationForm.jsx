import React from 'react';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import { useForm, usePage } from '@inertiajs/react';
import { Transition } from '@headlessui/react';
import { IconCamera, IconUser, IconMail } from '@tabler/icons-react';

export default function UpdateProfileInformation({ className = '' }) {
    const user = usePage().props.auth.user;

    const { data, setData, post, errors, processing, recentlySuccessful } = useForm({
        name: user.name,
        email: user.email,
        image: null, // File baru yang akan diupload
        // _method: 'PATCH' dihapus agar request dikirim sebagai POST murni.
        // Hal ini penting karena Laravel/PHP lebih stabil menangani upload file via POST.
    });

    const submit = (e) => {
        e.preventDefault();
        
        // Menggunakan post() murni ke route 'profile.update' yang sudah diatur sebagai Route::post di web.php
        post(route('profile.update'), {
            forceFormData: true, // Wajib true untuk pengiriman data berupa file (Multipart/Form-Data)
            preserveScroll: true,
            onSuccess: () => {
                // Reset state image untuk membersihkan memori preview
                setData('image', null);
            },
        });
    };

    // Helper untuk menentukan sumber gambar profil (Preview & Existing Data)
    const getProfileImage = () => {
        // 1. Jika ada file baru yang dipilih user (Preview sementara)
        if (data.image) {
            return URL.createObjectURL(data.image);
        }
        
        // 2. Jika user sudah memiliki gambar di database
        if (user.image) {
            // Cek jika data di DB adalah URL luar
            if (user.image.startsWith('http')) {
                return user.image;
            }
            // Jika data di DB adalah path lokal hasil upload
            return `/storage/users/${user.image}`;
        }

        // 3. Default avatar jika belum ada gambar
        return `https://ui-avatars.com/api/?name=${user.name}&background=random`;
    };

    return (
        <section className={className}>
            <header className="flex items-center gap-4">
                <div className="p-3 bg-primary-100 dark:bg-primary-900/30 rounded-2xl text-primary-600">
                    <IconUser size={24} />
                </div>
                <div>
                    <h2 className="text-lg font-bold text-slate-900 dark:text-white">Informasi Profil</h2>
                    <p className="mt-1 text-sm text-slate-700 dark:text-slate-300">
                        Perbarui informasi profil dan alamat email akun Anda.
                    </p>
                </div>
            </header>

            <form onSubmit={submit} className="mt-6 space-y-6">
                {/* Bagian Upload Foto */}
                <div className="flex flex-col sm:flex-row items-center gap-6 bg-slate-100/50 dark:bg-slate-800/50 p-6 rounded-[2rem] border border-slate-200 dark:border-slate-800">
                    <div className="relative group">
                        <img 
                            src={getProfileImage()} 
                            className="w-24 h-24 rounded-full object-cover border-4 border-white dark:border-slate-700 shadow-xl transition-transform group-hover:scale-105"
                            alt="Profile Preview"
                            onError={(e) => { e.target.src = `https://ui-avatars.com/api/?name=${user.name}&background=random` }}
                        />
                        <label className="absolute bottom-0 right-0 bg-primary-500 text-white p-2 rounded-full cursor-pointer shadow-lg hover:bg-primary-600 transition-all border-2 border-white dark:border-slate-900">
                            <IconCamera size={16} />
                            <input 
                                type="file" 
                                className="hidden" 
                                onChange={(e) => setData('image', e.target.files[0])} 
                                accept="image/png, image/jpg, image/jpeg" 
                            />
                        </label>
                    </div>
                    <div className="text-center sm:text-left">
                        <p className="text-xs font-bold text-slate-600 dark:text-slate-400 uppercase tracking-widest mb-1">Foto Profil</p>
                        <p className="text-[11px] text-slate-800 dark:text-slate-300 leading-relaxed font-medium">
                            Format: JPG, PNG, atau JPEG.<br />
                            Ukuran maksimal 2MB.
                        </p>
                        {errors.image && <InputError className="mt-2" message={errors.image} />}
                    </div>
                </div>

                {/* Input Nama */}
                <div>
                    <div className="flex items-center gap-2 mb-2">
                        <IconUser size={16} className="text-slate-500 dark:text-slate-400" />
                        <InputLabel htmlFor="name" value="Nama Lengkap" className="!mb-0 !text-slate-900 dark:!text-white font-bold" />
                    </div>
                    <TextInput
                        id="name"
                        className="mt-1 block w-full !rounded-2xl border-slate-300 dark:border-slate-700 focus:ring-primary-500 dark:bg-slate-900 dark:text-white"
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        required
                        autoComplete="name"
                    />
                    <InputError className="mt-2" message={errors.name} />
                </div>

                {/* Input Email */}
                <div>
                    <div className="flex items-center gap-2 mb-2">
                        <IconMail size={16} className="text-slate-500 dark:text-slate-400" />
                        <InputLabel htmlFor="email" value="Alamat Email" className="!mb-0 !text-slate-900 dark:!text-white font-bold" />
                    </div>
                    <TextInput
                        id="email"
                        type="email"
                        className="mt-1 block w-full !rounded-2xl border-slate-300 dark:border-slate-800 focus:ring-primary-500 dark:bg-slate-900 dark:text-white"
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                        required
                        autoComplete="username"
                    />
                    <InputError className="mt-2" message={errors.email} />
                </div>

                <div className="flex items-center gap-4 pt-2">
                    <PrimaryButton disabled={processing} className="!rounded-2xl !px-8 !py-3">
                        Simpan Perubahan
                    </PrimaryButton>

                    <Transition
                        show={recentlySuccessful}
                        enter="transition ease-in-out"
                        enterFrom="opacity-0"
                        leave="transition ease-in-out"
                        leaveTo="opacity-0"
                    >
                        <p className="text-sm text-emerald-700 dark:text-emerald-400 font-bold">Berhasil disimpan.</p>
                    </Transition>
                </div>
            </form>
        </section>
    );
}