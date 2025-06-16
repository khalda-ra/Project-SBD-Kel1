<?php
session_start();

require_once 'koneksi.php'; // Pastikan file koneksi.php sudah ada dan benar

$ADMIN_PASSWORD = "adminkopiler"; // Password untuk login admin
$notif = ''; // Variabel untuk notifikasi ke user

// --- START: Logika Upload Bukti Pembayaran ---
$target_dir = "uploads/bukti_pembayaran/"; // Direktori tempat menyimpan file upload

if (!is_dir($target_dir)) {
    mkdir($target_dir, 0777, true); // Perizinan 0777 mungkin tidak aman di produksi, sesuaikan (e.g., 0755, 0775)
}

if (isset($_POST['upload_bukti_pembayaran'])) {
    $id_pesanan_dari_form = (int)$_POST['id_pesanan_untuk_upload']; // ID pesanan yang akan diupdate

    if ($id_pesanan_dari_form <= 0) {
        $notif = "ID Pesanan tidak valid untuk unggah bukti.";
    } else {
        // Cek apakah ada file yang diunggah dan tidak ada error
        if (isset($_FILES['bukti_pembayaran']) && $_FILES['bukti_pembayaran']['error'] == UPLOAD_ERR_OK) {
            $file_name = $_FILES['bukti_pembayaran']['name'];
            $file_tmp = $_FILES['bukti_pembayaran']['tmp_name'];
            $file_size = $_FILES['bukti_pembayaran']['size'];
            $file_type = $_FILES['bukti_pembayaran']['type'];
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

            // Generate nama file unik untuk menghindari tabrakan nama dan keamanan
            $new_file_name = uniqid('bukti_') . '.' . $file_ext;
            $target_file_path = $target_dir . $new_file_name;

            // Tipe file yang diizinkan
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'pdf'];
            $max_file_size = 5 * 1024 * 1024; // 5 MB

            // Validasi ekstensi file
            if (!in_array($file_ext, $allowed_extensions)) {
                $notif = "Tipe file tidak diizinkan. Hanya JPG, PNG, atau PDF.";
            }
            // Validasi ukuran file
            else if ($file_size > $max_file_size) {
                $notif = "Ukuran file terlalu besar. Maksimal 5MB.";
            } else {
                // Pindahkan file dari temporary location ke target directory
                if (move_uploaded_file($file_tmp, $target_file_path)) {
                    // File berhasil diunggah, sekarang simpan informasi path ke database Pesanan
                    try {
                        // Perubahan di sini: Menggunakan 'bukti_pembayaran' sebagai nama kolom
                        $sql_update_bukti = "UPDATE Pesanan SET bukti_pembayaran = ?, status_pesanan = 'Menunggu Verifikasi Pembayaran' WHERE id_pesanan = ?";
                        $stmt_update_bukti = $koneksi->prepare($sql_update_bukti);
                        $stmt_update_bukti->bind_param("si", $target_file_path, $id_pesanan_dari_form);
                        $stmt_update_bukti->execute();

                        $notif = "Bukti pembayaran berhasil diunggah! Pesanan Anda sedang menunggu verifikasi.";
                        // Redirect dengan notifikasi sukses dan scroll ke section upload
                        header('Location: index.php?status=upload_sukses&id_pesanan=' . $id_pesanan_dari_form . '#upload-payment-section');
                        exit();

                    } catch (mysqli_sql_exception $e) {
                        // Rollback jika ada masalah database
                        if (file_exists($target_file_path)) {
                            unlink($target_file_path); // Hapus file yang sudah terunggah jika ada masalah database
                        }
                        $notif = "Terjadi kesalahan database saat menyimpan bukti: " . $e->getMessage();
                    }
                } else {
                    $notif = "Gagal mengunggah file. Periksa izin folder 'uploads/bukti_pembayaran/'.";
                }
            }
        } else {
            // Error dari $_FILES
            $error_code = $_FILES['bukti_pembayaran']['error'];
            $error_messages = [
                UPLOAD_ERR_INI_SIZE   => 'Ukuran file melebihi batas upload.',
                UPLOAD_ERR_FORM_SIZE  => 'Ukuran file melebihi batas upload yang ditentukan form.',
                UPLOAD_ERR_PARTIAL    => 'File hanya terunggah sebagian.',
                UPLOAD_ERR_NO_FILE    => 'Tidak ada file yang dipilih untuk diunggah.',
                UPLOAD_ERR_NO_TMP_DIR => 'Direktori temporary hilang.',
                UPLOAD_ERR_CANT_WRITE => 'Gagal menulis file ke disk.',
                UPLOAD_ERR_EXTENSION  => 'Ekstensi PHP menghentikan upload file.'
            ];
            $notif = $error_messages[$error_code] ?? 'Terjadi kesalahan tidak diketahui saat upload.';
        }
    }
}
// --- END: Logika Upload Bukti Pembayaran ---


