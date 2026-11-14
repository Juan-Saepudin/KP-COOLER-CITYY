<?php
/**
 * Komponen Header & Top Navigation - Sistem Inventory Cooler City
 * Desain 100% Mengacu pada Google Stitch AI
 */

if (!function_exists('base_url')) {
    require_once __DIR__ . '/config/koneksi.php';
}

$current_user_name  = $_SESSION['nama_user'] ?? 'Lina';
$current_user_level = $_SESSION['level'] ?? 'owner';
$page_heading       = $page_title ?? 'DASHBOARD';
$active_menu        = $current_page ?? 'dashboard';

// Format label jabatan sesuai mockup
$current_user_level_label = ($current_user_level === 'owner') ? 'Manajer Operasional' : 'Staf Gudang';
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta content="width=device-width, initial-scale=1.0" name="viewport">
  <meta content="web_dashboard" name="shell-type">
  <title><?= htmlspecialchars($page_heading) ?> - Cooler City</title>
  
  <!-- Google Fonts: Inter & Material Symbols Outlined -->
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@100..900&amp;display=swap" rel="stylesheet">
  
  <style>
    @layer base {
      html, body { margin: 0; padding: 0; }
      body { overscroll-behavior: none; }
      main > :first-child { margin-top: 0 !important; }
      main > :last-child { margin-bottom: 0 !important; }
    }
    ::-webkit-scrollbar { display: none; }
  </style>

  <!-- Tailwind CSS CDN -->
  <script src="https://cdn.tailwindcss.com"></script>

  <!-- Chart.js CDN untuk Grafik Visualisasi -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
  
  <!-- Tailwind Configuration Sesuai Design Tokens Google Stitch AI -->
  <script id="tailwind-config">
    tailwind.config = {
      darkMode: "class",
      theme: {
        extend: {
          "colors": {
            "surface-dim": "#cbdbf5",
            "secondary": "#854d64",
            "on-primary": "#ffffff",
            "primary": "#00677d",
            "on-surface": "#0b1c30",
            "surface-variant": "#d3e4fe",
            "on-surface-variant": "#3d494d",
            "surface-tint": "#00677d",
            "on-secondary-fixed": "#360b20",
            "outline-variant": "#bcc9ce",
            "on-tertiary-fixed-variant": "#752e4b",
            "on-background": "#0b1c30",
            "on-secondary": "#ffffff",
            "primary-fixed": "#b3ebff",
            "secondary-fixed-dim": "#f9b3cd",
            "secondary-fixed": "#ffd9e5",
            "tertiary-fixed": "#ffd9e3",
            "on-secondary-container": "#7b455c",
            "on-error": "#ffffff",
            "tertiary-container": "#e48aaa",
            "on-error-container": "#93000a",
            "secondary-container": "#ffb8d3",
            "surface-bright": "#f8f9ff",
            "on-tertiary-fixed": "#3d011f",
            "primary-container": "#00b4d8",
            "primary-fixed-dim": "#4cd6fb",
            "inverse-surface": "#213145",
            "on-tertiary-container": "#66223f",
            "background": "#f8f9ff",
            "tertiary": "#924563",
            "on-primary-fixed-variant": "#004e5f",
            "on-tertiary": "#ffffff",
            "on-primary-fixed": "#001f27",
            "inverse-on-surface": "#eaf1ff",
            "surface-container": "#e5eeff",
            "tertiary-fixed-dim": "#ffb0cb",
            "surface": "#f8f9ff",
            "outline": "#6d797e",
            "on-secondary-fixed-variant": "#6a364c",
            "on-primary-container": "#00414f",
            "surface-container-high": "#dce9ff",
            "surface-container-highest": "#d3e4fe",
            "error": "#ba1a1a",
            "surface-container-lowest": "#ffffff",
            "error-container": "#ffdad6",
            "surface-container-low": "#eff4ff",
            "inverse-primary": "#4cd6fb"
          },
          "borderRadius": {
            "DEFAULT": "0.25rem",
            "lg": "0.5rem",
            "xl": "0.75rem",
            "full": "9999px"
          },
          "spacing": {
            "space-sm": "0.5rem",
            "space-lg": "1.5rem",
            "space-xs": "0.25rem",
            "gutter": "1rem",
            "space-md": "1rem",
            "space-xl": "2rem",
            "margin": "1rem"
          },
          "fontFamily": {
            "headline-lg": ["Inter"],
            "headline-md": ["Inter"],
            "label-sm": ["Inter"],
            "label-md": ["Inter"],
            "body-sm": ["Inter"],
            "metric-display": ["Inter"],
            "body-lg": ["Inter"],
            "headline-sm": ["Inter"],
            "body-md": ["Inter"],
            "headline-lg-mobile": ["Inter"],
            "label-lg": ["Inter"]
          },
          "fontSize": {
            "headline-lg": ["30px", { "lineHeight": "38px", "fontWeight": "700" }],
            "headline-md": ["20px", { "lineHeight": "26px", "fontWeight": "600" }],
            "label-sm": ["9px", { "lineHeight": "12px", "fontWeight": "700" }],
            "label-md": ["11px", { "lineHeight": "14px", "fontWeight": "600" }],
            "body-sm": ["11px", { "lineHeight": "16px", "fontWeight": "400" }],
            "metric-display": ["28px", { "lineHeight": "32px", "fontWeight": "700" }],
            "body-lg": ["15px", { "lineHeight": "22px", "fontWeight": "400" }],
            "headline-sm": ["16px", { "lineHeight": "22px", "fontWeight": "600" }],
            "body-md": ["13px", { "lineHeight": "18px", "fontWeight": "400" }],
            "headline-lg-mobile": ["22px", { "lineHeight": "28px", "fontWeight": "700" }],
            "label-lg": ["13px", { "lineHeight": "18px", "fontWeight": "600" }]
          }
        }
      }
    };
  </script>
