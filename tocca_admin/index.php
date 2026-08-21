<?php
require_once __DIR__ . '/session_bootstrap.php';
if (!isset($_SESSION['login_attempts'])) $_SESSION['login_attempts'] = 0;
if (!isset($_SESSION['login_lock_until'])) $_SESSION['login_lock_until'] = 0;
$maxAttempts = 3;

if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

$isLoginApi = $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_SERVER['CONTENT_TYPE'])
    && strpos($_SERVER['CONTENT_TYPE'], 'application/json') === 0;

// Keep the login screen usable even when MySQL is stopped.
if (!defined('TOCCA_ALLOW_DB_FAIL')) {
    define('TOCCA_ALLOW_DB_FAIL', true);
}

if ($isLoginApi) {
    header('Content-Type: application/json');
    if ($_SESSION['login_lock_until'] > time()) {
        $remaining = $_SESSION['login_lock_until'] - time();
        echo json_encode([
            'success' => false,
            'message' => 'Too many failed attempts. Please wait ',
            'lock_seconds' => $remaining
        ]);
        exit();
    }
    require_once __DIR__ . '/db_connection.php';
    try {
        if (!($conn instanceof mysqli)) {
            throw new Exception((string) ($GLOBALS['tocca_db_error'] ?? 'Database unavailable. Start MySQL in XAMPP, then try again.'));
        }
        $input = json_decode(file_get_contents("php://input"), true);
        if (!$input || !isset($input['username'], $input['password'])) {
            throw new Exception('Invalid input.');
        }
        $username = trim($input['username']);
        $password = trim($input['password']);
        $stmt = $conn->prepare("SELECT userID, password FROM tbl_user WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->store_result();
        require_once __DIR__ . '/audit_log.php';
        if ($stmt->num_rows === 0) {
            $_SESSION['login_attempts']++;
            audit_log($conn, 'auth', 'login_failed', 'user', null, ['username' => $username]);
            if ($_SESSION['login_attempts'] >= $maxAttempts) {
                $_SESSION['login_lock_until'] = time() + (int) tocca_config('login_lock_seconds');
                $_SESSION['login_attempts'] = 0;
                echo json_encode([
                    'success' => false,
                    'message' => 'Too many failed attempts. Please wait ',
                    'lock_seconds' => $_SESSION['login_lock_until'] - time()
                ]);
                exit();
            }
            $attemptsLeft = max(0, $maxAttempts - $_SESSION['login_attempts']);
            throw new Exception('Invalid username or password. Attempts left: ' . $attemptsLeft . '.');
        }
        $stmt->bind_result($userId, $hashed_password);
        $stmt->fetch();
        if (!password_verify($password, $hashed_password)) {
            $_SESSION['login_attempts']++;
            audit_log($conn, 'auth', 'login_failed', 'user', (int) $userId, ['username' => $username]);
            if ($_SESSION['login_attempts'] >= $maxAttempts) {
                $_SESSION['login_lock_until'] = time() + (int) tocca_config('login_lock_seconds');
                $_SESSION['login_attempts'] = 0;
                echo json_encode([
                    'success' => false,
                    'message' => 'Too many failed attempts. Please wait ',
                    'lock_seconds' => $_SESSION['login_lock_until'] - time()
                ]);
                exit;
            }
            $attemptsLeft = max(0, $maxAttempts - $_SESSION['login_attempts']);
            echo json_encode([
                'success' => false,
                'message' => 'Invalid username or password. Attempts left: ' . $attemptsLeft . ''
            ]);
            exit;
        }
        $_SESSION['login_attempts'] = 0;
        $_SESSION['login_lock_until'] = 0;
        $_SESSION['loggedin'] = true;
        $_SESSION['username'] = $username;
        $_SESSION['user_id'] = (int) $userId;
        $_SESSION['admin_id'] = (int) $userId;
        $_SESSION['admin_name'] = $username;
        session_regenerate_id(true);
        audit_log($conn, 'auth', 'login', 'user', (int) $userId, ['username' => $username]);

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}

include 'get_logo.php';
$dbUnavailable = !isset($conn) || !($conn instanceof mysqli);
$loginSideLogoPath = isset($loginSideLogoPath) ? $loginSideLogoPath : 'img/tatak ormoc logo.png';
$loginBannerPath   = isset($loginBannerPath)   ? $loginBannerPath   : 'img/tocca_banner.jpg';
$loginTitle        = isset($loginTitle)        ? $loginTitle        : 'TATAK ORMOC CONSUMERS’ CHOICE AWARDS';
$loginBgColor      = isset($loginBgColor)      ? $loginBgColor      : '#0d47a1';
$faviconPath       = isset($faviconPath)       ? $faviconPath       : 'img/tatakormoclogo.png';
?>
<!DOCTYPE html>
<html lang="en" class="login-page" data-bs-theme="light">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>TOCCA Admin Login</title>
    <link rel="icon" type="image/png" href="<?php echo h($faviconPath); ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="css/tocca-theme.css">
    <script>
    (function () {
      try {
        document.documentElement.classList.remove('dark-mode');
        document.documentElement.setAttribute('data-bs-theme', 'light');
      } catch (e) {}
    })();
    </script>
<style>
    .login-wrapper {
        background: linear-gradient(145deg, <?php echo h($loginBgColor); ?> 0%, #1e3a8a 55%, #1e40af 100%);
        min-height: 100vh;
    }
    .no-invalid-border.is-invalid {
        border-color: #ced4da !important;
        box-shadow: none !important;
    }
    .login-form {
        position: relative;
        z-index: 2;
    }
    .login-form .btn-primary {
        position: relative;
        z-index: 3;
        pointer-events: auto;
    }
</style>
</head>
<body class="login-page">
    <main>
        <div class="container login-wrapper d-flex align-items-center justify-content-center">
            <div class="card login-card d-flex flex-row shadow-lg">
                <div class="login-logo col-md-5 d-flex align-items-center justify-content-center">
                    <img src="<?php echo h($loginSideLogoPath); ?>" alt="Tatak Ormoc Logo"
                         onerror="this.onerror=null;this.src='img/tatak ormoc logo.png';" />
                </div>
                <div class="login-form col-md-7">
                    <div class="text-center mb-3">
                        <img src="<?php echo h($loginBannerPath); ?>" class="img-fluid mb-3 w-100" style="max-height: 200px; object-fit: contain;" alt="Banner">
                        <h4><?php echo h($loginTitle); ?></h4>
                        <p class="text-muted">Admin | Login</p>
                    </div>
                    <?php if ($dbUnavailable): ?>
                        <div class="alert alert-warning" role="alert">
                            MySQL is not running. Open <strong>XAMPP Control Panel</strong>, start <strong>MySQL</strong>, then try Login again.
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($error_message)): ?>
                        <div class="text-danger mb-3 text-center fw-bold">
                            <?php echo h($error_message); ?>
                        </div>
                    <?php endif; ?>
                    <form id="loginForm" novalidate>
                        <div class="form-floating mb-3">
                            <input type="text" class="form-control no-invalid-border" id="inputUsername" placeholder="Username" required autocomplete="username">
                            <label for="inputUsername">Username</label>
                            <div id="usernameFeedback" class="invalid-feedback"></div>
                        </div>
                        <div class="form-floating mb-3">
                            <input type="password" class="form-control no-invalid-border" id="inputPassword" placeholder="Password" required autocomplete="current-password">
                            <label for="inputPassword">Password</label>
                            <div id="passwordFeedback" class="invalid-feedback"></div>
                        </div>
                        <div class="mb-3" aria-live="polite">
                            <div id="loginError" class="alert alert-danger d-none" role="alert"></div>
                            <div class="toast-container position-static w-100">
                                <div id="successToast" class="toast align-items-center text-bg-success border-0 w-100" role="alert" aria-live="assertive" aria-atomic="true" data-bs-delay="1200">
                                    <div class="d-flex">
                                        <div class="toast-body">
                                            Login successful! Redirecting...
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary w-100" id="loginSubmitBtn">Login</button>
                    </form>
                </div>
            </div>
        </div>
    </main>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    let lockoutActive = false;
    let lockoutTimer = null;
    function showLockout(seconds) {
        lockoutActive = true;
        var errorDiv = document.getElementById('loginError');
        var loginBtn = document.getElementById('loginSubmitBtn');
        loginBtn.disabled = true;
        function updateCountdown() {
            if (seconds > 0) {
                var mins = Math.floor(seconds / 60);
                var secs = seconds % 60;
                errorDiv.textContent = "Too many failed attempts. Please wait " + mins + ":" + (secs < 10 ? "0" : "") + secs + " before trying again.";
                errorDiv.classList.remove('d-none');
                seconds--;
                lockoutTimer = setTimeout(updateCountdown, 1000);
            } else {
                errorDiv.classList.add('d-none');
                loginBtn.disabled = false;
                lockoutActive = false;
            }
        }
        updateCountdown();
    }
    <?php if ($_SESSION['login_lock_until'] > time()): ?>
    showLockout(<?php echo (int) ($_SESSION['login_lock_until'] - time()); ?>);
    <?php endif; ?>
    document.getElementById('loginForm').addEventListener('submit', function(e) {
        e.preventDefault();
        if (lockoutActive) return;
        var username = document.getElementById('inputUsername');
        var password = document.getElementById('inputPassword');
        var usernameFeedback = document.getElementById('usernameFeedback');
        var passwordFeedback = document.getElementById('passwordFeedback');
        var errorDiv = document.getElementById('loginError');
        var loginBtn = document.getElementById('loginSubmitBtn');
        var valid = true;
        username.classList.remove('is-invalid');
        password.classList.remove('is-invalid');
        usernameFeedback.textContent = '';
        passwordFeedback.textContent = '';
        errorDiv.classList.add('d-none');
        errorDiv.textContent = '';
        if (!username.value.trim()) {
            username.classList.add('is-invalid');
            usernameFeedback.textContent = 'Username is required.';
            valid = false;
        }
        if (!password.value.trim()) {
            password.classList.add('is-invalid');
            passwordFeedback.textContent = 'Password is required.';
            valid = false;
        }
        if (!valid) return;
        loginBtn.disabled = true;
        fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ username: username.value.trim(), password: password.value.trim() })
        })
        .then(function(res) {
            return res.text().then(function(text) {
                try {
                    return JSON.parse(text);
                } catch (err) {
                    throw new Error(text || 'Invalid server response.');
                }
            });
        })
        .then(data => {
            if (data.success) {
                var toastEl = document.getElementById('successToast');
                var toast = new bootstrap.Toast(toastEl);
                toast.show();
                setTimeout(function() {
                    window.location.href = 'dashboard.php';
                }, 1200);
            } else {
                password.classList.remove('is-invalid');
                passwordFeedback.textContent = '';
                if (data.lock_seconds) {
                    showLockout(data.lock_seconds);
                } else {
                    errorDiv.textContent = data.message || 'Invalid username or password.';
                    errorDiv.classList.remove('d-none');
                    loginBtn.disabled = false;
                }
            }
        })
        .catch(function(err) {
            var msg = (err && err.message) ? String(err.message) : '';
            if (msg.indexOf('Database') !== -1 || msg.indexOf('connection failed') !== -1 || msg.indexOf('MySQL') !== -1) {
                errorDiv.textContent = 'Database is not running. Open XAMPP Control Panel and start MySQL, then try again.';
            } else if (msg && msg.length < 200 && msg.indexOf('<') === -1) {
                errorDiv.textContent = msg;
            } else {
                errorDiv.textContent = 'Network error. Please try again.';
            }
            errorDiv.classList.remove('d-none');
            loginBtn.disabled = false;
        });
    });
    document.getElementById('inputUsername').addEventListener('input', function() {
        this.classList.remove('is-invalid');
        document.getElementById('usernameFeedback').textContent = '';
    });
    document.getElementById('inputPassword').addEventListener('input', function() {
        this.classList.remove('is-invalid');
        document.getElementById('passwordFeedback').textContent = '';
        document.getElementById('loginError').classList.add('d-none');
    });
    </script>
</body>
</html>
