import * as faceapi from 'face-api.js';
import React, { useEffect, useRef, useState } from 'react';
import axios from 'axios';
import Swal from 'sweetalert2';
import { IconCamera, IconFaceId, IconLoader2, IconX } from '@tabler/icons-react';

export default function FaceRegistration() {
    const videoRef = useRef();
    const [modelsLoaded, setModelsLoaded] = useState(false);
    const [isCapturing, setIsCapturing] = useState(false);
    const [isRegistering, setIsRegistering] = useState(false);

    // 1. Load Model AI
    useEffect(() => {
        let isMounted = true;
        const loadModels = async () => {
            try {
                const MODEL_URL = '/models'; 
                await Promise.all([
                    faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL),
                    faceapi.nets.faceLandmark68Net.loadFromUri(MODEL_URL),
                    faceapi.nets.faceRecognitionNet.loadFromUri(MODEL_URL),
                ]);
                if (isMounted) setModelsLoaded(true);
            } catch (error) {
                console.error("Gagal memuat model FaceAPI:", error);
            }
        };
        loadModels();
        return () => { isMounted = false; };
    }, []);

    // 2. Jalankan Kamera
    const startCamera = async () => {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            return Swal.fire({
                title: 'Akses Kamera Ditolak',
                text: 'Browser memblokir kamera karena koneksi tidak aman (Bukan HTTPS). Jika di lokal, gunakan localhost, bukan IP.',
                icon: 'error',
                confirmButtonColor: '#4f46e5'
            });
        }

        try {
            setIsCapturing(true);
            const stream = await navigator.mediaDevices.getUserMedia({ 
                video: { 
                    facingMode: "user",
                    width: { ideal: 640 },
                    height: { ideal: 480 }
                } 
            });
            if (videoRef.current) {
                videoRef.current.srcObject = stream;
            }
        } catch (err) {
            console.error("Error Kamera:", err);
            setIsCapturing(false);
            Swal.fire('Gagal', 'Tidak dapat mengakses kamera. Pastikan izin diberikan.', 'error');
        }
    };

    // 3. Proses Registrasi Wajah (Updated Route & Payload)
    const handleRegister = async () => {
        if (!videoRef.current || isRegistering) return;

        setIsRegistering(true);
        try {
            const detection = await faceapi.detectSingleFace(
                videoRef.current, 
                new faceapi.TinyFaceDetectorOptions()
            ).withFaceLandmarks().withFaceDescriptor();

            if (detection) {
                /**
                 * PERBAIKAN DI SINI:
                 * 1. Menggunakan route 'profile.face.update' sesuai web.php
                 * 2. Mengirim key 'face_data' sesuai ProfileController@updateFace
                 */
                const response = await axios.post(route('profile.face.update'), { 
                    face_data: Array.from(detection.descriptor) 
                });

                // Laravel redirect back dengan success message
                Swal.fire({
                    title: 'Berhasil!',
                    text: 'Wajah Anda telah terdaftar sebagai kunci masuk.',
                    icon: 'success',
                    timer: 2000,
                    showConfirmButton: false
                });
                
                stopCamera();
                // Refresh halaman untuk memperbarui status UI di Profile Index
                window.location.reload(); 

            } else {
                Swal.fire('Wajah Tidak Terdeteksi', 'Pastikan wajah terlihat jelas di tengah kamera dengan pencahayaan yang cukup.', 'warning');
            }
        } catch (error) {
            console.error(error);
            Swal.fire('Gagal Menyimpan', 'Terjadi kesalahan saat mengirim data ke server.', 'error');
        } finally {
            setIsRegistering(false);
        }
    };

    // 4. Matikan Kamera
    const stopCamera = () => {
        if (videoRef.current && videoRef.current.srcObject) {
            const stream = videoRef.current.srcObject;
            const tracks = stream.getTracks();
            tracks.forEach(track => track.stop());
            videoRef.current.srcObject = null;
        }
        setIsCapturing(false);
    };

    return (
        <div className="p-6 bg-white dark:bg-slate-900 rounded-[2rem] border border-slate-200 dark:border-slate-800 transition-all duration-300">
            <div className="flex items-center gap-3 mb-6">
                <div className="p-2 bg-indigo-50 dark:bg-indigo-900/20 rounded-xl text-indigo-600">
                    <IconFaceId size={24} />
                </div>
                <h3 className="font-black text-slate-800 dark:text-white uppercase tracking-tight">Face ID Setup</h3>
            </div>

            {!isCapturing ? (
                <div className="text-center py-6 border-2 border-dashed border-slate-100 dark:border-slate-800 rounded-3xl">
                    <p className="text-sm text-slate-500 dark:text-slate-400 mb-6 px-4 font-medium">
                        Daftarkan wajah Anda untuk mengaktifkan fitur login biometrik tanpa password.
                    </p>
                    <button 
                        type="button"
                        onClick={startCamera} 
                        disabled={!modelsLoaded}
                        className="inline-flex items-center gap-2 bg-primary-600 hover:bg-primary-700 text-white px-8 py-3 rounded-2xl font-black uppercase text-[10px] tracking-widest transition-all shadow-lg shadow-primary-500/20 disabled:opacity-50"
                    >
                        {modelsLoaded ? (
                            <><IconCamera size={18} /> Mulai Registrasi</>
                        ) : (
                            <><IconLoader2 size={18} className="animate-spin" /> Memuat AI...</>
                        )}
                    </button>
                </div>
            ) : (
                <div className="space-y-6 animate-in fade-in zoom-in duration-300">
                    <div className="relative mx-auto w-full max-w-sm aspect-square bg-slate-100 dark:bg-slate-950 rounded-[2.5rem] overflow-hidden border-4 border-primary-500 shadow-2xl">
                        <video ref={videoRef} autoPlay muted className="w-full h-full object-cover scale-x-[-1]" />
                        <div className="absolute inset-0 border-[3px] border-dashed border-white/20 rounded-full m-10 animate-spin-slow pointer-events-none"></div>
                    </div>
                    
                    <div className="flex flex-col sm:flex-row gap-3">
                        <button 
                            type="button"
                            onClick={handleRegister} 
                            disabled={isRegistering}
                            className="flex-1 bg-green-600 hover:bg-green-700 text-white py-4 rounded-2xl font-black uppercase text-[10px] tracking-widest transition-all shadow-lg shadow-green-500/20 flex items-center justify-center gap-2"
                        >
                            {isRegistering ? <IconLoader2 size={18} className="animate-spin" /> : 'Ambil Sampel Wajah'}
                        </button>
                        <button 
                            type="button"
                            onClick={stopCamera} 
                            className="px-6 bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400 py-4 rounded-2xl font-black uppercase text-[10px] tracking-widest hover:bg-red-50 hover:text-red-600 transition-all flex items-center justify-center gap-2"
                        >
                            <IconX size={18} /> Batal
                        </button>
                    </div>
                </div>
            )}

            <style>{`
                .animate-spin-slow {
                    animation: spin 10s linear infinite;
                }
                @keyframes spin {
                    from { transform: rotate(0deg); }
                    to { transform: rotate(360deg); }
                }
            `}</style>
        </div>
    );
}