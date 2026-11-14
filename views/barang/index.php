<?php
/**
 * Halaman Master Data Bahan Baku - Sistem Inventory Cooler City
 * Desain 100% Mengacu pada Google Stitch AI
 * Terintegrasi Penuh dengan MySQL (tb_barang), Empty State, Filter Real-time, dan RBAC
 */

require_once __DIR__ . '/../../config/koneksi.php';
check_login();

$user_level = $_SESSION['level'] ?? 'owner';
$user_name  = $_SESSION['nama_user'] ?? 'User';
$page_title = 'DATA BAHAN BAKU';
$current_page = 'barang';

$alert_message = '';
$alert_type = ''; // 'success' | 'error'

// Ambil notifikasi dari session jika ada
if (isset($_SESSION['flash_msg'])) {
    $alert_message = $_SESSION['flash_msg']['text'];
    $alert_type = $_SESSION['flash_msg']['type'];
    unset($_SESSION['flash_msg']);
}

// ==============================================================
// 1. BACKEND HANDLER (TAMBAH, EDIT, HAPUS) DENGAN RBAC KETAT
// ==============================================================
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action'])) {
    // RBAC: Owner DILARANG melakukan modifikasi data (Read-Only)
    if ($user_level === 'owner') {
        $_SESSION['flash_msg'] = [
            'type' => 'error',
            'text' => 'Akses Ditolak: Role Owner hanya memiliki akses baca (Read-only) dan tidak diizinkan menambah, mengubah, atau menghapus data.'
        ];
        header("Location: " . base_url('views/barang/index.php'));
        exit;
    }

    $action = $_POST['action'];

    // ----------------------------------------------------------
    // A. ACTION: TAMBAH BAHAN BAKU
    // ----------------------------------------------------------
    if ($action === 'tambah') {
        $kode_barang   = strtoupper(trim($_POST['kode_barang'] ?? ''));
        $nama_barang   = trim($_POST['nama_barang'] ?? '');
        $kategori      = trim($_POST['kategori'] ?? '');
        $stok          = (int)($_POST['stok'] ?? 0);
        $satuan        = trim($_POST['satuan'] ?? '');
        $harga         = (float)($_POST['harga'] ?? 0.00);
        $stok_minimum  = (int)($_POST['stok_minimum'] ?? 10);

        if ($stok < 0) $stok = 0;
        if ($harga < 0) $harga = 0.00;
        if ($stok_minimum < 0) $stok_minimum = 10;

        // Validasi kolom wajib
        if (empty($kode_barang) || empty($nama_barang) || empty($kategori) || empty($satuan)) {
            $_SESSION['flash_msg'] = [
                'type' => 'error',
                'text' => 'Semua kolom wajib diisi (Kode, Nama, Kategori, Satuan)!'
            ];
            header("Location: " . base_url('views/barang/index.php'));
            exit;
        }

        // Cek duplikasi kode barang (UNIQUE)
        $stmt_check = $koneksi->prepare("SELECT id_barang FROM tb_barang WHERE kode_barang = ? LIMIT 1");
        $stmt_check->bind_param("s", $kode_barang);
        $stmt_check->execute();
        $res_check = $stmt_check->get_result();

        if ($res_check && $res_check->num_rows > 0) {
            $_SESSION['flash_msg'] = [
                'type' => 'error',
                'text' => "Kode barang '{$kode_barang}' sudah terdaftar! Harap gunakan kode unik lain."
            ];
            header("Location: " . base_url('views/barang/index.php'));
            exit;
        }
        $stmt_check->close();

        // Hitung status stok sesuai aturan PRD
        // stok == 0 -> Habis, stok <= stok_minimum -> Menipis, stok > stok_minimum -> Aman
        if ($stok == 0) {
            $status = 'Habis';
        } elseif ($stok <= $stok_minimum) {
            $status = 'Menipis';
        } else {
            $status = 'Aman';
        }

        // Simpan ke database
        $stmt_ins = $koneksi->prepare("INSERT INTO tb_barang (kode_barang, nama_barang, kategori, stok, satuan, harga, stok_minimum, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt_ins->bind_param("sssisdis", $kode_barang, $nama_barang, $kategori, $stok, $satuan, $harga, $stok_minimum, $status);

        if ($stmt_ins->execute()) {
            $_SESSION['flash_msg'] = [
                'type' => 'success',
                'text' => "Bahan baku '{$nama_barang}' ({$kode_barang}) berhasil ditambahkan!"
            ];
        } else {
            $_SESSION['flash_msg'] = [
                'type' => 'error',
                'text' => "Gagal menambahkan data: " . $stmt_ins->error
            ];
        }
        $stmt_ins->close();
        header("Location: " . base_url('views/barang/index.php'));
        exit;
    }

    // ----------------------------------------------------------
    // B. ACTION: EDIT / UPDATE BAHAN BAKU
    // ----------------------------------------------------------
    if ($action === 'edit') {
        $id_barang     = (int)($_POST['id_barang'] ?? 0);
        $kode_barang   = strtoupper(trim($_POST['kode_barang'] ?? ''));
        $nama_barang   = trim($_POST['nama_barang'] ?? '');
        $kategori      = trim($_POST['kategori'] ?? '');
        $stok          = (int)($_POST['stok'] ?? 0);
        $satuan        = trim($_POST['satuan'] ?? '');
        $harga         = (float)($_POST['harga'] ?? 0.00);
        $stok_minimum  = (int)($_POST['stok_minimum'] ?? 10);

        if ($id_barang <= 0 || empty($kode_barang) || empty($nama_barang) || empty($kategori) || empty($satuan)) {
            $_SESSION['flash_msg'] = [
                'type' => 'error',
                'text' => 'Data tidak lengkap atau ID barang tidak valid!'
            ];
            header("Location: " . base_url('views/barang/index.php'));
            exit;
        }

        if ($stok < 0) $stok = 0;
        if ($harga < 0) $harga = 0.00;
        if ($stok_minimum < 0) $stok_minimum = 10;

        // Cek duplikasi kode pada ID barang lain
        $stmt_check = $koneksi->prepare("SELECT id_barang FROM tb_barang WHERE kode_barang = ? AND id_barang != ? LIMIT 1");
        $stmt_check->bind_param("si", $kode_barang, $id_barang);
        $stmt_check->execute();
        $res_check = $stmt_check->get_result();

        if ($res_check && $res_check->num_rows > 0) {
            $_SESSION['flash_msg'] = [
                'type' => 'error',
                'text' => "Kode barang '{$kode_barang}' sudah digunakan oleh item bahan baku lain!"
            ];
            header("Location: " . base_url('views/barang/index.php'));
            exit;
        }
        $stmt_check->close();

        // Hitung status stok
        if ($stok == 0) {
            $status = 'Habis';
        } elseif ($stok <= $stok_minimum) {
            $status = 'Menipis';
        } else {
            $status = 'Aman';
        }

        // Update database
        $stmt_upd = $koneksi->prepare("UPDATE tb_barang SET kode_barang = ?, nama_barang = ?, kategori = ?, stok = ?, satuan = ?, harga = ?, stok_minimum = ?, status = ? WHERE id_barang = ?");
        $stmt_upd->bind_param("sssisdisi", $kode_barang, $nama_barang, $kategori, $stok, $satuan, $harga, $stok_minimum, $status, $id_barang);

        if ($stmt_upd->execute()) {
            $_SESSION['flash_msg'] = [
                'type' => 'success',
                'text' => "Data bahan baku '{$nama_barang}' berhasil diperbarui!"
            ];
        } else {
            $_SESSION['flash_msg'] = [
                'type' => 'error',
                'text' => "Gagal memperbarui data: " . $stmt_upd->error
            ];
        }
        $stmt_upd->close();
        header("Location: " . base_url('views/barang/index.php'));
        exit;
    }

    // ----------------------------------------------------------
    // C. ACTION: HAPUS BAHAN BAKU
    // ----------------------------------------------------------
    if ($action === 'hapus') {
        $id_barang = (int)($_POST['id_barang'] ?? 0);

        if ($id_barang <= 0) {
            $_SESSION['flash_msg'] = [
                'type' => 'error',
                'text' => 'ID barang tidak valid untuk dihapus!'
            ];
            header("Location: " . base_url('views/barang/index.php'));
            exit;
        }

        // Pengecekan integritas referensial: Cek apakah barang memiliki transaksi masuk/keluar
        $has_transactions = false;
        $q_check_m = $koneksi->query("SELECT id_masuk FROM tb_barangmasuk WHERE id_barang = $id_barang LIMIT 1");
        if ($q_check_m && $q_check_m->num_rows > 0) $has_transactions = true;

        $q_check_k = $koneksi->query("SELECT id_keluar FROM tb_barangkeluar WHERE id_barang = $id_barang LIMIT 1");
        if ($q_check_k && $q_check_k->num_rows > 0) $has_transactions = true;

        if ($has_transactions) {
            $_SESSION['flash_msg'] = [
                'type' => 'error',
                'text' => 'Bahan baku tidak dapat dihapus karena sudah memiliki riwayat transaksi barang masuk atau keluar!'
            ];
            header("Location: " . base_url('views/barang/index.php'));
            exit;
        }

        $stmt_del = $koneksi->prepare("DELETE FROM tb_barang WHERE id_barang = ?");
        $stmt_del->bind_param("i", $id_barang);

        if ($stmt_del->execute()) {
            $_SESSION['flash_msg'] = [
                'type' => 'success',
                'text' => "Bahan baku berhasil dihapus dari sistem."
            ];
        } else {
            $_SESSION['flash_msg'] = [
                'type' => 'error',
                'text' => "Gagal menghapus data: " . $stmt_del->error
            ];
        }
        $stmt_del->close();
        header("Location: " . base_url('views/barang/index.php'));
        exit;
    }
}

