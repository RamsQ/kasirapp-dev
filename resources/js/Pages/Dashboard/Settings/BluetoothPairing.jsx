import React, { useState, useEffect } from "react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { Head } from "@inertiajs/react";
import { 
    IconBluetooth, IconRefresh, IconPrinter, 
    IconCheck, IconX, IconAlertCircle 
} from "@tabler/icons-react";
import Swal from "sweetalert2";

export default function BluetoothPairing() {
    const [devices, setDevices] = useState([]);
    const [isScanning, setIsScanning] = useState(false);
    const [connectedDevice, setConnectedDevice] = useState(null);

    // Variabel plugin global dari Cordova/Capacitor
    const btSerial = window.bluetoothSerial;

    useEffect(() => {
        // Ambil data printer yang tersimpan di local storage
        const savedDevice = localStorage.getItem("selected_printer");
        if (savedDevice) {
            setConnectedDevice(JSON.parse(savedDevice));
        }
    }, []);

    const startScan = () => {
        if (!btSerial) {
            Swal.fire({
                icon: 'info',
                title: 'Browser Mode',
                text: 'Fitur Bluetooth hanya tersedia saat aplikasi dijalankan di Android (APK).',
                background: '#0f172a',
                color: '#fff'
            });
            return;
        }

        try {
            setIsScanning(true);
            setDevices([]);
            
            // Mengambil daftar perangkat yang SUDAH di-pairing di sistem Android
            // Ini jauh lebih stabil untuk printer Panda daripada melakukan LE Scan
            btSerial.list((list) => {
                setDevices(list);
                setIsScanning(false);
                if (list.length === 0) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Tidak Ada Perangkat',
                        text: 'Pastikan Printer Panda sudah di-pairing (disambungkan) melalui menu Pengaturan Bluetooth HP Anda.',
                        background: '#0f172a',
                        color: '#fff'
                    });
                }
            }, (error) => {
                console.error(error);
                setIsScanning(false);
                Swal.fire({
                    icon: 'error',
                    title: 'Bluetooth Error',
                    text: 'Gagal memuat daftar perangkat: ' + error,
                    background: '#0f172a',
                    color: '#fff'
                });
            });

        } catch (error) {
            console.error(error);
            setIsScanning(false);
        }
    };

    const savePrinter = (device) => {
        // Simpan data perangkat ke localstorage (format disamakan agar BluetoothPrinter.jsx tidak error)
        const printerData = {
            name: device.name,
            address: device.address, // Bluetooth Serial menggunakan 'address'
            deviceId: device.address // Alias untuk kompatibilitas kode lama
        };

        localStorage.setItem("selected_printer", JSON.stringify(printerData));
        setConnectedDevice(printerData);

        Swal.fire({
            icon: 'success',
            title: 'Printer Tersimpan',
            text: `${device.name} siap digunakan.`,
            timer: 2000,
            showConfirmButton: false,
            background: '#0f172a',
            color: '#fff'
        });
    };

    const removePrinter = () => {
        localStorage.removeItem("selected_printer");
        setConnectedDevice(null);
    };

    return (
        <DashboardLayout>
            <Head title="Bluetooth Pairing" />
            
            <div className="p-4 md:p-8 max-w-3xl mx-auto">
                <div className="bg-slate-900 border border-slate-800 rounded-[2.5rem] p-6 md:p-10 shadow-2xl">
                    
                    {/* Header */}
                    <div className="flex items-center justify-between mb-10">
                        <div>
                            <h1 className="text-2xl font-black text-white uppercase tracking-tighter italic text-primary-500">Printer Bluetooth</h1>
                            <p className="text-slate-500 text-sm font-medium">Model: Panda PRJ CX-58B (Serial Mode)</p>
                        </div>
                        <div className={`p-4 rounded-3xl ${isScanning ? 'bg-primary-500/20 text-primary-500 animate-pulse' : 'bg-slate-800 text-slate-400'}`}>
                            <IconBluetooth size={32} />
                        </div>
                    </div>

                    {/* Status Terhubung */}
                    {connectedDevice ? (
                        <div className="mb-10 p-6 bg-primary-600 rounded-[2rem] text-white flex items-center justify-between shadow-lg shadow-primary-900/20">
                            <div className="flex items-center gap-4">
                                <div className="bg-white/20 p-3 rounded-2xl">
                                    <IconPrinter size={24} />
                                </div>
                                <div>
                                    <p className="text-[10px] font-black uppercase opacity-80 tracking-[0.2em]">Terhubung ke</p>
                                    <h3 className="text-lg font-bold">{connectedDevice.name}</h3>
                                    <p className="text-xs font-mono opacity-70">{connectedDevice.address || connectedDevice.deviceId}</p>
                                </div>
                            </div>
                            <button 
                                onClick={removePrinter}
                                className="bg-white/10 hover:bg-white/20 p-2 rounded-xl transition-colors"
                            >
                                <IconX size={20} />
                            </button>
                        </div>
                    ) : (
                        <div className="mb-10 p-6 bg-slate-800/50 border border-dashed border-slate-700 rounded-[2rem] text-center">
                            <IconAlertCircle className="mx-auto text-slate-500 mb-2" size={32} />
                            <p className="text-slate-400 font-medium">Belum ada printer yang dipilih</p>
                            <p className="text-slate-600 text-[10px] mt-1 uppercase font-bold tracking-widest">Pairing printer di menu setting HP dulu</p>
                        </div>
                    )}

                    {/* Scan Section */}
                    <div className="space-y-6">
                        <div className="flex items-center justify-between border-b border-slate-800 pb-4">
                            <h2 className="text-xs font-black uppercase tracking-widest text-slate-500">Daftar Perangkat Terpasang</h2>
                            <button 
                                onClick={startScan} 
                                disabled={isScanning}
                                className="flex items-center gap-2 px-6 py-2.5 bg-primary-600 hover:bg-primary-500 disabled:bg-slate-800 text-white text-[10px] font-black rounded-xl transition-all uppercase tracking-widest shadow-lg shadow-primary-900/20"
                            >
                                <IconRefresh size={16} className={isScanning ? "animate-spin" : ""} />
                                {isScanning ? "Memuat..." : "Muat Daftar"}
                            </button>
                        </div>

                        <div className="grid gap-3">
                            {devices.length === 0 && !isScanning && (
                                <div className="py-10 text-center text-slate-600 italic text-sm">
                                    Tekan tombol Muat Daftar untuk melihat printer yang sudah dipairing...
                                </div>
                            )}

                            {devices.map((device, index) => (
                                <div 
                                    key={index}
                                    className="flex items-center justify-between p-5 bg-slate-800/30 border border-slate-800 rounded-2xl hover:border-primary-500/50 transition-all group"
                                >
                                    <div className="flex items-center gap-4">
                                        <div className="bg-slate-800 p-3 rounded-xl group-hover:bg-primary-500/10 group-hover:text-primary-500 transition-colors">
                                            <IconPrinter size={20} />
                                        </div>
                                        <div>
                                            <h4 className="text-white font-bold">{device.name || 'Unknown Device'}</h4>
                                            <p className="text-[10px] text-slate-500 font-mono tracking-wider uppercase">{device.address}</p>
                                        </div>
                                    </div>
                                    <button 
                                        onClick={() => savePrinter(device)}
                                        className="text-[10px] font-black uppercase tracking-widest px-4 py-2 rounded-lg bg-slate-800 text-slate-400 hover:bg-primary-500 hover:text-white transition-all"
                                    >
                                        Pilih
                                    </button>
                                </div>
                            ))}
                        </div>
                    </div>
                </div>
            </div>
        </DashboardLayout>
    );
}