// --- START: Logika Login Admin ---
if (isset($_POST['login_admin'])) {
    if ($_POST['password'] === $ADMIN_PASSWORD) {
        $_SESSION['admin_logged_in'] = true;
    } else {
        $notif = "Password salah!";
    }
}
// --- END: Logika Login Admin ---


// --- START: Logika Logout ---
if (isset($_GET['p']) && $_GET['p'] == 'logout') {
    session_destroy();
    header('Location: index.php');
    exit();
}
// --- END: Logika Logout ---


// --- START: Logika Update Status Pesanan (Admin) ---
if (isset($_POST['update_status'])) {
    if (isset($_SESSION['admin_logged_in'])) {
        $id_pesanan = (int)$_POST['id_pesanan'];
        $status_baru = $koneksi->real_escape_string($_POST['status_baru']);

        $status_valid = ['Menunggu Verifikasi Pembayaran', 'Diproses', 'Dikirim', 'Selesai', 'Batal'];

        if (in_array($status_baru, $status_valid)) {
            $sql = "UPDATE Pesanan SET status_pesanan = ? WHERE id_pesanan = ?";
            $stmt = $koneksi->prepare($sql);
            $stmt->bind_param("si", $status_baru, $id_pesanan);
            $stmt->execute();

            header('Location: index.php?p=lihat_pesanan&notif=status_sukses');
            exit();
        }
    }
}
// --- END: Logika Update Status Pesanan (Admin) ---


// --- START: Logika Pemesanan Baru (Pelanggan) ---
if (isset($_POST['pesan_sekarang'])) {
    $nama_lengkap = $koneksi->real_escape_string($_POST['nama']);
    $alamat = $koneksi->real_escape_string($_POST['alamat']);
    $metode_pembayaran = $koneksi->real_escape_string($_POST['metode_pembayaran']);
    $item_kopi_dipilih = isset($_POST['kopi']) ? $_POST['kopi'] : [];
    $jumlah_produk = isset($_POST['jumlah']) ? $_POST['jumlah'] : []; // Tangkap array jumlah

    // Pastikan ada item kopi yang dipilih dan form tidak kosong
    if (empty($item_kopi_dipilih)) {
        $notif = "Pilih setidaknya satu menu kopi!";
    } else if (!empty($nama_lengkap) && !empty($alamat) && !empty($metode_pembayaran)) {
        $koneksi->begin_transaction();
        try {
            $id_pelanggan_saat_ini = 0; // Variabel untuk menyimpan ID pelanggan yang akan digunakan

            // 1. Cek apakah pelanggan sudah ada berdasarkan nama lengkap dan alamat
            $sql_cek_pelanggan = "SELECT id_pelanggan FROM Pelanggan WHERE nama_lengkap = ? AND alamat_pengiriman = ?";
            $stmt_cek_pelanggan = $koneksi->prepare($sql_cek_pelanggan);
            $stmt_cek_pelanggan->bind_param("ss", $nama_lengkap, $alamat);
            $stmt_cek_pelanggan->execute();
            $result_cek_pelanggan = $stmt_cek_pelanggan->get_result();

            if ($result_cek_pelanggan->num_rows > 0) {
                // Pelanggan sudah ada, gunakan ID yang sudah ada
                $row_pelanggan = $result_cek_pelanggan->fetch_assoc();
                $id_pelanggan_saat_ini = $row_pelanggan['id_pelanggan'];
            } else {
                // Pelanggan belum ada, masukkan data pelanggan baru
                $sql_pelanggan_baru = "INSERT INTO Pelanggan (nama_lengkap, alamat_pengiriman) VALUES (?, ?)";
                $stmt_pelanggan_baru = $koneksi->prepare($sql_pelanggan_baru);
                $stmt_pelanggan_baru->bind_param("ss", $nama_lengkap, $alamat);
                $stmt_pelanggan_baru->execute();
                $id_pelanggan_saat_ini = $koneksi->insert_id;
            }

            $total_harga = 0;
            $harga_per_produk = [];
            
            // Ambil harga dan ID produk yang dipilih dari database
            if (!empty($item_kopi_dipilih)) {
                $placeholders = implode(',', array_fill(0, count($item_kopi_dipilih), '?'));
                $sql_harga = "SELECT id_produk, nama_produk, harga FROM Produk WHERE nama_produk IN ($placeholders)";
                $stmt_harga = $koneksi->prepare($sql_harga);
                $types = str_repeat('s', count($item_kopi_dipilih));
                $stmt_harga->bind_param($types, ...$item_kopi_dipilih);
                $stmt_harga->execute();
                $result_harga = $stmt_harga->get_result();
                
                while ($row = $result_harga->fetch_assoc()) {
                    $harga_per_produk[$row['nama_produk']] = ['harga' => $row['harga'], 'id_produk' => $row['id_produk']];
                }
            }

            // Hitung total harga berdasarkan jumlah yang dipilih per item
            foreach ($item_kopi_dipilih as $nama_kopi) {
                $kuantitas = isset($jumlah_produk[$nama_kopi]) ? (int)$jumlah_produk[$nama_kopi] : 1; // Ambil kuantitas spesifik
                if (isset($harga_per_produk[$nama_kopi])) {
                    $total_harga += $harga_per_produk[$nama_kopi]['harga'] * $kuantitas;
                }
            }

            // Masukkan data pesanan baru dengan ID pelanggan yang sudah ditentukan
            $sql_pesanan = "INSERT INTO Pesanan (id_pelanggan, metode_pembayaran, total_harga, status_pesanan) VALUES (?, ?, ?, 'Menunggu Pembayaran')";
            $stmt_pesanan = $koneksi->prepare($sql_pesanan);
            $stmt_pesanan->bind_param("isd", $id_pelanggan_saat_ini, $metode_pembayaran, $total_harga);
            $stmt_pesanan->execute();
            $id_pesanan_baru = $koneksi->insert_id;

            // Masukkan detail pesanan untuk setiap produk yang dipilih
            $sql_detail = "INSERT INTO Detail_Pesanan (id_pesanan, id_produk, jumlah, harga_saat_pesan) VALUES (?, ?, ?, ?)";
            $stmt_detail = $koneksi->prepare($sql_detail);
            foreach ($item_kopi_dipilih as $nama_kopi) {
                $kuantitas = isset($jumlah_produk[$nama_kopi]) ? (int)$jumlah_produk[$nama_kopi] : 1;
                if (isset($harga_per_produk[$nama_kopi])) {
                    $id_produk = $harga_per_produk[$nama_kopi]['id_produk'];
                    $harga_item = $harga_per_produk[$nama_kopi]['harga'];
                    $stmt_detail->bind_param("iiid", $id_pesanan_baru, $id_produk, $kuantitas, $harga_item);
                    $stmt_detail->execute();
                }
            }

            $koneksi->commit();
            $_SESSION['last_pesanan_id'] = $id_pesanan_baru; // Simpan ID pesanan baru di session
            header('Location: index.php?status=sukses#payment-section'); // Redirect ke section pembayaran
            exit();
        } catch (mysqli_sql_exception $exception) {
            $koneksi->rollback();
            die("Error saat memproses pesanan: " . $exception->getMessage());
        }
    } else {
        $notif = "Form pemesanan tidak lengkap!";
    }
}
// --- END: Logika Pemesanan Baru ---