// ==============================================================
// 2. QUERY METRIK SUMMARY CARDS
// ==============================================================
$total_sku = 0;
$total_kategori = 0;

$q_sku = $koneksi->query("SELECT COUNT(*) AS total_sku FROM tb_barang");
if ($q_sku && $row_sku = $q_sku->fetch_assoc()) {
    $total_sku = (int)$row_sku['total_sku'];
}

$q_kat = $koneksi->query("SELECT COUNT(DISTINCT kategori) AS total_kat FROM tb_barang WHERE kategori IS NOT NULL AND kategori != ''");
if ($q_kat && $row_kat = $q_kat->fetch_assoc()) {
    $total_kategori = (int)$row_kat['total_kat'];
}

// Ambil daftar kategori aktual di database untuk filter tags
$kategori_counts = [];
$q_all_kat = $koneksi->query("SELECT kategori, COUNT(*) as cnt FROM tb_barang GROUP BY kategori ORDER BY cnt DESC");
if ($q_all_kat) {
    while ($rk = $q_all_kat->fetch_assoc()) {
        $kategori_counts[$rk['kategori']] = (int)$rk['cnt'];
    }
}

// Kategori default Cooler City sesuai mockup Stitch
$preset_kategori = ['Es krim', 'Topping', 'Packaging', 'Sirup', 'Teh'];

// ==============================================================
// 3. QUERY DATA TABEL BAHAN BAKU
// ==============================================================
$barang_list = [];
$q_barang = $koneksi->query("SELECT * FROM tb_barang ORDER BY id_barang DESC");
if ($q_barang) {
    while ($rb = $q_barang->fetch_assoc()) {
        $barang_list[] = $rb;
    }
}

require_once __DIR__ . '/../../header.php';
require_once __DIR__ . '/../../sidebar.php';
?>

