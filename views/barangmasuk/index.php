<?php
/**
 * Halaman Transaksi Bahan Masuk - Sistem Inventory Cooler City
 * Desain 100% Mengacu pada Google Stitch AI
 * Terintegrasi Penuh dengan MySQL (tb_barangmasuk & tb_barang),
 * Pembaruan Stok Atomik (Database Transaction), Empty State, Real-time Search, dan RBAC
 */

require_once __DIR__ . '/../../config/koneksi.php';
check_login();

$user_level = $_SESSION['level'] ?? 'owner';
$user_name  = $_SESSION['nama_user'] ?? 'User';
$user_id    = (int)($_SESSION['user_id'] ?? 0);
$page_title = 'BAHAN MASUK';
$current_page = 'barangmasuk';

$alert_message = '';
$alert_type = ''; // 'success' | 'error'

// Ambil notifikasi dari session jika ada
if (isset($_SESSION['flash_msg'])) {
    $alert_message = $_SESSION['flash_msg']['text'];
    $alert_type = $_SESSION['flash_msg']['type'];
    unset($_SESSION['flash_msg']);
}

// ==============================================================
// 1. BACKEND HANDLER: TAMBAH TRANSAKSI BAHAN MASUK (RBAC & ATOMIC)
// ==============================================================
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'tambah_masuk') {
    // Proteksi RBAC: Role Owner DILARANG melakukan transaksi (Read-Only)
    if ($user_level === 'owner') {
        $_SESSION['flash_msg'] = [
            'type' => 'error',
            'text' => 'Akses Ditolak: Role Owner hanya memiliki hak akses baca (Read-only) dan tidak diizinkan menambah transaksi bahan masuk.'
        ];
        header("Location: " . base_url('views/barangmasuk/index.php'));
        exit;
    }

    $tanggal_masuk = trim($_POST['tanggal_masuk'] ?? '');
    $id_barang     = (int)($_POST['id_barang'] ?? 0);
    $supplier      = trim($_POST['supplier'] ?? '');
    $jumlah        = (int)($_POST['jumlah'] ?? 0);
    $harga_beli    = (float)($_POST['harga_beli'] ?? 0.00);
    $keterangan    = trim($_POST['keterangan'] ?? '');

    // Validasi input dasar
    if (empty($tanggal_masuk) || $id_barang <= 0 || empty($supplier) || $jumlah <= 0) {
        $_SESSION['flash_msg'] = [
            'type' => 'error',
            'text' => 'Semua kolom bertanda bintang wajib diisi dan jumlah masuk harus lebih besar dari 0!'
        ];
        header("Location: " . base_url('views/barangmasuk/index.php'));
        exit;
    }

    if ($harga_beli < 0) {
        $harga_beli = 0.00;
    }

    // Hitung total nilai pembelian di backend untuk mencegah manipulasi
    $total = (float)($jumlah * $harga_beli);

    // ==========================================================
    // EKSEKUSI DATABASE TRANSACTION ATOMIK
    // Menjamin tb_barangmasuk & tb_barang diperbarui secara bersamaan
    // ==========================================================
    $koneksi->begin_transaction();
    try {
        // 1. Kunci dan validasi data barang di tb_barang
        $stmt_check = $koneksi->prepare("SELECT id_barang, kode_barang, nama_barang, stok, stok_minimum FROM tb_barang WHERE id_barang = ? FOR UPDATE");
        $stmt_check->bind_param("i", $id_barang);
        $stmt_check->execute();
        $res_check = $stmt_check->get_result();

        if (!$res_check || $res_check->num_rows === 0) {
            throw new Exception("Bahan baku yang dipilih tidak ditemukan di master database.");
        }

        $barang = $res_check->fetch_assoc();
        $stmt_check->close();

        $kode_barang = $barang['kode_barang'];
        $nama_barang = $barang['nama_barang'];
        $stok_lama   = (int)$barang['stok'];
        $stok_min    = (int)$barang['stok_minimum'];

        // 2. Hitung penambahan stok dan status baru
        $stok_baru = $stok_lama + $jumlah;
        if ($stok_baru == 0) {
            $status_baru = 'Habis';
        } elseif ($stok_baru <= $stok_min) {
            $status_baru = 'Menipis';
        } else {
            $status_baru = 'Aman';
        }

        // 3. Update stok dan status pada tb_barang
        $stmt_upd = $koneksi->prepare("UPDATE tb_barang SET stok = ?, status = ? WHERE id_barang = ?");
        $stmt_upd->bind_param("isi", $stok_baru, $status_baru, $id_barang);
        if (!$stmt_upd->execute()) {
            throw new Exception("Gagal memperbarui stok master barang: " . $stmt_upd->error);
        }
        $stmt_upd->close();

        // 4. Catat transaksi ke tb_barangmasuk
        $stmt_ins = $koneksi->prepare("INSERT INTO tb_barangmasuk (tanggal_masuk, id_barang, kode_barang, nama_barang, supplier, jumlah, harga_beli, total, keterangan, id_user) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt_ins->bind_param("sisssidssi", $tanggal_masuk, $id_barang, $kode_barang, $nama_barang, $supplier, $jumlah, $harga_beli, $total, $keterangan, $user_id);
        if (!$stmt_ins->execute()) {
            throw new Exception("Gagal menyimpan riwayat transaksi bahan masuk: " . $stmt_ins->error);
        }
        $stmt_ins->close();

        // 5. Commit transaksi
        $koneksi->commit();

        $_SESSION['flash_msg'] = [
            'type' => 'success',
            'text' => "Transaksi berhasil dicatat! Stok '{$nama_barang}' bertambah +{$jumlah} unit (Stok sekarang: {$stok_baru})."
        ];
    } catch (Exception $e) {
        $koneksi->rollback();
        $_SESSION['flash_msg'] = [
            'type' => 'error',
            'text' => "Transaksi gagal diproses: " . $e->getMessage()
        ];
    }

    header("Location: " . base_url('views/barangmasuk/index.php'));
    exit;
}

// ==============================================================
// 2. QUERY METRIK SUMMARY CARDS
// ==============================================================
$today = date('Y-m-d');
$total_pembelian_hari_ini = 0.00;
$total_item_hari_ini = 0;

$q_today = $koneksi->query("SELECT COALESCE(SUM(total), 0) AS total_beli, COALESCE(SUM(jumlah), 0) AS total_item FROM tb_barangmasuk WHERE tanggal_masuk = '$today'");
if ($q_today && $r_td = $q_today->fetch_assoc()) {
    $total_pembelian_hari_ini = (float)$r_td['total_beli'];
    $total_item_hari_ini = (int)$r_td['total_item'];
}

$curr_year = date('Y');
$curr_month = date('m');
$total_transaksi_bulan_ini = 0;
$q_bln = $koneksi->query("SELECT COUNT(*) AS total_trx FROM tb_barangmasuk WHERE YEAR(tanggal_masuk) = '$curr_year' AND MONTH(tanggal_masuk) = '$curr_month'");
if ($q_bln && $r_bl = $q_bln->fetch_assoc()) {
    $total_transaksi_bulan_ini = (int)$r_bl['total_trx'];
}

$total_transaksi = 0;
$q_all = $koneksi->query("SELECT COUNT(*) AS total_all FROM tb_barangmasuk");
if ($q_all && $r_all = $q_all->fetch_assoc()) {
    $total_transaksi = (int)$r_all['total_all'];
}

// ==============================================================
// 3. QUERY MASTER BAHAN BAKU UNTUK DROPDOWN MODAL
// ==============================================================
$master_barang = [];
$q_mb = $koneksi->query("SELECT id_barang, kode_barang, nama_barang, kategori, stok, satuan, harga FROM tb_barang ORDER BY nama_barang ASC");
if ($q_mb) {
    while ($rb = $q_mb->fetch_assoc()) {
        $master_barang[] = $rb;
    }
}

// ==============================================================
// 4. QUERY RIWAYAT TRANSAKSI BAHAN MASUK
// ==============================================================
$transaksi_masuk = [];
$q_trx = $koneksi->query("
    SELECT m.*, b.satuan, b.kategori, u.nama_user 
    FROM tb_barangmasuk m
    LEFT JOIN tb_barang b ON m.id_barang = b.id_barang
    LEFT JOIN tb_user u ON m.id_user = u.id_user
    ORDER BY m.tanggal_masuk DESC, m.id_masuk DESC
");
if ($q_trx) {
    while ($rt = $q_trx->fetch_assoc()) {
        $transaksi_masuk[] = $rt;
    }
}

// Format nama bulan Indonesia
$nama_bulan_ini = tanggal_indo(date('Y-m-01'));
$bulan_parts = explode(' ', $nama_bulan_ini);
$label_bulan_tahun = ($bulan_parts[1] ?? date('F')) . ' ' . ($bulan_parts[2] ?? date('Y'));

require_once __DIR__ . '/../../header.php';
require_once __DIR__ . '/../../sidebar.php';
?>

<!-- Main Content Area Bahan Masuk (100% Persis Desain Google Stitch AI) -->
<main class="relative pt-16 bg-surface min-h-screen w-full px-space-lg py-space-lg">
  <div class="flex flex-col w-full">
    <div class="relative w-full max-w-7xl mx-auto flex flex-col gap-space-lg">

      <!-- Flash Alert Notification (Jika Ada) -->
      <?php if (!empty($alert_message)): ?>
      <div id="flashAlert" class="flex items-center justify-between p-space-md rounded-2xl shadow-sm transition-all duration-200 <?= ($alert_type === 'success') ? 'bg-emerald-50 text-emerald-800 border border-emerald-200' : 'bg-red-50 text-red-800 border border-red-200' ?>">
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

      <!-- Page Header (Creative Mascot Typography & Dual Tone) -->
      <div class="text-center pt-space-xs pb-space-sm">
        <div class="inline-flex items-center gap-space-xs bg-primary-fixed/40 px-space-md py-1 rounded-full text-primary font-label-md text-label-md uppercase tracking-wider mb-space-xs">
          <span class="w-2 h-2 rounded-full bg-primary-container animate-pulse"></span>
          Outlet Tanjungpinang · Logistik Terpadu
        </div>
        <h1 class="font-headline-lg text-headline-lg text-primary-container tracking-tight drop-shadow-sm" id="page-heading-title">
          Bahan Masuk
        </h1>
        <p class="font-body-md text-body-md text-tertiary-container font-semibold mt-1" id="page-heading-sub">
          Kelola transaksi penerimaan dan logistik bahan baku masuk dari supplier
        </p>
      </div>

      <!-- Mode Switcher Tabs & Quick Action Bar -->
      <div class="flex flex-col sm:flex-row items-center justify-between gap-space-md bg-surface-container-lowest p-space-sm rounded-xl shadow-sm">
        <!-- Segmented Pill Control -->
        <div class="inline-flex p-1 bg-surface-container rounded-xl gap-1">
          <div class="flex items-center gap-space-xs px-space-lg py-2 rounded-lg font-headline-sm text-headline-sm bg-primary-container text-on-primary shadow-sm">
            <span class="material-symbols-outlined text-[18px]">input</span>
            <span>Bahan Masuk</span>
            <span class="ml-1 text-[11px] px-1.5 py-0.5 rounded-full bg-white/20 font-label-sm" id="masuk-badge">
              <?= $total_transaksi ?> Logistik Aktif
            </span>
          </div>
        </div>

        <!-- Action Button (Triggers Drawer Modal): Hanya untuk Gudang -->
        <div class="flex items-center gap-space-sm">
          <?php if ($user_level === 'gudang'): ?>
          <button class="flex items-center gap-space-xs px-space-lg py-2.5 rounded-xl bg-primary-container hover:bg-primary text-on-primary shadow-sm hover:shadow-md transition-all font-headline-sm text-headline-sm cursor-pointer active:scale-95" id="btn-input-action" type="button">
            <span class="material-symbols-outlined text-[20px]">add_circle</span>
            <span id="btn-action-label">+ Input Bahan Masuk</span>
          </button>
          <?php else: ?>
          <div class="flex items-center gap-space-xs px-3 py-1.5 rounded-xl bg-surface-container-low text-on-surface-variant text-label-md font-medium border border-outline-variant/30">
            <span class="material-symbols-outlined text-[16px] text-outline">visibility</span>
            <span>Mode Read-Only (Owner)</span>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- KPI Summary Bento Cards -->
      <div class="grid grid-cols-1 md:grid-cols-2 gap-space-lg">
        
        <!-- Card 1: Value / Usage KPI (Pink Accent Identity - bg-secondary-container) -->
        <div class="relative overflow-hidden rounded-2xl bg-secondary-container p-space-xl text-on-secondary-container shadow-md flex flex-col justify-between min-h-[170px]">
          <div class="flex items-start justify-between">
            <div class="flex flex-col">
              <span class="font-headline-sm text-headline-sm font-semibold tracking-wide" id="kpi-card1-title">
                Pembelian Hari Ini
              </span>
              <span class="font-label-md text-label-md opacity-80 mt-0.5" id="kpi-card1-subtitle">
                Akumulasi nilai penerimaan logistik
              </span>
            </div>
            <div class="w-10 h-10 rounded-full bg-white/40 flex items-center justify-center">
              <span class="material-symbols-outlined text-on-secondary-container text-[22px]" id="kpi-card1-icon">payments</span>
            </div>
          </div>
          <div class="mt-4 flex items-baseline justify-between">
            <div>
              <div class="font-metric-display text-metric-display tracking-tight text-on-secondary-container leading-none" id="kpi-card1-val">
                <?= format_rupiah($total_pembelian_hari_ini) ?>
              </div>
              <div class="font-body-sm text-body-sm opacity-90 mt-1">
                <?= number_format($total_item_hari_ini) ?> item diterima hari ini
              </div>
            </div>
            <span class="px-space-sm py-1 bg-white/40 rounded-full font-label-sm text-label-sm uppercase tracking-wide">
              Terverifikasi
            </span>
          </div>
          <!-- Decorative background circles -->
          <div class="absolute -right-10 -bottom-10 w-36 h-36 bg-white/20 rounded-full pointer-events-none"></div>
        </div>

        <!-- Card 2: Transactions Quantity KPI (Cyan Accent Identity - bg-primary-container) -->
        <div class="relative overflow-hidden rounded-2xl bg-primary-container p-space-xl text-on-primary shadow-md flex flex-col justify-between min-h-[170px]">
          <div class="flex items-start justify-between">
            <div class="flex flex-col">
              <span class="font-headline-sm text-headline-sm font-semibold tracking-wide" id="kpi-card2-title">
                Transaksi Bulan Ini
              </span>
              <span class="font-label-md text-label-md opacity-85 mt-0.5">
                Periode <?= htmlspecialchars($label_bulan_tahun) ?> (Aktif)
              </span>
            </div>
            <div class="w-10 h-10 rounded-full bg-white/25 flex items-center justify-center">
              <span class="material-symbols-outlined text-on-primary text-[22px]">receipt_long</span>
            </div>
          </div>
          <div class="mt-4 flex items-baseline justify-between">
            <div>
              <div class="font-metric-display text-metric-display tracking-tight leading-none" id="kpi-card2-val">
                <?= number_format($total_transaksi_bulan_ini) ?>
              </div>
              <div class="font-body-sm text-body-sm opacity-90 mt-1">
                Jumlah total transaksi terekam sistem
              </div>
            </div>
            <span class="px-space-sm py-1 bg-white/25 rounded-full font-label-sm text-label-sm uppercase tracking-wide">
              100% Selesai
            </span>
          </div>
          <!-- Decorative background circles -->
          <div class="absolute -right-8 -top-8 w-32 h-32 bg-white/10 rounded-full pointer-events-none"></div>
        </div>

      </div>

      <!-- Main Transaction Table Panel -->
      <div class="bg-surface-container-lowest rounded-2xl shadow-md p-space-lg flex flex-col gap-space-md">
        
        <!-- Panel Header & Search Filter Controls -->
        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-space-md">
          <div>
            <h2 class="font-headline-md text-headline-md text-on-surface" id="table-card-title">
              Riwayat Bahan Masuk
            </h2>
            <p class="font-body-sm text-body-sm text-on-surface-variant" id="table-card-subtitle">
              Daftar penerimaan bahan baku dari supplier resmi dan catatan logistik pergudangan.
            </p>
          </div>
          
          <!-- Search Input Bar & Quick Filters -->
          <div class="flex flex-wrap items-center gap-space-sm">
            <div class="relative flex items-center min-w-[260px] flex-1 sm:flex-initial">
              <span class="material-symbols-outlined absolute left-3 text-on-surface-variant text-[18px]">search</span>
              <input class="w-full pl-9 pr-space-md py-2 rounded-xl bg-surface-container text-on-surface placeholder:text-on-surface-variant font-body-sm text-body-sm focus:outline-none focus:bg-surface-bright focus:ring-2 focus:ring-primary-container/30 transition-all" 
                     id="search-input" placeholder="Cari transaksi, kode, supplier..." type="text" autocomplete="off">
            </div>
          </div>
        </div>

        <!-- Responsive Desktop Table Structure -->
        <div class="overflow-x-auto rounded-xl bg-surface">
          <table class="w-full text-left border-collapse" id="transaksiTable">
            <thead>
              <tr class="bg-surface-container-high/60 text-on-surface-variant uppercase font-label-md text-label-md tracking-wider">
                <th class="py-3 px-4 font-semibold">Tanggal</th>
                <th class="py-3 px-3 font-semibold">Kode</th>
                <th class="py-3 px-4 font-semibold">Nama Bahan</th>
                <th class="py-3 px-4 font-semibold" id="th-partner-label">Supplier</th>
                <th class="py-3 px-3 font-semibold text-right">Jumlah</th>
                <th class="py-3 px-3 font-semibold text-right">Harga Satuan</th>
                <th class="py-3 px-4 font-semibold text-right">Total</th>
                <th class="py-3 px-4 font-semibold">Keterangan</th>
                <th class="py-3 px-3 font-semibold text-center">Aksi</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-surface-container font-body-sm text-body-sm text-on-surface" id="transaction-rows">

              <?php if (empty($transaksi_masuk)): ?>
              <!-- EMPTY STATE: BELUM ADA TRANSAKSI BAHAN MASUK -->
              <tr id="emptyTrxRow">
                <td colspan="9" class="py-16 px-6 text-center">
                  <div class="max-w-md mx-auto flex flex-col items-center justify-center">
                    <div class="w-16 h-16 rounded-2xl bg-primary-fixed/40 text-primary flex items-center justify-center mb-4 shadow-inner">
                      <span class="material-symbols-outlined text-[36px]">input</span>
                    </div>
                    <h3 class="font-headline-md text-headline-md text-on-surface font-semibold mb-1">
                      Belum Ada Transaksi Bahan Masuk
                    </h3>
                    <p class="font-body-md text-body-md text-on-surface-variant mb-6 text-center leading-relaxed">
                      <?php if ($user_level === 'gudang'): ?>
                        <?php if (empty($master_barang)): ?>
                          Master bahan baku masih kosong. Silakan tambahkan data bahan baku terlebih dahulu melalui menu <strong>Data Bahan Baku</strong> sebelum mencatat transaksi masuk.
                        <?php else: ?>
                          Belum ada riwayat transaksi penerimaan bahan baku yang tercatat di sistem. Silakan klik tombol di bawah untuk mencatat transaksi masuk pertama.
                        <?php endif; ?>
                      <?php else: ?>
                        Belum ada data transaksi bahan masuk yang dicatat oleh staf gudang ke dalam sistem.
                      <?php endif; ?>
                    </p>
                    
                    <?php if ($user_level === 'gudang'): ?>
                      <?php if (empty($master_barang)): ?>
                      <a href="<?= base_url('views/barang/index.php') ?>" class="inline-flex items-center gap-2 px-space-lg py-2.5 rounded-xl bg-secondary hover:bg-secondary/90 text-on-secondary font-label-lg text-label-lg shadow-md transition-all active:scale-95">
                        <span class="material-symbols-outlined text-[20px]">inventory_2</span>
                        <span>Buka Data Bahan Baku</span>
                      </a>
                      <?php else: ?>
                      <button type="button" onclick="openInputModal()" class="inline-flex items-center gap-2 px-space-lg py-2.5 rounded-xl bg-primary-container hover:bg-primary text-on-primary font-label-lg text-label-lg shadow-md transition-all active:scale-95 cursor-pointer">
                        <span class="material-symbols-outlined text-[20px]">add_circle</span>
                        <span>+ Input Bahan Masuk Pertama</span>
                      </button>
                      <?php endif; ?>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
              <?php else: ?>

                <?php 
                $kat_dot_colors = [
                  'es krim'   => 'bg-primary-container',
                  'topping'   => 'bg-secondary',
                  'packaging' => 'bg-tertiary-container',
                  'sirup'     => 'bg-secondary-container',
                  'teh'       => 'bg-primary'
                ];

                foreach ($transaksi_masuk as $row): 
                  $kat_key = strtolower(trim($row['kategori'] ?? ''));
                  $dot_color = $kat_dot_colors[$kat_key] ?? 'bg-primary-container';
                  $satuan_display = !empty($row['satuan']) ? htmlspecialchars($row['satuan']) : 'Unit';
                ?>
                <!-- Baris Transaksi Bahan Masuk -->
                <tr class="hover:bg-surface-container-low transition-colors group item-trx-row" 
                    data-search="<?= htmlspecialchars(strtolower($row['tanggal_masuk'] . ' ' . $row['kode_barang'] . ' ' . $row['nama_barang'] . ' ' . $row['supplier'] . ' ' . $row['keterangan'])) ?>">
                  
                  <!-- Tanggal -->
                  <td class="py-3 px-4 font-semibold whitespace-nowrap text-on-surface">
                    <?= htmlspecialchars($row['tanggal_masuk']) ?>
                  </td>
                  
                  <!-- Kode -->
                  <td class="py-3 px-3 whitespace-nowrap">
                    <span class="px-2 py-0.5 rounded-lg bg-surface-variant font-label-sm text-label-sm text-primary font-mono font-semibold">
                      <?= htmlspecialchars($row['kode_barang']) ?>
                    </span>
                  </td>
                  
                  <!-- Nama Bahan -->
                  <td class="py-3 px-4 font-semibold text-on-surface">
                    <div class="flex items-center gap-2">
                      <span class="w-2 h-2 rounded-full <?= $dot_color ?>"></span>
                      <span><?= htmlspecialchars($row['nama_barang']) ?></span>
                    </div>
                  </td>
                  
                  <!-- Supplier -->
                  <td class="py-3 px-4 text-on-surface-variant font-medium">
                    <?= htmlspecialchars($row['supplier']) ?>
                  </td>
                  
                  <!-- Jumlah -->
                  <td class="py-3 px-3 text-right font-semibold text-on-surface">
                    <?= number_format($row['jumlah']) ?> <?= $satuan_display ?>
                  </td>
                  
                  <!-- Harga Satuan -->
                  <td class="py-3 px-3 text-right text-on-surface-variant">
                    Rp <?= number_format($row['harga_beli'], 0, ',', '.') ?>
                  </td>
                  
                  <!-- Total -->
                  <td class="py-3 px-4 text-right font-semibold text-primary">
                    Rp <?= number_format($row['total'], 0, ',', '.') ?>
                  </td>
                  
                  <!-- Keterangan -->
                  <td class="py-3 px-4 text-on-surface-variant max-w-[180px] truncate" title="<?= htmlspecialchars($row['keterangan'] ?? '-') ?>">
                    <?= htmlspecialchars(!empty($row['keterangan']) ? $row['keterangan'] : '-') ?>
                  </td>
                  
                  <!-- Aksi Detail -->
                  <td class="py-3 px-3 text-center">
                    <button type="button" 
                            class="btn-view-detail p-1.5 rounded-lg text-on-surface-variant hover:text-primary hover:bg-surface-variant transition-colors cursor-pointer"
                            data-id="TRX-IN-<?= str_pad($row['id_masuk'], 5, '0', STR_PAD_LEFT) ?>"
                            data-tanggal="<?= htmlspecialchars($row['tanggal_masuk']) ?>"
                            data-kode="<?= htmlspecialchars($row['kode_barang']) ?>"
                            data-nama="<?= htmlspecialchars($row['nama_barang']) ?>"
                            data-supplier="<?= htmlspecialchars($row['supplier']) ?>"
                            data-jumlah="<?= number_format($row['jumlah']) ?> <?= $satuan_display ?>"
                            data-harga="Rp <?= number_format($row['harga_beli'], 0, ',', '.') ?>"
                            data-total="Rp <?= number_format($row['total'], 0, ',', '.') ?>"
                            data-keterangan="<?= htmlspecialchars($row['keterangan'] ?? '-') ?>"
                            data-petugas="<?= htmlspecialchars(!empty($row['nama_user']) ? $row['nama_user'] : 'Petugas Gudang') ?>"
                            title="Lihat Rincian Transaksi">
                      <span class="material-symbols-outlined text-[18px]">visibility</span>
                    </button>
                  </td>

                </tr>
                <?php endforeach; ?>

              <?php endif; ?>

              <!-- Baris Nihil Pencarian -->
              <tr id="noSearchRow" class="hidden">
                <td colspan="9" class="py-12 px-6 text-center">
                  <div class="max-w-md mx-auto flex flex-col items-center justify-center">
                    <div class="w-14 h-14 rounded-2xl bg-surface-container text-outline flex items-center justify-center mb-3">
                      <span class="material-symbols-outlined text-[32px]">search_off</span>
                    </div>
                    <h4 class="font-headline-sm text-headline-sm text-on-surface font-semibold mb-1">
                      Data Transaksi Tidak Ditemukan
                    </h4>
                    <p class="font-body-sm text-body-sm text-on-surface-variant mb-4">
                      Tidak ada transaksi bahan masuk yang cocok dengan kata kunci pencarian.
                    </p>
                    <button type="button" id="resetSearchBtn" class="px-space-md py-1.5 rounded-lg bg-surface-container-low hover:bg-surface-container text-on-surface font-label-md text-label-md transition-colors cursor-pointer">
                      Reset Pencarian
                    </button>
                  </div>
                </td>
              </tr>

            </tbody>
          </table>
        </div>

        <!-- Pagination & Summary Stats Bar -->
        <div class="flex flex-col sm:flex-row items-center justify-between gap-space-sm pt-space-xs text-on-surface-variant border-t border-surface-container/60">
          <div class="font-body-sm text-body-sm" id="tableMetaCount">
            Menampilkan <span class="font-semibold text-on-surface"><?= count($transaksi_masuk) ?></span> dari <span class="font-semibold text-on-surface"><?= $total_transaksi ?></span> data transaksi
          </div>
          
          <div class="inline-flex items-center gap-1">
            <button class="w-8 h-8 rounded-lg bg-surface-container flex items-center justify-center text-on-surface-variant hover:bg-surface-variant disabled:opacity-40" disabled>
              <span class="material-symbols-outlined text-[18px]">chevron_left</span>
            </button>
            <button class="w-8 h-8 rounded-lg bg-primary-container text-on-primary font-label-md text-label-md flex items-center justify-center shadow-xs">
              1
            </button>
            <button class="w-8 h-8 rounded-lg bg-surface-container flex items-center justify-center text-on-surface-variant hover:bg-surface-variant disabled:opacity-40" disabled>
              <span class="material-symbols-outlined text-[18px]">chevron_right</span>
            </button>
          </div>
        </div>

      </div>

    </div>
  </div>
</main>

<!-- ============================================================== -->
<!-- MODAL POPUP: INPUT BAHAN MASUK (Sesuai Desain Google Stitch) -->
<!-- ============================================================== -->
<?php if ($user_level === 'gudang'): ?>
<div class="fixed inset-0 z-50 flex items-center justify-center hidden" id="inputModal">
  <!-- Backdrop scrim -->
  <div class="absolute inset-0 bg-inverse-surface/40 backdrop-blur-sm transition-opacity" id="inputModalBackdrop"></div>
  
  <!-- Modal Dialog Card -->
  <div class="relative w-full max-w-lg bg-surface-container-lowest rounded-2xl shadow-2xl p-space-xl z-10 mx-4 transition-all">
    
    <!-- Modal Header -->
    <div class="flex items-start justify-between pb-space-md border-b border-surface-container/50">
      <div>
        <h3 class="font-headline-md text-headline-md text-on-surface font-bold">Input Bahan Masuk</h3>
        <p class="font-body-sm text-body-sm text-on-surface-variant mt-0.5">Masukkan data bahan baku yang baru diterima dari supplier</p>
      </div>
      <button type="button" class="w-8 h-8 rounded-full bg-surface-container-low hover:bg-surface-container text-on-surface flex items-center justify-center transition-colors cursor-pointer" id="closeInputModalBtn">
        <span class="material-symbols-outlined text-[20px]">close</span>
      </button>
    </div>

    <!-- Cek Jika Master Bahan Baku Kosong -->
    <?php if (empty($master_barang)): ?>
    <div class="p-4 rounded-xl bg-amber-50 border border-amber-200 text-amber-800 text-xs mt-4">
      <div class="flex items-center gap-2 font-bold mb-1">
        <span class="material-symbols-outlined text-[18px] text-amber-600">warning</span>
        <span>Data Master Bahan Baku Kosong</span>
      </div>
      <p class="mb-3">Anda belum memiliki data bahan baku master untuk dicatat transaksi masuknya.</p>
      <a href="<?= base_url('views/barang/index.php') ?>" class="inline-block px-3 py-1.5 rounded-lg bg-amber-600 hover:bg-amber-700 text-white font-semibold transition-colors">
        Tambah Bahan Baku Sekarang &rarr;
      </a>
    </div>
    <?php else: ?>

    <!-- Form Content Transaksi -->
    <form class="flex flex-col gap-space-md mt-space-md" method="POST" action="<?= base_url('views/barangmasuk/index.php') ?>" id="formInputBahanMasuk">
      <input type="hidden" name="action" value="tambah_masuk">

      <!-- Field: Tanggal Transaksi & Kode (Read-only Auto) -->
      <div class="grid grid-cols-2 gap-space-md">
        <div class="flex flex-col gap-1">
          <label class="font-label-md text-label-md text-on-surface-variant font-medium">Tanggal Transaksi *</label>
          <input class="h-10 px-3 rounded-xl bg-surface-container text-on-surface font-body-sm text-body-sm focus:outline-none focus:ring-2 focus:ring-primary-container/30 cursor-pointer" 
                 name="tanggal_masuk" id="inputTanggal" required type="date" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>">
        </div>

        <div class="flex flex-col gap-1">
          <label class="font-label-md text-label-md text-on-surface-variant font-medium">Kode SKU</label>
          <input class="h-10 px-3 rounded-xl bg-surface-container-low text-on-surface-variant font-mono font-bold text-body-sm focus:outline-none uppercase" 
                 id="displayKode" placeholder="Pilih bahan..." readonly type="text">
        </div>
      </div>

      <!-- Field: Pilihan Bahan Baku (Dropdown Dinamis MySQL) -->
      <div class="flex flex-col gap-1">
        <label class="font-label-md text-label-md text-on-surface-variant font-medium">Bahan Baku *</label>
        <select class="h-10 px-3 rounded-xl bg-surface-container text-on-surface font-body-sm text-body-sm focus:outline-none focus:ring-2 focus:ring-primary-container/30 cursor-pointer" 
                name="id_barang" id="selectBahanBaku" required>
          <option value="" disabled selected>-- Pilih Bahan Baku --</option>
          <?php foreach ($master_barang as $b): ?>
          <option value="<?= $b['id_barang'] ?>" 
                  data-kode="<?= htmlspecialchars($b['kode_barang']) ?>"
                  data-nama="<?= htmlspecialchars($b['nama_barang']) ?>"
                  data-satuan="<?= htmlspecialchars($b['satuan']) ?>"
                  data-stok="<?= (int)$b['stok'] ?>"
                  data-harga="<?= (float)$b['harga'] ?>">
            <?= htmlspecialchars($b['nama_barang']) ?> (<?= htmlspecialchars($b['kode_barang']) ?>) — Stok: <?= number_format($b['stok']) ?> <?= htmlspecialchars($b['satuan']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Field: Pemasok / Supplier & Jumlah Masuk -->
      <div class="grid grid-cols-2 gap-space-md">
        <div class="flex flex-col gap-1">
          <label class="font-label-md text-label-md text-on-surface-variant font-medium">Pemasok / Supplier *</label>
          <input class="h-10 px-3 rounded-xl bg-surface-container text-on-surface font-body-sm text-body-sm focus:outline-none focus:ring-2 focus:ring-primary-container/30" 
                 name="supplier" id="inputSupplier" placeholder="Nama mitra supplier..." required type="text" autocomplete="off">
        </div>

        <div class="flex flex-col gap-1">
          <label class="font-label-md text-label-md text-on-surface-variant font-medium">Jumlah Masuk *</label>
          <div class="flex items-center bg-surface-container rounded-xl overflow-hidden focus-within:ring-2 focus-within:ring-primary-container/30">
            <input class="w-full h-10 px-3 bg-transparent text-on-surface font-body-sm text-body-sm focus:outline-none" 
                   name="jumlah" id="inputJumlah" min="1" placeholder="0" required type="number" value="1">
            <span class="pr-3 text-on-surface-variant font-label-sm text-label-sm font-semibold whitespace-nowrap" id="badgeSatuan">
              Satuan
            </span>
          </div>
        </div>
      </div>

      <!-- Field: Harga Satuan Beli & Total Pembelian -->
      <div class="grid grid-cols-2 gap-space-md">
        <div class="flex flex-col gap-1">
          <label class="font-label-md text-label-md text-on-surface-variant font-medium">Harga Satuan Beli (Rp)</label>
          <input class="h-10 px-3 rounded-xl bg-surface-container text-on-surface font-body-sm text-body-sm focus:outline-none focus:ring-2 focus:ring-primary-container/30" 
                 name="harga_beli" id="inputHargaBeli" min="0" step="500" placeholder="0" type="number" value="0">
        </div>

        <div class="flex flex-col gap-1">
          <label class="font-label-md text-label-md text-on-surface-variant font-medium">Total Pembelian (Rp)</label>
          <input class="h-10 px-3 rounded-xl bg-surface-container-low text-primary font-bold text-body-sm focus:outline-none" 
                 id="displayTotal" placeholder="Rp 0" readonly type="text">
        </div>
      </div>

      <!-- Field: Keterangan Catatan -->
      <div class="flex flex-col gap-1">
        <label class="font-label-md text-label-md text-on-surface-variant font-medium">Keterangan Catatan</label>
        <input class="h-10 px-3 rounded-xl bg-surface-container text-on-surface font-body-sm text-body-sm focus:outline-none focus:ring-2 focus:ring-primary-container/30" 
               name="keterangan" id="inputKeterangan" list="keteranganPresets" placeholder="Contoh: Restok Rutin / Bahan Baku Eskrim" type="text" autocomplete="off">
        <datalist id="keteranganPresets">
          <option value="Restok Rutin Mingguan">
          <option value="Bahan Baku Eskrim">
          <option value="Bahan Topping Teh">
          <option value="Restok Kemasan Cup">
          <option value="Pengiriman Supplier Khusus">
        </datalist>
      </div>

      <!-- Footer Buttons -->
      <div class="flex items-center justify-end gap-space-sm pt-space-md border-t border-surface-container/50 mt-space-xs">
        <button type="button" class="h-10 px-space-lg rounded-xl bg-surface-container-low hover:bg-surface-container text-on-surface font-label-lg text-label-lg transition-colors cursor-pointer" id="cancelInputModalBtn">
          Batal
        </button>
        <button type="submit" class="h-10 px-space-xl rounded-xl bg-primary-container hover:bg-primary text-on-primary font-headline-sm text-headline-sm shadow-md transition-all flex items-center gap-1 cursor-pointer active:scale-95" id="btnSubmitTransaksi">
          <span class="material-symbols-outlined text-[18px]">check</span>
          <span>Simpan Transaksi</span>
        </button>
      </div>
    </form>
    <?php endif; ?>

  </div>
</div>
<?php endif; ?>

<!-- ============================================================== -->
<!-- MODAL POPUP: RINCIAN DETAIL TRANSAKSI BAHAN MASUK -->
<!-- ============================================================== -->
<div class="fixed inset-0 z-50 flex items-center justify-center hidden" id="detailModal">
  <!-- Backdrop scrim -->
  <div class="absolute inset-0 bg-inverse-surface/40 backdrop-blur-sm transition-opacity" id="detailModalBackdrop"></div>
  
  <!-- Modal Dialog Card -->
  <div class="relative w-full max-w-lg bg-surface-container-lowest rounded-2xl shadow-2xl p-space-xl z-10 mx-4 transition-all">
    <div class="flex items-start justify-between pb-space-md border-b border-surface-container/50">
      <div>
        <div class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-primary-fixed/50 text-primary font-mono text-[11px] font-bold mb-1" id="detailTrxId">
          TRX-IN-00001
        </div>
        <h3 class="font-headline-md text-headline-md text-on-surface font-bold">Rincian Transaksi Bahan Masuk</h3>
      </div>
      <button type="button" class="w-8 h-8 rounded-full bg-surface-container-low hover:bg-surface-container text-on-surface flex items-center justify-center transition-colors cursor-pointer" id="closeDetailModalBtn">
        <span class="material-symbols-outlined text-[20px]">close</span>
      </button>
    </div>

    <div class="flex flex-col gap-3.5 mt-space-md text-body-sm text-on-surface">
      <div class="grid grid-cols-2 gap-3 p-3 bg-surface-container-low rounded-xl">
        <div>
          <span class="text-on-surface-variant font-label-sm uppercase tracking-wider block mb-0.5">Tanggal Masuk</span>
          <span class="font-bold text-on-surface" id="detailTanggal">-</span>
        </div>
        <div>
          <span class="text-on-surface-variant font-label-sm uppercase tracking-wider block mb-0.5">Petugas Input</span>
          <span class="font-bold text-on-surface" id="detailPetugas">-</span>
        </div>
      </div>

      <div class="p-3 bg-surface-container-low rounded-xl flex flex-col gap-1">
        <span class="text-on-surface-variant font-label-sm uppercase tracking-wider">Bahan Baku</span>
        <div class="flex items-center gap-2">
          <span class="px-2 py-0.5 rounded bg-surface-variant text-primary font-mono font-bold text-xs" id="detailKode">-</span>
          <span class="font-semibold text-base text-on-surface" id="detailNama">-</span>
        </div>
      </div>

      <div class="grid grid-cols-2 gap-3 p-3 bg-surface-container-low rounded-xl">
        <div>
          <span class="text-on-surface-variant font-label-sm uppercase tracking-wider block mb-0.5">Supplier / Mitra</span>
          <span class="font-semibold text-on-surface" id="detailSupplier">-</span>
        </div>
        <div>
          <span class="text-on-surface-variant font-label-sm uppercase tracking-wider block mb-0.5">Jumlah Masuk</span>
          <span class="font-bold text-emerald-700" id="detailJumlah">-</span>
        </div>
      </div>

      <div class="grid grid-cols-2 gap-3 p-3 bg-surface-container-low rounded-xl">
        <div>
          <span class="text-on-surface-variant font-label-sm uppercase tracking-wider block mb-0.5">Harga Beli Satuan</span>
          <span class="font-semibold text-on-surface" id="detailHarga">-</span>
        </div>
        <div>
          <span class="text-on-surface-variant font-label-sm uppercase tracking-wider block mb-0.5">Total Pembelian</span>
          <span class="font-bold text-primary text-base" id="detailTotal">-</span>
        </div>
      </div>

      <div class="p-3 bg-surface-container-low rounded-xl">
        <span class="text-on-surface-variant font-label-sm uppercase tracking-wider block mb-0.5">Catatan / Keterangan</span>
        <span class="text-on-surface font-medium" id="detailKeterangan">-</span>
      </div>
    </div>

    <div class="flex items-center justify-end pt-space-md border-t border-surface-container/50 mt-space-md">
      <button type="button" class="h-10 px-space-lg rounded-xl bg-surface-container-low hover:bg-surface-container text-on-surface font-label-lg text-label-lg transition-colors cursor-pointer" id="dismissDetailModalBtn">
        Tutup
      </button>
    </div>
  </div>
</div>

<!-- ============================================================== -->
<!-- CLIENT-SIDE SCRIPT: SEARCH, INPUT CALCULATOR & MODAL CONTROLS -->
<!-- ============================================================== -->
<script>
document.addEventListener('DOMContentLoaded', function () {
  // 1. FILTER REAL-TIME PADA TABEL TRANSAKSI
  const searchInput = document.getElementById('search-input');
  const tableRows = document.querySelectorAll('.item-trx-row');
  const noSearchRow = document.getElementById('noSearchRow');
  const resetBtn = document.getElementById('resetSearchBtn');
  const tableMetaCount = document.getElementById('tableMetaCount');

  function filterTable() {
    const query = searchInput ? searchInput.value.trim().toLowerCase() : '';
    let visible = 0;

    tableRows.forEach(row => {
      const searchData = (row.getAttribute('data-search') || '').toLowerCase();
      if (!query || searchData.includes(query)) {
        row.style.display = '';
        visible++;
      } else {
        row.style.display = 'none';
      }
    });

    if (tableRows.length > 0) {
      if (visible === 0) {
        if (noSearchRow) noSearchRow.classList.remove('hidden');
      } else {
        if (noSearchRow) noSearchRow.classList.add('hidden');
      }
      if (tableMetaCount) {
        tableMetaCount.innerHTML = `Menampilkan <span class="font-semibold text-on-surface">${visible}</span> dari <span class="font-semibold text-on-surface">${tableRows.length}</span> data transaksi`;
      }
    }
  }

  if (searchInput) {
    searchInput.addEventListener('input', filterTable);
  }

  if (resetBtn) {
    resetBtn.addEventListener('click', function () {
      if (searchInput) searchInput.value = '';
      filterTable();
    });
  }

  // 2. MODAL INPUT TRANSAKSI CONTROLS & AUTO-CALCULATION
  const inputModal = document.getElementById('inputModal');
  const btnInputAction = document.getElementById('btn-input-action');
  const closeInputModalBtn = document.getElementById('closeInputModalBtn');
  const cancelInputModalBtn = document.getElementById('cancelInputModalBtn');
  const inputModalBackdrop = document.getElementById('inputModalBackdrop');

  const selectBahanBaku = document.getElementById('selectBahanBaku');
  const displayKode = document.getElementById('displayKode');
  const badgeSatuan = document.getElementById('badgeSatuan');
  const inputJumlah = document.getElementById('inputJumlah');
  const inputHargaBeli = document.getElementById('inputHargaBeli');
  const displayTotal = document.getElementById('displayTotal');
  const formInput = document.getElementById('formInputBahanMasuk');
  const btnSubmit = document.getElementById('btnSubmitTransaksi');

  function updateFormCalculations() {
    if (!selectBahanBaku) return;
    const selectedOption = selectBahanBaku.options[selectBahanBaku.selectedIndex];

    if (selectedOption && selectedOption.value) {
      const kode = selectedOption.getAttribute('data-kode') || '';
      const satuan = selectedOption.getAttribute('data-satuan') || 'Unit';
      const hargaMaster = parseFloat(selectedOption.getAttribute('data-harga') || '0');

      if (displayKode) displayKode.value = kode;
      if (badgeSatuan) badgeSatuan.textContent = satuan;

      // Jika harga beli di form masih 0, isi dengan harga master jika ada
      if (inputHargaBeli && (!inputHargaBeli.value || parseFloat(inputHargaBeli.value) === 0) && hargaMaster > 0) {
        inputHargaBeli.value = hargaMaster;
      }
    }

    const qty = parseFloat(inputJumlah ? inputJumlah.value : '0') || 0;
    const price = parseFloat(inputHargaBeli ? inputHargaBeli.value : '0') || 0;
    const total = qty * price;

    if (displayTotal) {
      displayTotal.value = 'Rp ' + Math.round(total).toLocaleString('id-ID');
    }
  }

  if (selectBahanBaku) {
    selectBahanBaku.addEventListener('change', updateFormCalculations);
  }
  if (inputJumlah) {
    inputJumlah.addEventListener('input', updateFormCalculations);
  }
  if (inputHargaBeli) {
    inputHargaBeli.addEventListener('input', updateFormCalculations);
  }

  // Pencegahan double-submit form
  if (formInput && btnSubmit) {
    formInput.addEventListener('submit', function () {
      btnSubmit.disabled = true;
      btnSubmit.innerHTML = '<span class="material-symbols-outlined text-[18px] animate-spin">refresh</span><span>Menyimpan...</span>';
    });
  }

  window.openInputModal = function () {
    if (inputModal) {
      inputModal.classList.remove('hidden');
      document.body.style.overflow = 'hidden';
      updateFormCalculations();
    }
  };

  function closeInputModal() {
    if (inputModal) {
      inputModal.classList.add('hidden');
      document.body.style.overflow = '';
    }
  }

  if (btnInputAction) btnInputAction.addEventListener('click', window.openInputModal);
  if (closeInputModalBtn) closeInputModalBtn.addEventListener('click', closeInputModal);
  if (cancelInputModalBtn) cancelInputModalBtn.addEventListener('click', closeInputModal);
  if (inputModalBackdrop) inputModalBackdrop.addEventListener('click', closeInputModal);

  // 3. MODAL DETAIL TRANSAKSI CONTROLS
  const detailModal = document.getElementById('detailModal');
  const closeDetailModalBtn = document.getElementById('closeDetailModalBtn');
  const dismissDetailModalBtn = document.getElementById('dismissDetailModalBtn');
  const detailModalBackdrop = document.getElementById('detailModalBackdrop');

  const detailTrxId = document.getElementById('detailTrxId');
  const detailTanggal = document.getElementById('detailTanggal');
  const detailPetugas = document.getElementById('detailPetugas');
  const detailKode = document.getElementById('detailKode');
  const detailNama = document.getElementById('detailNama');
  const detailSupplier = document.getElementById('detailSupplier');
  const detailJumlah = document.getElementById('detailJumlah');
  const detailHarga = document.getElementById('detailHarga');
  const detailTotal = document.getElementById('detailTotal');
  const detailKeterangan = document.getElementById('detailKeterangan');

  const viewDetailButtons = document.querySelectorAll('.btn-view-detail');

  viewDetailButtons.forEach(btn => {
    btn.addEventListener('click', function () {
      if (detailTrxId) detailTrxId.textContent = this.getAttribute('data-id') || '-';
      if (detailTanggal) detailTanggal.textContent = this.getAttribute('data-tanggal') || '-';
      if (detailPetugas) detailPetugas.textContent = this.getAttribute('data-petugas') || 'Staf Gudang';
      if (detailKode) detailKode.textContent = this.getAttribute('data-kode') || '-';
      if (detailNama) detailNama.textContent = this.getAttribute('data-nama') || '-';
      if (detailSupplier) detailSupplier.textContent = this.getAttribute('data-supplier') || '-';
      if (detailJumlah) detailJumlah.textContent = this.getAttribute('data-jumlah') || '-';
      if (detailHarga) detailHarga.textContent = this.getAttribute('data-harga') || '-';
      if (detailTotal) detailTotal.textContent = this.getAttribute('data-total') || '-';
      if (detailKeterangan) detailKeterangan.textContent = this.getAttribute('data-keterangan') || '-';

      if (detailModal) {
        detailModal.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
      }
    });
  });

  function closeDetailModal() {
    if (detailModal) {
      detailModal.classList.add('hidden');
      document.body.style.overflow = '';
    }
  }

  if (closeDetailModalBtn) closeDetailModalBtn.addEventListener('click', closeDetailModal);
  if (dismissDetailModalBtn) dismissDetailModalBtn.addEventListener('click', closeDetailModal);
  if (detailModalBackdrop) detailModalBackdrop.addEventListener('click', closeDetailModal);

  // Keyboard shortcut: ESC to close active modal
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      closeInputModal();
      closeDetailModal();
    }
  });
});
</script>

<?php
require_once __DIR__ . '/../../footer.php';
?>

