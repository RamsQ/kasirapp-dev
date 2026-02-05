import React from "react";
import {
    IconShoppingBag,
    IconPhoto,
    IconMinus,
    IconPlus,
} from "@tabler/icons-react";
import { getProductImageUrl } from "@/Utils/imageUrl";

const formatPrice = (value = 0) =>
    value.toLocaleString("id-ID", {
        style: "currency",
        currency: "IDR",
        minimumFractionDigits: 0,
    });

// Single Product Card
function ProductCard({ product, onAddToCart, isAdding }) {
    const hasStock = product.stock > 0;
    const lowStock = product.stock > 0 && product.stock <= 5;

    return (
        <button
            onClick={() => hasStock && onAddToCart(product)}
            disabled={!hasStock || isAdding}
            className={`
                group relative flex flex-col bg-white dark:bg-slate-900
                rounded-2xl border border-slate-200 dark:border-slate-800
                overflow-hidden transition-all duration-200
                ${
                    hasStock
                        ? "hover:border-primary-300 dark:hover:border-primary-700 hover:shadow-lg hover:-translate-y-0.5 active:scale-[0.98] cursor-pointer"
                        : "opacity-60 cursor-not-allowed"
                }
            `}
        >
            {/* Product Image */}
            <div className="relative aspect-square bg-slate-100 dark:bg-slate-800 overflow-hidden">
                {product.image ? (
                    <img
                        src={getProductImageUrl(product.image)}
                        alt={product.title}
                        className="w-full h-full object-cover transition-transform duration-300 group-hover:scale-105"
                        loading="lazy"
                    />
                ) : (
                    <div className="w-full h-full flex items-center justify-center">
                        <IconPhoto
                            size={32}
                            className="text-slate-300 dark:text-slate-600"
                        />
                    </div>
                )}

                {/* Stock Badge */}
                {lowStock && (
                    <span className="absolute top-2 right-2 px-2 py-0.5 text-xs font-medium bg-warning-100 text-warning-700 dark:bg-warning-900/50 dark:text-warning-400 rounded-full">
                        Sisa {product.stock}
                    </span>
                )}

                {/* Out of Stock Overlay */}
                {!hasStock && (
                    <div className="absolute inset-0 bg-slate-900/60 flex items-center justify-center">
                        <span className="px-3 py-1 bg-danger-500 text-white text-xs font-semibold rounded-full">
                            Habis
                        </span>
                    </div>
                )}
            </div>

            {/* Product Info */}
            <div className="flex-1 p-3 flex flex-col justify-between min-h-[80px]">
                <h3 className="text-sm font-medium text-slate-800 dark:text-slate-200 line-clamp-2 leading-tight">
                    {product.title}
                </h3>
                <p className="mt-2 text-base font-bold text-primary-600 dark:text-primary-400">
                    {formatPrice(product.sell_price)}
                </p>
            </div>

            {/* Hover Add Indicator */}
            {hasStock && (
                <div className="absolute inset-0 bg-primary-500/10 opacity-0 group-hover:opacity-100 transition-opacity pointer-events-none flex items-center justify-center">
                    <div className="bg-primary-500 text-white px-4 py-2 rounded-full text-sm font-semibold shadow-lg transform translate-y-2 group-hover:translate-y-0 transition-transform">
                        + Tambah
                    </div>
                </div>
            )}
        </button>
    );
}

// Category Tab Button
function CategoryTab({ category, isActive, onClick }) {
    return (
        <button
            onClick={onClick}
            className={`
                px-4 py-2.5 rounded-xl text-sm font-medium whitespace-nowrap
                transition-all duration-200 min-h-touch
                ${
                    isActive
                        ? "bg-primary-500 text-white shadow-md shadow-primary-500/30"
                        : "bg-white dark:bg-slate-900 text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 border border-slate-200 dark:border-slate-700"
                }
            `}
        >
            {category.name}
        </button>
    );
}

