<?php
/**
 * Halaman Login - Sistem Inventory Cooler City
 * Desain 100% Mengacu pada Google Stitch AI
 * Otentikasi aman menggunakan session & password_verify()
 */

require_once __DIR__ . '/config/koneksi.php';

// Jika sudah ada sesi login aktif, langsung alihkan ke dashboard
if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
    header('Location: ' . base_url('dashboard.php'));
    exit;
}

$error = '';
$username_val = '';

// Proses form submit login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username_val = trim($_POST['username'] ?? '');
    $password_val = $_POST['password'] ?? '';

    if (empty($username_val) || empty($password_val)) {
        $error = 'Mohon masukkan email / username dan password Anda.';
    } else {
        // Query menggunakan prepared statement untuk keamanan
        $stmt = $koneksi->prepare("SELECT id_user, nama_user, username, password, level FROM tb_user WHERE username = ? LIMIT 1");
        
        if ($stmt) {
            $stmt->bind_param("s", $username_val);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($row = $result->fetch_assoc()) {
                // Verifikasi password dengan hash bcrypt
                if (password_verify($password_val, $row['password'])) {
                    // Set data sesi
                    $_SESSION['user_id']   = (int)$row['id_user'];
                    $_SESSION['nama_user'] = $row['nama_user'];
                    $_SESSION['username']  = $row['username'];
                    $_SESSION['level']      = $row['level'];
                    $_SESSION['logged_at']  = time();
                    
                    // Redirect ke dashboard
                    header('Location: ' . base_url('dashboard.php'));
                    exit;
                } else {
                    $error = 'Password yang Anda masukkan salah. Silakan coba lagi.';
                }
            } else {
                $error = 'Akun tidak ditemukan. Pastikan username/email benar.';
            }
            $stmt->close();
        } else {
            $error = 'Terjadi kesalahan sistem saat memproses login.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
  <title>Login - Cooler City</title>
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&amp;display=swap" rel="stylesheet"/>
  <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
  <style>
    body {
      font-family: 'Inter', sans-serif;
    }
    input:focus {
      outline: none;
      box-shadow: none;
    }
  </style>
</head>
<body class="bg-[#F3F4F6] text-[#111827] min-h-screen flex flex-col justify-between antialiased selection:bg-sky-200">

  <!-- Header / Top Bar (Persis Desain Asli Stitch AI) -->
  <header class="w-full px-8 py-6 md:px-14 md:py-8 flex items-center justify-between">
    <!-- Brand Logo Left -->
    <div class="flex items-center space-x-3">
      <!-- Mascot Icon -->
      <div class="w-9 h-9 relative flex-shrink-0">
        <img src="<?= base_url('assets/img/cooler_city_logo.png') ?>" alt="Cooler City Logo" class="w-full h-full object-contain">
      </div>
      <div class="flex flex-col">
        <span class="font-extrabold text-[15px] tracking-tight text-[#0090d0] leading-none flex items-center">
          COOLER CITY<span class="text-[9px] align-super ml-0.5 font-bold">®</span>
        </span>
        <span class="text-[10px] text-[#ef4444] font-medium tracking-wide mt-0.5">Icecream • Tea</span>
      </div>
    </div>

    <!-- Product System Right -->
    <div class="flex items-center space-x-2 text-[#1E293B]">
      <!-- 8-spoke Asterisk Icon -->
      <svg class="w-5 h-5 text-current stroke-current" fill="none" stroke-linecap="round" stroke-width="2" viewBox="0 0 24 24">
        <line x1="12" x2="12" y1="2" y2="22"></line>
        <line x1="2" x2="22" y1="12" y2="12"></line>
        <line x1="4.93" x2="19.07" y1="4.93" y2="19.07"></line>
        <line x1="4.93" x2="19.07" y1="19.07" y2="4.93"></line>
      </svg>
      <span class="text-[15px] font-medium tracking-tight text-gray-800">Paju Product System</span>
    </div>
  </header>

  <!-- Main Content Area -->
  <main class="flex-1 flex items-center justify-center px-6 py-6 md:py-12">
    <div class="w-full max-w-5xl grid grid-cols-1 lg:grid-cols-12 gap-10 lg:gap-16 items-center">
      
      <!-- Left Column: Illustration (Penguin & Login Access Card dari Stitch AI) -->
      <div class="lg:col-span-7 flex justify-center items-center select-none">
        <div class="w-full max-w-[480px] flex justify-center items-center">
          <img src="<?= base_url('assets/img/login_illustration.jpg') ?>" alt="Penguin Login Illustration" class="w-full h-auto object-contain drop-shadow-sm select-none">
        </div>
      </div>

      <!-- Right Column: Login Form (Persis Desain Asli Stitch AI) -->
      <div class="lg:col-span-5 flex flex-col justify-center max-w-sm w-full mx-auto lg:mx-0">
        <!-- Titles -->
        <div class="mb-10">
          <h1 class="text-[28px] md:text-[32px] font-extrabold text-[#0B132B] tracking-tight">
            Welcome back, Yash
          </h1>
          <p class="text-[14px] text-gray-500 font-normal mt-2">
            Welcome back! Please enter your details.
          </p>
        </div>

        <?php if (!empty($error)): ?>
          <div class="mb-5 p-3 rounded-lg bg-red-50 border border-red-200 text-red-700 text-[13px] flex items-center gap-2">
            <span class="material-symbols-outlined text-[18px]">error</span>
            <span><?= htmlspecialchars($error) ?></span>
          </div>
        <?php endif; ?>

        <!-- Form with Minimal Underline Inputs -->
        <form class="space-y-7" method="POST" action="">
          <!-- Email / Username Input -->
          <div class="flex flex-col">
            <label class="text-[13px] font-medium text-gray-800 mb-1.5" for="username">
              Username
            </label>
            <div class="relative border-b border-gray-400 focus-within:border-black transition-colors pb-1">
              <input autocomplete="username" 
                     class="w-full bg-transparent border-0 p-0 text-[14px] text-gray-900 focus:ring-0 placeholder-gray-400" 
                     id="username" 
                     name="username" 
                     type="text" 
                     value="<?= htmlspecialchars($username_val) ?>"
                     required 
                     autofocus/>
            </div>
          </div>

          <!-- Password Input -->
          <div class="flex flex-col">
            <label class="text-[13px] font-medium text-gray-800 mb-1.5" for="password">
              Password
            </label>
            <div class="relative flex items-center border-b border-gray-400 focus-within:border-black transition-colors pb-1">
              <input autocomplete="current-password" 
                     class="w-full bg-transparent border-0 p-0 pr-8 text-[14px] text-gray-900 focus:ring-0 placeholder-gray-400" 
                     id="password" 
                     name="password" 
                     type="password" 
                     required/>
              <button aria-label="Toggle password visibility" class="absolute right-0 text-gray-500 hover:text-gray-800 focus:outline-none" id="togglePassword" type="button">
                <!-- Eye off icon matching mockup -->
                <span class="material-symbols-outlined text-[19px] leading-none" id="eyeIcon">visibility_off</span>
              </button>
            </div>
          </div>

          <!-- Log In Button -->
          <div class="pt-3">
            <button class="w-full bg-black hover:bg-neutral-900 text-white font-semibold text-[14px] py-3 rounded-md shadow-sm transition-colors text-center cursor-pointer" type="submit">
              Log in
            </button>
          </div>
        </form>

      </div>

    </div>
  </main>

  <!-- Empty bottom spacer to center content evenly -->
  <footer class="h-6"></footer>

  <!-- Script for password toggle -->
  <script>
    const toggleBtn = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('password');
    const eyeIcon = document.getElementById('eyeIcon');

    if (toggleBtn && passwordInput && eyeIcon) {
      toggleBtn.addEventListener('click', () => {
        const isPassword = passwordInput.getAttribute('type') === 'password';
        passwordInput.setAttribute('type', isPassword ? 'text' : 'password');
        eyeIcon.textContent = isPassword ? 'visibility' : 'visibility_off';
      });
    }
  </script>
</body>
</html>
