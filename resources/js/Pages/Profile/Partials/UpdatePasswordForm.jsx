import { useRef } from 'react';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import { useForm } from '@inertiajs/react';
import { Transition } from '@headlessui/react';
import { IconLock, IconKey, IconShieldCheck } from '@tabler/icons-react';

export default function UpdatePasswordForm({ className = '' }) {
    const passwordInput = useRef();
    const currentPasswordInput = useRef();

    const { data, setData, errors, put, reset, processing, recentlySuccessful } = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    const updatePassword = (e) => {
        e.preventDefault();

        put(route('password.update'), {
            preserveScroll: true,
            onSuccess: () => reset(),
            onError: (errors) => {
                if (errors.password) {
                    reset('password', 'password_confirmation');
                    passwordInput.current.focus();
                }

                if (errors.current_password) {
                    reset('current_password');
                    currentPasswordInput.current.focus();
                }
            },
        });
    };

    return (
        <section className={className}>
            <header className="flex items-center gap-4">
                <div className="p-3 bg-amber-100 dark:bg-amber-900/30 rounded-2xl text-amber-600">
                    <IconShieldCheck size={24} />
                </div>
                <div>
                    {/* FIXED: text-slate-900 agar hitam pekat di mode terang */}
                    <h2 className="text-lg font-bold text-slate-900 dark:text-white">Keamanan Password</h2>
                    {/* FIXED: text-slate-700 agar lebih terbaca dibanding slate-600 */}
                    <p className="mt-1 text-sm text-slate-700 dark:text-slate-300">
                        Pastikan akun Anda menggunakan password yang kuat untuk tetap aman.
                    </p>
                </div>
            </header>

            <form onSubmit={updatePassword} className="mt-6 space-y-6">
                <div>
                    <div className="flex items-center gap-2 mb-2">
                        <IconKey size={16} className="text-slate-500 dark:text-slate-400" />
                        {/* FIXED: font-bold dan warna pekat untuk label */}
                        <InputLabel 
                            htmlFor="current_password" 
                            value="Password Saat Ini" 
                            className="!mb-0 !text-slate-900 dark:!text-white font-bold" 
                        />
                    </div>

                    <TextInput
                        id="current_password"
                        ref={currentPasswordInput}
                        value={data.current_password}
                        onChange={(e) => setData('current_password', e.target.value)}
                        type="password"
                        className="mt-1 block w-full !rounded-2xl border-slate-300 dark:border-slate-700 focus:ring-amber-500 focus:border-amber-500 dark:bg-slate-900 dark:text-white"
                        autoComplete="current-password"
                        placeholder="••••••••"
                    />

                    <InputError message={errors.current_password} className="mt-2" />
                </div>

                <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <div className="flex items-center gap-2 mb-2">
                            <IconLock size={16} className="text-slate-500 dark:text-slate-400" />
                            <InputLabel 
                                htmlFor="password" 
                                value="Password Baru" 
                                className="!mb-0 !text-slate-900 dark:!text-white font-bold" 
                            />
                        </div>

                        <TextInput
                            id="password"
                            ref={passwordInput}
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                            type="password"
                            className="mt-1 block w-full !rounded-2xl border-slate-300 dark:border-slate-700 focus:ring-amber-500 focus:border-amber-500 dark:bg-slate-900 dark:text-white"
                            autoComplete="new-password"
                            placeholder="Minimal 8 karakter"
                        />

                        <InputError message={errors.password} className="mt-2" />
                    </div>

                    <div>
                        <div className="flex items-center gap-2 mb-2">
                            <IconLock size={16} className="text-slate-500 dark:text-slate-400" />
                            <InputLabel 
                                htmlFor="password_confirmation" 
                                value="Konfirmasi Password" 
                                className="!mb-0 !text-slate-900 dark:!text-white font-bold" 
                            />
                        </div>

                        <TextInput
                            id="password_confirmation"
                            value={data.password_confirmation}
                            onChange={(e) => setData('password_confirmation', e.target.value)}
                            type="password"
                            className="mt-1 block w-full !rounded-2xl border-slate-300 dark:border-slate-700 focus:ring-amber-500 focus:border-amber-500 dark:bg-slate-900 dark:text-white"
                            autoComplete="new-password"
                            placeholder="Ulangi password baru"
                        />

                        <InputError message={errors.password_confirmation} className="mt-2" />
                    </div>
                </div>

                <div className="flex items-center gap-4 pt-2">
                    <PrimaryButton 
                        disabled={processing}
                        className="!bg-amber-500 hover:!bg-amber-600 !rounded-2xl !px-8 !py-3 shadow-lg shadow-amber-500/20 transition-all active:scale-95 text-white font-bold"
                    >
                        Update Password
                    </PrimaryButton>

                    <Transition
                        show={recentlySuccessful}
                        enter="transition ease-in-out"
                        enterFrom="opacity-0"
                        leave="transition ease-in-out"
                        leaveTo="opacity-0"
                    >
                        <p className="text-sm text-emerald-700 dark:text-emerald-400 font-bold flex items-center gap-1">
                             Berhasil disimpan.
                        </p>
                    </Transition>
                </div>
            </form>
        </section>
    );
}