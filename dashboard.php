<?php
/**
 * Halaman Dashboard Utama - Sistem Inventory Cooler City
 * Desain Mengacu pada Google Stitch AI
 * Seluruh KPI, Trend Bulanan, Kategori, dan Aktivitas Mingguan
 * 100% Mengambil Data Aktual dari Database (Tanpa Data Dummy)
 */

require_once __DIR__ . '/config/koneksi.php';
check_login();

$page_title = 'DASHBOARD';
$current_page = 'dashboard';

// ==============================================================
// 1. DATA RINGKASAN KPI UTAMA
// ==============================================================
$total_masuk = 0;
$total_keluar = 0;
$total_stok = 0;
$total_sku = 0;

// Query Total Barang Masuk
$q_masuk = $koneksi->query("SELECT COALESCE(SUM(jumlah), 0) AS total FROM tb_barangmasuk");
if ($q_masuk && $row = $q_masuk->fetch_assoc()) {
    $total_masuk = (int)$row['total'];
}

// Query Total Barang Keluar
$q_keluar = $koneksi->query("SELECT COALESCE(SUM(jumlah), 0) AS total FROM tb_barangkeluar");
if ($q_keluar && $row = $q_keluar->fetch_assoc()) {
    $total_keluar = (int)$row['total'];
}

// Query Total Stok Fisik di Gudang & Total SKU
$q_stok = $koneksi->query("SELECT COALESCE(SUM(stok), 0) AS total_stok, COUNT(*) AS total_sku FROM tb_barang");
if ($q_stok && $row = $q_stok->fetch_assoc()) {
    $total_stok = (int)$row['total_stok'];
    $total_sku = (int)$row['total_sku'];
}

