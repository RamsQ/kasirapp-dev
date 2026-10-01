import React, { createContext, useContext, useState, useEffect } from 'react';

const ThemeSwitcher = createContext();

export const ThemeSwitcherProvider = ({ children }) => {
    // define state darkMode
    const [darkMode, setDarkMode] = useState(
        localStorage.getItem('darkMode') === 'true'
    )

    useEffect(() => {
        const root = document.documentElement; // Ini merujuk ke tag <html>

        const toggleTransition = () => {
            root.classList.add('no-transition');
            setTimeout(() => {
                root.classList.remove('no-transition');
            }, 0);
        };

        toggleTransition();

        // PERBAIKAN: Gunakan root (<html>) bukan document.body
        //
        // Hanya <html> yang boleh disentuh. Script di app.blade.php sudah
        // memasang kelas yang sama sebelum React mount, jadi di titik ini kita
        // cuma memastikan ulang, bukan memasang dari nol.
        //
        // ClassList.toggle dengan explicit second argument dipakai supaya
        // kelas yang berlawanan selalu dibersihkan. Dulu kode ini memakai
        // add() dan remove() dalam cabang if, sehingga kelas "light" dari
        // awal render bisa ikut tertinggal dan <html> berakhir punya
        // "light dark" sekaligus. Tailwind tetap membaca dark, tapi aturan
        // html.light body di app.blade.php ikut bentrok dan yang menang
        // ditentukan urutan di stylesheet, bukan tema yang sedang aktif.
        root.classList.toggle('dark', darkMode);
        root.classList.toggle('light', !darkMode);

        // set darkMode in localstorage
        localStorage.setItem('darkMode', darkMode);
    }, [darkMode]);

    const themeSwitcher = () => setDarkMode(!darkMode);

    return (
        <ThemeSwitcher.Provider value={{ darkMode, themeSwitcher }}>
            {children}
        </ThemeSwitcher.Provider>
    )
}

export const useTheme = () => useContext(ThemeSwitcher);