// --- START: Logika Tambah Produk (Admin) ---
if (isset($_POST['tambah_produk'])) {
    if (isset($_SESSION['admin_logged_in'])) {
        $nama = $koneksi->real_escape_string($_POST['nama_produk']);
        $deskripsi = $koneksi->real_escape_string($_POST['deskripsi']);
        $harga = (float)$_POST['harga'];

        $sql = "INSERT INTO Produk (nama_produk, deskripsi, harga) VALUES (?, ?, ?)";
        $stmt = $koneksi->prepare($sql);
        $stmt->bind_param("ssd", $nama, $deskripsi, $harga);
        $stmt->execute();
        header('Location: index.php?p=admin&notif=tambah_sukses');
        exit();
    }
}
// --- END: Logika Tambah Produk (Admin) ---


// --- START: Logika Hapus Produk (Admin) ---
if (isset($_GET['p']) && $_GET['p'] == 'hapus_produk' && isset($_GET['id'])) {
    if (isset($_SESSION['admin_logged_in'])) {
        $id = (int)$_GET['id'];
        $sql = "DELETE FROM Produk WHERE id_produk = ?";
        $stmt = $koneksi->prepare($sql);
        $stmt->bind_param("i", $id);
        $stmt->execute();
        header('Location: index.php?p=admin&notif=hapus_sukses');
        exit();
    }
}
// --- END: Logika Hapus Produk (Admin) ---

// Ambil daftar produk untuk ditampilkan di halaman depan
$produk_list = [];
$result_produk = $koneksi->query("SELECT * FROM Produk ORDER BY id_produk ASC");
if ($result_produk->num_rows > 0) {
    while($row = $result_produk->fetch_assoc()) {
        $produk_list[] = $row;
    }
}

// START: Logika untuk Transaksi Per Hari (Admin)
$transaksi_per_hari = [];
$transaksi_per_bulan = [];

