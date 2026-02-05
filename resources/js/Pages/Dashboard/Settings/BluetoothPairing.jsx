import React, { useState, useEffect } from "react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { Head } from "@inertiajs/react";
import { 
    IconBluetooth, IconRefresh, IconPrinter, 
    IconCheck, IconX, IconAlertCircle 
} from "@tabler/icons-react";
import { BleClient } from "@capacitor-community/bluetooth-le";
import Swal from "sweetalert2";

export default function BluetoothPairing() {
    const [devices, setDevices] = useState([]);
    const [isScanning, setIsScanning] = useState(false);
    const [connectedDevice, setConnectedDevice] = useState(null);

    useEffect(() => {
        // Ambil data printer yang tersimpan di local storage
        const savedDevice = localStorage.getItem("selected_printer");
        if (savedDevice) {
            setConnectedDevice(JSON.parse(savedDevice));
        }
        
        // Inisialisasi library BLE
        BleClient.initialize();
    }, []);

    const startScan = async () => {
        try {
            setIsScanning(true);
            setDevices([]);
            
            await BleClient.requestLEScan({}, (result) => {
                if (result.device.name) {
                    setDevices((prev) => {
                        const exists = prev.find(d => d.deviceId === result.device.deviceId);
                        if (!exists) return [...prev, result.device];
                        return prev;
                    });
                }
            });

            // Scan selama 6 detik
            setTimeout(async () => {
                await BleClient.stopLEScan();
                setIsScanning(false);
            }, 6000);

        } catch (error) {
            console.error(error);
            setIsScanning(false);
            Swal.fire({
                icon: 'error',
                title: 'Bluetooth Error',
                text: 'Pastikan Bluetooth dan Lokasi (GPS) Anda sudah aktif.',
                background: '#0f172a',
                color: '#fff'
            });
        }
    };

    const savePrinter = (device) => {
        localStorage.setItem("selected_printer", JSON.stringify(device));
        setConnectedDevice(device);
        Swal.fire({
            icon: 'success',
            title: 'Printer Tersimpan',
            text: `${device.name} siap digunakan.`,
            timer: 2000,
            showConfirmButton: false
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
                            <h1 className="text-2xl font-black text-white uppercase tracking-tighter">Printer Bluetooth</h1>
                            <p className="text-slate-500 text-sm font-medium">Hubungkan perangkat kasir dengan printer thermal</p>
                        </div>
                        <div className={`p-4 rounded-3xl ${isScanning ? 'bg-emerald-500/20 text-emerald-500 animate-pulse' : 'bg-slate-800 text-slate-400'}`}>
                            <IconBluetooth size={32} />
                        </div>
                    </div>

                    {/* Status Terhubung */}
                    {connectedDevice ? (
                        <div className="mb-10 p-6 bg-emerald-600 rounded-[2rem] text-white flex items-center justify-between shadow-lg shadow-emerald-900/20">
                            <div className="flex items-center gap-4">
                                <div className="bg-white/20 p-3 rounded-2xl">
                                    <IconPrinter size={24} />
                                </div>
                                <div>
                                    <p className="text-[10px] font-black uppercase opacity-80 tracking-[0.2em]">Terhubung ke</p>
                                    <h3 className="text-lg font-bold">{connectedDevice.name}</h3>
                                    <p className="text-xs font-mono opacity-70">{connectedDevice.deviceId}</p>
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
                            <p className="text-slate-400 font-medium">Belum ada printer yang dipasangkan</p>
                        </div>
                    )}

                    {/* Scan Section */}
                    <div className="space-y-6">
                        <div className="flex items-center justify-between border-b border-slate-800 pb-4">
                            <h2 className="text-xs font-black uppercase tracking-widest text-slate-500">Perangkat Disekitar</h2>
                            <button 
                                onClick={startScan} 
                                disabled={isScanning}
                                className="flex items-center gap-2 px-4 py-2 bg-emerald-500 hover:bg-emerald-400 disabled:bg-slate-800 text-white text-xs font-black rounded-xl transition-all uppercase tracking-widest shadow-lg shadow-emerald-900/20"
                            >
                                <IconRefresh size={16} className={isScanning ? "animate-spin" : ""} />
                                {isScanning ? "Mencari..." : "Scan Printer"}
                            </button>
                        </div>

                        <div className="grid gap-3">
                            {devices.length === 0 && !isScanning && (
                                <div className="py-10 text-center text-slate-600 italic text-sm">
                                    Tekan tombol Scan untuk mulai mencari printer...
                                </div>
                            )}

                            {devices.map((device) => (
                                <div 
                                    key={device.deviceId}
                                    className="flex items-center justify-between p-5 bg-slate-800/30 border border-slate-800 rounded-2xl hover:border-emerald-500/50 transition-all group"
                                >
                                    <div className="flex items-center gap-4">
                                        <div className="bg-slate-800 p-3 rounded-xl group-hover:bg-emerald-500/10 group-hover:text-emerald-500 transition-colors">
                                            <IconPrinter size={20} />
                                        </div>
                                        <div>
                                            <h4 className="text-white font-bold">{device.name}</h4>
                                            <p className="text-[10px] text-slate-500 font-mono tracking-wider uppercase">{device.deviceId}</p>
                                        </div>
                                    </div>
                                    <button 
                                        onClick={() => savePrinter(device)}
                                        className="text-[10px] font-black uppercase tracking-widest px-4 py-2 rounded-lg bg-slate-800 text-slate-400 hover:bg-emerald-500 hover:text-white transition-all"
                                    >
                                        Hubungkan
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