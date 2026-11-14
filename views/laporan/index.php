<?php
/**
 * Halaman Laporan & Analisis Persediaan - Sistem Inventory Cooler City
 * Desain 100% Mengacu pada Google Stitch AI
 * Terintegrasi Penuh dengan MySQL (tb_barang, tb_barangmasuk, tb_barangkeluar)
 * Mendukung Tabbed View (Ringkasan, Barang Terlaris, Stok Kritis),
 * Filter Periode Fleksibel (Bulan Ini, Bulan Lalu, Kuartal, YTD, Custom Range),
 * Ekspor Multi-Format: Excel (CSV UTF-8 Spreadsheet) & PDF (Print-Ready Layout Resmi),
 * Empty State Penanganan Lengkap & RBAC untuk Staf Gudang dan Owner
 */

require_once __DIR__ . '/../../config/koneksi.php';
check_login();

$user_level = $_SESSION['level'] ?? 'owner';
$user_name  = $_SESSION['nama_user'] ?? 'User';
$user_id    = (int)($_SESSION['user_id'] ?? 0);
$page_title = 'LAPORAN';
$current_page = 'laporan';

// ==============================================================
// 1. FILTER PERIODE WAKTU LAPORAN
// ==============================================================
$periode   = trim($_GET['periode'] ?? 'current');
$tgl_mulai = trim($_GET['tgl_mulai'] ?? '');
$tgl_akhir = trim($_GET['tgl_akhir'] ?? '');

$current_year  = date('Y');
$current_month = (int)date('n');

$nama_bulan = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
    5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
    9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];

$nama_bulan_singkat = [
    1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr',
    5 => 'Mei', 6 => 'Jun', 7 => 'Jul', 8 => 'Agu',
    9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des'
];

switch ($periode) {
    case 'last_month':
        $last_month_ts = strtotime('first day of last month');
        $tgl_mulai = date('Y-m-01', $last_month_ts);
        $tgl_akhir = date('Y-m-t', $last_month_ts);
        $lm_num = (int)date('n', $last_month_ts);
        $lm_yr  = date('Y', $last_month_ts);
        $label_periode = 'Bulan Lalu (' . $nama_bulan[$lm_num] . ' ' . $lm_yr . ')';
        $label_rentang = '01 ' . $nama_bulan_singkat[$lm_num] . ' - ' . date('t', $last_month_ts) . ' ' . $nama_bulan_singkat[$lm_num] . ' ' . $lm_yr;
        break;

    case 'q3':
        // Kuartal 3 (Jul - Sep)
        $tgl_mulai = $current_year . '-07-01';
        $tgl_akhir = $current_year . '-09-30';
        $label_periode = 'Kuartal 3 (Jul - Sep ' . $current_year . ')';
        $label_rentang = '01 Jul - 30 Sep ' . $current_year;
        break;

    case 'ytd':
        // Tahun Berjalan (YTD)
        $tgl_mulai = $current_year . '-01-01';
        $tgl_akhir = date('Y-m-d');
        $label_periode = 'Tahun Berjalan (YTD ' . $current_year . ')';
        $label_rentang = '01 Jan - ' . date('d ') . $nama_bulan_singkat[$current_month] . ' ' . $current_year;
        break;

    case 'custom':
        if (empty($tgl_mulai)) $tgl_mulai = date('Y-m-01');
        if (empty($tgl_akhir)) $tgl_akhir = date('Y-m-d');
        if ($tgl_mulai > $tgl_akhir) {
            $tmp = $tgl_mulai;
            $tgl_mulai = $tgl_akhir;
            $tgl_akhir = $tmp;
        }
        $label_periode = 'Kustom Tanggal';
        $label_rentang = tanggal_indo($tgl_mulai) . ' - ' . tanggal_indo($tgl_akhir);
        break;

    case 'current':
    default:
        $periode = 'current';
        $tgl_mulai = date('Y-m-01');
        $tgl_akhir = date('Y-m-t');
        $label_periode = 'Bulan Ini (' . $nama_bulan[$current_month] . ' ' . $current_year . ')';
        $label_rentang = '01 ' . $nama_bulan_singkat[$current_month] . ' - ' . date('t') . ' ' . $nama_bulan_singkat[$current_month] . ' ' . $current_year;
        break;
}

// ==============================================================
// 2. QUERY AGREGASI DATA AKTUAL DARI MYSQL SESUAI PERIODE
// ==============================================================

