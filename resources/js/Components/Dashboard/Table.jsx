import React from 'react'

const Card = ({ icon, title, className, children }) => {
    return (
        <>
            <div className={`p-4 rounded-t-lg border ${className} bg-white dark:bg-gray-950 dark:border-gray-900 `}>
                <div className='flex items-center gap-2 font-semibold text-sm text-gray-700 dark:text-gray-200'>
                    {title}
                </div>
            </div>
            <div className='bg-white dark:bg-gray-950 rounded-b-lg border-t-0 dark:border-gray-900'>
                {children}
            </div>
        </>

    )
}

const Table = ({ children }) => {
    return (
        // `overflow-hidden` sebelumnya ikut ditulis di sini berdampingan dengan
        // `overflow-x-auto`. Dua-duanya tidak bisa berlaku: yang menang
        // menentukan sumbu apa yang bisa digeser. Karena `Td` memakai
        // `whitespace-nowrap`, tabel dengan kolom banyak SELALU lebih lebar
        // dari layar HP — jadi yang menang harus x, kalau tidak kolom
        // terakhirnya terpotong dan tidak pernah bisa dijangkau.
        <div className="w-full overflow-x-auto border-collapse rounded-b-lg border border-t-0 dark:border-gray-900">
            <table className="w-full text-sm">
                {children}
            </table>
        </div>
    );
};

const Thead = ({ className, children }) => {
    return (
        <thead className={`${className} border-b bg-gray-50 dark:border-gray-900 dark:bg-gray-950`}>{children}</thead>
    );
};

const Tbody = ({ className, children }) => {
    return (
        <tbody className={`${className} divide-y bg-white dark:divide-gray-900 dark:bg-gray-950`}>
            {children}
        </tbody>
    );
};

const Td = ({ className, children }) => {
    return (
        // `px-3 sm:px-4` menggantikan padding tetap 1rem. Di layar 375px,
        // selisih padding 16px per sel menentukan berapa banyak kolom yang muat
        // sebelum harus digeser, dan tabel 9 kolom seperti Laporan Shift
        // sangat terbantu oleh selisih itu.
        <td
            className={`${className} whitespace-nowrap px-3 sm:px-4 py-3 sm:py-4 align-middle text-gray-700 dark:text-gray-400`}
        >
            {children}
        </td>
    );
};

const Th = ({ className, children }) => {
    return (
        <th
            scope="col"
            className={`${className} h-12 px-4 text-left align-middle font-medium text-gray-700 dark:text-gray-400`}
        >
            {children}
        </th>
    );
};

const Empty = ({ colSpan, message, children }) => {
    return (
        <tr>
            <td colSpan={colSpan}>
                <div className="flex items-center justify-center h-96">
                    <div className="text-center">
                        {children}
                        <div className="mt-5">
                            {message}
                        </div>
                    </div>
                </div>
            </td>
        </tr>
    )
}

Table.Card = Card;
Table.Thead = Thead;
Table.Tbody = Tbody;
Table.Td = Td;
Table.Th = Th;
Table.Empty = Empty;

export default Table;