</head>
<body class="bg-surface font-body-md text-on-surface antialiased">
  <svg class="inline-defs-container" aria-hidden="true" style="position:absolute;width:0;height:0;overflow:hidden"></svg>

  <!-- Top Navigation Header (Persis Desain Asli Stitch AI) -->
  <header class="fixed top-0 left-0 lg:left-64 right-0 h-16 bg-primary-container z-40 px-space-lg flex items-center justify-between shadow-[0_1px_8px_rgba(0,0,0,0.08)]">
    <!-- Header Title Left -->
    <div class="flex items-center gap-space-md">
      <!-- Mobile Hamburger Toggle -->
      <button id="sidebarToggle" class="lg:hidden text-on-primary p-1 rounded hover:bg-white/10 transition-colors focus:outline-none" aria-label="Toggle Navigation">
        <span class="material-symbols-outlined text-[24px]">menu</span>
      </button>
      <div class="flex flex-col">
        <span class="font-bold uppercase text-on-primary tracking-wide font-headline-sm text-headline-sm leading-tight">
          <?= strtoupper(htmlspecialchars($page_heading)) ?>
        </span>
        <span class="font-label-sm text-label-sm text-on-primary/90 font-medium leading-none">
          Sistem Inventory Cooler City
        </span>
      </div>
    </div>

    <!-- Header Actions Right (Profil Pengguna & Logout) -->
    <div class="flex items-center gap-space-md">
      <!-- User Profile Pill & Dropdown -->
      <div class="relative">
        <div id="userMenuBtn" class="flex items-center gap-space-sm pl-space-xs cursor-pointer select-none">
          <div class="text-right hidden sm:block">
            <div class="font-label-lg text-label-lg text-on-primary leading-tight">
              <?= htmlspecialchars($current_user_name) ?>
            </div>
            <div class="font-label-sm text-label-sm text-primary-fixed leading-tight">
              <?= htmlspecialchars($current_user_level_label) ?>
            </div>
          </div>
          <div class="w-8 h-8 rounded-full bg-primary flex items-center justify-center shadow-sm">
            <span class="material-symbols-outlined text-on-primary text-[18px]">person</span>
          </div>
        </div>

        <!-- User Dropdown Menu -->
        <div id="userDropdown" class="hidden absolute right-0 mt-2 w-48 bg-white rounded-xl shadow-lg border border-slate-100 py-1.5 z-50">
          <div class="px-4 py-2 border-b border-slate-100">
            <div class="font-bold text-slate-800 text-[13px]"><?= htmlspecialchars($current_user_name) ?></div>
            <div class="text-[11px] text-slate-500 uppercase font-semibold"><?= htmlspecialchars($current_user_level) ?></div>
          </div>
          <a href="<?= base_url('logout.php') ?>" class="flex items-center gap-2 px-4 py-2 text-[13px] text-red-600 hover:bg-red-50 transition-colors">
            <span class="material-symbols-outlined text-[18px]">logout</span>
            <span class="font-medium">Keluar Sistem</span>
          </a>
        </div>
      </div>
    </div>
  </header>