// A. KPI 1 & Nilai Pembelian: Data dari tb_barangmasuk
$stmt_masuk = $koneksi->prepare("
    SELECT 
        COUNT(*) AS total_transaksi,
        COALESCE(SUM(jumlah), 0) AS total_unit,
        COALESCE(SUM(total), 0) AS total_nilai
    FROM tb_barangmasuk
    WHERE tanggal_masuk BETWEEN ? AND ?
");
$stmt_masuk->bind_param("ss", $tgl_mulai, $tgl_akhir);
$stmt_masuk->execute();
$kpi_masuk = $stmt_masuk->get_result()->fetch_assoc();
$stmt_masuk->close();

$total_masuk_transaksi = (int)($kpi_masuk['total_transaksi'] ?? 0);
$total_masuk_unit      = (int)($kpi_masuk['total_unit'] ?? 0);
$total_nilai_pembelian = (float)($kpi_masuk['total_nilai'] ?? 0);

// B. KPI 2: Data dari tb_barangkeluar
$stmt_keluar = $koneksi->prepare("
    SELECT 
        COUNT(*) AS total_transaksi,
        COALESCE(SUM(jumlah), 0) AS total_unit
    FROM tb_barangkeluar
    WHERE tanggal_keluar BETWEEN ? AND ?
");
$stmt_keluar->bind_param("ss", $tgl_mulai, $tgl_akhir);
$stmt_keluar->execute();
$kpi_keluar = $stmt_keluar->get_result()->fetch_assoc();
$stmt_keluar->close();

$total_keluar_transaksi = (int)($kpi_keluar['total_transaksi'] ?? 0);
$total_keluar_unit      = (int)($kpi_keluar['total_unit'] ?? 0);

// C. Tab 2: Top 10 Bahan Baku Terlaris / Pengeluaran Tertinggi
$stmt_top = $koneksi->prepare("
    SELECT 
        b.id_barang,
        b.kode_barang,
        b.nama_barang,
        b.kategori,
        b.satuan,
        COALESCE(SUM(k.jumlah), 0) AS total_keluar
    FROM tb_barangkeluar k
    JOIN tb_barang b ON k.id_barang = b.id_barang
    WHERE k.tanggal_keluar BETWEEN ? AND ?
    GROUP BY b.id_barang, b.kode_barang, b.nama_barang, b.kategori, b.satuan
    ORDER BY total_keluar DESC
    LIMIT 10
");
$stmt_top->bind_param("ss", $tgl_mulai, $tgl_akhir);
$stmt_top->execute();
$res_top = $stmt_top->get_result();
$top_barang_list = [];
$total_disposisi_unit = 0;
while ($row = $res_top->fetch_assoc()) {
    $top_barang_list[] = $row;
    $total_disposisi_unit += (int)$row['total_keluar'];
}
$stmt_top->close();

// D. Tab 3: Stok Kritis (Habis & Menipis berdasarkan tb_barang)
$res_kritis = $koneksi->query("
    SELECT 
        id_barang,
        kode_barang,
        nama_barang,
        kategori,
        stok,
        satuan,
        stok_minimum,
        status
    FROM tb_barang
    WHERE stok <= stok_minimum OR status IN ('Menipis', 'Habis')
    ORDER BY stok ASC, nama_barang ASC
");
$stok_kritis_list = [];
$count_habis   = 0;
$count_menipis = 0;
if ($res_kritis) {
    while ($row = $res_kritis->fetch_assoc()) {
        $computed_status = hitung_status_stok($row['stok'], $row['stok_minimum']);
        $row['status'] = $computed_status;
        if ($computed_status === 'Habis') {
            $count_habis++;
        } else {
            $count_menipis++;
        }
        $stok_kritis_list[] = $row;
    }
}
$total_kritis = count($stok_kritis_list);

// E. Laporan Bulanan Rekapitulasi (Performa Multi-Bulan untuk Tabel Ringkasan)
$monthly_summary = [];

// Rekap barang masuk per bulan
$res_m_in = $koneksi->query("
    SELECT 
        DATE_FORMAT(tanggal_masuk, '%Y-%m') AS ym,
        COUNT(*) AS total_trx,
        COALESCE(SUM(jumlah), 0) AS total_unit,
        COALESCE(SUM(total), 0) AS total_beli
    FROM tb_barangmasuk
    GROUP BY DATE_FORMAT(tanggal_masuk, '%Y-%m')
");
if ($res_m_in) {
    while ($row = $res_m_in->fetch_assoc()) {
        $ym = $row['ym'];
        $monthly_summary[$ym]['masuk_trx']  = (int)$row['total_trx'];
        $monthly_summary[$ym]['masuk_unit'] = (int)$row['total_unit'];
        $monthly_summary[$ym]['total_beli'] = (float)$row['total_beli'];
    }
}

// Rekap barang keluar per bulan
$res_m_out = $koneksi->query("
    SELECT 
        DATE_FORMAT(tanggal_keluar, '%Y-%m') AS ym,
        COUNT(*) AS total_trx,
        COALESCE(SUM(jumlah), 0) AS total_unit
    FROM tb_barangkeluar
    GROUP BY DATE_FORMAT(tanggal_keluar, '%Y-%m')
");
if ($res_m_out) {
    while ($row = $res_m_out->fetch_assoc()) {
        $ym = $row['ym'];
        $monthly_summary[$ym]['keluar_trx']  = (int)$row['total_trx'];
        $monthly_summary[$ym]['keluar_unit'] = (int)$row['total_unit'];
    }
}

// Urutkan daftar bulan secara descending
krsort($monthly_summary);

// ==============================================================
// 3. BACKEND EXPORT: EXCEL / CSV DOWNLOAD HANDLER
// ==============================================================
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $filename = "Laporan_Inventory_CoolerCity_" . date('Ymd_His') . ".csv";

    // Set header agar browser mengunduh sebagai file CSV kompatibel Excel
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    // Buka PHP output stream
    $output = fopen('php://output', 'w');

    // Tulis UTF-8 BOM agar Microsoft Excel membaca karakter dengan tepat tanpa encoding rusak
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    // Judul & Metadata Laporan
    fputcsv($output, ['SISTEM INVENTORY COOLER CITY - ICE CREAM & TEA']);
    fputcsv($output, ['OUTLET: Tanjungpinang']);
    fputcsv($output, ['LAPORAN: Persediaan & Aktivitas Transaksi Inventory']);
    fputcsv($output, ['Periode Filter:', $label_periode . ' (' . $label_rentang . ')']);
    fputcsv($output, ['Rentang Tanggal:', $tgl_mulai . ' s/d ' . $tgl_akhir]);
    fputcsv($output, ['Dicetak Oleh:', $user_name . ' (' . strtoupper($user_level) . ')']);
    fputcsv($output, ['Waktu Ekspor:', date('d-m-Y H:i:s')]);
    fputcsv($output, []); // Baris kosong

    // Bagian 1: Ringkasan Metrik KPI
    fputcsv($output, ['=== 1. RINGKASAN METRIK KPI ===']);
    fputcsv($output, ['Indikator', 'Nilai / Jumlah', 'Keterangan']);
    fputcsv($output, ['Total Barang Masuk (Unit)', $total_masuk_unit, $total_masuk_transaksi . ' transaksi pengiriman']);
    fputcsv($output, ['Total Barang Keluar (Unit)', $total_keluar_unit, $total_keluar_transaksi . ' sesi pemakaian operasional']);
    fputcsv($output, ['Total Nilai Pembelian (Rp)', $total_nilai_pembelian, 'Akumulasi biaya pengadaan bahan masuk']);
    fputcsv($output, ['Total Bahan Baku Kritis (SKU)', $total_kritis, $count_habis . ' Habis, ' . $count_menipis . ' Menipis']);
    fputcsv($output, []);

    // Bagian 2: Top 10 Bahan Baku Terlaris
    fputcsv($output, ['=== 2. TOP 10 BAHAN BAKU TERLARIS / PENGELUARAN TERTINGGI ===']);
    fputcsv($output, ['Ranking', 'Kode Barang', 'Nama Barang', 'Kategori', 'Total Keluar', 'Satuan']);
    if (empty($top_barang_list)) {
        fputcsv($output, ['-', 'Belum ada transaksi pengeluaran pada periode ini', '-', '-', 0, '-']);
    } else {
        $rank = 1;
        foreach ($top_barang_list as $top) {
            fputcsv($output, [
                '#' . $rank++,
                $top['kode_barang'],
                $top['nama_barang'],
                $top['kategori'],
                $top['total_keluar'],
                $top['satuan']
            ]);
        }
    }
    fputcsv($output, []);

    // Bagian 3: Bahan Baku Stok Kritis
    fputcsv($output, ['=== 3. DAFTAR BAHAN BAKU STOK KRITIS (RESTOK SEGERA) ===']);
    fputcsv($output, ['Kode Barang', 'Nama Barang', 'Kategori', 'Stok Saat Ini', 'Stok Minimal', 'Satuan', 'Status']);
    if (empty($stok_kritis_list)) {
        fputcsv($output, ['-', 'Semua stok dalam kondisi aman (tidak ada bahan baku berstatus kritis)', '-', 0, 0, '-', 'Aman']);
    } else {
        foreach ($stok_kritis_list as $kritis) {
            fputcsv($output, [
                $kritis['kode_barang'],
                $kritis['nama_barang'],
                $kritis['kategori'],
                $kritis['stok'],
                $kritis['stok_minimum'],
                $kritis['satuan'],
                $kritis['status']
            ]);
        }
    }
    fputcsv($output, []);

    // Bagian 4: Rincian Riwayat Transaksi Bahan Masuk Periode Ini
    fputcsv($output, ['=== 4. RINCIAN TRANSAKSI BAHAN MASUK PERIODE INI ===']);
    fputcsv($output, ['ID Masuk', 'Tanggal', 'Kode Barang', 'Nama Barang', 'Supplier', 'Jumlah', 'Harga Beli (Rp)', 'Total (Rp)', 'Keterangan']);
    
    $stmt_detail_in = $koneksi->prepare("
        SELECT id_masuk, tanggal_masuk, kode_barang, nama_barang, supplier, jumlah, harga_beli, total, keterangan
        FROM tb_barangmasuk
        WHERE tanggal_masuk BETWEEN ? AND ?
        ORDER BY tanggal_masuk DESC, id_masuk DESC
    ");
    $stmt_detail_in->bind_param("ss", $tgl_mulai, $tgl_akhir);
    $stmt_detail_in->execute();
    $res_detail_in = $stmt_detail_in->get_result();
    if ($res_detail_in->num_rows === 0) {
        fputcsv($output, ['-', '-', 'Belum ada transaksi masuk pada periode ini', '-', '-', 0, 0, 0, '-']);
    } else {
        while ($r = $res_detail_in->fetch_assoc()) {
            fputcsv($output, [
                'BM-' . str_pad($r['id_masuk'], 5, '0', STR_PAD_LEFT),
                $r['tanggal_masuk'],
                $r['kode_barang'],
                $r['nama_barang'],
                $r['supplier'],
                $r['jumlah'],
                $r['harga_beli'],
                $r['total'],
                $r['keterangan'] ?? '-'
            ]);
        }
    }
    $stmt_detail_in->close();
    fputcsv($output, []);

    // Bagian 5: Rincian Riwayat Transaksi Bahan Keluar Periode Ini
    fputcsv($output, ['=== 5. RINCIAN TRANSAKSI BAHAN KELUAR PERIODE INI ===']);
    fputcsv($output, ['ID Keluar', 'Tanggal', 'Kode Barang', 'Nama Barang', 'Tujuan', 'Jumlah', 'Keterangan']);
    
    $stmt_detail_out = $koneksi->prepare("
        SELECT id_keluar, tanggal_keluar, kode_barang, nama_barang, tujuan, jumlah, keterangan
        FROM tb_barangkeluar
        WHERE tanggal_keluar BETWEEN ? AND ?
        ORDER BY tanggal_keluar DESC, id_keluar DESC
    ");
    $stmt_detail_out->bind_param("ss", $tgl_mulai, $tgl_akhir);
    $stmt_detail_out->execute();
    $res_detail_out = $stmt_detail_out->get_result();
    if ($res_detail_out->num_rows === 0) {
        fputcsv($output, ['-', '-', 'Belum ada transaksi keluar pada periode ini', '-', '-', 0, '-']);
    } else {
        while ($r = $res_detail_out->fetch_assoc()) {
            fputcsv($output, [
                'BK-' . str_pad($r['id_keluar'], 5, '0', STR_PAD_LEFT),
                $r['tanggal_keluar'],
                $r['kode_barang'],
                $r['nama_barang'],
                $r['tujuan'],
                $r['jumlah'],
                $r['keterangan'] ?? '-'
            ]);
        }
    }
    $stmt_detail_out->close();

    fclose($output);
    exit;
}

// ==============================================================
// 4. BACKEND EXPORT: PDF PRINT-READY LAYOUT HANDLER
// ==============================================================
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    // Ambil detail transaksi masuk & keluar untuk tampilan cetak dokumen lengkap
    $stmt_pin = $koneksi->prepare("
        SELECT id_masuk, tanggal_masuk, kode_barang, nama_barang, supplier, jumlah, harga_beli, total
        FROM tb_barangmasuk
        WHERE tanggal_masuk BETWEEN ? AND ?
        ORDER BY tanggal_masuk DESC, id_masuk DESC
    ");
    $stmt_pin->bind_param("ss", $tgl_mulai, $tgl_akhir);
    $stmt_pin->execute();
    $list_pin = $stmt_pin->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_pin->close();

    $stmt_pout = $koneksi->prepare("
        SELECT id_keluar, tanggal_keluar, kode_barang, nama_barang, tujuan, jumlah, keterangan
        FROM tb_barangkeluar
        WHERE tanggal_keluar BETWEEN ? AND ?
        ORDER BY tanggal_keluar DESC, id_keluar DESC
    ");
    $stmt_pout->bind_param("ss", $tgl_mulai, $tgl_akhir);
    $stmt_pout->execute();
    $list_pout = $stmt_pout->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_pout->close();
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
      <title>Laporan Inventory Cooler City - <?= htmlspecialchars($label_rentang) ?></title>
      <script src="https://cdn.tailwindcss.com"></script>
      <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
      <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
      <style>
        body { font-family: 'Inter', sans-serif; }
        @media print {
          @page { size: A4; margin: 12mm 15mm 15mm 15mm; }
          .no-print { display: none !important; }
          body { background: #fff !important; color: #000 !important; font-size: 11pt; }
          .page-break { page-break-before: always; }
        }
      </style>
    </head>
    <body class="bg-slate-100 text-slate-800 antialiased p-4 md:p-8">

      <!-- Floating Print Control Toolbar (Disembunyikan saat dicetak) -->
      <div class="no-print max-w-4xl mx-auto mb-6 bg-white p-4 rounded-xl shadow-md border border-slate-200 flex flex-wrap items-center justify-between gap-4">
        <div class="flex items-center gap-2">
          <span class="material-symbols-outlined text-cyan-600 text-[24px]">picture_as_pdf</span>
          <div>
            <h2 class="font-bold text-slate-800 text-sm">Pratinjau Dokumen Cetak / Simpan PDF</h2>
            <p class="text-xs text-slate-500">Gunakan dialog cetak browser untuk menyimpan dokumen sebagai file PDF atau langsung mencetaknya.</p>
          </div>
        </div>
        <div class="flex items-center gap-2">
          <button onclick="window.print()" class="px-4 py-2 bg-[#00b4d8] hover:bg-[#0096b4] text-white font-semibold text-xs rounded-lg transition-colors flex items-center gap-1.5 shadow-sm">
            <span class="material-symbols-outlined text-[18px]">print</span>
            <span>Cetak / Simpan PDF</span>
          </button>
          <button onclick="window.close()" class="px-4 py-2 bg-slate-200 hover:bg-slate-300 text-slate-700 font-semibold text-xs rounded-lg transition-colors">
            Tutup
          </button>
        </div>
      </div>

      <!-- Lembar Dokumen Resmi Laporan -->
      <div class="max-w-4xl mx-auto bg-white p-8 md:p-12 rounded-xl shadow-sm border border-slate-200">
        
        <!-- Header Dokumen Surat Resmi -->
        <div class="flex items-start justify-between border-b-2 border-slate-800 pb-4 mb-6">
          <div class="flex items-center gap-4">
            <img src="<?= base_url('assets/img/cooler_city_logo.png') ?>" alt="Logo" class="h-16 w-auto object-contain">
            <div>
              <h1 class="text-2xl font-black text-[#0090d0] tracking-wide uppercase leading-none">COOLER CITY</h1>
              <p class="text-xs font-semibold text-red-500 tracking-wider">ICECREAM · TEA</p>
              <p class="text-xs text-slate-600 mt-1 font-medium">Outlet Tanjungpinang · Kepulauan Riau</p>
              <p class="text-[10px] text-slate-400">Sistem Inventory Real-Time Terpadu</p>
            </div>
          </div>
          <div class="text-right">
            <h2 class="text-base font-bold text-slate-800 uppercase tracking-tight">LAPORAN INVENTORY</h2>
            <p class="text-xs font-semibold text-cyan-700 mt-0.5"><?= htmlspecialchars($label_periode) ?></p>
            <p class="text-[11px] text-slate-500 mt-0.5">Rentang: <?= htmlspecialchars($label_rentang) ?></p>
            <p class="text-[10px] text-slate-400 mt-1">Dicetak: <?= date('d/m/Y H:i') ?> WIB</p>
          </div>
        </div>

        <!-- Metadata Ringkasan Petugas -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 bg-slate-50 p-3 rounded-lg border border-slate-200 mb-6 text-xs">
          <div>
            <span class="text-slate-400 block font-medium">Dicetak Oleh:</span>
            <span class="font-bold text-slate-800"><?= htmlspecialchars($user_name) ?></span>
          </div>
          <div>
            <span class="text-slate-400 block font-medium">Hak Akses:</span>
            <span class="font-bold text-slate-800 uppercase"><?= htmlspecialchars($user_level) ?></span>
          </div>
          <div>
            <span class="text-slate-400 block font-medium">Status Sinkronisasi:</span>
            <span class="font-bold text-emerald-600">Database Aktual MySQL</span>
          </div>
          <div>
            <span class="text-slate-400 block font-medium">Total Item Kritis:</span>
            <span class="font-bold <?= ($total_kritis > 0) ? 'text-red-600' : 'text-slate-800' ?>">
              <?= $total_kritis ?> Item
            </span>
          </div>
        </div>

        <!-- 1. Ringkasan KPI -->
        <div class="mb-6">
          <h3 class="text-xs font-bold text-slate-800 uppercase tracking-wider mb-2 flex items-center gap-1.5">
            <span class="w-2 h-2 rounded-full bg-cyan-600"></span>
            1. Ringkasan Metrik Kinerja (KPI)
          </h3>
          <div class="grid grid-cols-3 gap-3">
            <div class="p-3 bg-cyan-50 border border-cyan-200 rounded-lg">
              <span class="text-[10px] text-cyan-800 font-semibold uppercase block">Total Barang Masuk</span>
              <div class="text-xl font-bold text-cyan-900 mt-0.5"><?= number_format($total_masuk_unit) ?> Unit</div>
              <span class="text-[10px] text-cyan-700"><?= $total_masuk_transaksi ?> Pengiriman</span>
            </div>
            <div class="p-3 bg-pink-50 border border-pink-200 rounded-lg">
              <span class="text-[10px] text-pink-800 font-semibold uppercase block">Total Barang Keluar</span>
              <div class="text-xl font-bold text-pink-900 mt-0.5"><?= number_format($total_keluar_unit) ?> Unit</div>
              <span class="text-[10px] text-pink-700"><?= $total_keluar_transaksi ?> Sesi Operasional</span>
            </div>
            <div class="p-3 bg-cyan-50 border border-cyan-200 rounded-lg">
              <span class="text-[10px] text-cyan-800 font-semibold uppercase block">Nilai Pembelian</span>
              <div class="text-xl font-bold text-cyan-900 mt-0.5"><?= format_rupiah($total_nilai_pembelian) ?></div>
              <span class="text-[10px] text-cyan-700">Periode <?= htmlspecialchars($label_rentang) ?></span>
            </div>
          </div>
        </div>

        <!-- 2. Top 10 Bahan Baku Terlaris -->
        <div class="mb-6">
          <h3 class="text-xs font-bold text-slate-800 uppercase tracking-wider mb-2 flex items-center gap-1.5">
            <span class="w-2 h-2 rounded-full bg-cyan-600"></span>
            2. Top 10 Bahan Baku Terlaris / Pengeluaran Tertinggi
          </h3>
          <table class="w-full text-xs text-left border border-slate-200">
            <thead class="bg-slate-100 text-slate-700 font-bold uppercase">
              <tr>
                <th class="py-2 px-3 border-b border-slate-200 w-12 text-center">Rank</th>
                <th class="py-2 px-3 border-b border-slate-200 w-28">Kode</th>
                <th class="py-2 px-3 border-b border-slate-200">Nama Bahan Baku</th>
                <th class="py-2 px-3 border-b border-slate-200">Kategori</th>
                <th class="py-2 px-3 border-b border-slate-200 text-right w-28">Total Keluar</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <?php if (empty($top_barang_list)): ?>
              <tr>
                <td colspan="5" class="py-4 text-center text-slate-500 italic">Belum ada transaksi pengeluaran pada periode ini.</td>
              </tr>
              <?php else: ?>
                <?php $rank = 1; foreach ($top_barang_list as $top): ?>
                <tr>
                  <td class="py-2 px-3 text-center font-bold text-slate-700">#<?= $rank++ ?></td>
                  <td class="py-2 px-3 font-mono font-medium"><?= htmlspecialchars($top['kode_barang']) ?></td>
                  <td class="py-2 px-3 font-semibold text-slate-800"><?= htmlspecialchars($top['nama_barang']) ?></td>
                  <td class="py-2 px-3 text-slate-600"><?= htmlspecialchars($top['kategori']) ?></td>
                  <td class="py-2 px-3 text-right font-bold text-cyan-700"><?= number_format($top['total_keluar']) ?> <?= htmlspecialchars($top['satuan']) ?></td>
                </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <!-- 3. Stok Kritis -->
        <div class="mb-6">
          <h3 class="text-xs font-bold text-slate-800 uppercase tracking-wider mb-2 flex items-center gap-1.5">
            <span class="w-2 h-2 rounded-full bg-red-600"></span>
            3. Daftar Bahan Baku Berstatus Kritis (Habis & Menipis)
          </h3>
          <table class="w-full text-xs text-left border border-slate-200">
            <thead class="bg-slate-100 text-slate-700 font-bold uppercase">
              <tr>
                <th class="py-2 px-3 border-b border-slate-200 w-28">Kode</th>
                <th class="py-2 px-3 border-b border-slate-200">Nama Bahan Baku</th>
                <th class="py-2 px-3 border-b border-slate-200">Kategori</th>
                <th class="py-2 px-3 border-b border-slate-200 text-center w-24">Stok Fisik</th>
                <th class="py-2 px-3 border-b border-slate-200 text-center w-24">Stok Minimum</th>
                <th class="py-2 px-3 border-b border-slate-200 text-center w-24">Status</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <?php if (empty($stok_kritis_list)): ?>
              <tr>
                <td colspan="6" class="py-4 text-center text-emerald-700 font-medium">Semua persediaan dalam batas aman (tidak ada bahan baku yang berada di bawah stok minimum).</td>
              </tr>
              <?php else: ?>
                <?php foreach ($stok_kritis_list as $kritis): ?>
                <tr>
                  <td class="py-2 px-3 font-mono font-medium"><?= htmlspecialchars($kritis['kode_barang']) ?></td>
                  <td class="py-2 px-3 font-semibold text-slate-800"><?= htmlspecialchars($kritis['nama_barang']) ?></td>
                  <td class="py-2 px-3 text-slate-600"><?= htmlspecialchars($kritis['kategori']) ?></td>
                  <td class="py-2 px-3 text-center font-bold <?= ($kritis['stok'] <= 0) ? 'text-red-600' : 'text-slate-800' ?>">
                    <?= number_format($kritis['stok']) ?> <?= htmlspecialchars($kritis['satuan']) ?>
                  </td>
                  <td class="py-2 px-3 text-center text-slate-600"><?= number_format($kritis['stok_minimum']) ?></td>
                  <td class="py-2 px-3 text-center">
                    <span class="inline-block px-2 py-0.5 rounded text-[10px] font-bold <?= ($kritis['status'] === 'Habis') ? 'bg-red-100 text-red-800' : 'bg-pink-100 text-pink-800' ?>">
                      <?= htmlspecialchars($kritis['status']) ?>
                    </span>
                  </td>
                </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <!-- Tanda Tangan Pengesahan -->
        <div class="mt-12 pt-6 border-t border-slate-200 text-xs text-slate-700">
          <div class="flex justify-between items-end">
            <div class="text-center w-48">
              <p class="mb-16">Dibuat Oleh,</p>
              <p class="font-bold underline text-slate-900"><?= htmlspecialchars($user_name) ?></p>
              <p class="text-[11px] text-slate-500 uppercase"><?= htmlspecialchars($user_level === 'owner' ? 'Owner / Pimpinan' : 'Staf Gudang') ?></p>
            </div>
            <div class="text-center w-48">
              <p class="mb-1">Tanjungpinang, <?= tanggal_indo(date('Y-m-d')) ?></p>
              <p class="mb-16">Mengetahui,</p>
              <p class="font-bold underline text-slate-900">Owner Cooler City</p>
              <p class="text-[11px] text-slate-500">Manajer Operasional</p>
            </div>
          </div>
        </div>

      </div>

      <script>
        // Otomatis membuka popup print ketika dokumen selesai dimuat
        window.addEventListener('load', function () {
          // Memberi sedikit jeda agar font & css ter-render sempurna
          setTimeout(function () {
            window.print();
          }, 400);
        });
      </script>
    </body>
    </html>
    <?php
    exit;
}

// ==============================================================
// 5. TAMPILAN HALAMAN UTAMA LAPORAN (DESAIN 100% GOOGLE STITCH AI)
// ==============================================================

require_once __DIR__ . '/../../header.php';
require_once __DIR__ . '/../../sidebar.php';
?>

<!-- Main Content Laporan (100% Persis Desain Google Stitch AI) -->
<main class="relative pt-16 bg-surface min-h-screen w-full px-space-lg py-space-lg">
  <div class="flex flex-col w-full">

    <!-- Page Header (Persis Desain Stitch) -->
    <div class="flex flex-col items-center justify-center text-center mb-space-lg">
      <h1 class="font-headline-lg text-headline-lg text-primary-container tracking-tight drop-shadow-sm">
        Laporan
      </h1>
      <p class="font-headline-sm text-headline-sm text-tertiary-container mt-1">
        Analisis dan laporan inventory management
      </p>
    </div>

    <!-- Top Filter & Control Card (Persis Desain Stitch) -->
    <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg mb-space-lg border border-outline-variant/20">
      <form method="GET" action="<?= base_url('views/laporan/index.php') ?>" id="filter-form" class="flex flex-col lg:flex-row lg:items-center justify-between gap-space-md">
        
        <!-- Period Selector Left Group -->
        <div class="flex flex-wrap items-center gap-space-md">
          <div class="flex items-center gap-space-xs text-on-surface">
            <span class="material-symbols-outlined text-[22px] text-primary">calendar_month</span>
            <span class="font-headline-sm text-headline-sm">Filter Periode</span>
          </div>

          <div class="flex items-center gap-space-xs">
            <span class="font-body-md text-body-md text-on-surface-variant">Periode :</span>
            <div class="relative">
              <select name="periode" id="period-dropdown" class="appearance-none bg-surface-container-low text-on-surface font-body-md text-body-md pl-3 pr-8 py-2 rounded-lg outline-none focus:bg-surface-container-lowest transition-all cursor-pointer border-0">
                <option value="current" <?= ($periode === 'current') ? 'selected' : '' ?>>
                  Bulan Ini (<?= $nama_bulan[$current_month] . ' ' . $current_year ?>)
                </option>
                <option value="last_month" <?= ($periode === 'last_month') ? 'selected' : '' ?>>
                  Bulan Lalu (<?= $nama_bulan[(int)date('n', strtotime('first day of last month'))] . ' ' . date('Y', strtotime('first day of last month')) ?>)
                </option>
                <option value="q3" <?= ($periode === 'q3') ? 'selected' : '' ?>>
                  Kuartal 3 (Jul - Sep <?= $current_year ?>)
                </option>
                <option value="ytd" <?= ($periode === 'ytd') ? 'selected' : '' ?>>
                  Tahun Berjalan (YTD <?= $current_year ?>)
                </option>
                <option value="custom" <?= ($periode === 'custom') ? 'selected' : '' ?>>
                  Kustom Rentang Tanggal...
                </option>
              </select>
              <span class="material-symbols-outlined absolute right-2 top-1/2 -translate-y-1/2 text-on-surface-variant pointer-events-none text-[18px]">
                expand_more
              </span>
            </div>
          </div>

          <!-- Custom Date Input Containers (Muncul jika Kustom Dipilih) -->
          <div id="custom-date-container" class="<?= ($periode === 'custom') ? 'flex' : 'hidden' ?> items-center gap-2">
            <input type="date" name="tgl_mulai" value="<?= htmlspecialchars($tgl_mulai) ?>" class="bg-surface-container-low text-on-surface font-body-md text-body-md px-2.5 py-1.5 rounded-lg border border-outline-variant/30 outline-none">
            <span class="text-xs text-on-surface-variant font-medium">s/d</span>
            <input type="date" name="tgl_akhir" value="<?= htmlspecialchars($tgl_akhir) ?>" class="bg-surface-container-low text-on-surface font-body-md text-body-md px-2.5 py-1.5 rounded-lg border border-outline-variant/30 outline-none">
          </div>

          <!-- Date Range Badge Indicator -->
          <div class="flex items-center gap-space-xs bg-surface-container-low px-3 py-1.5 rounded-lg text-on-surface-variant">
            <span class="material-symbols-outlined text-[16px] text-primary">date_range</span>
            <span class="font-body-sm text-body-sm text-on-surface font-medium">
              <?= htmlspecialchars($label_rentang) ?>
            </span>
          </div>

          <button type="submit" class="bg-primary text-on-primary hover:bg-primary-container font-label-lg text-label-lg px-4 py-2 rounded-lg transition-colors flex items-center gap-1 shadow-sm active:scale-95">
            <span class="material-symbols-outlined text-[18px]">filter_alt</span>
            <span>Terapkan</span>
          </button>
        </div>

        <!-- Action Button Right Group: Export Laporan -->
        <div class="flex items-center gap-space-sm justify-end">
          <button type="button" id="open-export-btn" class="bg-surface-container-lowest text-on-surface hover:bg-surface-container-low font-label-lg text-label-lg px-5 py-2.5 rounded-lg shadow-sm border border-outline-variant/30 transition-all flex items-center gap-2 cursor-pointer active:scale-95">
            <span class="material-symbols-outlined text-[20px] text-secondary">file_download</span>
            <span class="font-semibold">Export Laporan</span>
          </button>
        </div>
      </form>
    </div>

    <!-- Tab Filter Navigation Pills (Persis Desain Stitch) -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-space-md mb-space-md">
      <div class="inline-flex p-1 bg-surface-container-high rounded-full shadow-inner gap-1 self-start">
        <!-- Tab 1: Ringkasan -->
        <button type="button" 
                class="tab-pill-btn px-5 py-2 rounded-full font-label-lg text-label-lg transition-all duration-200 bg-surface-container-lowest text-on-surface shadow-sm font-semibold" 
                id="tab-btn-summary" 
                onclick="switchReportTab('summary')">
          Ringkasan
        </button>

        <!-- Tab 2: Barang Terlaris -->
        <button type="button" 
                class="tab-pill-btn px-5 py-2 rounded-full font-label-lg text-label-lg transition-all duration-200 text-on-surface-variant hover:text-on-surface" 
                id="tab-btn-top" 
                onclick="switchReportTab('top')">
          Barang Terlaris
        </button>

        <!-- Tab 3: Stok Kritis -->
        <button type="button" 
                class="tab-pill-btn px-5 py-2 rounded-full font-label-lg text-label-lg transition-all duration-200 text-on-surface-variant hover:text-on-surface flex items-center gap-1.5" 
                id="tab-btn-critical" 
                onclick="switchReportTab('critical')">
          <span>Stok Kritis</span>
          <?php if ($total_kritis > 0): ?>
          <span class="w-2 h-2 rounded-full bg-error animate-pulse"></span>
          <?php endif; ?>
        </button>
      </div>

      <!-- Quick Store Info Banner -->
      <div class="hidden md:flex items-center gap-2 text-on-surface-variant font-body-sm text-body-sm">
        <span class="material-symbols-outlined text-[16px] text-primary">verified</span>
        <span>Sinkronisasi Otomatis POS &amp; Gudang Pusat</span>
      </div>
    </div>

    <!-- ============================================================== -->
    <!-- TAB PANE 1: Ringkasan Laporan (Default Active)                 -->
    <!-- ============================================================== -->
    <div class="tab-pane flex flex-col gap-space-lg" id="pane-summary">
      
      <!-- 3 Bento KPI Summary Metric Cards (Cyan - Pink - Cyan) -->
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-space-md">
        
        <!-- KPI 1: Total Barang Masuk (Cyan Card) -->
        <div class="bg-primary-container text-on-primary rounded-xl p-space-lg shadow-sm flex flex-col justify-between relative overflow-hidden transition-transform duration-200 hover:-translate-y-1 min-h-[160px]">
          <div class="absolute -right-4 -bottom-4 w-28 h-28 bg-white/10 rounded-full blur-sm pointer-events-none"></div>
          <div class="flex items-center justify-between mb-space-sm relative z-10">
            <span class="font-headline-sm text-headline-sm text-on-primary tracking-wide">
              Total Barang Masuk
            </span>
            <div class="w-9 h-9 rounded-full bg-white/20 flex items-center justify-center shadow-sm">
              <span class="material-symbols-outlined text-[20px] text-on-primary">archive</span>
            </div>
          </div>
          <div class="relative z-10 mt-2">
            <div class="font-metric-display text-metric-display tracking-tight text-white font-bold leading-none">
              <?= number_format($total_masuk_unit) ?>
            </div>
            <div class="font-body-sm text-body-sm text-on-primary/90 mt-2 flex items-center gap-1">
              <span class="material-symbols-outlined text-[14px]">calendar_today</span>
              <span><?= htmlspecialchars($label_periode) ?> (<?= $total_masuk_transaksi ?> Pengiriman)</span>
            </div>
          </div>
        </div>

        <!-- KPI 2: Total Barang Keluar (Pink Card) -->
        <div class="bg-secondary-container text-on-secondary-container rounded-xl p-space-lg shadow-sm flex flex-col justify-between relative overflow-hidden transition-transform duration-200 hover:-translate-y-1 min-h-[160px]">
          <div class="absolute -right-4 -bottom-4 w-28 h-28 bg-white/20 rounded-full blur-sm pointer-events-none"></div>
          <div class="flex items-center justify-between mb-space-sm relative z-10">
            <span class="font-headline-sm text-headline-sm text-on-secondary-container tracking-wide font-bold">
              Total Barang Keluar
            </span>
            <div class="w-9 h-9 rounded-full bg-secondary/15 flex items-center justify-center shadow-sm">
              <span class="material-symbols-outlined text-[20px] text-on-secondary-container">unarchive</span>
            </div>
          </div>
          <div class="relative z-10 mt-2">
            <div class="font-metric-display text-metric-display tracking-tight text-on-secondary-container font-bold leading-none">
              <?= number_format($total_keluar_unit) ?>
            </div>
            <div class="font-body-sm text-body-sm text-on-secondary-container/90 mt-2 flex items-center gap-1 font-medium">
              <span class="material-symbols-outlined text-[14px]">local_shipping</span>
              <span><?= htmlspecialchars($label_periode) ?> (<?= $total_keluar_transaksi ?> Sesi Operasional)</span>
            </div>
          </div>
        </div>

        <!-- KPI 3: Nilai Pembelian (Cyan Card) -->
        <div class="bg-primary-container text-on-primary rounded-xl p-space-lg shadow-sm flex flex-col justify-between relative overflow-hidden transition-transform duration-200 hover:-translate-y-1 min-h-[160px] sm:col-span-2 lg:col-span-1">
          <div class="absolute -right-4 -bottom-4 w-28 h-28 bg-white/10 rounded-full blur-sm pointer-events-none"></div>
          <div class="flex items-center justify-between mb-space-sm relative z-10">
            <span class="font-headline-sm text-headline-sm text-on-primary tracking-wide">
              Nilai Pembelian
            </span>
            <div class="w-9 h-9 rounded-full bg-white/20 flex items-center justify-center shadow-sm">
              <span class="material-symbols-outlined text-[20px] text-on-primary">shopping_bag</span>
            </div>
          </div>
          <div class="relative z-10 mt-2">
            <div class="font-metric-display text-metric-display tracking-tight text-white font-bold leading-none">
              <?= format_rupiah($total_nilai_pembelian) ?>
            </div>
            <div class="font-body-sm text-body-sm text-on-primary/90 mt-2 flex items-center gap-1">
              <span class="material-symbols-outlined text-[14px]">trending_up</span>
              <span>Total pengadaan bahan baku periode ini</span>
            </div>
          </div>
        </div>

      </div>

      <!-- Monthly Performance Table Card (Persis Desain Stitch) -->
      <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg border border-outline-variant/20">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between pb-space-md gap-space-sm">
          <div>
            <h2 class="font-headline-md text-headline-md text-on-surface">Laporan Bulanan</h2>
            <p class="font-body-sm text-body-sm text-on-surface-variant">
              Perbandingan performa transaksi berkala
            </p>
          </div>
          <span class="inline-flex items-center gap-1.5 px-3 py-1 bg-surface-container-high text-primary rounded-full font-label-md text-label-md font-semibold self-start sm:self-auto">
            <span class="w-2 h-2 rounded-full bg-primary animate-pulse"></span>
            Tersinkronisasi Real-Time
          </span>
        </div>

        <div class="overflow-x-auto w-full">
          <table class="w-full text-left">
            <thead>
              <tr class="bg-surface-container-low text-on-surface-variant font-label-md text-label-md uppercase tracking-wider">
                <th class="py-3.5 px-4 rounded-l-lg">Periode</th>
                <th class="py-3.5 px-4 text-center">Barang Masuk</th>
                <th class="py-3.5 px-4 text-center">Barang Keluar</th>
                <th class="py-3.5 px-4 text-right rounded-r-lg">Nilai Pembelian</th>
              </tr>
            </thead>
            <tbody class="font-body-md text-body-md text-on-surface divide-y-0">
              <?php if (empty($monthly_summary)): ?>
              <tr>
                <td colspan="4" class="py-8 text-center text-on-surface-variant">
                  <div class="flex flex-col items-center justify-center gap-2">
                    <span class="material-symbols-outlined text-outline-variant text-[36px]">event_busy</span>
                    <span class="font-medium">Belum ada riwayat transaksi yang tercatat pada database.</span>
                    <span class="font-body-sm text-body-sm text-on-surface-variant">Data rekapitulasi akan otomatis terbentuk saat transaksi bahan masuk dan keluar ditambahkan.</span>
                  </div>
                </td>
              </tr>
              <?php else: ?>
                <?php 
                $is_first = true;
                foreach ($monthly_summary as $ym => $data): 
                  $parts = explode('-', $ym);
                  $m_idx = (int)($parts[1] ?? 1);
                  $label_m = ($nama_bulan[$m_idx] ?? $ym) . ' ' . ($parts[0] ?? '');
                  $m_masuk_unit = $data['masuk_unit'] ?? 0;
                  $m_masuk_trx  = $data['masuk_trx'] ?? 0;
                  $m_keluar_unit = $data['keluar_unit'] ?? 0;
                  $m_keluar_trx  = $data['keluar_trx'] ?? 0;
                  $m_beli = $data['total_beli'] ?? 0;
                ?>
                <tr class="hover:bg-surface-container-low/60 transition-colors <?= $is_first ? '' : 'bg-surface-container-low/30' ?>">
                  <td class="py-4 px-4 font-headline-sm text-headline-sm text-on-surface flex items-center gap-2">
                    <span class="w-2.5 h-2.5 rounded-full <?= $is_first ? 'bg-primary-container' : 'bg-outline-variant' ?>"></span>
                    <span><?= htmlspecialchars($label_m) ?></span>
                  </td>
                  <td class="py-4 px-4 text-center">
                    <span class="px-2.5 py-1 bg-primary-fixed text-on-primary-fixed-variant rounded-md font-label-md text-label-md font-semibold">
                      <?= number_format($m_masuk_unit) ?> Unit (<?= $m_masuk_trx ?> Trx)
                    </span>
                  </td>
                  <td class="py-4 px-4 text-center">
                    <span class="px-2.5 py-1 bg-secondary-fixed text-on-secondary-fixed rounded-md font-label-md text-label-md font-semibold">
                      <?= number_format($m_keluar_unit) ?> Unit (<?= $m_keluar_trx ?> Trx)
                    </span>
                  </td>
                  <td class="py-4 px-4 text-right font-semibold text-on-surface">
                    <?= format_rupiah($m_beli) ?>
                  </td>
                </tr>
                <?php 
                  $is_first = false;
                endforeach; 
                ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Dual Summary Highlights (Preview Barang Terlaris & Peringatan Restok Kritis) -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-space-lg">
        
        <!-- Quick Preview Barang Terlaris Mini Card -->
        <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg border border-outline-variant/20 flex flex-col justify-between">
          <div>
            <div class="flex items-center justify-between mb-space-md">
              <div class="flex items-center gap-space-xs">
                <span class="material-symbols-outlined text-primary text-[20px]">trending_up</span>
                <h3 class="font-headline-sm text-headline-sm text-on-surface">Preview Barang Terlaris</h3>
              </div>
              <button type="button" class="text-primary hover:text-primary-container font-label-md text-label-md flex items-center gap-1 transition-colors font-semibold" onclick="switchReportTab('top')">
                <span>Lihat Semua</span>
                <span class="material-symbols-outlined text-[16px]">chevron_right</span>
              </button>
            </div>

            <div class="space-y-space-sm">
              <?php if (empty($top_barang_list)): ?>
              <div class="p-6 bg-surface-container-low rounded-lg text-center text-on-surface-variant font-body-sm">
                Belum ada transaksi pengeluaran pada periode <?= htmlspecialchars($label_periode) ?>.
              </div>
              <?php else: ?>
                <?php 
                $preview_top = array_slice($top_barang_list, 0, 2);
                $p_rank = 1;
                foreach ($preview_top as $item):
                ?>
                <div class="flex items-center justify-between p-space-sm bg-surface-container-low rounded-lg">
                  <div class="flex items-center gap-space-sm">
                    <span class="w-6 h-6 rounded-full <?= ($p_rank === 1) ? 'bg-secondary-container text-on-secondary-container' : 'bg-surface-container-highest text-on-surface' ?> font-headline-sm text-label-md flex items-center justify-center font-bold">
                      <?= $p_rank++ ?>
                    </span>
                    <div>
                      <p class="font-label-lg text-label-lg text-on-surface leading-tight font-semibold">
                        <?= htmlspecialchars($item['nama_barang']) ?>
                      </p>
                      <p class="font-label-sm text-label-sm text-on-surface-variant">
                        <?= htmlspecialchars($item['kode_barang']) ?> · <?= htmlspecialchars($item['kategori']) ?>
                      </p>
                    </div>
                  </div>
                  <div class="text-right">
                    <span class="font-headline-sm text-headline-sm text-on-surface font-bold text-primary">
                      <?= number_format($item['total_keluar']) ?> <?= htmlspecialchars($item['satuan']) ?>
                    </span>
                  </div>
                </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>

          <div class="mt-space-md pt-space-xs text-right">
            <button type="button" onclick="switchReportTab('top')" class="font-label-md text-label-md text-primary hover:underline">
              Buka Top 10 Lengkap &rarr;
            </button>
          </div>
        </div>

        <!-- Quick Critical Alert Mini Card -->
        <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg border border-outline-variant/20 flex flex-col justify-between">
          <div>
            <div class="flex items-center justify-between mb-space-md">
              <div class="flex items-center gap-space-xs">
                <span class="material-symbols-outlined text-error text-[20px]">warning</span>
                <h3 class="font-headline-sm text-headline-sm text-on-surface">Peringatan Restok Kritis</h3>
              </div>
              <button type="button" class="text-tertiary hover:text-tertiary-container font-label-md text-label-md flex items-center gap-1 transition-colors font-semibold" onclick="switchReportTab('critical')">
                <span>Daftar Kritis</span>
                <span class="material-symbols-outlined text-[16px]">chevron_right</span>
              </button>
            </div>

            <div class="space-y-space-sm">
              <?php if (empty($stok_kritis_list)): ?>
              <div class="p-6 bg-emerald-50 text-emerald-800 rounded-lg text-center font-body-sm border border-emerald-100">
                <div class="flex items-center justify-center gap-1.5 font-semibold text-emerald-700">
                  <span class="material-symbols-outlined text-[18px]">verified</span>
                  <span>Semua stok dalam batas aman!</span>
                </div>
                <p class="text-xs text-emerald-600 mt-1">Tidak ada bahan baku yang berada di bawah stok minimum saat ini.</p>
              </div>
              <?php else: ?>
                <?php 
                $preview_kritis = array_slice($stok_kritis_list, 0, 2);
                foreach ($preview_kritis as $item):
                  $is_habis = ($item['status'] === 'Habis');
                ?>
                <div class="flex items-center justify-between p-space-sm <?= $is_habis ? 'bg-error-container/40' : 'bg-surface-container-low' ?> rounded-lg">
                  <div class="flex items-center gap-space-sm">
                    <span class="material-symbols-outlined <?= $is_habis ? 'text-error' : 'text-on-secondary-fixed-variant' ?> text-[20px]">
                      <?= $is_habis ? 'error' : 'schedule' ?>
                    </span>
                    <div>
                      <p class="font-label-lg text-label-lg text-on-surface leading-tight font-semibold">
                        <?= htmlspecialchars($item['nama_barang']) ?>
                      </p>
                      <p class="font-label-sm text-label-sm <?= $is_habis ? 'text-error font-medium' : 'text-on-surface-variant' ?>">
                        <?= htmlspecialchars($item['kode_barang']) ?> · <?= $is_habis ? 'Habis Total' : 'Sisa ' . number_format($item['stok']) . ' ' . htmlspecialchars($item['satuan']) ?>
                      </p>
                    </div>
                  </div>
                  <div class="text-right">
                    <?php if ($is_habis): ?>
                    <span class="px-2.5 py-1 bg-error text-on-error rounded-md font-label-md text-label-md font-bold">
                      0 / Min <?= number_format($item['stok_minimum']) ?>
                    </span>
                    <?php else: ?>
                    <span class="px-2.5 py-1 bg-secondary-fixed text-on-secondary-fixed rounded-md font-label-md text-label-md font-bold">
                      Menipis (<?= number_format($item['stok']) ?>)
                    </span>
                    <?php endif; ?>
                  </div>
                </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>

          <div class="mt-space-md pt-space-xs text-right">
            <button type="button" onclick="switchReportTab('critical')" class="font-label-md text-label-md text-tertiary hover:underline">
              Kelola <?= $total_kritis ?> Stok Kritis &rarr;
            </button>
          </div>
        </div>

      </div>

    </div>

    <!-- ============================================================== -->
    <!-- TAB PANE 2: Top 10 Barang Terlaris (Hidden by default)         -->
    <!-- ============================================================== -->
    <div class="tab-pane flex-col gap-space-lg hidden" id="pane-top">
      <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg border border-outline-variant/20">
        
        <div class="flex flex-col sm:flex-row sm:items-center justify-between pb-space-md gap-space-sm">
          <div class="flex items-center gap-space-sm">
            <div class="w-10 h-10 rounded-lg bg-primary/10 flex items-center justify-center text-primary shadow-xs">
              <span class="material-symbols-outlined text-[24px]">trending_up</span>
            </div>
            <div>
              <h2 class="font-headline-md text-headline-md text-on-surface">Top 10 Barang Terlaris</h2>
              <p class="font-body-sm text-body-sm text-on-surface-variant">
                Barang dengan volume pemakaian dan penjualan tertinggi periode ini
              </p>
            </div>
          </div>
          <div class="text-left sm:text-right">
            <span class="font-label-sm text-label-sm text-on-surface-variant uppercase tracking-wider block">Total Disposisi</span>
            <span class="font-headline-sm text-headline-sm text-primary font-bold">
              <?= number_format($total_disposisi_unit) ?> Bahan Terpakai
            </span>
          </div>
        </div>

        <div class="overflow-x-auto w-full">
          <table class="w-full text-left">
            <thead class="bg-surface-container-low text-on-surface-variant font-label-md text-label-md uppercase tracking-wider">
              <tr>
                <th class="py-3.5 px-4 rounded-l-lg w-24 text-center">Ranking</th>
                <th class="py-3.5 px-4 w-36">Kode Barang</th>
                <th class="py-3.5 px-4">Nama Barang</th>
                <th class="py-3.5 px-4">Kategori</th>
                <th class="py-3.5 px-4 text-right rounded-r-lg w-40">Total Keluar</th>
              </tr>
            </thead>
            <tbody class="font-body-md text-body-md text-on-surface">
              <?php if (empty($top_barang_list)): ?>
              <tr>
                <td colspan="5" class="py-12 text-center text-on-surface-variant">
                  <div class="flex flex-col items-center justify-center gap-2">
                    <span class="material-symbols-outlined text-outline-variant text-[40px]">inventory</span>
                    <span class="font-headline-sm text-headline-sm text-on-surface">Belum Ada Transaksi Pengeluaran</span>
                    <span class="font-body-sm text-body-sm text-on-surface-variant max-w-md">
                      Tidak ada transaksi bahan keluar yang tercatat pada rentang <?= htmlspecialchars($label_rentang) ?>.
                    </span>
                  </div>
                </td>
              </tr>
              <?php else: ?>
                <?php 
                $rank = 1;
                foreach ($top_barang_list as $row):
                  $is_striped = ($rank % 2 === 0);
                  if ($rank === 1) {
                    $rank_badge = "bg-on-surface text-surface-container-lowest";
                  } elseif ($rank <= 3) {
                    $rank_badge = "bg-surface-container-high text-on-surface";
                  } else {
                    $rank_badge = "bg-surface-container-low text-on-surface";
                  }
                ?>
                <tr class="hover:bg-surface-container-low/60 transition-colors <?= $is_striped ? 'bg-surface-container-low/30' : '' ?>">
                  <td class="py-4 px-4 text-center">
                    <span class="inline-flex items-center justify-center w-7 h-7 rounded-full <?= $rank_badge ?> font-headline-sm text-label-md font-bold">
                      #<?= $rank++ ?>
                    </span>
                  </td>
                  <td class="py-4 px-4 font-mono font-medium text-on-surface">
                    <?= htmlspecialchars($row['kode_barang']) ?>
                  </td>
                  <td class="py-4 px-4 font-headline-sm text-headline-sm text-on-surface">
                    <?= htmlspecialchars($row['nama_barang']) ?>
                  </td>
                  <td class="py-4 px-4 text-on-surface-variant">
                    <?= htmlspecialchars($row['kategori']) ?>
                  </td>
                  <td class="py-4 px-4 text-right font-semibold text-primary font-headline-sm">
                    <?= number_format($row['total_keluar']) ?> <?= htmlspecialchars($row['satuan']) ?>
                  </td>
                </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

      </div>
    </div>

    <!-- ============================================================== -->
    <!-- TAB PANE 3: Stok Kritis (Hidden by default)                     -->
    <!-- ============================================================== -->
    <div class="tab-pane flex-col gap-space-lg hidden" id="pane-critical">
      <div class="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg border border-outline-variant/20">
        
        <div class="flex flex-col sm:flex-row sm:items-center justify-between pb-space-md gap-space-sm">
          <div class="flex items-center gap-space-sm">
            <div class="w-10 h-10 rounded-lg bg-error-container/60 flex items-center justify-center text-error shadow-xs">
              <span class="material-symbols-outlined text-[24px]">crisis_alert</span>
            </div>
            <div>
              <h2 class="font-headline-md text-headline-md text-on-surface font-bold">Stok Kritis</h2>
              <p class="font-body-sm text-body-sm text-on-surface-variant">
                Bahan baku yang berada di bawah ambang batas stok minimum dan memerlukan restok segera
              </p>
            </div>
          </div>
          <div>
            <span class="inline-flex items-center gap-1.5 px-3 py-1 bg-red-50 text-red-700 rounded-full font-label-md text-label-md font-semibold border border-red-200">
              <span class="w-2 h-2 rounded-full bg-red-600"></span>
              <?= $total_kritis ?> Item Memerlukan Perhatian
            </span>
          </div>
        </div>

        <div class="overflow-x-auto w-full">
          <table class="w-full text-left border-collapse">
            <thead>
              <tr class="text-on-surface font-headline-sm text-body-md border-b-2 border-outline-variant/60 bg-surface-container-low">
                <th class="py-3 px-4 font-bold rounded-l-lg">Kode Barang</th>
                <th class="py-3 px-4 font-bold">Nama Barang</th>
                <th class="py-3 px-4 font-bold">Kategori</th>
                <th class="py-3 px-4 font-bold text-center">Stok Sekarang</th>
                <th class="py-3 px-4 font-bold text-center">Stok Minimal</th>
                <th class="py-3 px-4 font-bold text-center rounded-r-lg">Status</th>
              </tr>
            </thead>
            <tbody class="font-body-md text-body-md text-on-surface divide-y divide-outline-variant/30">
              <?php if (empty($stok_kritis_list)): ?>
              <tr>
                <td colspan="6" class="py-12 text-center text-on-surface-variant">
                  <div class="flex flex-col items-center justify-center gap-2">
                    <span class="material-symbols-outlined text-emerald-500 text-[48px]">check_circle</span>
                    <span class="font-headline-sm text-headline-sm text-emerald-800 font-bold">Semua Stok Aman!</span>
                    <span class="font-body-sm text-body-sm text-emerald-600 max-w-md">
                      Saat ini tidak ada bahan baku yang berada di bawah ambang batas stok minimum. Seluruh persediaan dalam kondisi aman.
                    </span>
                  </div>
                </td>
              </tr>
              <?php else: ?>
                <?php foreach ($stok_kritis_list as $row): 
                  $is_habis = ($row['status'] === 'Habis');
                ?>
                <tr class="hover:bg-surface-container-low/50 transition-colors <?= $is_habis ? 'bg-error-container/15' : '' ?>">
                  <td class="py-3.5 px-4 font-mono font-medium <?= $is_habis ? 'text-error font-bold' : 'text-on-surface' ?>">
                    <?= htmlspecialchars($row['kode_barang']) ?>
                  </td>
                  <td class="py-3.5 px-4 font-headline-sm text-on-surface">
                    <?= htmlspecialchars($row['nama_barang']) ?>
                  </td>
                  <td class="py-3.5 px-4 text-on-surface-variant">
                    <?= htmlspecialchars($row['kategori']) ?>
                  </td>
                  <td class="py-3.5 px-4 text-center font-bold font-headline-sm <?= $is_habis ? 'text-error' : 'text-on-surface' ?>">
                    <?= number_format($row['stok']) ?> <?= htmlspecialchars($row['satuan']) ?>
                  </td>
                  <td class="py-3.5 px-4 text-center text-on-surface-variant font-medium">
                    <?= number_format($row['stok_minimum']) ?> <?= htmlspecialchars($row['satuan']) ?>
                  </td>
                  <td class="py-3.5 px-4 text-center">
                    <?php if ($is_habis): ?>
                    <span class="inline-flex items-center justify-center min-w-[80px] px-3.5 py-1 bg-error text-white font-label-md text-label-md rounded-full font-bold shadow-xs">
                      Habis
                    </span>
                    <?php else: ?>
                    <span class="inline-flex items-center justify-center min-w-[80px] px-3.5 py-1 bg-secondary-fixed text-on-secondary-fixed font-label-md text-label-md rounded-full font-bold shadow-xs">
                      Menipis
                    </span>
                    <?php endif; ?>
                  </td>
                </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

      </div>
    </div>

  </div>
</main>

<!-- ============================================================== -->
<!-- EXPORT MODAL DIALOG (Persis Desain Stitch AI)                  -->
<!-- ============================================================== -->
<div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-xs hidden transition-opacity" id="export-modal" aria-hidden="true">
  <div class="bg-surface-container-lowest rounded-xl shadow-xl w-full max-w-md p-space-xl mx-4 transform transition-transform border border-outline-variant/30">
    
    <!-- Modal Header -->
    <div class="flex items-center justify-between pb-space-sm mb-space-md border-b-0">
      <div class="flex items-center gap-space-xs">
        <span class="material-symbols-outlined text-primary text-[24px]">download</span>
        <h3 class="font-headline-md text-headline-md text-on-surface font-bold">Export - Pilih Jenis Export</h3>
      </div>
      <button type="button" class="w-8 h-8 rounded-full hover:bg-surface-container-high flex items-center justify-center text-on-surface-variant transition-colors" id="close-export-btn" aria-label="Tutup Dialog">
        <span class="material-symbols-outlined text-[20px]">close</span>
      </button>
    </div>

    <!-- Modal Body Description -->
    <p class="font-body-md text-body-md text-on-surface-variant mb-space-lg leading-relaxed">
      Silakan tentukan format file laporan yang ingin Anda unduh untuk periode <strong class="text-on-surface"><?= htmlspecialchars($label_rentang) ?></strong>.
    </p>

    <!-- Modal 2-Option Cards Grid -->
    <div class="grid grid-cols-2 gap-space-md mb-space-md">
      
      <!-- Option 1: PDF (Soft Pink Style - Persis Stitch) -->
      <button type="button" class="flex flex-col items-center justify-center gap-space-xs p-space-md bg-secondary-container hover:bg-secondary-fixed text-on-secondary-container rounded-xl transition-all shadow-sm group active:scale-95 cursor-pointer text-center" onclick="triggerExport('pdf')">
        <div class="w-12 h-12 rounded-full bg-white/40 flex items-center justify-center group-hover:scale-110 transition-transform shadow-xs">
          <span class="material-symbols-outlined text-[28px] text-secondary">picture_as_pdf</span>
        </div>
        <span class="font-headline-sm text-headline-sm font-bold mt-1">Format PDF</span>
        <span class="font-label-sm text-label-sm opacity-80">Siap Cetak &amp; Resmi</span>
      </button>

      <!-- Option 2: Excel (Solid Cyan Style - Persis Stitch) -->
      <button type="button" class="flex flex-col items-center justify-center gap-space-xs p-space-md bg-primary-container hover:bg-primary-fixed-dim text-white rounded-xl transition-all shadow-sm group active:scale-95 cursor-pointer text-center" onclick="triggerExport('excel')">
        <div class="w-12 h-12 rounded-full bg-white/20 flex items-center justify-center group-hover:scale-110 transition-transform shadow-xs">
          <span class="material-symbols-outlined text-[28px] text-white">table_view</span>
        </div>
        <span class="font-headline-sm text-headline-sm font-bold mt-1">Format Excel</span>
        <span class="font-label-sm text-label-sm opacity-90">Data Mentah &amp; CSV</span>
      </button>

    </div>

    <!-- Modal Footer Actions -->
    <div class="flex items-center justify-end pt-space-xs">
      <button type="button" class="px-4 py-2 font-label-lg text-label-lg text-on-surface-variant hover:text-on-surface font-semibold transition-colors" id="cancel-export-btn">
        Batal
      </button>
    </div>

  </div>
</div>

<!-- ============================================================== -->
<!-- JAVASCRIPT: TAB NAVIGATION & EXPORT MODAL LOGIC                -->
<!-- ============================================================== -->
<script>
/**
 * Switch Tab Laporan (Ringkasan, Barang Terlaris, Stok Kritis)
 * Mengubah visibilitas konten dan status active styling pill
 */
function switchReportTab(tabKey) {
  const tabs = ['summary', 'top', 'critical'];
  tabs.forEach(t => {
    const pane = document.getElementById('pane-' + t);
    const btn  = document.getElementById('tab-btn-' + t);
    if (pane && btn) {
      if (t === tabKey) {
        pane.classList.remove('hidden');
        pane.classList.add('flex');
        btn.classList.add('bg-surface-container-lowest', 'text-on-surface', 'shadow-sm', 'font-semibold');
        btn.classList.remove('text-on-surface-variant');
      } else {
        pane.classList.add('hidden');
        pane.classList.remove('flex');
        btn.classList.remove('bg-surface-container-lowest', 'text-on-surface', 'shadow-sm', 'font-semibold');
        btn.classList.add('text-on-surface-variant');
      }
    }
  });
}

// Modal Export Dialog References
const exportModal     = document.getElementById('export-modal');
const openExportBtn   = document.getElementById('open-export-btn');
const closeExportBtn  = document.getElementById('close-export-btn');
const cancelExportBtn = document.getElementById('cancel-export-btn');
const periodDropdown  = document.getElementById('period-dropdown');
const customDateBox   = document.getElementById('custom-date-container');

function openExport() {
  if (exportModal) {
    exportModal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
  }
}

function closeExport() {
  if (exportModal) {
    exportModal.classList.add('hidden');
    document.body.style.overflow = '';
  }
}

if (openExportBtn)   openExportBtn.addEventListener('click', openExport);
if (closeExportBtn)  closeExportBtn.addEventListener('click', closeExport);
if (cancelExportBtn) cancelExportBtn.addEventListener('click', closeExport);

// Close modal saat klik di luar kotak
if (exportModal) {
  exportModal.addEventListener('click', function(e) {
    if (e.target === exportModal) {
      closeExport();
    }
  });
}

// Keyboard ESC listener
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    closeExport();
  }
});

// Listener untuk dropdown periode (Tampilkan custom date jika dipilih)
if (periodDropdown && customDateBox) {
  periodDropdown.addEventListener('change', function () {
    if (this.value === 'custom') {
      customDateBox.classList.remove('hidden');
      customDateBox.classList.add('flex');
    } else {
      customDateBox.classList.add('hidden');
      customDateBox.classList.remove('flex');
    }
  });
}

/**
 * Trigger Export File sesuai tipe yang dipilih (PDF atau Excel)
 * Mengikuti parameter filter periode yang aktif saat ini
 */
function triggerExport(type) {
  const currentPeriode  = <?= json_encode($periode) ?>;
  const currentTglMulai = <?= json_encode($tgl_mulai) ?>;
  const currentTglAkhir = <?= json_encode($tgl_akhir) ?>;

  const params = new URLSearchParams({
    export: type,
    periode: currentPeriode,
    tgl_mulai: currentTglMulai,
    tgl_akhir: currentTglAkhir
  });

  const targetUrl = '<?= base_url("views/laporan/index.php") ?>?' + params.toString();

  if (type === 'pdf') {
    window.open(targetUrl, '_blank');
  } else {
    window.location.href = targetUrl;
  }

  closeExport();
}
</script>

<?php
require_once __DIR__ . '/../../footer.php';
?>