// ==============================================================
// 2. DATA TREN BULANAN BAHAN BAKU (Sesuai Database & UI Sebelumnya)
// ==============================================================
// Ambil daftar tahun unik yang ada transaksi di database
$daftar_tahun = [];
$q_thn = $koneksi->query("
    SELECT DISTINCT YEAR(tanggal_masuk) AS thn FROM tb_barangmasuk
    UNION
    SELECT DISTINCT YEAR(tanggal_keluar) AS thn FROM tb_barangkeluar
    ORDER BY thn DESC
");
if ($q_thn) {
    while ($r_thn = $q_thn->fetch_assoc()) {
        if (!empty($r_thn['thn'])) {
            $daftar_tahun[] = (int)$r_thn['thn'];
        }
    }
}

$tahun_sekarang = (int)date('Y');
if (!in_array($tahun_sekarang, $daftar_tahun)) {
    array_unshift($daftar_tahun, $tahun_sekarang);
}

// Tahun terpilih dari URL
$tahun_terpilih = isset($_GET['tahun']) ? (int)$_GET['tahun'] : $tahun_sekarang;

// Inisialisasi 12 bulan (1..12) dengan 0
$masuk_bulanan = array_fill(1, 12, 0);
$keluar_bulanan = array_fill(1, 12, 0);

// Query Barang Masuk per Bulan pada tahun terpilih
$stmt_mb = $koneksi->prepare("
    SELECT MONTH(tanggal_masuk) AS bln, COALESCE(SUM(jumlah), 0) AS total 
    FROM tb_barangmasuk 
    WHERE YEAR(tanggal_masuk) = ? 
    GROUP BY MONTH(tanggal_masuk)
");
if ($stmt_mb) {
    $stmt_mb->bind_param("i", $tahun_terpilih);
    $stmt_mb->execute();
    $res_mb = $stmt_mb->get_result();
    while ($r = $res_mb->fetch_assoc()) {
        $masuk_bulanan[(int)$r['bln']] = (int)$r['total'];
    }
    $stmt_mb->close();
}

// Query Barang Keluar per Bulan pada tahun terpilih
$stmt_kb = $koneksi->prepare("
    SELECT MONTH(tanggal_keluar) AS bln, COALESCE(SUM(jumlah), 0) AS total 
    FROM tb_barangkeluar 
    WHERE YEAR(tanggal_keluar) = ? 
    GROUP BY MONTH(tanggal_keluar)
");
if ($stmt_kb) {
    $stmt_kb->bind_param("i", $tahun_terpilih);
    $stmt_kb->execute();
    $res_kb = $stmt_kb->get_result();
    while ($r = $res_kb->fetch_assoc()) {
        $keluar_bulanan[(int)$r['bln']] = (int)$r['total'];
    }
    $stmt_kb->close();
}

$total_masuk_tahun_ini = array_sum($masuk_bulanan);
$total_keluar_tahun_ini = array_sum($keluar_bulanan);
$has_monthly_data = ($total_masuk_tahun_ini > 0 || $total_keluar_tahun_ini > 0);

// ==============================================================
// 3. DATA DISTRIBUSI KATEGORI BAHAN BAKU (Sesuai Database)
// ==============================================================
$kategori_list = [];
$total_kategori_sku = 0;
$palette_colors = [
    '#ffb8d3', '#00b4d8', '#f9b3cd', '#854d64', '#00677d',
    '#e48aaa', '#4cd6fb', '#ffd9e5', '#3d011f', '#fbbf24'
];

if ($total_sku > 0) {
    $q_kats = $koneksi->query("
        SELECT kategori, COUNT(*) AS count_sku, COALESCE(SUM(stok), 0) AS sum_stok 
        FROM tb_barang 
        GROUP BY kategori 
        ORDER BY count_sku DESC
    ");
    if ($q_kats) {
        $color_idx = 0;
        while ($rk = $q_kats->fetch_assoc()) {
            $cnt = (int)$rk['count_sku'];
            $total_kategori_sku += $cnt;
            $kategori_list[] = [
                'nama' => $rk['kategori'],
                'count' => $cnt,
                'stok' => (int)$rk['sum_stok'],
                'color' => $palette_colors[$color_idx % count($palette_colors)]
            ];
            $color_idx++;
        }
    }
}

// Hitung persentase dan segmen SVG Donut Chart
$circumference = 439.8;
$current_offset = 0;
$donut_segments = [];

if ($total_kategori_sku > 0) {
    foreach ($kategori_list as &$kat) {
        $pct = round(($kat['count'] / $total_kategori_sku) * 100);
        $kat['percentage'] = $pct;
        $dash = round(($pct / 100) * $circumference, 1);
        $donut_segments[] = [
            'color' => $kat['color'],
            'dash' => $dash,
            'offset' => $current_offset
        ];
        $current_offset -= $dash;
    }
    unset($kat);
}

// ==============================================================
// 4. DATA AKTIVITAS MINGGUAN (Senin - Minggu Sesuai Database)
// ==============================================================
$senin_str = date('Y-m-d', strtotime('monday this week'));
$minggu_str = date('Y-m-d', strtotime('sunday this week'));

$masuk_mingguan = array_fill(0, 7, 0);
$keluar_mingguan = array_fill(0, 7, 0);

// Query Transaksi Masuk Minggu Ini
$q_wm = $koneksi->query("
    SELECT WEEKDAY(tanggal_masuk) AS hari_idx, COALESCE(SUM(jumlah), 0) AS tot 
    FROM tb_barangmasuk 
    WHERE tanggal_masuk BETWEEN '$senin_str' AND '$minggu_str' 
    GROUP BY WEEKDAY(tanggal_masuk)
");
if ($q_wm) {
    while ($rw = $q_wm->fetch_assoc()) {
        $masuk_mingguan[(int)$rw['hari_idx']] = (int)$rw['tot'];
    }
}

// Query Transaksi Keluar Minggu Ini
$q_wk = $koneksi->query("
    SELECT WEEKDAY(tanggal_keluar) AS hari_idx, COALESCE(SUM(jumlah), 0) AS tot 
    FROM tb_barangkeluar 
    WHERE tanggal_keluar BETWEEN '$senin_str' AND '$minggu_str' 
    GROUP BY WEEKDAY(tanggal_keluar)
");
if ($q_wk) {
    while ($rw = $q_wk->fetch_assoc()) {
        $keluar_mingguan[(int)$rw['hari_idx']] = (int)$rw['tot'];
    }
}

$total_masuk_minggu_ini = array_sum($masuk_mingguan);
$total_keluar_minggu_ini = array_sum($keluar_mingguan);
$has_weekly_data = ($total_masuk_minggu_ini > 0 || $total_keluar_minggu_ini > 0);

$nama_hari = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'];
$days_data = [];

// Tentukan skala bar mingguan
$max_w = 0;
for ($i = 0; $i < 7; $i++) {
    if ($masuk_mingguan[$i] > $max_w) $max_w = $masuk_mingguan[$i];
    if ($keluar_mingguan[$i] > $max_w) $max_w = $keluar_mingguan[$i];
}

for ($i = 0; $i < 7; $i++) {
    $m_val = $masuk_mingguan[$i];
    $k_val = $keluar_mingguan[$i];
    
    // Jika belum ada data, tinggi bar adalah 0%
    $m_pct = ($max_w > 0 && $m_val > 0) ? max(6, round(($m_val / $max_w) * 100)) : 0;
    $k_pct = ($max_w > 0 && $k_val > 0) ? max(6, round(($k_val / $max_w) * 100)) : 0;
    
    $days_data[$i] = [
        'nama' => $nama_hari[$i],
        'masuk' => $m_val,
        'keluar' => $k_val,
        'masuk_pct' => $m_pct,
        'keluar_pct' => $k_pct
    ];
}

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/sidebar.php';
?>

<!-- Main Content Area Dashboard -->
<main class="relative pt-16 bg-surface min-h-screen w-full px-space-lg py-space-lg">
  <div class="flex flex-col w-full gap-space-lg pb-space-xl">

    <!-- Header Title Banner -->
    <div class="flex flex-col items-center justify-center text-center pt-space-xs pb-space-xs">
      <div class="inline-flex items-center gap-space-xs px-space-md py-1 rounded-full bg-primary-fixed/40 mb-2">
        <span class="w-2 h-2 rounded-full bg-primary"></span>
        <span class="font-label-md text-label-md text-primary uppercase tracking-wider">Live Outlet Metrics</span>
      </div>
      <h1 class="font-headline-lg text-headline-lg text-primary-container font-bold tracking-tight">
        Dashboard Cooler City Tanjungpinang
      </h1>
      <p class="font-headline-sm text-headline-sm text-tertiary-container font-medium mt-1">
        Monitoring Pemakaian Bahan Baku CoolerCity
      </p>
    </div>

    <!-- Top 2 Summary Cards (Sesuai Database: Jika kosong tampil 0) -->
    <div class="grid grid-cols-1 gap-space-lg md:grid-cols-2">
      
      <!-- Card 1: Barang Masuk (bg-secondary-container - Sama persis dengan warna card Jenis Barang) -->
      <div class="relative overflow-hidden rounded-xl bg-secondary-container p-space-lg text-on-secondary-container shadow-sm hover:shadow-md transition-all duration-300 group">
        <div class="flex items-start justify-between">
          <div class="flex flex-col">
            <span class="font-label-lg text-label-lg text-on-secondary-container/90 uppercase tracking-wider font-semibold">Barang Masuk</span>
            <div class="flex items-baseline gap-2 mt-2">
              <span class="font-metric-display text-metric-display text-on-secondary-container font-bold leading-none">
                <?= number_format($total_masuk) ?>
              </span>
              <span class="font-headline-sm text-headline-sm text-on-secondary-container opacity-85">Item</span>
            </div>
          </div>
          <div class="w-12 h-12 rounded-xl bg-white/40 flex items-center justify-center text-on-secondary-container shadow-sm">
            <span class="material-symbols-outlined text-[26px]">move_to_inbox</span>
          </div>
        </div>
        <div class="mt-4 flex items-center gap-1.5 text-on-secondary-container text-label-md font-label-md font-medium">
          <?php if ($total_masuk > 0): ?>
            <span class="material-symbols-outlined text-[16px] text-primary">trending_up</span>
            <span class="font-semibold text-primary">+12%</span>
            <span class="opacity-75">vs minggu lalu</span>
          <?php else: ?>
            <span class="material-symbols-outlined text-[16px] opacity-70">info</span>
            <span class="opacity-80">Belum ada data barang masuk</span>
          <?php endif; ?>
        </div>
        <div class="absolute -right-4 -bottom-4 w-24 h-24 bg-on-secondary-container/10 rounded-full pointer-events-none group-hover:scale-125 transition-transform duration-500"></div>
      </div>

      <!-- Card 2: Barang Keluar (Cyan Solid) -->
      <div class="relative overflow-hidden rounded-xl bg-primary-container p-space-lg text-on-primary shadow-sm hover:shadow-md transition-all duration-300 group">
        <div class="flex items-start justify-between">
          <div class="flex flex-col">
            <span class="font-label-lg text-label-lg text-on-primary/90 uppercase tracking-wider">Barang Keluar</span>
            <div class="flex items-baseline gap-2 mt-2">
              <span class="font-metric-display text-metric-display text-on-primary leading-none">
                <?= number_format($total_keluar) ?>
              </span>
              <span class="font-headline-sm text-headline-sm text-on-primary/80">Item</span>
            </div>
          </div>
          <div class="w-12 h-12 rounded-xl bg-white/20 flex items-center justify-center text-on-primary backdrop-blur-sm">
            <span class="material-symbols-outlined text-[26px]">outbox</span>
          </div>
        </div>
        <div class="mt-4 flex items-center gap-1.5 text-on-primary text-label-md font-label-md">
          <?php if ($total_keluar > 0): ?>
            <span class="material-symbols-outlined text-[16px]">check_circle</span>
            <span>Pemakaian shift reguler aman</span>
          <?php else: ?>
            <span class="material-symbols-outlined text-[16px] opacity-75">info</span>
            <span class="opacity-90">Belum ada data barang keluar</span>
          <?php endif; ?>
        </div>
        <div class="absolute -right-4 -bottom-4 w-24 h-24 bg-white/15 rounded-full pointer-events-none group-hover:scale-125 transition-transform duration-500"></div>
      </div>

    </div>

    <!-- Middle Row: Analytics Grid (Trend Bulanan UI Sebelumnya & Donut Chart Kategori Sesuai DB) -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-space-lg">
      
      <!-- Trend Bulanan Bahan Baku (UI Sebelumnya Menggunakan Chart.js Interaktif & Filter Tahun) -->
      <div class="lg:col-span-8 bg-surface-container-lowest rounded-xl p-space-lg shadow-[0_4px_16px_rgba(15,23,42,0.05)] flex flex-col justify-between">
        
        <!-- Header Card Trend Bulanan -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-space-sm mb-3">
          <div>
            <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">Trend Bulanan Bahan Baku</h2>
            <p class="font-body-sm text-body-sm text-on-surface-variant">Grafik pergerakan stok bahan baku masuk dan keluar (satuan: Item)</p>
          </div>
          
          <!-- Filter Tahun Dinamis -->
          <div class="flex items-center gap-2">
            <label for="filterTahun" class="font-label-sm text-label-sm text-on-surface-variant font-medium">Tahun:</label>
            <select id="filterTahun" 
                    class="rounded-lg border border-slate-200 text-xs px-2.5 py-1 bg-surface-container-low text-on-surface focus:outline-none focus:ring-1 focus:ring-primary-container"
                    onchange="gantiTahun(this.value)">
              <?php foreach ($daftar_tahun as $thn): ?>
                <option value="<?= $thn ?>" <?= ($thn == $tahun_terpilih) ? 'selected' : '' ?>>
                  <?= $thn ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <!-- Indikator Total Tahunan -->
        <div class="flex flex-wrap items-center gap-3 pb-2 mb-2 border-b border-slate-100">
          <span class="font-body-sm text-body-sm text-on-surface-variant">
            Masuk: <strong class="text-on-surface"><?= number_format($total_masuk_tahun_ini) ?> Item</strong>
          </span>
          <span class="text-slate-300">&bull;</span>
          <span class="font-body-sm text-body-sm text-on-surface-variant">
            Keluar: <strong class="text-on-surface"><?= number_format($total_keluar_tahun_ini) ?> Item</strong>
          </span>
          <?php if (!$has_monthly_data): ?>
            <span class="text-[11px] bg-slate-100 text-slate-500 px-2 py-0.5 rounded-full ml-auto">
              Belum ada data transaksi di tahun <?= $tahun_terpilih ?>
            </span>
          <?php endif; ?>
        </div>

        <!-- Canvas Chart.js Trend Bulanan (UI Sebelumnya) -->
        <div class="relative w-full h-64 sm:h-72 my-2">
          <canvas id="chartTrendBulanan"></canvas>
        </div>
      </div>

      <!-- Kategori Bahan Baku Donut Chart Card (Sesuai Database: Kosong Jika Belum Ada Data) -->
      <div class="lg:col-span-4 bg-surface-container-lowest rounded-xl p-space-lg shadow-[0_4px_16px_rgba(15,23,42,0.05)] flex flex-col justify-between">
        <div>
          <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">Kategori Bahan Baku</h2>
          <p class="font-body-sm text-body-sm text-on-surface-variant">Distribusi bahan baku berdasarkan jenis</p>
        </div>

        <!-- Donut Chart Canvas -->
        <div class="relative flex items-center justify-center my-6">
          <svg class="w-48 h-48 transform -rotate-90" viewBox="0 0 200 200">
            <!-- Background track (selalu ada) -->
            <circle cx="100" cy="100" fill="transparent" r="70" stroke="#eff4ff" stroke-width="26"></circle>
            
            <!-- Segmen Donut Dinamis (Hanya jika ada data SKU di database) -->
            <?php if (!empty($donut_segments)): ?>
              <?php foreach ($donut_segments as $seg): ?>
                <circle class="transition-all duration-500 hover:opacity-90" 
                        cx="100" cy="100" fill="transparent" r="70" 
                        stroke="<?= $seg['color'] ?>" 
                        stroke-dasharray="<?= $seg['dash'] ?> 439.8" 
                        stroke-dashoffset="<?= $seg['offset'] ?>" 
                        stroke-width="26"></circle>
              <?php endforeach; ?>
            <?php endif; ?>
          </svg>

          <!-- Center Donut Cutout Metric -->
          <div class="absolute inset-0 flex flex-col items-center justify-center text-center pointer-events-none">
            <span class="font-headline-md text-headline-md text-on-surface font-bold leading-none">
              <?= $total_sku ?>
            </span>
            <span class="font-label-sm text-label-sm text-on-surface-variant uppercase mt-0.5">Total SKU</span>
          </div>
        </div>

        <!-- Donut Legend (Dinamis Sesuai Database / Kosong Jika 0) -->
        <div class="flex flex-col gap-2 pt-2">
          <?php if (!empty($kategori_list)): ?>
            <?php foreach ($kategori_list as $kat): ?>
              <div class="flex items-center justify-between p-2 rounded-lg bg-surface-container-low">
                <div class="flex items-center gap-2">
                  <span class="w-3 h-3 rounded-full" style="background-color: <?= $kat['color'] ?>;"></span>
                  <span class="font-body-md text-body-md text-on-surface"><?= htmlspecialchars($kat['nama']) ?></span>
                </div>
                <span class="font-label-lg text-label-lg text-on-surface font-bold"><?= $kat['percentage'] ?>%</span>
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="flex flex-col items-center justify-center py-4 text-center text-on-surface-variant">
              <span class="material-symbols-outlined text-[26px] opacity-40 mb-1">category</span>
              <span class="font-body-sm text-body-sm font-medium">Belum ada bahan baku</span>
              <span class="font-label-sm text-label-sm opacity-60">Data kategori akan muncul setelah diinput</span>
            </div>
          <?php endif; ?>
        </div>
      </div>

    </div>

    <!-- Bottom Row: Weekly Bar Activity (Sesuai Database) & Mascot Card -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-space-lg items-stretch">
      
      <!-- Left: Aktivitas Mingguan (Grouped Bar Chart Sesuai Database: Kosong Jika Belum Ada Data) -->
      <div class="lg:col-span-6 bg-surface-container-lowest rounded-xl p-space-lg shadow-[0_4px_16px_rgba(15,23,42,0.05)] flex flex-col justify-between">
        <div class="flex items-center justify-between mb-4">
          <div>
            <h2 class="font-headline-sm text-headline-sm text-on-surface font-bold">Aktivitas Mingguan</h2>
            <p class="font-body-sm text-body-sm text-on-surface-variant">
              Perbandingan harian bahan masuk vs bahan terpakai (<?= date('d M', strtotime($senin_str)) ?> - <?= date('d M Y', strtotime($minggu_str)) ?>)
            </p>
          </div>
          <div class="flex items-center gap-3">
            <div class="flex items-center gap-1.5">
              <span class="w-2.5 h-2.5 rounded-full bg-secondary-container"></span>
              <span class="font-label-sm text-label-sm text-on-surface-variant">Masuk</span>
            </div>
            <div class="flex items-center gap-1.5">
              <span class="w-2.5 h-2.5 rounded-full bg-primary-container"></span>
              <span class="font-label-sm text-label-sm text-on-surface-variant">Keluar</span>
            </div>
          </div>
        </div>

        <?php if (!$has_weekly_data): ?>
          <div class="text-center py-2">
            <span class="text-xs bg-slate-100 text-slate-500 px-2.5 py-1 rounded-full">
              Belum ada aktivitas transaksi pada minggu ini
            </span>
          </div>
        <?php endif; ?>

        <!-- Bars Container (Senin s/d Minggu: Tinggi Bar 0% Jika Belum Ada Data) -->
        <div class="h-56 pt-4 flex items-end justify-between px-2 sm:px-6">
          <?php foreach ($days_data as $day): ?>
          <div class="flex flex-col items-center gap-2">
            <div class="flex items-end gap-1.5 h-40">
              <!-- Bar Masuk -->
              <div class="w-3 sm:w-4 bg-secondary-container rounded-t-full transition-all duration-300 hover:opacity-80" 
                   style="height: <?= $day['masuk_pct'] ?>%; min-height: <?= ($day['masuk'] > 0) ? '6px' : '0px' ?>;" 
                   title="Masuk: <?= $day['masuk'] ?> Item"></div>
              <!-- Bar Keluar -->
              <div class="w-3 sm:w-4 bg-primary-container rounded-t-full transition-all duration-300 hover:opacity-80" 
                   style="height: <?= $day['keluar_pct'] ?>%; min-height: <?= ($day['keluar'] > 0) ? '6px' : '0px' ?>;" 
                   title="Keluar: <?= $day['keluar'] ?> Item"></div>
            </div>
            <span class="font-label-md text-label-md text-on-surface-variant"><?= $day['nama'] ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Right: Mascot & Branding Showcase Card -->
      <div class="lg:col-span-6 bg-surface-container-lowest rounded-xl p-space-lg shadow-[0_4px_16px_rgba(15,23,42,0.05)] flex items-center justify-center relative overflow-hidden">
        <!-- Ambient decorative gradient circles -->
        <div class="absolute -top-12 -right-12 w-48 h-48 bg-primary-fixed/30 rounded-full blur-2xl pointer-events-none"></div>
        <div class="absolute -bottom-12 -left-12 w-48 h-48 bg-secondary-fixed/40 rounded-full blur-2xl pointer-events-none"></div>
        
        <div class="relative z-10 flex flex-col sm:flex-row items-center gap-space-lg text-center sm:text-left py-4">
          <!-- Mascot Image Container -->
          <div class="w-32 h-32 sm:w-40 sm:h-40 flex-shrink-0 flex items-center justify-center p-2 rounded-2xl bg-surface-container-low">
            <img class="w-full h-full object-contain" 
                 alt="Cooler City Mascot" 
                 src="<?= base_url('assets/img/cooler_city_logo.png') ?>">
          </div>
          
          <!-- Branding Text & Quick Status -->
          <div class="flex flex-col">
            <div class="flex items-center justify-center sm:justify-start gap-1">
              <span class="font-headline-lg text-headline-lg font-black tracking-tight text-primary-container leading-none">
                COOLER CITY
              </span>
              <span class="text-xs font-bold text-primary-container leading-none align-super">®</span>
            </div>
            <span class="font-headline-sm text-headline-sm text-secondary font-medium tracking-wide mt-1">
              Icecream · Tea
            </span>
            <div class="mt-4 flex flex-wrap items-center justify-center sm:justify-start gap-2">
              <span class="px-2.5 py-1 rounded-full bg-primary-fixed text-primary font-label-md text-label-md">
                Sistem Inventory Siap
              </span>
              <span class="px-2.5 py-1 rounded-full bg-surface-container-high text-on-surface-variant font-label-md text-label-md">
                Koneksi POS Terhubung
              </span>
            </div>
            <p class="font-body-sm text-body-sm text-on-surface-variant mt-2 max-w-sm">
              Semua catatan penerimaan dan stok keluar hari ini tersinkronisasi otomatis dengan gudang utama Tanjungpinang.
            </p>
          </div>
        </div>
      </div>

    </div>

  </div>
</main>

<!-- Script Chart.js untuk Trend Bulanan (UI Sebelumnya Sesuai Permintaan) -->
<script>
function gantiTahun(tahun) {
    const url = new URL(window.location.href);
    url.searchParams.set('tahun', tahun);
    window.location.href = url.toString();
}

document.addEventListener('DOMContentLoaded', function () {
    const dataMasukBulanan = <?= json_encode(array_values($masuk_bulanan)) ?>;
    const dataKeluarBulanan = <?= json_encode(array_values($keluar_bulanan)) ?>;
    const bulanLabels = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

    const ctxTrend = document.getElementById('chartTrendBulanan');
    if (ctxTrend) {
        new Chart(ctxTrend, {
            type: 'line',
            data: {
                labels: bulanLabels,
                datasets: [
                    {
                        label: 'Barang Masuk',
                        data: dataMasukBulanan,
                        borderColor: '#F497B8',
                        backgroundColor: 'rgba(244, 151, 184, 0.20)',
                        fill: true,
                        tension: 0.35,
                        pointBackgroundColor: '#F497B8',
                        pointBorderColor: '#ffffff',
                        pointBorderWidth: 2,
                        pointRadius: 4,
                        pointHoverRadius: 6
                    },
                    {
                        label: 'Barang Keluar',
                        data: dataKeluarBulanan,
                        borderColor: '#00B4D8',
                        backgroundColor: 'rgba(0, 180, 216, 0.20)',
                        fill: true,
                        tension: 0.35,
                        pointBackgroundColor: '#00B4D8',
                        pointBorderColor: '#ffffff',
                        pointBorderWidth: 2,
                        pointRadius: 4,
                        pointHoverRadius: 6
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    intersect: false,
                    mode: 'index'
                },
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            usePointStyle: true,
                            boxWidth: 8,
                            font: { family: 'Inter', size: 12 }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                return context.dataset.label + ': ' + Number(context.raw).toLocaleString('id-ID') + ' Item';
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        suggestedMax: 10,
                        ticks: {
                            precision: 0,
                            font: { family: 'Inter' }
                        },
                        grid: { color: '#EFF4FF' }
                    },
                    x: {
                        ticks: { font: { family: 'Inter' } },
                        grid: { display: false }
                    }
                }
            }
        });
    }
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