if (isset($_SESSION['admin_logged_in'])) {
    // Query untuk menghitung total pesanan dan total pendapatan per hari
    // Hanya hitung transaksi yang "Selesai" sebagai pendapatan
    $sql_transaksi_harian = "
        SELECT
            DATE(tanggal_pesanan) AS tanggal_transaksi,
            COUNT(id_pesanan) AS jumlah_pesanan,
            SUM(total_harga) AS total_pendapatan
        FROM
            Pesanan
        WHERE 
            status_pesanan = 'Selesai'
        GROUP BY
            DATE(tanggal_pesanan)
        ORDER BY
            tanggal_transaksi DESC
        LIMIT 30; -- Ambil data untuk 30 hari terakhir
    ";
    $result_transaksi_harian = $koneksi->query($sql_transaksi_harian);

    if ($result_transaksi_harian && $result_transaksi_harian->num_rows > 0) {
        while ($row = $result_transaksi_harian->fetch_assoc()) {
            $transaksi_per_hari[] = $row;
        }
    }

    // Query untuk menghitung total pesanan dan total pendapatan per bulan
    $sql_transaksi_bulanan = "
        SELECT
            DATE_FORMAT(tanggal_pesanan, '%Y-%m') AS bulan_transaksi,
            COUNT(id_pesanan) AS jumlah_pesanan,
            SUM(total_harga) AS total_pendapatan
        FROM
            Pesanan
        WHERE 
            status_pesanan = 'Selesai'
        GROUP BY
            bulan_transaksi
        ORDER BY
            bulan_transaksi DESC
        LIMIT 12; -- Ambil data untuk 12 bulan terakhir
    ";
    $result_transaksi_bulanan = $koneksi->query($sql_transaksi_bulanan);

    if ($result_transaksi_bulanan && $result_transaksi_bulanan->num_rows > 0) {
        while ($row = $result_transaksi_bulanan->fetch_assoc()) {
            $transaksi_per_bulan[] = $row;
        }
    }
}
// END: Logika untuk Transaksi Per Hari dan Bulan (Admin)

