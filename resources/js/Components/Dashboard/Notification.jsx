import React, { useState, useEffect, useRef } from 'react'
import { Menu, Transition } from '@headlessui/react'
import { Link, usePage } from '@inertiajs/react' // Import Link dan usePage
import { 
    IconBell, IconDots, IconCircleCheck, IconCircleCheckFilled, 
    IconAlertTriangle, IconCalendarCancel, IconChevronRight 
} from '@tabler/icons-react'

export default function Notification() {
    // --- INTEGRASI DATA DARI LARAVEL (INERTIA) ---
    const { notifications } = usePage().props;
    const lowStock = notifications?.low_stock_count || 0;
    const expired = notifications?.expired_count || 0;
    const totalAlerts = lowStock + expired;

    // Mapping data manual ke array untuk ditampilkan di list
    const notificationData = [];
    
    if (lowStock > 0) {
        notificationData.push({
            user: "Sistem Inventori",
            title: `${lowStock} Produk Stok Menipis`,
            time: "Hari Ini",
            icon: <IconAlertTriangle size={20} className="text-orange-500" />,
            is_read: 0,
            link: route('products.index')
        });
    }

    if (expired > 0) {
        notificationData.push({
            user: "Sistem Kontrol",
            title: `${expired} Produk Kadaluarsa`,
            time: "Hari Ini",
            icon: <IconCalendarCancel size={20} className="text-red-500" />,
            is_read: 0,
            link: route('reports.expired.index')
        });
    }

    // --- LOGIC BAWAAN TEMPLATE ---
    const [isMobile, setIsMobile] = useState(false);
    const [isOpen, setIsOpen] = useState(false);
    const notificationRef = useRef(null);

    const handleClickOutside = (event) => {
        if (notificationRef.current && !notificationRef.current.contains(event.target)) {
            setIsOpen(false);
        }
    };

    useEffect(() => {
        const handleResize = () => setIsMobile(window.innerWidth <= 768);
        window.addEventListener('resize', handleResize);
        window.addEventListener("mousedown", handleClickOutside);
        handleResize();
        return () => {
            window.removeEventListener('resize', handleResize);
            window.removeEventListener('mousedown', handleClickOutside);
        };
    }, []);

    // Reusable Component untuk item list
    const NotificationItem = ({ data }) => (
        <Link 
            href={data.link} 
            className='flex items-center justify-between w-full p-4 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-900 transition-colors border-b dark:border-gray-900 last:border-0'
            onClick={() => setIsOpen(false)}
        >
            <div className='flex items-center gap-4'>
                <div className='p-2 bg-gray-50 dark:bg-gray-800 rounded-lg'>
                    {data.icon}
                </div>
                <div>
                    <div className='font-semibold text-sm text-gray-700 dark:text-gray-200 '>
                        {data.user} <sup className='text-xs font-mono text-gray-400 ml-1'>{data.time}</sup>
                    </div>
                    <div className='text-gray-500 text-sm'>{data.title}</div>
                </div>
            </div>
            <IconChevronRight size={18} className="text-gray-300" />
        </Link>
    );

    return (
        <>
            {isMobile === false ? (
                <Menu className='relative z-50' as="div">
                    <Menu.Button className='flex items-center rounded-md group p-2 relative'>
                        {/* BADGE ANGKA (17) */}
                        {totalAlerts > 0 && (
                            <div className='absolute text-[10px] font-bold border-2 border-white dark:border-gray-950 bg-rose-500 text-white top-0 -right-1 rounded-full px-1.5 py-0.5 z-10 animate-pulse'>
                                {totalAlerts}
                            </div>
                        )}
                        <IconBell strokeWidth={1.5} size={20} className='text-gray-700 dark:text-gray-400' />
                    </Menu.Button>
                    <Transition
                        enter="transition duration-100 ease-out"
                        enterFrom="transform scale-95 opacity-0"
                        enterTo="transform scale-100 opacity-100"
                        leave="transition duration-75 ease-out"
                        leaveFrom="transform scale-100 opacity-100"
                        leaveTo="transform scale-95 opacity-0"
                    >
                        <Menu.Items className='absolute rounded-xl w-[400px] border right-0 top-10 z-[100] bg-white dark:bg-gray-950 dark:border-gray-900 shadow-2xl'>
                            <div className='flex justify-between items-center p-4 border-b dark:border-gray-900'>
                                <div className='text-lg font-bold text-gray-700 dark:text-gray-200'>Notifikasi Sistem</div>
                                <IconDots className='text-gray-400' size={20} />
                            </div>
                            <div className='p-2 max-h-80 overflow-y-auto'>
                                {notificationData.length === 0 ? (
                                    <div className='py-10 text-center text-sm text-gray-400 italic font-medium'>
                                        Semua stok & tanggal aman.
                                    </div>
                                ) : (
                                    notificationData.map((item, i) => (
                                        <NotificationItem key={i} data={item} />
                                    ))
                                )}
                            </div>
                        </Menu.Items>
                    </Transition>
                </Menu>
            ) : (
                <div ref={notificationRef}>
                    <button className='flex items-center rounded-md group p-2 relative' onClick={() => setIsOpen(!isOpen)}>
                        {totalAlerts > 0 && (
                            <div className='absolute text-[10px] font-bold bg-rose-500 text-white top-0 -right-1 rounded-full px-1.5 py-0.5 z-10'>
                                {totalAlerts}
                            </div>
                        )}
                        <IconBell strokeWidth={1.5} size={20} className='text-gray-500 dark:text-gray-400' />
                    </button>
                    <div className={`${isOpen ? 'translate-x-0 opacity-100 shadow-2xl' : 'translate-x-full opacity-0'} fixed top-0 right-0 z-[999] w-[300px] h-full transition-all duration-300 transform border-l bg-white dark:bg-gray-950 dark:border-gray-900`}>
                        <div className='flex justify-between items-center p-4 border-b dark:border-gray-900 '>
                            <div className='text-base font-bold text-gray-700 dark:text-gray-200'>Notifications</div>
                            <button onClick={() => setIsOpen(false)} className='p-2 text-gray-400'>✕</button>
                        </div>
                        <div className='p-2'>
                            {notificationData.length === 0 ? (
                                <div className='py-20 text-center text-gray-400 italic'>Kosong</div>
                            ) : (
                                notificationData.map((item, i) => (
                                    <NotificationItem key={i} data={item} />
                                ))
                            )}
                        </div>
                    </div>
                </div>
            )}
        </>
    )
}