// Search Input
function SearchInput({
    value,
    onChange,
    onSearch,
    isSearching,
    placeholder,
    inputRef,
}) {
    return (
        <div className="relative">
            <input
                ref={inputRef}
                type="text"
                value={value}
                onChange={(e) => onChange(e.target.value)}
                onKeyDown={(e) => e.key === "Enter" && onSearch?.()}
                placeholder={
                    placeholder ||
                    "Cari produk atau scan barcode... (/ untuk fokus)"
                }
                className="w-full h-12 pl-4 pr-12 rounded-xl border border-slate-200 dark:border-slate-700
                    bg-white dark:bg-slate-900 text-slate-800 dark:text-slate-200
                    placeholder-slate-400 dark:placeholder-slate-500
                    focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 dark:focus:border-primary-500
                    transition-all text-base"
                disabled={isSearching}
            />
            <div className="absolute right-3 top-1/2 -translate-y-1/2">
                {isSearching ? (
                    <div className="w-5 h-5 border-2 border-primary-500 border-t-transparent rounded-full animate-spin" />
                ) : (
                    <IconShoppingBag size={20} className="text-slate-400" />
                )}
            </div>
        </div>
    );
}

// Main ProductGrid Component
export default function ProductGrid({
    products = [],
    categories = [],
    selectedCategory,
    onCategoryChange,
    searchQuery,
    onSearchChange,
    onSearch,
    isSearching,
    onAddToCart,
    addingProductId,
    searchInputRef,
}) {
    // Filter products by category and search
    const filteredProducts = products.filter((product) => {
        const matchesCategory =
            !selectedCategory || product.category_id === selectedCategory;
        const matchesSearch =
            !searchQuery ||
            product.title.toLowerCase().includes(searchQuery.toLowerCase()) ||
            product.barcode?.toLowerCase().includes(searchQuery.toLowerCase());
        return matchesCategory && matchesSearch;
    });

    return (
        <div className="h-full flex flex-col">
            {/* Search Bar */}
            <div className="p-4 border-b border-slate-200 dark:border-slate-800">
                <SearchInput
                    value={searchQuery}
                    onChange={onSearchChange}
                    onSearch={onSearch}
                    isSearching={isSearching}
                    placeholder="Cari produk atau scan barcode... (tekan / untuk fokus)"
                    inputRef={searchInputRef}
                />
            </div>

            {/* Category Tabs */}
            <div className="px-4 py-3 border-b border-slate-200 dark:border-slate-800 overflow-x-auto scrollbar-hide">
                <div className="flex gap-2">
                    <CategoryTab
                        category={{ id: null, name: "Semua" }}
                        isActive={!selectedCategory}
                        onClick={() => onCategoryChange(null)}
                    />
                    {categories.map((category) => (
                        <CategoryTab
                            key={category.id}
                            category={category}
                            isActive={selectedCategory === category.id}
                            onClick={() => onCategoryChange(category.id)}
                        />
                    ))}
                </div>
            </div>

            {/* Products Grid */}
            <div className="flex-1 overflow-y-auto p-4 scrollbar-thin">
                {filteredProducts.length > 0 ? (
                    <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-3">
                        {filteredProducts.map((product) => (
                            <ProductCard
                                key={product.id}
                                product={product}
                                onAddToCart={onAddToCart}
                                isAdding={addingProductId === product.id}
                            />
                        ))}
                    </div>
                ) : (
                    <div className="h-full flex flex-col items-center justify-center text-slate-400 dark:text-slate-600">
                        <IconShoppingBag
                            size={48}
                            strokeWidth={1.5}
                            className="mb-3"
                        />
                        <p className="text-sm">
                            {searchQuery
                                ? "Produk tidak ditemukan"
                                : "Tidak ada produk"}
                        </p>
                    </div>
                )}
            </div>
        </div>
    );
}

// Export sub-components
ProductGrid.Card = ProductCard;
ProductGrid.CategoryTab = CategoryTab;
ProductGrid.SearchInput = SearchInput;
