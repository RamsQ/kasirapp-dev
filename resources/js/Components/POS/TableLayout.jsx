import React from 'react';

export default function TableLayout({ tables, onTableClick }) {
    return (
        <div className="grid grid-cols-4 gap-4 p-4">
            {tables.map((table) => (
                <button
                    key={table.id}
                    onClick={() => onTableClick(table)}
                    className={`p-6 rounded-xl border-2 transition-all ${
                        table.status === 'occupied' 
                        ? 'bg-red-50 border-red-500 text-red-700' 
                        : 'bg-green-50 border-green-500 text-green-700 hover:scale-105'
                    }`}
                >
                    <div className="font-bold text-lg">{table.name}</div>
                    <div className="text-xs uppercase">{table.status}</div>
                </button>
            ))}
        </div>
    );
}