<!-- Main Content Area Data Bahan Baku (100% Persis Desain Google Stitch AI) -->
<main class="relative pt-16 bg-surface min-h-screen w-full px-space-lg py-space-lg">
  <div class="flex flex-col w-full">

    <!-- Flash Alert Notification (Jika Ada) -->
    <?php if (!empty($alert_message)): ?>
    <div id="flashAlert" class="mb-space-lg flex items-center justify-between p-space-md rounded-2xl shadow-sm transition-all duration-200 <?= ($alert_type === 'success') ? 'bg-emerald-50 text-emerald-800 border border-emerald-200' : 'bg-red-50 text-red-800 border border-red-200' ?>">
      <div class="flex items-center gap-space-sm">
        <span class="material-symbols-outlined text-[22px] <?= ($alert_type === 'success') ? 'text-emerald-600' : 'text-red-600' ?>">
          <?= ($alert_type === 'success') ? 'check_circle' : 'error' ?>
        </span>
        <span class="font-body-md text-body-md font-medium"><?= htmlspecialchars($alert_message) ?></span>
      </div>
      <button onclick="document.getElementById('flashAlert').remove()" class="p-1 rounded-lg hover:bg-black/5 text-slate-500 transition-colors" aria-label="Tutup Notifikasi">
        <span class="material-symbols-outlined text-[18px]">close</span>
      </button>
    </div>
    <?php endif; ?>

    <!-- Header Title & Subtitle Section -->
    <div class="flex flex-col items-center justify-center text-center mb-space-lg">
      <div class="inline-flex items-center gap-space-xs px-space-md py-1 rounded-full bg-primary-fixed/50 text-primary mb-space-xs">
        <span class="material-symbols-outlined text-[16px]">inventory_2</span>
        <span class="font-label-md text-label-md uppercase tracking-wider">Master Inventory</span>
      </div>
      <h1 class="font-headline-lg text-headline-lg text-primary-container tracking-tight">
        Data Bahan Baku Cooler City Tanjungpinang
      </h1>
      <p class="font-body-md text-body-md text-tertiary-container mt-1 font-medium">
        Kelola data master bahan baku untuk produksi CoolerCity
      </p>
    </div>

    <!-- KPI Top Metric Cards Grid (2 Cards Sesuai Desain Stitch) -->
    <div class="grid grid-cols-1 gap-space-lg mb-space-xl grid-cols-2">
      
      <!-- Card 1: Jenis Barang (Pink - bg-secondary-container) -->
      <div class="relative overflow-hidden rounded-2xl bg-secondary-container p-space-lg shadow-md transition-transform duration-200 hover:-translate-y-1">
        <div class="absolute -right-4 -bottom-4 w-24 h-24 rounded-full bg-on-secondary-container/10 pointer-events-none"></div>
        <div class="flex items-center justify-between">
          <span class="font-headline-md text-headline-md text-on-secondary-container font-semibold">Jenis Barang</span>
          <span class="material-symbols-outlined text-on-secondary-container/80 text-[26px]">category</span>
        </div>
        <div class="mt-space-md flex items-baseline gap-space-xs">
          <span class="font-metric-display text-metric-display text-on-secondary-container" id="kpiSkuCount"><?= number_format($total_sku) ?></span>
          <span class="font-label-lg text-label-lg text-on-secondary-container opacity-85">Item</span>
        </div>
        <p class="font-label-sm text-label-sm text-on-secondary-container/80 mt-1 uppercase tracking-wider">Variasi SKU Aktif</p>
      </div>

      <!-- Card 2: Jumlah Kategori (Cyan - bg-primary-container) -->
      <div class="relative overflow-hidden rounded-2xl bg-primary-container p-space-lg shadow-md transition-transform duration-200 hover:-translate-y-1">
        <div class="absolute -right-4 -bottom-4 w-24 h-24 rounded-full bg-white/15 pointer-events-none"></div>
        <div class="flex items-center justify-between">
          <span class="font-headline-md text-headline-md text-on-primary font-semibold">Jumlah Kategori</span>
          <span class="material-symbols-outlined text-on-primary/80 text-[26px]">view_in_ar</span>
        </div>
        <div class="mt-space-md flex items-baseline gap-space-xs">
          <span class="font-metric-display text-metric-display text-on-primary" id="kpiKatCount"><?= number_format($total_kategori) ?></span>
          <span class="font-label-lg text-label-lg text-primary-fixed">Kategori</span>
        </div>
        <p class="font-label-sm text-label-sm text-on-primary/80 mt-1 uppercase tracking-wider">Es Krim, Sirup, Cup &amp; Teh</p>
      </div>

    </div>

    <!-- Main Content Card (White background, rounded-2xl, elevated shadow) -->
    <div class="bg-surface-container-lowest rounded-2xl shadow-xl p-space-xl flex flex-col w-full">
      
      <!-- Toolbar Section -->
      <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-space-md pb-space-lg">
        <div>
          <h2 class="font-headline-md text-headline-md text-on-surface">Daftar Bahan Baku</h2>
          <p class="font-body-md text-body-md text-on-surface-variant mt-0.5">Kelola semua bahan baku Cooler City</p>
        </div>
        
        <!-- Action Buttons: Ditampilkan Khusus Staf Gudang (Owner Read-Only) -->
        <?php if ($user_level === 'gudang'): ?>
        <div class="flex items-center gap-space-sm">
          <button class="h-11 px-space-lg rounded-xl bg-primary-container text-on-primary font-label-lg text-label-lg flex items-center gap-space-xs shadow-md hover:bg-primary transition-all duration-150 active:scale-95 cursor-pointer" id="openAddModalBtn" type="button">
            <span class="material-symbols-outlined text-[20px]">add</span>
            <span>Tambah Item</span>
          </button>
        </div>
        <?php else: ?>
        <div class="flex items-center gap-space-xs px-3 py-1.5 rounded-xl bg-surface-container-low text-on-surface-variant text-label-md font-medium border border-outline-variant/30">
          <span class="material-symbols-outlined text-[16px] text-outline">visibility</span>
          <span>Mode Read-Only (Owner)</span>
        </div>
        <?php endif; ?>
      </div>

      <!-- Search & Filter Bar -->
      <div class="flex flex-col sm:flex-row items-center justify-between gap-space-md mb-space-lg">
        <div class="relative w-full sm:w-80">
          <span class="material-symbols-outlined absolute left-3.5 top-1/2 -translate-y-1/2 text-outline text-[18px]">search</span>
          <input class="w-full h-11 pl-10 pr-space-md rounded-xl bg-surface-container-low text-on-surface font-body-md text-body-md placeholder:text-on-surface-variant focus:outline-none focus:bg-surface-container-lowest focus:ring-2 focus:ring-primary-container shadow-inner" id="tableSearchInput" placeholder="Cari bahan baku..." type="text" autocomplete="off">
        </div>
        
        <!-- Filter Tags Category -->
        <div class="flex items-center gap-space-xs w-full sm:w-auto overflow-x-auto pb-1 sm:pb-0" id="filterContainer">
          <button class="px-space-md py-1.5 rounded-full bg-primary-container text-on-primary font-label-md text-label-md transition-colors filter-tag active cursor-pointer whitespace-nowrap" data-filter="all">
            Semua (<?= $total_sku ?>)
          </button>
          
          <?php foreach ($preset_kategori as $kat_name): 
            $count_in_kat = $kategori_counts[$kat_name] ?? 0;
          ?>
          <button class="px-space-md py-1.5 rounded-full bg-surface-container-low text-on-surface-variant hover:bg-surface-container font-label-md text-label-md transition-colors filter-tag cursor-pointer whitespace-nowrap" data-filter="<?= htmlspecialchars($kat_name) ?>">
            <?= htmlspecialchars($kat_name) ?> <?= ($count_in_kat > 0) ? "($count_in_kat)" : "" ?>
          </button>
          <?php endforeach; ?>

          <?php 
          // Render kategori tambahan di luar preset jika ada
          foreach ($kategori_counts as $extra_kat => $cnt):
            if (!in_array($extra_kat, $preset_kategori)):
          ?>
          <button class="px-space-md py-1.5 rounded-full bg-surface-container-low text-on-surface-variant hover:bg-surface-container font-label-md text-label-md transition-colors filter-tag cursor-pointer whitespace-nowrap" data-filter="<?= htmlspecialchars($extra_kat) ?>">
            <?= htmlspecialchars($extra_kat) ?> (<?= $cnt ?>)
          </button>
          <?php 
            endif;
          endforeach; 
          ?>
        </div>
      </div>

      <!-- Data Table Container -->
      <div class="w-full overflow-x-auto">
        <table class="w-full text-left border-collapse" id="inventoryTable">
          <thead>
            <tr class="bg-surface-container-low/70">
              <th class="py-space-md px-space-md font-label-md text-label-md uppercase tracking-wider text-outline rounded-l-xl">Kode</th>
              <th class="py-space-md px-space-md font-label-md text-label-md uppercase tracking-wider text-outline">Nama Barang</th>
              <th class="py-space-md px-space-md font-label-md text-label-md uppercase tracking-wider text-outline">Kategori</th>
              <th class="py-space-md px-space-md font-label-md text-label-md uppercase tracking-wider text-outline text-right">Stok</th>
              <th class="py-space-md px-space-md font-label-md text-label-md uppercase tracking-wider text-outline">Satuan</th>
              <th class="py-space-md px-space-md font-label-md text-label-md uppercase tracking-wider text-outline text-right">Harga</th>
              <th class="py-space-md px-space-md font-label-md text-label-md uppercase tracking-wider text-outline text-center">Status</th>
              <?php if ($user_level === 'gudang'): ?>
              <th class="py-space-md px-space-md font-label-md text-label-md uppercase tracking-wider text-outline text-center rounded-r-xl">Aksi</th>
              <?php endif; ?>
            </tr>
          </thead>
          <tbody class="divide-y-0" id="tableBody">
            
            <?php if (empty($barang_list)): ?>
            <!-- EMPTY STATE: KONDISI DATABASE MASIH KOSONG -->
            <tr id="emptyDatabaseRow">
              <td colspan="<?= ($user_level === 'gudang') ? 8 : 7 ?>" class="py-16 px-6 text-center">
                <div class="max-w-md mx-auto flex flex-col items-center justify-center">
                  <div class="w-16 h-16 rounded-2xl bg-primary-fixed/40 text-primary flex items-center justify-center mb-4 shadow-inner">
                    <span class="material-symbols-outlined text-[36px]">inventory_2</span>
                  </div>
                  <h3 class="font-headline-md text-headline-md text-on-surface font-semibold mb-1">
                    Belum Ada Data Bahan Baku
                  </h3>
                  <p class="font-body-md text-body-md text-on-surface-variant mb-6 text-center leading-relaxed">
                    <?php if ($user_level === 'gudang'): ?>
                      Database master bahan baku saat ini masih kosong. Silakan klik tombol di bawah untuk menambahkan data bahan baku pertama Cooler City.
                    <?php else: ?>
                      Belum ada data master bahan baku yang didaftarkan ke dalam sistem oleh staf gudang.
                    <?php endif; ?>
                  </p>
                  <?php if ($user_level === 'gudang'): ?>
                  <button type="button" id="emptyStateAddBtn" class="inline-flex items-center gap-2 px-space-lg py-2.5 rounded-xl bg-primary-container hover:bg-primary text-on-primary font-label-lg text-label-lg shadow-md transition-all active:scale-95 cursor-pointer">
                    <span class="material-symbols-outlined text-[20px]">add</span>
                    <span>Tambah Bahan Baku Pertama</span>
                  </button>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
            <?php else: ?>

              <?php 
              // Palet warna dot untuk tiap kategori
              $kat_dot_colors = [
                'es krim'   => 'bg-primary-container',
                'topping'   => 'bg-secondary',
                'packaging' => 'bg-tertiary-container',
                'sirup'     => 'bg-secondary-container',
                'teh'       => 'bg-primary'
              ];

              foreach ($barang_list as $item): 
                $kat_lower = strtolower(trim($item['kategori']));
                $dot_color = $kat_dot_colors[$kat_lower] ?? 'bg-primary-container';
                $is_habis = ($item['status'] === 'Habis' || (int)$item['stok'] === 0);
                $is_menipis = ($item['status'] === 'Menipis');
              ?>
              <!-- Row Item Bahan Baku -->
              <tr class="item-row hover:bg-surface-container-low/50 transition-colors group <?= $is_habis ? 'bg-error-container/20' : '' ?>" 
                  data-category="<?= htmlspecialchars($item['kategori']) ?>"
                  data-search="<?= htmlspecialchars(strtolower($item['kode_barang'] . ' ' . $item['nama_barang'] . ' ' . $item['kategori'])) ?>">
                
                <!-- Kode Barang -->
                <td class="py-space-md px-space-md font-headline-sm text-headline-sm <?= $is_habis ? 'text-error' : 'text-on-surface' ?>">
                  <?= htmlspecialchars($item['kode_barang']) ?>
                </td>
                
                <!-- Nama Barang -->
                <td class="py-space-md px-space-md font-body-md text-body-md text-on-surface font-medium">
                  <div class="flex items-center gap-space-xs">
                    <span class="w-2 h-2 rounded-full <?= $is_habis ? 'bg-error' : $dot_color ?>"></span>
                    <span><?= htmlspecialchars($item['nama_barang']) ?></span>
                  </div>
                </td>
                
                <!-- Kategori -->
                <td class="py-space-md px-space-md">
                  <span class="px-space-sm py-0.5 rounded-md bg-surface-container text-on-surface-variant font-label-md text-label-md">
                    <?= htmlspecialchars($item['kategori']) ?>
                  </span>
                </td>
                
                <!-- Stok -->
                <td class="py-space-md px-space-md font-headline-sm text-headline-sm text-right <?= $is_habis ? 'text-error' : 'text-on-surface' ?>">
                  <?= number_format($item['stok']) ?>
                </td>
                
                <!-- Satuan -->
                <td class="py-space-md px-space-md font-body-md text-body-md text-on-surface-variant">
                  <?= htmlspecialchars($item['satuan']) ?>
                </td>
                
                <!-- Harga -->
                <td class="py-space-md px-space-md font-body-md text-body-md text-on-surface text-right font-medium">
                  Rp <?= number_format($item['harga'], 0, ',', '.') ?>,00
                </td>
                
                <!-- Status Stok -->
                <td class="py-space-md px-space-md text-center">
                  <?php if ($is_habis): ?>
                    <span class="inline-flex items-center gap-1 px-space-sm py-1 rounded-full bg-error-container text-on-error-container font-label-md text-label-md font-semibold">
                      <span class="w-1.5 h-1.5 rounded-full bg-error"></span>
                      Habis
                    </span>
                  <?php elseif ($is_menipis): ?>
                    <span class="inline-flex items-center gap-1 px-space-sm py-1 rounded-full bg-secondary-fixed text-on-secondary-fixed font-label-md text-label-md font-semibold">
                      <span class="w-1.5 h-1.5 rounded-full bg-secondary"></span>
                      Menipis
                    </span>
                  <?php else: ?>
                    <span class="inline-flex items-center gap-1 px-space-sm py-1 rounded-full bg-surface-container text-primary font-label-md text-label-md font-semibold">
                      <span class="w-1.5 h-1.5 rounded-full bg-primary"></span>
                      Aman
                    </span>
                  <?php endif; ?>
                </td>
                
                <!-- Aksi: Hanya untuk Staf Gudang -->
                <?php if ($user_level === 'gudang'): ?>
                <td class="py-space-md px-space-md text-center">
                  <button type="button" 
                          class="edit-item-btn h-8 px-space-md rounded-lg bg-surface-container-low hover:bg-surface-container text-on-surface font-label-md text-label-md transition-colors cursor-pointer"
                          data-id="<?= $item['id_barang'] ?>"
                          data-kode="<?= htmlspecialchars($item['kode_barang']) ?>"
                          data-nama="<?= htmlspecialchars($item['nama_barang']) ?>"
                          data-kategori="<?= htmlspecialchars($item['kategori']) ?>"
                          data-stok="<?= (int)$item['stok'] ?>"
                          data-satuan="<?= htmlspecialchars($item['satuan']) ?>"
                          data-harga="<?= (float)$item['harga'] ?>"
                          data-stok-minimum="<?= (int)$item['stok_minimum'] ?>">
                    Edit
                  </button>
                </td>
                <?php endif; ?>

              </tr>
              <?php endforeach; ?>

            <?php endif; ?>

            <!-- EMPTY STATE UNTUK HASIL PENCARIAN NIHIL -->
            <tr id="noSearchResultsRow" class="hidden">
              <td colspan="<?= ($user_level === 'gudang') ? 8 : 7 ?>" class="py-12 px-6 text-center">
                <div class="max-w-md mx-auto flex flex-col items-center justify-center">
                  <div class="w-14 h-14 rounded-2xl bg-surface-container text-outline flex items-center justify-center mb-3">
                    <span class="material-symbols-outlined text-[32px]">search_off</span>
                  </div>
                  <h4 class="font-headline-sm text-headline-sm text-on-surface font-semibold mb-1">
                    Data Tidak Ditemukan
                  </h4>
                  <p class="font-body-sm text-body-sm text-on-surface-variant mb-4">
                    Tidak ada bahan baku yang cocok dengan kata kunci pencarian atau filter yang dipilih.
                  </p>
                  <button type="button" id="resetSearchFilterBtn" class="px-space-md py-1.5 rounded-lg bg-surface-container-low hover:bg-surface-container text-on-surface font-label-md text-label-md transition-colors cursor-pointer">
                    Reset Filter
                  </button>
                </div>
              </td>
            </tr>

          </tbody>
        </table>
      </div>

      <!-- Table Footer with Pagination & Meta -->
      <div class="mt-space-lg pt-space-md flex flex-col sm:flex-row items-center justify-between gap-space-md bg-surface-container-lowest border-t border-surface-container-low/80">
        <span class="font-body-sm text-body-sm text-on-surface-variant" id="tableMetaCount">
          Menampilkan <?= count($barang_list) ?> dari <?= $total_sku ?> master item
        </span>
        
        <div class="flex items-center gap-1">
          <button class="w-8 h-8 rounded-lg bg-surface-container-low text-on-surface-variant flex items-center justify-center hover:bg-surface-container transition-colors disabled:opacity-40" disabled>
            <span class="material-symbols-outlined text-[16px]">chevron_left</span>
          </button>
          <button class="w-8 h-8 rounded-lg bg-primary-container text-on-primary font-label-md text-label-md flex items-center justify-center">1</button>
          <button class="w-8 h-8 rounded-lg bg-surface-container-low text-on-surface-variant flex items-center justify-center hover:bg-surface-container transition-colors disabled:opacity-40" disabled>
            <span class="material-symbols-outlined text-[16px]">chevron_right</span>
          </button>
        </div>
      </div>

    </div>

  </div>