// Menentukan halaman yang akan ditampilkan
$page = isset($_GET['p']) ? $_GET['p'] : 'home';

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Kopi Ler - Menu & Pemesanan</title>
    <style>
        /* Gaya CSS Anda */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body, html { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; scroll-behavior: smooth; background-color: #fefae0; }
        .notification { padding: 15px; margin-bottom: 20px; border-radius: 8px; color: #155724; background-color: #d4edda; border: 1px solid #c3e6cb; text-align: center; font-weight: bold; }
        .hero { background-image: url('https://images.unsplash.com/photo-1509042239860-f550ce710b93'); background-size: cover; background-position: center; height: 100vh; color: white; display: flex; flex-direction: column; justify-content: center; align-items: center; text-align: center; text-shadow: 1px 1px 5px rgba(0, 0, 0, 0.7); }
        .hero h1 { font-size: 4rem; margin-bottom: 20px; }
        .hero p { font-size: 1.5rem; margin-bottom: 30px; }
        .hero a { text-decoration: none; background-color: #6f4e37; color: white; padding: 15px 30px; font-size: 1.2rem; border-radius: 8px; transition: background-color 0.3s ease; }
        .hero a:hover { background-color: #563c29; }
        .menu-section { padding: 60px 20px; color: #333; }
        .section-title { text-align: center; font-size: 3rem; margin-bottom: 40px; color: #6f4e37; }
        .menu-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 30px; max-width: 1000px; margin: auto; }
        .menu-item { background-color: #fff; border-radius: 12px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); padding: 20px; text-align: center; }
        .menu-item h3 { margin-bottom: 10px; color: #6f4e37; }
        .menu-item p { font-size: 1rem; color: #666; }
        .menu-item .price { margin-top: 10px; font-weight: bold; color: #333; }
        .order-button { display: inline-block; background-color: #6f4e37; color: white; padding: 15px 30px; border-radius: 8px; text-decoration: none; font-size: 1.2rem; transition: background-color 0.3s ease; }
        .order-button:hover { background-color: #563c29; }
        .order-form-container { background: #43260d; padding: 32px 40px; border-radius: 16px; width: 90%; max-width: 480px; margin: 60px auto; box-shadow: 0 12px 24px rgba(0, 0, 0, 0.2); color: #e5ccae; }
        .order-form-container h1 { font-weight: 800; font-size: 2.25rem; margin-bottom: 24px; text-align: center; }
        label { font-weight: 600; margin-bottom: 8px; display: block; }
        input[type="text"], input[type="password"], input[type="number"], textarea, select { width: 100%; padding: 12px 16px; border-radius: 12px; border: 1.5px solid #e1c78f; background-color: #725b37; color: #fff; margin-bottom: 16px; }
        input[type="file"] { width: 100%; padding: 12px 16px; border-radius: 12px; border: 1.5px solid #e1c78f; background-color: #725b37; color: #fff; margin-bottom: 16px; }
        input[type="checkbox"] { margin-right: 10px; cursor: pointer; }
        .coffee-options { display: flex; flex-direction: column; gap: 12px; max-height: 160px; overflow-y: auto; padding-right: 8px; margin-bottom: 16px; }
        button[type="submit"] { width: 100%; padding: 14px 0; background: linear-gradient(135deg, #43260d, #6f4e37); border: none; border-radius: 14px; color: #e1c78f ; font-weight: 700; font-size: 1.25rem; cursor: pointer; transition: transform 0.3s ease, box-shadow 0.3s ease; }
        button[type="submit"]:hover { transform: scale(1.05); }
        .payment-section { padding: 60px 20px; color: #333; text-align: center; max-width: 600px; margin: 60px auto; }
        .payment-option { margin: 20px 0; padding: 20px; border: 1px solid #6f4e37; border-radius: 12px; user-select: text; font-weight: 600; font-size: 1.1rem; background: #fff; }
        
        /* Style untuk Admin Panel */
        .admin-panel { max-width: 1000px; margin: 40px auto; padding: 30px; background: #fff; border-radius: 8px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        .admin-panel table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        .admin-panel th, .admin-panel td { border: 1px solid #ddd; padding: 10px; text-align: left; }
        .admin-panel th { background-color: #f2f2f2; }
        .admin-panel a { color: #563c29; }
        .admin-nav { margin-bottom: 20px; border-bottom: 1px solid #ddd; padding-bottom: 10px; display: flex; justify-content: flex-start; flex-wrap: wrap;} /* Updated for better spacing */
        .admin-nav a { margin-right: 20px; font-weight: bold; text-decoration: none; padding: 5px 0; }
        .admin-nav a.active { color: #8B4513; border-bottom: 2px solid #8B4513; } /* Active link style */
        .main-footer {
            text-align: center;
            padding: 20px;
            margin-top: 40px;
            background-color: #e9e2d0; 
            border-top: 1px solid #dcd5c0;
            color: #555;
            font-size: 0.9em;
        }
        .main-footer a {
            color: var(--brand-dark-brown, #563c29);
            font-weight: bold;
            text-decoration: none;
        }
        .main-footer a:hover {
            text-decoration: underline;
        }
        .tab-content { margin-top: 20px; }
    </style>
</head>
<body>

<?php
// Konten utama halaman berdasarkan parameter 'p'
switch ($page) {
    case 'admin':
    case 'lihat_pesanan':
    case 'transaksi': // Menggunakan satu case untuk semua tampilan admin yang terkait transaksi
        if (isset($_SESSION['admin_logged_in'])) :
            // Mengambil data produk lagi untuk ditampilkan di admin panel
            $result_produk_admin = $koneksi->query("SELECT * FROM Produk ORDER BY id_produk DESC");
    ?>
            <div class="admin-panel">
                <a href="index.php?p=logout" style="float:right; background-color: #dc3545; color: white; padding: 8px 12px; border-radius: 4px; text-decoration: none;">Logout</a>
                <h1>Panel Admin</h1>
                <div class="admin-nav">
                    <a href="index.php?p=admin" class="<?php echo ($page == 'admin' ? 'active' : ''); ?>">Kelola Produk</a>
                    <a href="index.php?p=lihat_pesanan" class="<?php echo ($page == 'lihat_pesanan' ? 'active' : ''); ?>">Lihat Pesanan</a>
                    <a href="index.php?p=transaksi" class="<?php echo ($page == 'transaksi' ? 'active' : ''); ?>">Transaksi</a> </div>

                <?php if ($page == 'admin'): ?>
                    <h2>Tambah Produk Baru</h2>
                    <form action="index.php" method="POST">
                        <input type="text" name="nama_produk" placeholder="Nama Produk" required>
                        <textarea name="deskripsi" placeholder="Deskripsi Produk" required></textarea>
                        <input type="number" name="harga" placeholder="Harga" step="500" required>
                        <button type="submit" name="tambah_produk">Tambah Produk</button>
                    </form>

                    <h2 style="margin-top: 40px;">Daftar Produk</h2>
                    <table>
                        <thead><tr><th>ID</th><th>Nama</th><th>Harga</th><th>Aksi</th></tr></thead>
                        <tbody>
                            <?php while($p = $result_produk_admin->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo $p['id_produk']; ?></td>
                                <td><?php echo htmlspecialchars($p['nama_produk']); ?></td>
                                <td>Rp <?php echo number_format($p['harga'], 0, ',', '.'); ?></td>
                                <td>
                                    <a href="index.php?p=hapus_produk&id=<?php echo $p['id_produk']; ?>" onclick="return confirm('Yakin ingin menghapus produk ini?')">Hapus</a>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>

                <?php elseif ($page == 'lihat_pesanan'):
                    // Ambil semua data pesanan, termasuk path bukti pembayaran
                    $sql_pesanan = "SELECT p.id_pesanan, pl.nama_lengkap, pl.alamat_pengiriman, p.tanggal_pesanan, p.total_harga, p.status_pesanan, p.bukti_pembayaran
                                    FROM Pesanan p JOIN Pelanggan pl ON p.id_pelanggan = pl.id_pelanggan
                                    ORDER BY p.tanggal_pesanan DESC";
                    $result_pesanan = $koneksi->query($sql_pesanan);
                    
                    // Daftar status pesanan yang valid (termasuk status baru untuk verifikasi bukti)
                    $daftar_status = ['Menunggu Pembayaran', 'Menunggu Verifikasi Pembayaran', 'Diproses', 'Dikirim', 'Selesai', 'Batal'];
                ?>
                    <div class="tab-content">
                        <h2>Daftar Pesanan Masuk</h2>

                        <?php
                        // Tampilkan notifikasi jika ada
                        if (isset($_GET['notif']) && $_GET['notif'] == 'status_sukses') {
                            echo '<div class="notification" style="margin-bottom: 20px;">Status pesanan berhasil diubah!</div>';
                        }
                        ?>
                        
                        <table style="font-size: 0.9em;">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Nama Pelanggan</th>
                                    <th>Tanggal</th>
                                    <th>Total</th>
                                    <th>Bukti Pembayaran</th> 
                                    <th>Status Saat Ini</th>
                                    <th style="width: 250px;">Ubah Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($pesanan = $result_pesanan->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo $pesanan['id_pesanan']; ?></td>
                                    <td><?php echo htmlspecialchars($pesanan['nama_lengkap']); ?></td>
                                    <td><?php echo date('d M Y H:i', strtotime($pesanan['tanggal_pesanan'])); ?></td>
                                    <td>Rp <?php echo number_format($pesanan['total_harga'], 0, ',', '.'); ?></td>
                                    <td>
                                        <?php 
                                        if (!empty($pesanan['bukti_pembayaran'])): ?>
                                            <a href="<?php echo htmlspecialchars($pesanan['bukti_pembayaran']); ?>" target="_blank">Lihat Bukti</a>
                                        <?php else: ?>
                                            Belum Ada
                                        <?php endif; ?>
                                    </td>
                                    <td><strong><?php echo htmlspecialchars($pesanan['status_pesanan']); ?></strong></td>
                                    
                                    <td>
                                        <form action="index.php?p=lihat_pesanan" method="POST" style="display: flex; gap: 5px;">
                                            <input type="hidden" name="id_pesanan" value="<?php echo $pesanan['id_pesanan']; ?>">
                                            <select name="status_baru" style="width: 150px; padding: 5px;">
                                                <?php foreach ($daftar_status as $status): ?>
                                                    <option value="<?php echo $status; ?>" <?php if ($pesanan['status_pesanan'] == $status) echo 'selected'; ?>>
                                                        <?php echo $status; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" name="update_status" style="width: 100%; padding: 6px 8px; border-radius: 12px; border: 1.5px solid #e1c78f; background-color: #725b37; color: #fff; margin-bottom: 16px; font-size: small;">Update</button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>

                <?php elseif ($page == 'transaksi'): ?>
                    <div class="tab-content">
                        <h2>Ringkasan Transaksi Harian (30 Hari Terakhir)</h2>
                        <?php if (empty($transaksi_per_hari)): ?>
                            <p>Belum ada data transaksi yang selesai dalam 30 hari terakhir.</p>
                        <?php else: ?>
                            <table style="font-size: 0.9em;">
                                <thead>
                                    <tr>
                                        <th>Tanggal</th>
                                        <th>Jumlah Pesanan Selesai</th>
                                        <th>Total Pendapatan</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($transaksi_per_hari as $data): ?>
                                        <tr>
                                            <td><?php echo date('d M Y', strtotime($data['tanggal_transaksi'])); ?></td>
                                            <td><?php echo $data['jumlah_pesanan']; ?></td>
                                            <td>Rp <?php echo number_format($data['total_pendapatan'], 0, ',', '.'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>

                        <h2 style="margin-top: 40px;">Ringkasan Transaksi Bulanan (12 Bulan Terakhir)</h2>
                        <?php if (empty($transaksi_per_bulan)): ?>
                            <p>Belum ada data transaksi yang selesai dalam 12 bulan terakhir.</p>
                        <?php else: ?>
                            <table style="font-size: 0.9em;">
                                <thead>
                                    <tr>
                                        <th>Bulan</th>
                                        <th>Jumlah Pesanan Selesai</th>
                                        <th>Total Pendapatan</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($transaksi_per_bulan as $data): ?>
                                        <tr>
                                            <td><?php echo date('F Y', strtotime($data['bulan_transaksi'] . '-01')); ?></td>
                                            <td><?php echo $data['jumlah_pesanan']; ?></td>
                                            <td>Rp <?php echo number_format($data['total_pendapatan'], 0, ',', '.'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

            </div>
    <?php
        else:
            // Jika belum login, tampilkan form password
    ?>
            <div class="order-form-container">
                <h1>Login Admin</h1>
                <?php if ($notif) echo "<p style='color:red; text-align:center; margin-bottom:15px;'>$notif</p>"; ?>
                <form action="index.php?p=admin" method="POST">
                    <label for="password">Password Admin</label>
                    <input type="password" name="password" id="password" required>
                    <button type="submit" name="login_admin">Login</button>
                </form>
            </div>
    <?php
        endif;
        break;

    // Halaman default untuk pelanggan
    default:
?>
    <div class="hero">
        <h1>Kopi Ler</h1>
        <p>Tempat Nongkrong dengan Cita Rasa Kopi Nusantara</p>
        <a href="#menu">Lihat Menu</a>
    </div>

    <section id="menu" class="menu-section">
        <h2 class="section-title">Menu Kopiler</h2>
        <div class="menu-grid">
            <?php foreach ($produk_list as $produk): ?>
              <div class="menu-item">
                <h3><?php echo htmlspecialchars($produk['nama_produk']); ?></h3>
                <p><?php echo htmlspecialchars($produk['deskripsi']); ?></p>
                <div class="price">Rp <?php echo number_format($produk['harga'], 0, ',', '.'); ?></div>
              </div>
            <?php endforeach; ?>
        </div>
        <div style="text-align: center; margin-top: 40px;">
            <a href="#order" class="order-button">Pesan Sekarang</a>
        </div>
    </section>

    <form class="order-form-container" id="order" action="index.php" method="POST">
        <div id="notification">
        <?php if ($notif && !isset($_POST['upload_bukti_pembayaran'])): // Tampilkan notif khusus form pemesanan ?>
            <div class="notification" style="background-color: #f8d7da; color: #721c24;">
                <?php echo htmlspecialchars($notif); ?>
            </div>
        <?php endif; ?>
        </div>
        <h1>Form Pemesanan</h1>
        <label for="nama">Nama Lengkap</label>
        <input type="text" id="nama" name="nama" placeholder="Masukkan nama lengkap Anda" required />
        <label for="alamat">Alamat Pengiriman</label>
        <textarea id="alamat" name="alamat" placeholder="Masukkan alamat lengkap Anda" required></textarea>
        <label>Pilih Menu</label>
        <div class="coffee-options">
        <?php foreach ($produk_list as $produk): ?>
            <label>
            <input type="checkbox"
                         name="kopi[]"
                         value="<?php echo htmlspecialchars($produk['nama_produk']); ?>"
                         onchange="toggleQuantityInput(this)" />
            
            <?php echo htmlspecialchars($produk['nama_produk']); ?>
            
            <input type="number"
                         name="jumlah[<?php echo htmlspecialchars($produk['nama_produk']); ?>]"
                         min="1"
                         value="1"
                         style="display:none; margin-left: 10px; width: 60px; padding: 5px; border-radius: 5px; border: 1px solid #ccc;"
                         disabled />
            </label>
        <?php endforeach; ?>
        </div>
        <label for="payment">Metode Pembayaran</label>
        <select id="payment-method" name="metode_pembayaran" required>
            <option value="" disabled selected>Pilih metode pembayaran</option>
            <option value="Transfer Bank">Transfer Bank</option>
            <option value="Gopay">Gopay</option>
            <option value="DANA">DANA</option>
            <option value="QRIS">QRIS</option>
        </select>
        <button type="submit" name="pesan_sekarang">Lanjutkan Pembayaran</button>
    </form>

    <section class="payment-section" id="payment-section" aria-live="polite" aria-atomic="true">
        <h2>Informasi Metode Pembayaran</h2>
        <div class="payment-option">
            <h3>Transfer Bank</h3>
            <p>Nomor Rekening: <strong>1234567890 (Bank BCA)</strong></p>
            <p>Atas Nama: <strong>Kopi Ler</strong></p>
        </div>
        <div class="payment-option">
            <h3>Gopay</h3>
            <p>Nomor DANA: <strong>081234567890</strong></p>
        </div>
        <div class="payment-option">
            <h3>DANA</h3>
            <p>Nomor DANA: <strong>081234567890</strong></p>
            </div>
        <div id="qris-details" class="payment-option">
                <h3>QRIS</h3>
                <p>Silakan scan QR Code di bawah ini untuk pembayaran:</p>
                <img src="Kopiler.png" alt="QR Code Pembayaran QRIS Kopi Ler" 
                     style="width: 250px; height: auto; display: block; margin: 20px auto; border: 1px solid #eee;">
                </div>
        </div>
        <p style="margin-top: 20px;">
            Setelah pembayaran, silakan unggah bukti pembayaran Anda di bawah ini.
    </section>

    <section class="upload-payment-section" id="upload-payment-section">
        <div class="order-form-container" style="background: #e9e2d0; color: #43260d;">
            <h2>Unggah Bukti Pembayaran Anda</h2>
            <p style="margin-bottom: 20px; text-align: center;">Unggah bukti pembayaran Anda di sini setelah transfer. Pastikan Anda mencatat ID Pesanan Anda.</p>

            <?php if (isset($_GET['status']) && ($_GET['status'] == 'upload_sukses' || $_GET['status'] == 'upload_gagal')): // Notif khusus upload ?>
                <div class="notification" style="<?php echo ($_GET['status'] == 'upload_gagal') ? 'background-color: #f8d7da; color: #721c24;' : 'background-color: #d4edda; color: #155724;'; ?>">
                    <?php 
                    if ($_GET['status'] == 'upload_sukses') {
                        echo 'Bukti pembayaran berhasil diunggah! Pesanan Anda dengan ID ' . htmlspecialchars($_GET['id_pesanan'] ?? '') . ' sedang menunggu verifikasi.';
                    } else {
                        // $notif sudah diisi di bagian PHP upload jika ada error
                        echo htmlspecialchars($notif); 
                    }
                    ?>
                </div>
            <?php elseif ($notif && isset($_POST['upload_bukti_pembayaran'])): // Notif jika ada error saat POST upload_bukti_pembayaran tanpa redirect ?>
                   <div class="notification" style="background-color: #f8d7da; color: #721c24;">
                       <?php echo htmlspecialchars($notif); ?>
                   </div>
            <?php endif; ?>

            <form action="index.php" method="POST" enctype="multipart/form-data">
                <label for="id_pesanan_untuk_upload">ID Pesanan Anda</label>
                <input type="text" id="id_pesanan_untuk_upload" name="id_pesanan_untuk_upload"
                         placeholder="Masukkan ID Pesanan Anda" required
                         value="<?php echo isset($_SESSION['last_pesanan_id']) ? htmlspecialchars($_SESSION['last_pesanan_id']) : ''; ?>" />

                <label for="bukti_file">Pilih File Bukti Pembayaran (JPG, PNG, PDF)</label>
                <input type="file" id="bukti_file" name="bukti_pembayaran" accept=".jpg, .jpeg, .png, .pdf" required />

                <button type="submit" name="upload_bukti_pembayaran" style="background: linear-gradient(135deg, #6f4e37, #43260d); color: #e5ccae;">Unggah Bukti</button>
            </form>
        </div>
    </section>

    <footer class="main-footer">
        <p>&copy; <?php echo date('Y'); ?> Kopi Ler - Semua Hak Cipta Dilindungi.</p>
        <p>
            <a href="index.php?p=admin">Admin Login</a>
        </p>
    </footer>

<?php
        break; 
} 
?>

<script>
    // Fungsi untuk menampilkan/menyembunyikan input jumlah
    function toggleQuantityInput(checkbox) {
        const quantityInput = checkbox.nextElementSibling; 
        if (quantityInput) { // Pastikan elemen berikutnya ada
            if (checkbox.checked) {
                quantityInput.style.display = 'inline-block'; 
                quantityInput.disabled = false; 
            } else {
                quantityInput.style.display = 'none'; 
                quantityInput.disabled = true; 
                quantityInput.value = '1'; // Reset nilai ke 1 saat tidak dipilih
            }
        }
    }

    // Script untuk notifikasi dan auto-scroll
    document.addEventListener('DOMContentLoaded', function() {
        const urlParams = new URLSearchParams(window.location.search);
        const notificationDiv = document.getElementById('notification');
        const paymentSection = document.getElementById('payment-section');
        const uploadSection = document.getElementById('upload-payment-section');

        // Notifikasi setelah pemesanan berhasil
        if (urlParams.get('status') === 'sukses') {
            if(notificationDiv) {
                // Pastikan 'id_pesanan_untuk_upload' memiliki nilai sebelum diakses
                const idPesananElem = document.getElementById('id_pesanan_untuk_upload');
                const idPesanan = idPesananElem ? idPesananElem.value : 'tidak diketahui';
                notificationDiv.innerHTML = `<div class="notification">Pesanan Anda berhasil dibuat! ID Pesanan Anda: ${idPesanan}. Silakan lanjutkan ke pembayaran.</div>`;
            }
            if (paymentSection) {
                paymentSection.scrollIntoView({ behavior: 'smooth' });
            }
            // Hapus parameter 'status' dari URL setelah diproses
            urlParams.delete('status');
            window.history.replaceState({}, document.title, window.location.pathname + urlParams.toString() + window.location.hash);
        }

        // Notifikasi setelah upload bukti pembayaran
        if (urlParams.get('status') === 'upload_sukses' || urlParams.get('status') === 'upload_gagal') {
            if (uploadSection) {
                uploadSection.scrollIntoView({ behavior: 'smooth' });
            }
            // Hapus parameter 'status' dan 'id_pesanan' dari URL setelah diproses
            urlParams.delete('status');
            urlParams.delete('id_pesanan');
            window.history.replaceState({}, document.title, window.location.pathname + urlParams.toString() + window.location.hash);
        }
    });

    // Menginisialisasi input jumlah yang sudah diceklis saat halaman dimuat (misal setelah refresh dengan data form)
    document.querySelectorAll('.coffee-options input[type="checkbox"]').forEach(checkbox => {
        // Panggil fungsi toggleQuantityInput untuk setiap checkbox saat DOMContentLoaded
        // Ini memastikan input jumlah terlihat jika checkbox sudah tercentang dari session/data lama
        toggleQuantityInput(checkbox);
    });
</script>

</body>
</html>