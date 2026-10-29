import React, { useState, useRef, useEffect } from "react";
import { Head, usePage, router } from "@inertiajs/react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { 
    IconSend, IconRobot, IconUser, IconLoader2, 
    IconChartBar, IconPackage, IconCash, IconMessages,
    IconCalendar, IconRefresh
} from "@tabler/icons-react";
import axios from "axios";

const SESSION_KEY = "lakupos.ai.session";

export default function Consultation() {
    const {
        auth,
        aiModel = 'gemini-3.6-flash',
        aiProvider = 'Gemini',
        aiConfigured = false,
        aiMaxMessageLength = 1000,
        periods = {},
        sessionId = null,
        history = [],
    } = usePage().props;

    const greeting = {
        role: 'ai',
        text: `Halo ${auth.user.name.split(' ')[0]}! Saya Business Coach AI Anda. Pilih periode di atas, lalu tanya apa saja tentang performa bisnis Anda.`
    };

    // Riwayat disimpan di server, sessionId hanya penanda agar percakapan
    // dilanjutkan lintas refresh halaman.
    const [messages, setMessages] = useState([greeting]);
    const [period, setPeriod] = useState('today');
    const [input, setInput] = useState('');
    const [loading, setLoading] = useState(false);
    const [session, setSession] = useState(sessionId);
    const scrollRef = useRef(null);
    const appliedHistory = useRef(null);

    useEffect(() => {
        if (scrollRef.current) {
            scrollRef.current.scrollTop = scrollRef.current.scrollHeight;
        }
    }, [messages, loading]);

    // Saat riwayat diterima/diubah dari server, bangun ulang tampilan chat.
    const toMessages = (turns) => [
        greeting,
        ...turns.map((turn) => ({
            role: turn.role === 'user' ? 'user' : 'ai',
            text: turn.content,
        })),
    ];

    useEffect(() => {
        if (history?.length && appliedHistory.current !== history) {
            appliedHistory.current = history;
            setMessages(toMessages(history));
        }
    }, [history]);

    // Riwayat disimpan di server. Saat halaman dibuka ulang, ambil kembali
    // percakapan terakhir dari session ini supaya konteks tidak hilang.
    useEffect(() => {
        if (sessionId) return;
        const stored = window.localStorage.getItem(SESSION_KEY);
        if (!stored) return;

        router.get(
            route("ai.index", { session: stored }),
            {},
            { preserveState: true, preserveScroll: true, replace: true, only: ["sessionId", "history"] }
        );
    }, [sessionId]);

    const sendMessage = async (userText) => {
        const textToSend = userText || input;
        if (!aiConfigured || !textToSend.trim() || loading) return;

        setMessages(prev => [...prev, { role: 'user', text: textToSend }]);
        setInput('');
        setLoading(true);

        try {
            const response = await axios.post(route('ai.chat'), {
                message: textToSend,
                period,
                session,
            });
            if (response.data.reply) {
                setMessages(prev => [...prev, { role: 'ai', text: response.data.reply }]);
            }
            if (response.data.session_id) {
                setSession(response.data.session_id);
                window.localStorage.setItem(SESSION_KEY, response.data.session_id);
            }
        } catch (error) {
            const serverError = error.response?.data?.reply || 'Koneksi ke AI terputus.';
            setMessages(prev => [...prev, { role: 'ai', text: serverError }]);
        } finally {
            setLoading(false);
        }
    };

    const startNewConversation = () => {
        window.localStorage.removeItem(SESSION_KEY);
        setSession(null);
        setMessages([greeting]);
    };

    const suggestions = [
        { label: "Omzet periode ini", icon: <IconCash size={16}/> },
        { label: "Kenapa berubah dari periode lalu?", icon: <IconChartBar size={16}/> },
        { label: "Stok kritis", icon: <IconPackage size={16}/> },
        { label: "Produk terlaris", icon: <IconChartBar size={16}/> },
    ];

    return (
        <>
            <Head title="AI Business Coach" />
            <div className="space-y-6">
                {/* Header Page */}
                <div className="flex justify-between items-center font-sans">
                    <div>
                        <h1 className="text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2">
                            <IconRobot className="text-primary-500 dark:text-primary-400" /> AI Business Coach
                        </h1>
                        <p className="text-sm text-slate-500 dark:text-slate-400 mt-1 uppercase font-bold tracking-tighter">
                            Gunakan asisten AI untuk menganalisis data performa LakuPOS Anda.
                        </p>
                    </div>
                </div>

                {/* Main Chat Container */}
                <div className="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden shadow-sm flex flex-col h-[650px]">
                    
                    {/* Status Engine Header */}
                    <div className="px-6 py-4 bg-slate-50 dark:bg-slate-800/50 border-b border-slate-100 dark:border-slate-800 flex justify-between items-center">
                        <div className="flex items-center gap-2">
                            <div className={`w-2 h-2 rounded-full ${aiConfigured ? 'bg-emerald-500 animate-pulse' : 'bg-amber-500'}`}></div>
                            <span className="text-[10px] font-black text-slate-500 dark:text-slate-400 uppercase tracking-widest">
                                {aiConfigured ? `${aiProvider} · ${aiModel}` : 'AI Belum Dikonfigurasi'}
                            </span>
                        </div>
                        <div className="flex items-center gap-2">
                            {session && (
                                <button
                                    type="button"
                                    onClick={startNewConversation}
                                    title="Mulai percakapan baru"
                                    className="flex items-center gap-1 px-2 py-1 rounded-lg text-[10px] font-black uppercase tracking-widest text-slate-500 dark:text-slate-400 hover:bg-slate-200 dark:hover:bg-slate-700 transition-all"
                                >
                                    <IconRefresh size={13} /> Percakapan Baru
                                </button>
                            )}
                            <IconMessages size={18} className="text-slate-500 dark:text-slate-400" />
                        </div>
                    </div>

                    {/* Pemilih Periode */}
                    <div className="px-6 py-3 bg-white dark:bg-slate-900 border-b border-slate-100 dark:border-slate-800 flex flex-wrap items-center gap-2">
                        <span className="flex items-center gap-1.5 text-[10px] font-black uppercase tracking-widest text-slate-500 dark:text-slate-400">
                            <IconCalendar size={13} /> Periode
                        </span>
                        {Object.entries(periods).map(([key, label]) => (
                            <button
                                key={key}
                                type="button"
                                onClick={() => setPeriod(key)}
                                className={`px-3 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-tight transition-all active:scale-95 ${
                                    period === key
                                        ? 'bg-primary-500 dark:bg-primary-600 text-white shadow-md shadow-primary-500/20'
                                        : 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400 hover:bg-slate-200 dark:hover:bg-slate-700'
                                }`}
                            >
                                {label}
                            </button>
                        ))}
                    </div>

                    {/* Chat Area */}
                    <div ref={scrollRef} className="flex-1 overflow-y-auto p-6 space-y-6 bg-white dark:bg-slate-900 custom-scrollbar font-sans">
                        {messages.map((msg, i) => (
                            <div key={i} className={`flex ${msg.role === 'user' ? 'justify-end' : 'justify-start'}`}>
                                <div className={`flex gap-3 max-w-[85%] ${msg.role === 'user' ? 'flex-row-reverse' : 'flex-row'}`}>
                                    <div className={`w-9 h-9 rounded-xl flex items-center justify-center shrink-0 shadow-sm ${
                                        msg.role === 'user' ? 'bg-slate-800 text-white' : 'bg-primary-500 dark:bg-primary-600 text-white shadow-lg shadow-primary-500/20'
                                    }`}>
                                        {msg.role === 'user' ? <IconUser size={18} /> : <IconRobot size={18} />}
                                    </div>
                                    <div className={`p-4 rounded-2xl text-sm leading-relaxed ${
                                        msg.role === 'user' 
                                        ? "bg-primary-500 dark:bg-primary-600 text-white rounded-tr-none shadow-lg shadow-primary-500/10 font-medium" 
                                        : "bg-slate-100 dark:bg-slate-800 text-slate-800 dark:text-slate-200 rounded-tl-none border border-slate-200 dark:border-slate-700 font-medium"
                                    }`}>
                                        <p className="whitespace-pre-wrap">{msg.text}</p>
                                    </div>
                                </div>
                            </div>
                        ))}

                        {loading && (
                            <div className="flex gap-3 justify-start items-center animate-pulse">
                                <div className="w-9 h-9 rounded-xl bg-primary-500 dark:bg-primary-600 flex items-center justify-center text-white shadow-lg shadow-primary-500/20">
                                    <IconLoader2 size={18} className="animate-spin" />
                                </div>
                                <div className="bg-slate-100 dark:bg-slate-800 px-4 py-2 rounded-2xl rounded-tl-none text-[10px] text-slate-500 dark:text-slate-400 uppercase font-black italic border border-slate-200 dark:border-slate-700 tracking-widest">
                                    Coach sedang berpikir...
                                </div>
                            </div>
                        )}
                    </div>

                    {/* Footer Input Area */}
                    <div className="p-6 bg-slate-50 dark:bg-slate-800/50 border-t border-slate-100 dark:border-slate-800">
                        <div className="flex flex-wrap gap-2 mb-4">
                            {suggestions.map((s, idx) => (
                                <button 
                                    key={idx} 
                                    onClick={() => sendMessage(s.label)} 
                                    disabled={loading || !aiConfigured}
                                    className="flex items-center gap-1.5 px-3 py-1.5 bg-white dark:bg-slate-900 rounded-lg border border-slate-200 dark:border-slate-700 text-[10px] font-black text-slate-600 dark:text-slate-400 hover:border-primary-500 hover:text-primary-500 transition-all active:scale-95 disabled:opacity-50 uppercase shadow-sm tracking-tight"
                                >
                                    {s.icon} {s.label}
                                </button>
                            ))}
                        </div>

                        <form onSubmit={(e) => { e.preventDefault(); sendMessage(); }} className="flex gap-3">
                            <input
                                type="text"
                                value={input}
                                onChange={(e) => setInput(e.target.value)}
                                disabled={loading || !aiConfigured}
                                maxLength={aiMaxMessageLength}
                                placeholder={aiConfigured ? 'Tanya sesuatu tentang tokomu...' : 'Hubungi administrator untuk mengaktifkan AI.'}
                                className="flex-1 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl text-sm py-3 px-4 text-slate-900 dark:text-white focus:ring-2 focus:ring-primary-500 focus:border-primary-500 outline-none transition-all shadow-inner font-sans disabled:cursor-not-allowed disabled:opacity-60"
                            />
                            <button
                                type="submit"
                                disabled={loading || !aiConfigured || !input.trim()}
                                className="bg-primary-500 dark:bg-primary-600 text-white px-5 rounded-xl hover:bg-primary-600 transition-all shadow-lg shadow-primary-500/20 active:scale-95 disabled:opacity-50 flex items-center justify-center"
                            >
                                <IconSend size={20} />
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <style dangerouslySetInnerHTML={{ __html: `
                .custom-scrollbar::-webkit-scrollbar { width: 4px; } 
                .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
                .dark .custom-scrollbar::-webkit-scrollbar-thumb { background: #334155; }
            ` }} />
        </>
    );
}

/** * PERBAIKAN UTAMA:
 * Kita pastikan props dialirkan ke DashboardLayout.
 * Ini memastikan Sidebar menerima data auth/url untuk merender Menu.
 */
Consultation.layout = (page) => <DashboardLayout children={page} {...page.props} />;