</main>

<!-- ============================================================== -->
<!-- POPUP 1: MODAL TAMBAH DATA BAHAN BAKU (Sesuai Desain Stitch) -->
<!-- ============================================================== -->
<?php if ($user_level === 'gudang'): ?>
<div class="fixed inset-0 z-50 flex items-center justify-center hidden" id="addModal">
  <!-- Backdrop scrim -->
  <div class="absolute inset-0 bg-inverse-surface/40 backdrop-blur-sm transition-opacity" id="addModalBackdrop"></div>
  
  <!-- Modal Dialog Card -->
  <div class="relative w-full max-w-lg bg-surface-container-lowest rounded-2xl shadow-2xl p-space-xl z-10 mx-4 transition-all">
    <div class="flex items-start justify-between pb-space-md border-b border-surface-container/50">
      <div>
        <h3 class="font-headline-md text-headline-md text-on-surface font-bold">Input Bahan Baku</h3>
        <p class="font-body-sm text-body-sm text-on-surface-variant mt-0.5">Masukkan data master bahan baku baru</p>
      </div>
      <button type="button" class="w-8 h-8 rounded-full bg-surface-container-low hover:bg-surface-container text-on-surface flex items-center justify-center transition-colors cursor-pointer" id="closeAddModalBtn">
        <span class="material-symbols-outlined text-[20px]">close</span>
      </button>
    </div>

    <form class="flex flex-col gap-space-md mt-space-md" method="POST" action="<?= base_url('views/barang/index.php') ?>" id="formTambahBarang">
      <input type="hidden" name="action" value="tambah">

      <!-- Kode -->
      <div class="flex flex-col gap-1">
        <label class="font-label-md text-label-md text-on-surface-variant font-medium">Kode</label>
        <input class="h-10 px-space-md rounded-xl bg-surface-container-low text-on-surface font-body-md text-body-md placeholder:text-outline focus:outline-none focus:ring-2 focus:ring-primary-container uppercase" 
               name="kode_barang" placeholder="ICE001, CUP001, etc" required type="text" autocomplete="off">
      </div>

      <!-- Nama Barang -->
      <div class="flex flex-col gap-1">
        <label class="font-label-md text-label-md text-on-surface-variant font-medium">Nama Barang</label>
        <input class="h-10 px-space-md rounded-xl bg-surface-container-low text-on-surface font-body-md text-body-md placeholder:text-outline focus:outline-none focus:ring-2 focus:ring-primary-container" 
               name="nama_barang" placeholder="Es krim Vanilla, Cup U, etc" required type="text" autocomplete="off">
      </div>

      <!-- Kategori -->
      <div class="flex flex-col gap-1">
        <label class="font-label-md text-label-md text-on-surface-variant font-medium">Kategori</label>
        <input class="h-10 px-space-md rounded-xl bg-surface-container-low text-on-surface font-body-md text-body-md placeholder:text-outline focus:outline-none focus:ring-2 focus:ring-primary-container" 
               name="kategori" list="kategoriDatalist" placeholder="Es krim, Topping, Sirup, Packaging, Teh" required type="text" autocomplete="off">
        <datalist id="kategoriDatalist">
          <option value="Es krim">
          <option value="Topping">
          <option value="Packaging">
          <option value="Sirup">
          <option value="Teh">
        </datalist>
      </div>

      <!-- Stok Awal & Satuan -->
      <div class="grid grid-cols-2 gap-space-md">
        <div class="flex flex-col gap-1">
          <label class="font-label-md text-label-md text-on-surface-variant font-medium">Stok Awal</label>
          <input class="h-10 px-space-md rounded-xl bg-surface-container-low text-on-surface font-body-md text-body-md placeholder:text-outline focus:outline-none focus:ring-2 focus:ring-primary-container" 
                 name="stok" placeholder="0" required type="number" min="0" value="0">
        </div>
        <div class="flex flex-col gap-1">
          <label class="font-label-md text-label-md text-on-surface-variant font-medium">Satuan</label>
          <input class="h-10 px-space-md rounded-xl bg-surface-container-low text-on-surface font-body-md text-body-md placeholder:text-outline focus:outline-none focus:ring-2 focus:ring-primary-container" 
                 name="satuan" placeholder="Bag, Botol, Toples, Pack, Kg, Slop" required type="text">
        </div>
      </div>

      <!-- Harga (Rp) & Stok Minimum -->
      <div class="grid grid-cols-2 gap-space-md">
        <div class="flex flex-col gap-1">
          <label class="font-label-md text-label-md text-on-surface-variant font-medium">Harga (Rp)</label>
          <input class="h-10 px-space-md rounded-xl bg-surface-container-low text-on-surface font-body-md text-body-md placeholder:text-outline focus:outline-none focus:ring-2 focus:ring-primary-container" 
                 name="harga" placeholder="0" required type="number" min="0" step="500" value="0">
        </div>
        <div class="flex flex-col gap-1">
          <label class="font-label-md text-label-md text-on-surface-variant font-medium">Stok Minimum</label>
          <input class="h-10 px-space-md rounded-xl bg-surface-container-low text-on-surface font-body-md text-body-md placeholder:text-outline focus:outline-none focus:ring-2 focus:ring-primary-container" 
                 name="stok_minimum" placeholder="10" required type="number" min="0" value="10">
        </div>
      </div>

      <!-- Tombol Aksi Bawah Modal Tambah -->
      <div class="flex items-center justify-end gap-space-sm pt-space-md border-t border-surface-container/50 mt-space-xs">
        <button type="button" class="h-10 px-space-lg rounded-xl bg-surface-container-low hover:bg-surface-container text-on-surface font-label-lg text-label-lg transition-colors cursor-pointer" id="cancelAddModalBtn">
          Batal
        </button>
        <button type="submit" class="h-10 px-space-xl rounded-xl bg-primary-container hover:bg-primary text-on-primary font-label-lg text-label-lg transition-colors flex items-center gap-1 shadow-md cursor-pointer active:scale-95" id="submitAddModalBtn">
          <span>Tambah</span>
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ============================================================== -->
<!-- POPUP 2: MODAL EDIT DATA BAHAN BAKU (Sesuai Desain Stitch) -->
<!-- ============================================================== -->
<div class="fixed inset-0 z-50 flex items-center justify-center hidden" id="editModal">
  <!-- Backdrop scrim -->
  <div class="absolute inset-0 bg-inverse-surface/40 backdrop-blur-sm transition-opacity" id="editModalBackdrop"></div>
  
  <!-- Modal Dialog Card -->
  <div class="relative w-full max-w-lg bg-surface-container-lowest rounded-2xl shadow-2xl p-space-xl z-10 mx-4 transition-all">
    <!-- Header Modal -->
    <div class="flex items-start justify-between pb-space-md border-b border-surface-container/50">
      <div>
        <h3 class="font-headline-md text-headline-md text-on-surface font-bold">Edit Bahan Baku</h3>
        <p class="font-body-sm text-body-sm text-on-surface-variant mt-0.5">Ganti atau hapus data bahan baku</p>
      </div>
      <button type="button" class="w-8 h-8 rounded-full bg-surface-container-low hover:bg-surface-container text-on-surface flex items-center justify-center transition-colors cursor-pointer" id="closeEditModalBtn">
        <span class="material-symbols-outlined text-[20px]">close</span>
      </button>
    </div>

    <!-- Formulir Edit Field -->
    <form class="flex flex-col gap-space-md mt-space-md" method="POST" action="<?= base_url('views/barang/index.php') ?>" id="formEditBarang">
      <input type="hidden" name="action" id="editFormAction" value="edit">
      <input type="hidden" name="id_barang" id="editIdBarang" value="">

      <!-- Kode -->
      <div class="flex flex-col gap-1">
        <label class="font-label-md text-label-md text-on-surface-variant font-medium">Kode</label>
        <input class="h-10 px-space-md rounded-xl bg-surface-container-low text-on-surface font-body-md text-body-md focus:outline-none focus:ring-2 focus:ring-primary-container uppercase" 
               name="kode_barang" id="editKode" type="text" required>
      </div>

      <!-- Nama -->
      <div class="flex flex-col gap-1">
        <label class="font-label-md text-label-md text-on-surface-variant font-medium">Nama</label>
        <input class="h-10 px-space-md rounded-xl bg-surface-container-low text-on-surface font-body-md text-body-md focus:outline-none focus:ring-2 focus:ring-primary-container" 
               name="nama_barang" id="editNama" type="text" required>
      </div>

      <!-- Kategori -->
      <div class="flex flex-col gap-1">
        <label class="font-label-md text-label-md text-on-surface-variant font-medium">Kategori</label>
        <input class="h-10 px-space-md rounded-xl bg-surface-container-low text-on-surface font-body-md text-body-md focus:outline-none focus:ring-2 focus:ring-primary-container" 
               name="kategori" id="editKategori" list="kategoriDatalist" type="text" required>
      </div>

      <!-- Stok & Satuan -->
      <div class="grid grid-cols-2 gap-space-md">
        <div class="flex flex-col gap-1">
          <label class="font-label-md text-label-md text-on-surface-variant font-medium">Stok</label>
          <input class="h-10 px-space-md rounded-xl bg-surface-container-low text-on-surface font-body-md text-body-md focus:outline-none focus:ring-2 focus:ring-primary-container" 
                 name="stok" id="editStok" type="number" min="0" required>
        </div>
        <div class="flex flex-col gap-1">
          <label class="font-label-md text-label-md text-on-surface-variant font-medium">Satuan</label>
          <input class="h-10 px-space-md rounded-xl bg-surface-container-low text-on-surface font-body-md text-body-md focus:outline-none focus:ring-2 focus:ring-primary-container" 
                 name="satuan" id="editSatuan" type="text" required>
        </div>
      </div>

      <!-- Harga & Stok Minimum -->
      <div class="grid grid-cols-2 gap-space-md">
        <div class="flex flex-col gap-1">
          <label class="font-label-md text-label-md text-on-surface-variant font-medium">Harga (Rp)</label>
          <input class="h-10 px-space-md rounded-xl bg-surface-container-low text-on-surface font-body-md text-body-md focus:outline-none focus:ring-2 focus:ring-primary-container" 
                 name="harga" id="editHarga" type="number" min="0" step="500" required>
        </div>
        <div class="flex flex-col gap-1">
          <label class="font-label-md text-label-md text-on-surface-variant font-medium">Stok Minimum</label>
          <input class="h-10 px-space-md rounded-xl bg-surface-container-low text-on-surface font-body-md text-body-md focus:outline-none focus:ring-2 focus:ring-primary-container" 
                 name="stok_minimum" id="editStokMinimum" type="number" min="0" required>
        </div>
      </div>

      <!-- Tombol Aksi di bagian bawah modal (Hapus di Kiri, Update di Kanan Sesuai Stitch) -->
      <div class="flex items-center justify-between pt-space-md border-t border-surface-container/50 mt-space-xs">
        <button class="h-10 px-space-lg rounded-xl bg-error hover:bg-error/90 text-on-error font-label-lg text-label-lg transition-colors flex items-center gap-1 shadow-md cursor-pointer active:scale-95" 
                id="deleteModalBtn" type="button">
          <span class="material-symbols-outlined text-[18px]">delete</span>
          <span>Hapus</span>
        </button>
        
        <button class="h-10 px-space-xl rounded-xl bg-primary-container hover:bg-primary text-on-primary font-label-lg text-label-lg transition-colors flex items-center gap-1 shadow-md cursor-pointer active:scale-95" 
                id="submitEditModalBtn" type="submit">
          <span>Update</span>
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ============================================================== -->
<!-- POPUP 3: KONFIRMASI HAPUS BAHAN BAKU -->
<!-- ============================================================== -->
<div class="fixed inset-0 z-50 flex items-center justify-center hidden" id="deleteConfirmModal">
  <div class="absolute inset-0 bg-inverse-surface/40 backdrop-blur-sm" id="deleteConfirmBackdrop"></div>
  <div class="relative w-full max-w-md bg-surface-container-lowest rounded-2xl shadow-2xl p-space-xl z-10 mx-4">
    <div class="flex items-center gap-space-sm text-error mb-2">
      <span class="material-symbols-outlined text-[28px]">warning</span>
      <h3 class="font-headline-sm text-headline-sm font-bold text-on-surface">Konfirmasi Hapus</h3>
    </div>
    <p class="font-body-md text-body-md text-on-surface-variant mb-space-lg">
      Apakah Anda yakin ingin menghapus bahan baku <strong id="deleteConfirmName" class="text-on-surface"></strong>? Tindakan ini tidak dapat dibatalkan jika belum memiliki riwayat transaksi.
    </p>
    <form method="POST" action="<?= base_url('views/barang/index.php') ?>" class="flex items-center justify-end gap-space-sm">
      <input type="hidden" name="action" value="hapus">
      <input type="hidden" name="id_barang" id="deleteConfirmId" value="">
      <button type="button" id="cancelDeleteConfirmBtn" class="h-10 px-space-lg rounded-xl bg-surface-container-low hover:bg-surface-container text-on-surface font-label-lg text-label-lg transition-colors cursor-pointer">
        Batal
      </button>
      <button type="submit" class="h-10 px-space-lg rounded-xl bg-error hover:bg-error/90 text-on-error font-label-lg text-label-lg transition-colors flex items-center gap-1 shadow-md cursor-pointer active:scale-95">
        <span class="material-symbols-outlined text-[18px]">delete</span>
        <span>Ya, Hapus</span>
      </button>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- Client-side Interactive Search, Filter & Modal Controls -->
