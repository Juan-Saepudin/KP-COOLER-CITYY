<?php
/**
 * Komponen Sidebar - Sistem Inventory Cooler City
 * Desain 100% Mengacu pada Google Stitch AI
 */

$active_menu = $current_page ?? 'dashboard';
?>

<!-- Mobile Sidebar Backdrop -->
<div id="sidebarBackdrop" class="fixed inset-0 bg-black/50 z-40 hidden lg:hidden transition-opacity" aria-hidden="true"></div>

<!-- Sidebar Navigasi (Persis Desain Asli Stitch AI) -->
<aside id="appSidebar" class="fixed left-0 top-0 h-full w-64 bg-secondary z-50 flex flex-col justify-between shadow-[0_1px_8px_rgba(0,0,0,0.08)] -translate-x-full lg:translate-x-0 transition-transform duration-300">
  <div class="flex flex-col">
    <!-- Brand / Logo Header dengan background putih (surface-container-lowest) -->
    <div class="h-16 px-space-md flex items-center justify-between gap-space-sm bg-surface-container-lowest shadow-sm">
      <div class="flex items-center gap-space-sm">
        <img src="<?= base_url('assets/img/cooler_city_logo.png') ?>" alt="Cooler City Logo" class="h-12 w-auto object-contain">
        <div class="flex flex-col">
          <span class="font-headline-sm text-headline-sm text-primary-container font-black tracking-wide uppercase leading-none">
            COOLER CITY
          </span>
          <span class="font-label-md text-label-md text-tertiary-container font-semibold leading-tight">
            Icecream · Tea
          </span>
        </div>
      </div>
      <!-- Tombol Tutup Sidebar pada Mobile -->
      <button id="sidebarCloseBtn" class="lg:hidden text-slate-400 hover:text-slate-700 p-1" aria-label="Tutup Sidebar">
        <span class="material-symbols-outlined text-[20px]">close</span>
      </button>
    </div>

    <!-- Navigasi Menu Utama -->
    <div class="px-space-md py-space-sm">
      <div class="h-px bg-white/10 w-full mb-space-sm"></div>
      <span class="font-label-md text-label-md text-secondary-fixed uppercase tracking-wider px-space-xs block mb-space-xs">
        Menu Utama
      </span>
      <nav class="flex flex-col gap-space-xs" data-active-classes="bg-white/20 text-on-secondary font-headline-sm rounded-lg">
        <!-- 1. Dashboard -->
        <a href="<?= base_url('dashboard.php') ?>" 
           data-path="dashboard"
           class="flex items-center gap-space-sm px-space-sm py-space-sm rounded-lg transition-all <?= ($active_menu === 'dashboard') ? 'bg-white/20 text-on-secondary font-headline-sm' : 'text-on-secondary hover:bg-white/15 hover:text-on-secondary' ?>">
          <span class="material-symbols-outlined text-[20px] text-on-secondary">dashboard</span>
          <span class="font-body-md text-body-md">Dashboard</span>
        </a>

        <!-- 2. Data Bahan Baku -->
        <a href="<?= base_url('views/barang/index.php') ?>" 
           data-path="data-bahan-baku"
           class="flex items-center gap-space-sm px-space-sm py-space-sm rounded-lg transition-all <?= ($active_menu === 'barang') ? 'bg-white/20 text-on-secondary font-headline-sm' : 'text-on-secondary hover:bg-white/15 hover:text-on-secondary' ?>">
          <span class="material-symbols-outlined text-[20px] text-on-secondary">inventory_2</span>
          <span class="font-body-md text-body-md">Data Bahan Baku</span>
        </a>

        <!-- 3. Bahan Masuk -->
        <a href="<?= base_url('views/barangmasuk/index.php') ?>" 
           data-path="bahan-masuk"
           class="flex items-center gap-space-sm px-space-sm py-space-sm rounded-lg transition-all <?= ($active_menu === 'barangmasuk') ? 'bg-white/20 text-on-secondary font-headline-sm' : 'text-on-secondary hover:bg-white/15 hover:text-on-secondary' ?>">
          <span class="material-symbols-outlined text-[20px] text-on-secondary">input</span>
          <span class="font-body-md text-body-md">Bahan Masuk</span>
        </a>

        <!-- 4. Bahan Keluar -->
        <a href="<?= base_url('views/barangkeluar/index.php') ?>" 
           data-path="bahan-keluar"
           class="flex items-center gap-space-sm px-space-sm py-space-sm rounded-lg transition-all <?= ($active_menu === 'barangkeluar') ? 'bg-white/20 text-on-secondary font-headline-sm' : 'text-on-secondary hover:bg-white/15 hover:text-on-secondary' ?>">
          <span class="material-symbols-outlined text-[20px] text-on-secondary">output</span>
          <span class="font-body-md text-body-md">Bahan Keluar</span>
        </a>

        <!-- 5. Laporan -->
        <a href="<?= base_url('views/laporan/index.php') ?>" 
           data-path="laporan"
           class="flex items-center gap-space-sm px-space-sm py-space-sm rounded-lg transition-all <?= ($active_menu === 'laporan') ? 'bg-white/20 text-on-secondary font-headline-sm' : 'text-on-secondary hover:bg-white/15 hover:text-on-secondary' ?>">
          <span class="material-symbols-outlined text-[20px] text-on-secondary">bar_chart</span>
          <span class="font-body-md text-body-md">Laporan</span>
        </a>
      </nav>
    </div>
  </div>

  <!-- Footer Sidebar: Status Outlet Tanjungpinang -->
  <div class="p-space-md">
    <div class="bg-white/10 rounded-lg p-space-sm flex items-center justify-between">
      <div class="flex items-center gap-space-xs">
        <span class="material-symbols-outlined text-secondary-fixed text-[18px]">storefront</span>
        <div class="flex flex-col">
          <span class="font-label-md text-label-md text-on-secondary">Outlet Tanjungpinang</span>
          <span class="font-label-sm text-label-sm text-secondary-fixed">v2.4 Production</span>
        </div>
      </div>
      <span class="w-2 h-2 rounded-full bg-emerald-400" title="Aktif"></span>
    </div>
  </div>
</aside>

<!-- Wrapper Desktop Padding Left pl-64 -->
<div class="lg:pl-64 flex flex-col min-h-screen">
