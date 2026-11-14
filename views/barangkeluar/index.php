<?php
/**
 * Halaman Transaksi Bahan Keluar - Sistem Inventory Cooler City
 * Desain 100% Mengacu pada Google Stitch AI
 * Terintegrasi Penuh dengan MySQL (tb_barangkeluar & tb_barang),
 * Pengurangan Stok Atomik (Database Transaction & Row Lock), Validasi Stok Ketat,
 * Empty State, Real-time Search, Filter Divisi, dan RBAC
 */

require_once __DIR__ . '/../../config/koneksi.php';
check_login();

$user_level = $_SESSION['level'] ?? 'owner';
$user_name  = $_SESSION['nama_user'] ?? 'User';
$user_id    = (int)($_SESSION['user_id'] ?? 0);
$page_title = 'BAHAN KELUAR';
$current_page = 'barangkeluar';

$alert_message = '';
$alert_type = ''; // 'success' | 'error'

// Ambil notifikasi dari session jika ada
if (isset($_SESSION['flash_msg'])) {
    $alert_message = $_SESSION['flash_msg']['text'];
    $alert_type = $_SESSION['flash_msg']['type'];
    unset($_SESSION['flash_msg']);
}

// ==============================================================
// 1. BACKEND HANDLER: TAMBAH TRANSAKSI BAHAN KELUAR (RBAC & ATOMIC)
// ==============================================================
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'tambah_keluar') {
    // Proteksi RBAC: Role Owner DILARANG melakukan pengeluaran bahan (Read-Only)
    if ($user_level === 'owner') {
        $_SESSION['flash_msg'] = [
            'type' => 'error',
            'text' => 'Akses Ditolak: Role Owner hanya memiliki hak akses baca (Read-only) dan tidak diizinkan menambah transaksi bahan keluar.'
        ];
        header("Location: " . base_url('views/barangkeluar/index.php'));
        exit;
    }

    $tanggal_keluar = trim($_POST['tanggal_keluar'] ?? '');
    $id_barang      = (int)($_POST['id_barang'] ?? 0);
    $tujuan         = trim($_POST['tujuan'] ?? '');
    $jumlah         = (int)($_POST['jumlah'] ?? 0);
    $keterangan     = trim($_POST['keterangan'] ?? '');

    // Validasi input dasar
    if (empty($tanggal_keluar) || $id_barang <= 0 || empty($tujuan) || $jumlah <= 0) {
        $_SESSION['flash_msg'] = [
            'type' => 'error',
            'text' => 'Semua kolom bertanda bintang wajib diisi dan jumlah keluar harus lebih besar dari 0!'
        ];
        header("Location: " . base_url('views/barangkeluar/index.php'));
        exit;
    }

    // ==========================================================
    // EKSEKUSI DATABASE TRANSACTION ATOMIK DENGAN ROW LOCKING
    // Menjamin stok diverifikasi langsung dari database & bebas race condition
    // ==========================================================
    $koneksi->begin_transaction();
    try {
        // 1. Kunci baris master barang dengan FOR UPDATE
        $stmt_check = $koneksi->prepare("SELECT id_barang, kode_barang, nama_barang, stok, satuan, stok_minimum FROM tb_barang WHERE id_barang = ? FOR UPDATE");
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
        $satuan      = $barang['satuan'];
        $stok_min    = (int)$barang['stok_minimum'];

        // 2. Validasi stok: Jumlah keluar TIDAK BOLEH melebihi stok aktual
        if ($stok_lama <= 0) {
            throw new Exception("Stok bahan baku '{$nama_barang}' saat ini habis (0 {$satuan}). Pengeluaran tidak dapat diproses.");
        }

        if ($jumlah > $stok_lama) {
            throw new Exception("Stok bahan baku tidak mencukupi. Stok '{$nama_barang}' tersedia saat ini: {$stok_lama} {$satuan}. Permintaan keluar: {$jumlah} {$satuan}.");
        }

        // 3. Hitung pengurangan stok dan tentukan status baru
        $stok_baru = $stok_lama - $jumlah;
        if ($stok_baru < 0) {
            throw new Exception("Operasi ditolak: stok tidak boleh bernilai negatif.");
        }

        if ($stok_baru == 0) {
            $status_baru = 'Habis';
        } elseif ($stok_baru <= $stok_min) {
            $status_baru = 'Menipis';
        } else {
            $status_baru = 'Aman';
        }

        // 4. Update stok dan status pada master tb_barang
        $stmt_upd = $koneksi->prepare("UPDATE tb_barang SET stok = ?, status = ? WHERE id_barang = ?");
        $stmt_upd->bind_param("isi", $stok_baru, $status_baru, $id_barang);
        if (!$stmt_upd->execute()) {
            throw new Exception("Gagal memperbarui stok master barang: " . $stmt_upd->error);
        }
        $stmt_upd->close();

        // 5. Catat riwayat ke tb_barangkeluar
        $stmt_ins = $koneksi->prepare("INSERT INTO tb_barangkeluar (tanggal_keluar, id_barang, kode_barang, nama_barang, tujuan, jumlah, keterangan, id_user) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt_ins->bind_param("sisssisi", $tanggal_keluar, $id_barang, $kode_barang, $nama_barang, $tujuan, $jumlah, $keterangan, $user_id);
        if (!$stmt_ins->execute()) {
            throw new Exception("Gagal menyimpan riwayat transaksi bahan keluar: " . $stmt_ins->error);
        }
        $stmt_ins->close();

        // 6. Commit transaksi atomik
        $koneksi->commit();

        $_SESSION['flash_msg'] = [
            'type' => 'success',
            'text' => "Pengeluaran bahan baku '{$nama_barang}' berhasil dicatat! Sisa stok saat ini: {$stok_baru} {$satuan}."
        ];
    } catch (Exception $e) {
        $koneksi->rollback();
        $_SESSION['flash_msg'] = [
            'type' => 'error',
            'text' => $e->getMessage()
        ];
    }

    header("Location: " . base_url('views/barangkeluar/index.php'));
    exit;
}

// ==============================================================
// 2. QUERY METRIK SUMMARY CARDS
// ==============================================================
$today = date('Y-m-d');
$total_item_keluar_hari_ini = 0;
$total_sesi_hari_ini = 0;

$q_today = $koneksi->query("SELECT COALESCE(SUM(jumlah), 0) AS total_item, COUNT(*) AS total_sesi FROM tb_barangkeluar WHERE tanggal_keluar = '$today'");
if ($q_today && $r_td = $q_today->fetch_assoc()) {
    $total_item_keluar_hari_ini = (int)$r_td['total_item'];
    $total_sesi_hari_ini = (int)$r_td['total_sesi'];
}

$curr_year = date('Y');
$curr_month = date('m');
$total_transaksi_bulan_ini = 0;
$q_bln = $koneksi->query("SELECT COUNT(*) AS total_trx FROM tb_barangkeluar WHERE YEAR(tanggal_keluar) = '$curr_year' AND MONTH(tanggal_keluar) = '$curr_month'");
if ($q_bln && $r_bl = $q_bln->fetch_assoc()) {
    $total_transaksi_bulan_ini = (int)$r_bl['total_trx'];
}

$total_transaksi = 0;
$q_all = $koneksi->query("SELECT COUNT(*) AS total_all FROM tb_barangkeluar");
if ($q_all && $r_all = $q_all->fetch_assoc()) {
    $total_transaksi = (int)$r_all['total_all'];
}

// ==============================================================
// 3. QUERY MASTER BAHAN BAKU UNTUK DROPDOWN
// ==============================================================
$master_barang = [];
$q_mb = $koneksi->query("SELECT id_barang, kode_barang, nama_barang, kategori, stok, satuan, stok_minimum FROM tb_barang ORDER BY nama_barang ASC");
if ($q_mb) {
    while ($rb = $q_mb->fetch_assoc()) {
        $master_barang[] = $rb;
    }
}

// ==============================================================
// 4. QUERY RIWAYAT TRANSAKSI BAHAN KELUAR
// ==============================================================
$transaksi_keluar = [];
$q_trx = $koneksi->query("
    SELECT k.*, b.satuan, b.kategori, u.nama_user 
    FROM tb_barangkeluar k
    LEFT JOIN tb_barang b ON k.id_barang = b.id_barang
    LEFT JOIN tb_user u ON k.id_user = u.id_user
    ORDER BY k.tanggal_keluar DESC, k.id_keluar DESC
");
if ($q_trx) {
    while ($rk = $q_trx->fetch_assoc()) {
        $transaksi_keluar[] = $rk;
    }
}

// Format bulan Indonesia untuk kartu KPI
$nama_bulan_ini = tanggal_indo(date('Y-m-01'));
$bulan_parts = explode(' ', $nama_bulan_ini);
$label_bulan_tahun = ($bulan_parts[1] ?? date('F')) . ' ' . ($bulan_parts[2] ?? date('Y'));

require_once __DIR__ . '/../../header.php';
require_once __DIR__ . '/../../sidebar.php';
?>

<!-- Main Content Area Bahan Keluar (100% Persis Desain Google Stitch AI) -->
<main class="relative pt-16 bg-surface min-h-screen w-full px-space-lg py-space-lg">
  <div class="flex flex-col w-full gap-space-lg">

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

    <!-- Subheader Title Banner (Persis Desain Stitch) -->
    <div class="relative flex flex-col items-center justify-center text-center bg-surface-container-lowest p-space-lg rounded-xl shadow-sm">
      <div class="flex flex-col items-center justify-center">
        <h1 class="font-headline-lg text-[32px] font-bold text-primary-container tracking-tight">
          Bahan Keluar
        </h1>
        <p class="font-body-md text-body-md text-secondary mt-1 max-w-2xl font-medium">
          Kelola transaksi bahan baku keluar untuk produksi Cooler City
        </p>
      </div>

      <!-- Action Button / Read-only Indicator di Sisi Kanan -->
      <div class="mt-3 md:mt-0 md:absolute md:right-6 md:top-1/2 md:-translate-y-1/2">
        <?php if ($user_level === 'gudang'): ?>
        <button class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-primary-container text-on-primary font-label-md text-label-md font-semibold shadow-sm hover:opacity-90 transition-all cursor-pointer active:scale-95" id="btn-open-modal" type="button">
          <span class="material-symbols-outlined text-[18px]">add</span>
          <span>Input Bahan Keluar</span>
        </button>
        <?php else: ?>
        <div class="flex items-center gap-space-xs px-3 py-1.5 rounded-xl bg-surface-container-low text-on-surface-variant text-label-md font-medium border border-outline-variant/30">
          <span class="material-symbols-outlined text-[16px] text-outline">visibility</span>
          <span>Mode Read-Only (Owner)</span>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- KPI Summary Bento Cards Grid (Persis Desain Stitch) -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-space-lg">
      
      <!-- Card 1: Pink Pastel Solid Card (bg-secondary-container) -->
      <div class="relative overflow-hidden rounded-2xl p-space-lg bg-secondary-container shadow-sm flex flex-col justify-between min-h-[170px]">
        <div class="flex items-start justify-between relative z-10">
          <div>
            <h2 class="font-headline-sm text-headline-sm font-bold text-on-secondary-container">
              Penggunaan Hari Ini
            </h2>
            <p class="font-body-sm text-body-sm text-on-secondary-container/80 mt-0.5">
              Akumulasi nilai pemakaian produksi
            </p>
          </div>
          <div class="w-10 h-10 rounded-xl bg-white/40 text-on-secondary-container flex items-center justify-center shadow-sm">
            <span class="material-symbols-outlined text-[22px]">output</span>
          </div>
        </div>
        
        <div class="flex items-baseline justify-between pt-space-md relative z-10">
          <div>
            <div class="font-metric-display text-metric-display font-bold text-on-secondary-container tracking-tight leading-none">
              <?= number_format($total_item_keluar_hari_ini) ?> Item
            </div>
            <div class="font-body-sm text-body-sm text-on-secondary-container/80 mt-1">
              <?= $total_sesi_hari_ini ?> sesi transaksi hari ini
            </div>
          </div>
          <span class="inline-flex items-center px-2.5 py-1 rounded-full bg-secondary text-on-secondary font-label-sm text-label-sm font-semibold tracking-wide shadow-sm uppercase">
            TERVERIFIKASI
          </span>
        </div>
      </div>

      <!-- Card 2: Bright Cyan Solid Card (bg-primary-container) -->
      <div class="relative overflow-hidden rounded-2xl p-space-lg bg-primary-container shadow-sm flex flex-col justify-between min-h-[170px]">
        <div class="flex items-start justify-between relative z-10">
          <div>
            <h2 class="font-headline-sm text-headline-sm font-bold text-on-primary">
              Transaksi Bulan Ini
            </h2>
            <p class="font-body-sm text-body-sm text-on-primary/80 mt-0.5">
              Periode <?= htmlspecialchars($label_bulan_tahun) ?> (Aktif)
            </p>
          </div>
          <div class="w-10 h-10 rounded-xl bg-white/20 text-on-primary flex items-center justify-center shadow-sm">
            <span class="material-symbols-outlined text-[22px]">description</span>
          </div>
        </div>
        
        <div class="flex items-baseline justify-between pt-space-md relative z-10">
          <div>
            <div class="font-metric-display text-metric-display font-bold text-on-primary tracking-tight leading-none">
              <?= number_format($total_transaksi_bulan_ini) ?> Transaksi
            </div>
            <div class="font-body-sm text-body-sm text-on-primary/80 mt-1">
              Jumlah total transaksi keluar shift ini
            </div>
          </div>
          <span class="inline-flex items-center px-2.5 py-1 rounded-full bg-white text-primary font-label-sm text-label-sm font-bold tracking-wide shadow-sm uppercase">
            100% TERDATA
          </span>
        </div>
      </div>

    </div>

    <!-- Main Table Container Panel (Persis Desain Stitch) -->
    <div class="flex flex-col bg-surface-container-lowest rounded-xl shadow-sm overflow-hidden">
      
      <!-- Toolbar Section: Search & Filter Divisi -->
      <div class="p-space-md flex flex-col lg:flex-row lg:items-center justify-between gap-space-md bg-surface-container-low">
        <div class="flex flex-1 items-center gap-space-sm">
          <div class="relative flex-1 max-w-md">
            <span class="material-symbols-outlined absolute left-3 top-2.5 text-on-surface-variant text-[18px]">search</span>
            <input class="w-full pl-9 pr-space-md py-2 rounded-lg bg-surface-container-lowest font-body-sm text-body-sm text-on-surface placeholder:text-outline focus:outline-none shadow-sm" 
                   id="search-input" placeholder="Cari transaksi, kode, stasiun..." type="text" autocomplete="off">
          </div>
        </div>

        <div class="flex flex-wrap items-center gap-space-sm">
          <!-- Dropdown Filter Divisi / Stasiun -->
          <div class="flex items-center gap-1.5 bg-surface-container-lowest px-3 py-1.5 rounded-lg shadow-sm">
            <span class="material-symbols-outlined text-[16px] text-outline">tune</span>
            <span class="font-label-md text-label-md text-on-surface-variant">Divisi:</span>
            <select class="bg-transparent font-label-md text-label-md text-on-surface focus:outline-none pr-1 cursor-pointer" id="filter-divisi">
              <option value="all">Semua Stasiun</option>
              <option value="Produksi Soft Serve">Produksi Soft Serve</option>
              <option value="Bar Minuman & Topping">Bar Minuman &amp; Topping</option>
              <option value="Kasir & Bar Packaging">Kasir &amp; Bar Packaging</option>
              <option value="Dapur Persiapan">Dapur Persiapan</option>
            </select>
          </div>
        </div>
      </div>

      <!-- Responsive Table Section -->
      <div class="overflow-x-auto">
        <table class="w-full text-left font-body-md text-body-md" id="keluarTable">
          <thead>
            <tr class="bg-surface-container font-label-md text-label-md text-outline uppercase tracking-wider">
              <th class="py-3 px-space-md">Tanggal</th>
              <th class="py-3 px-space-md">Kode</th>
              <th class="py-3 px-space-md">Nama Bahan</th>
              <th class="py-3 px-space-md">Tujuan / Divisi</th>
              <th class="py-3 px-space-md text-right">Jumlah</th>
              <th class="py-3 px-space-md">Keterangan</th>
              <th class="py-3 px-space-md text-center">Aksi</th>
            </tr>
          </thead>
          <tbody class="text-on-surface" id="transaction-rows">

            <?php if (empty($transaksi_keluar)): ?>
            <!-- EMPTY STATE: KONDISI BELUM ADA TRANSAKSI BAHAN KELUAR -->
            <tr id="emptyKeluarRow">
              <td colspan="7" class="py-16 px-6 text-center">
                <div class="max-w-md mx-auto flex flex-col items-center justify-center">
                  <div class="w-16 h-16 rounded-2xl bg-secondary-fixed/50 text-secondary flex items-center justify-center mb-4 shadow-inner">
                    <span class="material-symbols-outlined text-[36px]">output</span>
                  </div>
                  <h3 class="font-headline-md text-headline-md text-on-surface font-semibold mb-1">
                    Belum Ada Transaksi Bahan Keluar
                  </h3>
                  <p class="font-body-md text-body-md text-on-surface-variant mb-6 text-center leading-relaxed">
                    <?php if ($user_level === 'gudang'): ?>
                      <?php if (empty($master_barang)): ?>
                        Master bahan baku saat ini masih kosong. Silakan tambahkan data bahan baku terlebih dahulu melalui menu <strong>Data Bahan Baku</strong>.
                      <?php else: ?>
                        Belum ada riwayat transaksi pengeluaran bahan baku untuk operasional produksi. Silakan klik tombol di bawah untuk mencatat transaksi bahan keluar pertama.
                      <?php endif; ?>
                    <?php else: ?>
                      Belum ada data transaksi bahan keluar yang dicatat oleh staf gudang ke dalam sistem.
                    <?php endif; ?>
                  </p>

                  <?php if ($user_level === 'gudang'): ?>
                    <?php if (empty($master_barang)): ?>
                    <a href="<?= base_url('views/barang/index.php') ?>" class="inline-flex items-center gap-2 px-space-lg py-2.5 rounded-xl bg-secondary hover:bg-secondary/90 text-on-secondary font-label-lg text-label-lg shadow-md transition-all active:scale-95">
                      <span class="material-symbols-outlined text-[20px]">inventory_2</span>
                      <span>Buka Data Bahan Baku</span>
                    </a>
                    <?php else: ?>
                    <button type="button" onclick="openModal()" class="inline-flex items-center gap-2 px-space-lg py-2.5 rounded-xl bg-primary-container hover:bg-primary text-on-primary font-label-lg text-label-lg shadow-md transition-all active:scale-95 cursor-pointer">
                      <span class="material-symbols-outlined text-[20px]">add</span>
                      <span>Input Bahan Keluar Pertama</span>
                    </button>
                    <?php endif; ?>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
            <?php else: ?>

              <?php 
              // Palet dot untuk divisi / stasiun
              $divisi_dot_colors = [
                'produksi soft serve'   => 'bg-secondary',
                'bar minuman & topping' => 'bg-tertiary',
                'kasir & bar packaging' => 'bg-primary',
                'dapur persiapan'       => 'bg-primary-container'
              ];

              foreach ($transaksi_keluar as $row): 
                $divisi_key = strtolower(trim($row['tujuan']));
                $dot_color  = $divisi_dot_colors[$divisi_key] ?? 'bg-secondary';
                $satuan_display = !empty($row['satuan']) ? htmlspecialchars($row['satuan']) : 'Unit';
              ?>
              <!-- Baris Transaksi Bahan Keluar -->
              <tr class="hover:bg-surface-container-low transition-colors item-keluar-row" 
                  data-divisi="<?= htmlspecialchars($row['tujuan']) ?>"
                  data-search="<?= htmlspecialchars(strtolower($row['tanggal_keluar'] . ' ' . $row['kode_barang'] . ' ' . $row['nama_barang'] . ' ' . $row['tujuan'] . ' ' . $row['keterangan'])) ?>">
                
                <!-- Tanggal -->
                <td class="py-3.5 px-space-md font-body-sm text-body-sm text-on-surface-variant whitespace-nowrap">
                  <?= htmlspecialchars($row['tanggal_keluar']) ?>
                </td>

                <!-- Kode -->
                <td class="py-3.5 px-space-md font-label-md text-label-md text-primary font-semibold">
                  <span class="px-2 py-0.5 rounded-lg bg-surface-variant font-mono">
                    <?= htmlspecialchars($row['kode_barang']) ?>
                  </span>
                </td>

                <!-- Nama Bahan -->
                <td class="py-3.5 px-space-md font-headline-sm text-headline-sm">
                  <?= htmlspecialchars($row['nama_barang']) ?>
                </td>

                <!-- Tujuan / Divisi -->
                <td class="py-3.5 px-space-md">
                  <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-surface-container-high text-on-surface font-label-sm text-label-sm">
                    <span class="w-1.5 h-1.5 rounded-full <?= $dot_color ?>"></span>
                    <span><?= htmlspecialchars($row['tujuan']) ?></span>
                  </span>
                </td>

                <!-- Jumlah -->
                <td class="py-3.5 px-space-md text-right font-headline-sm text-headline-sm text-on-surface">
                  <?= number_format($row['jumlah']) ?> <?= $satuan_display ?>
                </td>

                <!-- Keterangan -->
                <td class="py-3.5 px-space-md font-body-sm text-body-sm text-on-surface-variant max-w-[200px] truncate" title="<?= htmlspecialchars($row['keterangan'] ?? '-') ?>">
                  <?= htmlspecialchars(!empty($row['keterangan']) ? $row['keterangan'] : '-') ?>
                </td>

                <!-- Aksi Detail -->
                <td class="py-3.5 px-space-md text-center">
                  <button type="button" 
                          class="view-detail-btn p-1.5 rounded-lg text-on-surface-variant hover:text-primary hover:bg-surface-variant transition-colors cursor-pointer"
                          data-id="TRX-OUT-<?= str_pad($row['id_keluar'], 5, '0', STR_PAD_LEFT) ?>"
                          data-tanggal="<?= htmlspecialchars($row['tanggal_keluar']) ?>"
                          data-kode="<?= htmlspecialchars($row['kode_barang']) ?>"
                          data-nama="<?= htmlspecialchars($row['nama_barang']) ?>"
                          data-tujuan="<?= htmlspecialchars($row['tujuan']) ?>"
                          data-jumlah="<?= number_format($row['jumlah']) ?> <?= $satuan_display ?>"
                          data-keterangan="<?= htmlspecialchars($row['keterangan'] ?? '-') ?>"
                          data-petugas="<?= htmlspecialchars(!empty($row['nama_user']) ? $row['nama_user'] : 'Petugas Gudang') ?>"
                          title="Lihat Rincian Pengeluaran">
                    <span class="material-symbols-outlined text-[18px]">visibility</span>
                  </button>
                </td>

              </tr>
              <?php endforeach; ?>

            <?php endif; ?>

            <!-- Baris Nihil Pencarian -->
            <tr id="noSearchRow" class="hidden">
              <td colspan="7" class="py-12 px-6 text-center">
                <div class="max-w-md mx-auto flex flex-col items-center justify-center">
                  <div class="w-14 h-14 rounded-2xl bg-surface-container text-outline flex items-center justify-center mb-3">
                    <span class="material-symbols-outlined text-[32px]">search_off</span>
                  </div>
                  <h4 class="font-headline-sm text-headline-sm text-on-surface font-semibold mb-1">
                    Data Transaksi Tidak Ditemukan
                  </h4>
                  <p class="font-body-sm text-body-sm text-on-surface-variant mb-4">
                    Tidak ada transaksi bahan keluar yang cocok dengan kata kunci pencarian atau filter divisi yang dipilih.
                  </p>
                  <button type="button" id="resetSearchBtn" class="px-space-md py-1.5 rounded-lg bg-surface-container-low hover:bg-surface-container text-on-surface font-label-md text-label-md transition-colors cursor-pointer">
                    Reset Filter
                  </button>
                </div>
              </td>
            </tr>

          </tbody>
        </table>
      </div>

      <!-- Pagination & Summary Stats Bar -->
      <div class="px-space-md py-space-sm bg-surface-container-low flex flex-col sm:flex-row items-center justify-between gap-space-sm border-t border-surface-container/60">
        <span class="font-body-sm text-body-sm text-on-surface-variant" id="tableMetaText">
          Menampilkan <strong class="text-on-surface"><?= count($transaksi_keluar) ?></strong> dari <strong class="text-on-surface"><?= $total_transaksi ?></strong> data transaksi
        </span>
        
        <div class="flex items-center gap-1">
          <button class="px-2 py-1 rounded-lg bg-surface-container-lowest text-on-surface-variant opacity-50 cursor-not-allowed text-[12px] font-label-md flex items-center shadow-sm" disabled>
            <span class="material-symbols-outlined text-[16px]">chevron_left</span>
            <span>Sebelumnya</span>
          </button>
          <button class="w-7 h-7 rounded-lg bg-primary-container text-on-primary font-label-md text-label-md flex items-center justify-center shadow-sm">
            1
          </button>
          <button class="px-2 py-1 rounded-lg bg-surface-container-lowest text-on-surface-variant opacity-50 cursor-not-allowed text-[12px] font-label-md flex items-center shadow-sm" disabled>
            <span>Berikutnya</span>
            <span class="material-symbols-outlined text-[16px]">chevron_right</span>
          </button>
        </div>
      </div>

    </div>

  </div>
</main>

<!-- ============================================================== -->
<!-- MODAL POPUP: INPUT BAHAN KELUAR (Sesuai Desain Google Stitch) -->
<!-- ============================================================== -->
<?php if ($user_level === 'gudang'): ?>
<div class="fixed inset-0 bg-inverse-surface/40 backdrop-blur-sm z-50 hidden items-center justify-center p-4 transition-all" id="modal-input">
  <!-- Modal Card -->
  <div class="bg-white rounded-2xl w-full max-w-2xl p-6 shadow-2xl relative flex flex-col max-h-[90vh] overflow-y-auto">
    
    <!-- Close Button Top Right -->
    <button type="button" aria-label="Tutup" class="absolute top-5 right-5 text-outline hover:text-on-surface transition-colors cursor-pointer" id="btn-close-modal">
      <span class="material-symbols-outlined text-[20px]">close</span>
    </button>

    <!-- Modal Header -->
    <div class="mb-5">
      <div class="flex items-center gap-space-xs mb-1">
        <div class="w-7 h-7 rounded-full bg-primary-container text-on-primary flex items-center justify-center">
          <span class="material-symbols-outlined text-[16px]">north_east</span>
        </div>
        <h2 class="font-headline-sm text-[20px] font-bold text-on-surface leading-snug">
          Input Bahan Keluar
        </h2>
      </div>
      <p class="font-body-sm text-body-sm text-on-surface-variant">
        Masukkan data pengeluaran bahan baku untuk operasional / produksi
      </p>
    </div>

    <!-- Cek Jika Master Bahan Baku Kosong -->
    <?php if (empty($master_barang)): ?>
    <div class="p-4 rounded-xl bg-amber-50 border border-amber-200 text-amber-800 text-xs mt-2">
      <div class="flex items-center gap-2 font-bold mb-1">
        <span class="material-symbols-outlined text-[18px] text-amber-600">warning</span>
        <span>Data Master Bahan Baku Kosong</span>
      </div>
      <p class="mb-3">Belum ada bahan baku yang terdaftar di sistem untuk dikeluarkan.</p>
      <a href="<?= base_url('views/barang/index.php') ?>" class="inline-block px-3 py-1.5 rounded-lg bg-amber-600 hover:bg-amber-700 text-white font-semibold transition-colors">
        Tambah Bahan Baku &rarr;
      </a>
    </div>
    <?php else: ?>

    <!-- Formulir Input Bahan Keluar -->
    <form class="flex flex-col gap-3.5" id="form-bahan-keluar" method="POST" action="<?= base_url('views/barangkeluar/index.php') ?>">
      <input type="hidden" name="action" value="tambah_keluar">

      <!-- Field: Tanggal -->
      <div class="flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-space-md">
        <label class="font-label-md text-label-md text-on-surface font-semibold sm:w-32">
          Tanggal *
        </label>
        <div class="relative flex-1 flex items-center">
          <input class="w-full px-3.5 py-2.5 rounded-xl bg-surface-container-low text-on-surface font-body-sm text-body-sm focus:outline-none focus:ring-2 focus:ring-primary-container/30 cursor-pointer" 
                 name="tanggal_keluar" id="inputTanggal" required type="date" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>">
        </div>
      </div>

      <!-- Field: Bahan Baku -->
      <div class="flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-space-md">
        <label class="font-label-md text-label-md text-on-surface font-semibold sm:w-32">
          Bahan Baku *
        </label>
        <div class="relative flex-1 flex items-center">
          <select class="w-full px-3.5 py-2.5 rounded-xl bg-surface-container-low text-on-surface font-body-sm text-body-sm appearance-none focus:outline-none focus:ring-2 focus:ring-primary-container/30 pr-9 cursor-pointer" 
                  name="id_barang" id="selectBahanBaku" required>
            <option value="" disabled selected>-- Pilih Bahan Baku --</option>
            <?php foreach ($master_barang as $b): 
              $is_habis = ((int)$b['stok'] <= 0);
            ?>
            <option value="<?= $b['id_barang'] ?>"
                    data-kode="<?= htmlspecialchars($b['kode_barang']) ?>"
                    data-nama="<?= htmlspecialchars($b['nama_barang']) ?>"
                    data-satuan="<?= htmlspecialchars($b['satuan']) ?>"
                    data-stok="<?= (int)$b['stok'] ?>"
                    <?= $is_habis ? 'disabled class="text-slate-400 bg-slate-100"' : '' ?>>
              <?= htmlspecialchars($b['nama_barang']) ?> (<?= htmlspecialchars($b['kode_barang']) ?>) — Sisa: <?= number_format($b['stok']) ?> <?= htmlspecialchars($b['satuan']) ?><?= $is_habis ? ' [HABIS]' : '' ?>
            </option>
            <?php endforeach; ?>
          </select>
          <span class="material-symbols-outlined absolute right-3.5 text-outline text-[20px] pointer-events-none">expand_more</span>
        </div>
      </div>

      <!-- Field: Stok Tersedia Indikator -->
      <div class="flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-space-md" id="rowStokTersedia">
        <label class="font-label-md text-label-md text-on-surface-variant font-medium sm:w-32">
          Stok Tersedia
        </label>
        <div class="flex-1">
          <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-xl bg-surface-container text-body-sm font-semibold" id="boxStokTersedia">
            <span class="w-2 h-2 rounded-full bg-outline" id="dotStokTersedia"></span>
            <span id="labelStokTersedia" class="text-on-surface">Pilih bahan baku di atas</span>
          </div>
        </div>
      </div>

      <!-- Field: Tujuan / Divisi -->
      <div class="flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-space-md">
        <label class="font-label-md text-label-md text-on-surface font-semibold sm:w-32">
          Tujuan *
        </label>
        <div class="relative flex-1 flex items-center">
          <select class="w-full px-3.5 py-2.5 rounded-xl bg-surface-container-low text-on-surface font-body-sm text-body-sm appearance-none focus:outline-none focus:ring-2 focus:ring-primary-container/30 pr-9 cursor-pointer" 
                  name="tujuan" id="selectTujuan" required>
            <option value="" disabled selected>-- Pilih Tujuan Pemakaian --</option>
            <option value="Produksi Soft Serve">Produksi Soft Serve</option>
            <option value="Bar Minuman & Topping">Bar Minuman &amp; Topping</option>
            <option value="Kasir & Bar Packaging">Kasir &amp; Bar Packaging</option>
            <option value="Dapur Persiapan">Dapur Persiapan</option>
          </select>
          <span class="material-symbols-outlined absolute right-3.5 text-outline text-[20px] pointer-events-none">expand_more</span>
        </div>
      </div>

      <!-- Field: Jumlah Keluar -->
      <div class="flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-space-md">
        <label class="font-label-md text-label-md text-on-surface font-semibold sm:w-32">
          Jumlah *
        </label>
        <div class="flex-1">
          <div class="flex items-center bg-surface-container-low rounded-xl overflow-hidden focus-within:ring-2 focus-within:ring-primary-container/30">
            <input class="w-full px-3.5 py-2.5 bg-transparent text-on-surface font-body-sm text-body-sm focus:outline-none placeholder:text-outline" 
                   name="jumlah" id="inputJumlah" min="1" placeholder="0" required type="number" value="1">
            <span class="pr-3 text-on-surface-variant font-label-sm text-label-sm font-semibold whitespace-nowrap" id="badgeSatuanKeluar">
              Satuan
            </span>
          </div>
          <!-- Pesan Error Validasi Real-time Stok -->
          <p id="stokErrorMessage" class="text-[11px] text-error font-medium mt-1 hidden"></p>
        </div>
      </div>

      <!-- Field: Keterangan / Shift -->
      <div class="flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-space-md">
        <label class="font-label-md text-label-md text-on-surface font-semibold sm:w-32">
          Keterangan
        </label>
        <div class="relative flex-1 flex items-center">
          <input class="w-full px-3.5 py-2.5 rounded-xl bg-surface-container-low text-on-surface font-body-sm text-body-sm focus:outline-none focus:ring-2 focus:ring-primary-container/30 placeholder:text-outline" 
                 name="keterangan" id="inputKeterangan" list="presetKeteranganKeluar" placeholder="Contoh: Pemakaian Shift Pagi / Pengisian Mesin 1" type="text" autocomplete="off">
          <datalist id="presetKeteranganKeluar">
            <option value="Pemakaian Shift Pagi">
            <option value="Pemakaian Shift Siang">
            <option value="Pemakaian Shift Malam">
            <option value="Batch Soft Serve Ice Cream">
            <option value="Restok Bar Depan">
            <option value="Kemasan Takeaway Pelanggan">
          </datalist>
        </div>
      </div>

      <!-- Modal Action Buttons (Persis Desain Stitch) -->
      <div class="pt-4 flex items-center justify-end gap-2 border-t border-surface-container/50 mt-2">
        <button type="button" class="px-5 py-2.5 rounded-xl bg-surface-container-high text-on-surface font-label-md text-label-md font-semibold hover:opacity-90 transition-all cursor-pointer" id="btn-cancel-modal">
          Batal
        </button>
        <button type="submit" class="px-8 py-2.5 rounded-xl bg-primary-container text-on-primary font-label-md text-label-md font-semibold shadow-sm hover:opacity-90 transition-all flex items-center gap-1 cursor-pointer active:scale-95" id="btnSubmitKeluar">
          <span class="material-symbols-outlined text-[18px]">check</span>
          <span>Simpan Log Pengeluaran</span>
        </button>
      </div>
    </form>
    <?php endif; ?>

  </div>
</div>
<?php endif; ?>

<!-- ============================================================== -->
<!-- MODAL POPUP: RINCIAN DETAIL TRANSAKSI BAHAN KELUAR -->
<!-- ============================================================== -->
<div class="fixed inset-0 z-50 flex items-center justify-center hidden" id="detailModal">
  <!-- Backdrop scrim -->
  <div class="absolute inset-0 bg-inverse-surface/40 backdrop-blur-sm transition-opacity" id="detailModalBackdrop"></div>
  
  <!-- Modal Dialog Card -->
  <div class="relative w-full max-w-lg bg-surface-container-lowest rounded-2xl shadow-2xl p-space-xl z-10 mx-4 transition-all">
    <div class="flex items-start justify-between pb-space-md border-b border-surface-container/50">
      <div>
        <div class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-secondary-fixed text-on-secondary-fixed font-mono text-[11px] font-bold mb-1" id="detailTrxId">
          TRX-OUT-00001
        </div>
        <h3 class="font-headline-md text-headline-md text-on-surface font-bold">Rincian Bahan Keluar</h3>
      </div>
      <button type="button" class="w-8 h-8 rounded-full bg-surface-container-low hover:bg-surface-container text-on-surface flex items-center justify-center transition-colors cursor-pointer" id="closeDetailModalBtn">
        <span class="material-symbols-outlined text-[20px]">close</span>
      </button>
    </div>

    <div class="flex flex-col gap-3.5 mt-space-md text-body-sm text-on-surface">
      <div class="grid grid-cols-2 gap-3 p-3 bg-surface-container-low rounded-xl">
        <div>
          <span class="text-on-surface-variant font-label-sm uppercase tracking-wider block mb-0.5">Tanggal Keluar</span>
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
          <span class="text-on-surface-variant font-label-sm uppercase tracking-wider block mb-0.5">Tujuan / Divisi</span>
          <span class="font-semibold text-on-surface" id="detailTujuan">-</span>
        </div>
        <div>
          <span class="text-on-surface-variant font-label-sm uppercase tracking-wider block mb-0.5">Jumlah Keluar</span>
          <span class="font-bold text-secondary text-base" id="detailJumlah">-</span>
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
<!-- CLIENT-SIDE SCRIPT: SEARCH, STOCK VALIDATION & MODAL CONTROLS -->
<!-- ============================================================== -->
<script>
document.addEventListener('DOMContentLoaded', function () {
  // 1. FILTER REAL-TIME PADA TABEL (Pencarian & Divisi)
  const searchInput = document.getElementById('search-input');
  const filterDivisi = document.getElementById('filter-divisi');
  const tableRows = document.querySelectorAll('.item-keluar-row');
  const noSearchRow = document.getElementById('noSearchRow');
  const resetBtn = document.getElementById('resetSearchBtn');
  const tableMetaText = document.getElementById('tableMetaText');

  function filterTable() {
    const query = searchInput ? searchInput.value.trim().toLowerCase() : '';
    const divisiVal = filterDivisi ? filterDivisi.value : 'all';
    let visible = 0;

    tableRows.forEach(row => {
      const searchData = (row.getAttribute('data-search') || '').toLowerCase();
      const rowDivisi = row.getAttribute('data-divisi') || '';

      const matchesSearch = (!query || searchData.includes(query));
      const matchesDivisi = (divisiVal === 'all' || rowDivisi === divisiVal);

      if (matchesSearch && matchesDivisi) {
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
      if (tableMetaText) {
        tableMetaText.innerHTML = `Menampilkan <strong class="text-on-surface">${visible}</strong> dari <strong class="text-on-surface">${tableRows.length}</strong> data transaksi`;
      }
    }
  }

  if (searchInput) searchInput.addEventListener('input', filterTable);
  if (filterDivisi) filterDivisi.addEventListener('change', filterTable);

  if (resetBtn) {
    resetBtn.addEventListener('click', function () {
      if (searchInput) searchInput.value = '';
      if (filterDivisi) filterDivisi.value = 'all';
      filterTable();
    });
  }

  // 2. MODAL INPUT TRANSAKSI & VALIDASI STOK KETAT
  const modalInput = document.getElementById('modal-input');
  const btnOpenModal = document.getElementById('btn-open-modal');
  const btnCloseModal = document.getElementById('btn-close-modal');
  const btnCancelModal = document.getElementById('btn-cancel-modal');

  const selectBahanBaku = document.getElementById('selectBahanBaku');
  const labelStokTersedia = document.getElementById('labelStokTersedia');
  const dotStokTersedia = document.getElementById('dotStokTersedia');
  const badgeSatuanKeluar = document.getElementById('badgeSatuanKeluar');
  const inputJumlah = document.getElementById('inputJumlah');
  const stokErrorMessage = document.getElementById('stokErrorMessage');
  const formKeluar = document.getElementById('form-bahan-keluar');
  const btnSubmitKeluar = document.getElementById('btnSubmitKeluar');

  let currentAvailableStock = 0;
  let currentUnit = 'Unit';

  function updateStockDisplay() {
    if (!selectBahanBaku) return;
    const selectedOption = selectBahanBaku.options[selectBahanBaku.selectedIndex];

    if (selectedOption && selectedOption.value) {
      currentAvailableStock = parseInt(selectedOption.getAttribute('data-stok') || '0', 10);
      currentUnit = selectedOption.getAttribute('data-satuan') || 'Unit';

      if (labelStokTersedia) {
        labelStokTersedia.textContent = `${currentAvailableStock.toLocaleString('id-ID')} ${currentUnit}`;
      }
      if (badgeSatuanKeluar) {
        badgeSatuanKeluar.textContent = currentUnit;
      }

      // Indikator warna stok
      if (dotStokTersedia) {
        dotStokTersedia.className = 'w-2 h-2 rounded-full ' + (currentAvailableStock > 10 ? 'bg-emerald-500' : (currentAvailableStock > 0 ? 'bg-amber-500' : 'bg-red-500'));
      }

      if (inputJumlah) {
        inputJumlah.max = currentAvailableStock;
      }
      validateStockInput();
    } else {
      currentAvailableStock = 0;
      if (labelStokTersedia) labelStokTersedia.textContent = 'Pilih bahan baku di atas';
      if (dotStokTersedia) dotStokTersedia.className = 'w-2 h-2 rounded-full bg-outline';
    }
  }

  function validateStockInput() {
    if (!inputJumlah) return true;
    const requestedQty = parseInt(inputJumlah.value || '0', 10);

    if (requestedQty <= 0) {
      if (stokErrorMessage) {
        stokErrorMessage.textContent = 'Jumlah keluar harus lebih besar dari 0.';
        stokErrorMessage.classList.remove('hidden');
      }
      if (btnSubmitKeluar) btnSubmitKeluar.disabled = true;
      return false;
    }

    if (requestedQty > currentAvailableStock) {
      if (stokErrorMessage) {
        stokErrorMessage.textContent = `Stok tidak mencukupi! Maksimal yang dapat dikeluarkan: ${currentAvailableStock} ${currentUnit}.`;
        stokErrorMessage.classList.remove('hidden');
      }
      if (btnSubmitKeluar) btnSubmitKeluar.disabled = true;
      return false;
    }

    // Input valid
    if (stokErrorMessage) {
      stokErrorMessage.textContent = '';
      stokErrorMessage.classList.add('hidden');
    }
    if (btnSubmitKeluar) btnSubmitKeluar.disabled = false;
    return true;
  }

  if (selectBahanBaku) {
    selectBahanBaku.addEventListener('change', updateStockDisplay);
  }
  if (inputJumlah) {
    inputJumlah.addEventListener('input', validateStockInput);
  }

  // Pencegahan double-submit & validasi final sebelum submit
  if (formKeluar) {
    formKeluar.addEventListener('submit', function (e) {
      if (!validateStockInput()) {
        e.preventDefault();
        return false;
      }
      if (btnSubmitKeluar) {
        btnSubmitKeluar.disabled = true;
        btnSubmitKeluar.innerHTML = '<span class="material-symbols-outlined text-[18px] animate-spin">refresh</span><span>Menyimpan...</span>';
      }
    });
  }

  window.openModal = function () {
    if (modalInput) {
      modalInput.classList.remove('hidden');
      modalInput.classList.add('flex');
      document.body.style.overflow = 'hidden';
      updateStockDisplay();
    }
  };

  function closeModal() {
    if (modalInput) {
      modalInput.classList.add('hidden');
      modalInput.classList.remove('flex');
      document.body.style.overflow = '';
    }
  }

  if (btnOpenModal) btnOpenModal.addEventListener('click', window.openModal);
  if (btnCloseModal) btnCloseModal.addEventListener('click', closeModal);
  if (btnCancelModal) btnCancelModal.addEventListener('click', closeModal);

  // 3. MODAL DETAIL TRANSAKSI
  const detailModal = document.getElementById('detailModal');
  const closeDetailModalBtn = document.getElementById('closeDetailModalBtn');
  const dismissDetailModalBtn = document.getElementById('dismissDetailModalBtn');
  const detailModalBackdrop = document.getElementById('detailModalBackdrop');

  const detailTrxId = document.getElementById('detailTrxId');
  const detailTanggal = document.getElementById('detailTanggal');
  const detailPetugas = document.getElementById('detailPetugas');
  const detailKode = document.getElementById('detailKode');
  const detailNama = document.getElementById('detailNama');
  const detailTujuan = document.getElementById('detailTujuan');
  const detailJumlah = document.getElementById('detailJumlah');
  const detailKeterangan = document.getElementById('detailKeterangan');

  const viewDetailButtons = document.querySelectorAll('.view-detail-btn');

  viewDetailButtons.forEach(btn => {
    btn.addEventListener('click', function () {
      if (detailTrxId) detailTrxId.textContent = this.getAttribute('data-id') || '-';
      if (detailTanggal) detailTanggal.textContent = this.getAttribute('data-tanggal') || '-';
      if (detailPetugas) detailPetugas.textContent = this.getAttribute('data-petugas') || 'Staf Gudang';
      if (detailKode) detailKode.textContent = this.getAttribute('data-kode') || '-';
      if (detailNama) detailNama.textContent = this.getAttribute('data-nama') || '-';
      if (detailTujuan) detailTujuan.textContent = this.getAttribute('data-tujuan') || '-';
      if (detailJumlah) detailJumlah.textContent = this.getAttribute('data-jumlah') || '-';
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

  // Keyboard listener: ESC untuk menutup modal yang sedang terbuka
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      closeModal();
      closeDetailModal();
    }
  });
});
</script>

<?php
require_once __DIR__ . '/../../footer.php';
?>