<script>
document.addEventListener('DOMContentLoaded', function () {
  // 1. FILTER KATEGORI & REAL-TIME SEARCH PADA TABEL
  const searchInput = document.getElementById('tableSearchInput');
  const filterTags = document.querySelectorAll('.filter-tag');
  const tableRows = document.querySelectorAll('.item-row');
  const noSearchResultsRow = document.getElementById('noSearchResultsRow');
  const emptyDatabaseRow = document.getElementById('emptyDatabaseRow');
  const resetBtn = document.getElementById('resetSearchFilterBtn');
  const tableMetaCount = document.getElementById('tableMetaCount');

  let activeCategory = 'all';

  function filterTable() {
    const query = searchInput ? searchInput.value.trim().toLowerCase() : '';
    let visibleCount = 0;

    tableRows.forEach(row => {
      const rowCategory = (row.getAttribute('data-category') || '').toLowerCase();
      const rowSearchText = (row.getAttribute('data-search') || '').toLowerCase();

      const matchesCategory = (activeCategory === 'all' || rowCategory === activeCategory.toLowerCase());
      const matchesSearch = (!query || rowSearchText.includes(query));

      if (matchesCategory && matchesSearch) {
        row.style.display = '';
        visibleCount++;
      } else {
        row.style.display = 'none';
      }
    });

    // Tampilkan pesan kosong pencarian jika ada baris data tetapi hasil filter nihil
    if (tableRows.length > 0) {
      if (visibleCount === 0) {
        if (noSearchResultsRow) noSearchResultsRow.classList.remove('hidden');
      } else {
        if (noSearchResultsRow) noSearchResultsRow.classList.add('hidden');
      }
      if (tableMetaCount) {
        tableMetaCount.textContent = `Menampilkan ${visibleCount} dari ${tableRows.length} master item`;
      }
    }
  }

  if (searchInput) {
    searchInput.addEventListener('input', filterTable);
  }

  filterTags.forEach(tag => {
    tag.addEventListener('click', function () {
      filterTags.forEach(t => {
        t.classList.remove('bg-primary-container', 'text-on-primary', 'active');
        t.classList.add('bg-surface-container-low', 'text-on-surface-variant');
      });

      this.classList.remove('bg-surface-container-low', 'text-on-surface-variant');
      this.classList.add('bg-primary-container', 'text-on-primary', 'active');

      activeCategory = this.getAttribute('data-filter') || 'all';
      filterTable();
    });
  });

  if (resetBtn) {
    resetBtn.addEventListener('click', function () {
      if (searchInput) searchInput.value = '';
      activeCategory = 'all';
      filterTags.forEach((t, i) => {
        if (i === 0) {
          t.classList.remove('bg-surface-container-low', 'text-on-surface-variant');
          t.classList.add('bg-primary-container', 'text-on-primary', 'active');
        } else {
          t.classList.remove('bg-primary-container', 'text-on-primary', 'active');
          t.classList.add('bg-surface-container-low', 'text-on-surface-variant');
        }
      });
      filterTable();
    });
  }

  // 2. MODAL TAMBAH DATA KONTROL
  const addModal = document.getElementById('addModal');
  const openAddModalBtn = document.getElementById('openAddModalBtn');
  const emptyStateAddBtn = document.getElementById('emptyStateAddBtn');
  const closeAddModalBtn = document.getElementById('closeAddModalBtn');
  const cancelAddModalBtn = document.getElementById('cancelAddModalBtn');
  const addModalBackdrop = document.getElementById('addModalBackdrop');

  function openAddModal() {
    if (addModal) {
      addModal.classList.remove('hidden');
      document.body.style.overflow = 'hidden';
      const firstInput = addModal.querySelector('input[name="kode_barang"]');
      if (firstInput) setTimeout(() => firstInput.focus(), 100);
    }
  }

  function closeAddModal() {
    if (addModal) {
      addModal.classList.add('hidden');
      document.body.style.overflow = '';
    }
  }

  if (openAddModalBtn) openAddModalBtn.addEventListener('click', openAddModal);
  if (emptyStateAddBtn) emptyStateAddBtn.addEventListener('click', openAddModal);
  if (closeAddModalBtn) closeAddModalBtn.addEventListener('click', closeAddModal);
  if (cancelAddModalBtn) cancelAddModalBtn.addEventListener('click', closeAddModal);
  if (addModalBackdrop) addModalBackdrop.addEventListener('click', closeAddModal);

  // 3. MODAL EDIT DATA KONTROL
  const editModal = document.getElementById('editModal');
  const closeEditModalBtn = document.getElementById('closeEditModalBtn');
  const editModalBackdrop = document.getElementById('editModalBackdrop');
  const editButtons = document.querySelectorAll('.edit-item-btn');

  const editIdBarang = document.getElementById('editIdBarang');
  const editKode = document.getElementById('editKode');
  const editNama = document.getElementById('editNama');
  const editKategori = document.getElementById('editKategori');
  const editStok = document.getElementById('editStok');
  const editSatuan = document.getElementById('editSatuan');
  const editHarga = document.getElementById('editHarga');
  const editStokMinimum = document.getElementById('editStokMinimum');

  function openEditModal(data) {
    if (!editModal) return;

    if (editIdBarang) editIdBarang.value = data.id || '';
    if (editKode) editKode.value = data.kode || '';
    if (editNama) editNama.value = data.nama || '';
    if (editKategori) editKategori.value = data.kategori || '';
    if (editStok) editStok.value = data.stok !== undefined ? data.stok : 0;
    if (editSatuan) editSatuan.value = data.satuan || '';
    if (editHarga) editHarga.value = data.harga !== undefined ? data.harga : 0;
    if (editStokMinimum) editStokMinimum.value = data.stokMinimum !== undefined ? data.stokMinimum : 10;

    editModal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
  }

  function closeEditModal() {
    if (editModal) {
      editModal.classList.add('hidden');
      document.body.style.overflow = '';
    }
  }

  editButtons.forEach(btn => {
    btn.addEventListener('click', function () {
      const data = {
        id: this.getAttribute('data-id'),
        kode: this.getAttribute('data-kode'),
        nama: this.getAttribute('data-nama'),
        kategori: this.getAttribute('data-kategori'),
        stok: this.getAttribute('data-stok'),
        satuan: this.getAttribute('data-satuan'),
        harga: this.getAttribute('data-harga'),
        stokMinimum: this.getAttribute('data-stok-minimum')
      };
      openEditModal(data);
    });
  });

  if (closeEditModalBtn) closeEditModalBtn.addEventListener('click', closeEditModal);
  if (editModalBackdrop) editModalBackdrop.addEventListener('click', closeEditModal);

  // 4. KONFIRMASI HAPUS MODAL
  const deleteModalBtn = document.getElementById('deleteModalBtn');
  const deleteConfirmModal = document.getElementById('deleteConfirmModal');
  const deleteConfirmBackdrop = document.getElementById('deleteConfirmBackdrop');
  const cancelDeleteConfirmBtn = document.getElementById('cancelDeleteConfirmBtn');
  const deleteConfirmName = document.getElementById('deleteConfirmName');
  const deleteConfirmId = document.getElementById('deleteConfirmId');

  if (deleteModalBtn) {
    deleteModalBtn.addEventListener('click', function () {
      const id = editIdBarang ? editIdBarang.value : '';
      const nama = editNama ? editNama.value : 'item ini';

      if (deleteConfirmId) deleteConfirmId.value = id;
      if (deleteConfirmName) deleteConfirmName.textContent = nama;

      closeEditModal();
      if (deleteConfirmModal) deleteConfirmModal.classList.remove('hidden');
    });
  }

  function closeDeleteConfirmModal() {
    if (deleteConfirmModal) deleteConfirmModal.classList.add('hidden');
    document.body.style.overflow = '';
  }

  if (cancelDeleteConfirmBtn) cancelDeleteConfirmBtn.addEventListener('click', closeDeleteConfirmModal);
  if (deleteConfirmBackdrop) deleteConfirmBackdrop.addEventListener('click', closeDeleteConfirmModal);

  // ESC Key listener to close active modals
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      closeAddModal();
      closeEditModal();
      closeDeleteConfirmModal();
    }
  });
});
</script>

<?php
require_once __DIR__ . '/../../footer.php';
?>
