<?php
declare(strict_types=1);

/**
 * My Profile — signed-in admin can update account details and password.
 */
require_once __DIR__ . '/includes/admin_init.php';
admin_apply_nav_from_script();
require_once __DIR__ . '/audit_log.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = (string) $_SESSION['csrf_token'];

$flash = null;
if (!empty($_SESSION['flash_toast']) && is_array($_SESSION['flash_toast'])) {
    $flash = $_SESSION['flash_toast'];
    unset($_SESSION['flash_toast']);
}

$sessionUsername = trim((string) ($_SESSION['username'] ?? ''));
if ($sessionUsername === '') {
    header('Location: index.php');
    exit;
}

function profile_load_user(mysqli $conn, string $username): ?array
{
    $stmt = $conn->prepare(
        'SELECT userID, username, firstname, lastname, email, password
         FROM tbl_user WHERE username = ? LIMIT 1'
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function profile_username_taken(mysqli $conn, string $username, int $exceptUserId): bool
{
    $stmt = $conn->prepare(
        'SELECT 1 FROM tbl_user WHERE username = ? AND userID <> ? LIMIT 1'
    );
    if (!$stmt) {
        return true;
    }
    $stmt->bind_param('si', $username, $exceptUserId);
    $stmt->execute();
    $stmt->store_result();
    $taken = $stmt->num_rows > 0;
    $stmt->close();
    return $taken;
}

function profile_email_taken(mysqli $conn, string $email, int $exceptUserId): bool
{
    $stmt = $conn->prepare(
        'SELECT 1 FROM tbl_user WHERE email = ? AND userID <> ? LIMIT 1'
    );
    if (!$stmt) {
        return true;
    }
    $stmt->bind_param('si', $email, $exceptUserId);
    $stmt->execute();
    $stmt->store_result();
    $taken = $stmt->num_rows > 0;
    $stmt->close();
    return $taken;
}

$user = profile_load_user($conn, $sessionUsername);
if (!$user) {
    $_SESSION = [];
    header('Location: index.php');
    exit;
}

$formUsername = (string) ($user['username'] ?? '');
$formFirst = (string) ($user['firstname'] ?? '');
$formLast = (string) ($user['lastname'] ?? '');
$formEmail = (string) ($user['email'] ?? '');
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals($csrf, $token)) {
        $errors[] = 'Invalid session token. Please reload the page and try again.';
    } else {
        $formUsername = trim((string) ($_POST['username'] ?? ''));
        $formFirst = trim((string) ($_POST['firstname'] ?? ''));
        $formLast = trim((string) ($_POST['lastname'] ?? ''));
        $formEmail = trim((string) ($_POST['email'] ?? ''));
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        $userId = (int) ($user['userID'] ?? 0);
        $storedHash = (string) ($user['password'] ?? '');

        if ($currentPassword === '' || !password_verify($currentPassword, $storedHash)) {
            $errors[] = 'Current password is incorrect.';
        }

        if ($formUsername === '' || mb_strlen($formUsername) < 3 || mb_strlen($formUsername) > 50) {
            $errors[] = 'Username must be 3–50 characters.';
        } elseif (!preg_match('/^[A-Za-z0-9._-]+$/', $formUsername)) {
            $errors[] = 'Username may only contain letters, numbers, dots, underscores, and hyphens.';
        } elseif (profile_username_taken($conn, $formUsername, $userId)) {
            $errors[] = 'That username is already taken.';
        }

        if ($formFirst === '' || mb_strlen($formFirst) > 255) {
            $errors[] = 'First name is required (max 255 characters).';
        }
        if ($formLast === '' || mb_strlen($formLast) > 255) {
            $errors[] = 'Last name is required (max 255 characters).';
        }

        if ($formEmail === '' || !filter_var($formEmail, FILTER_VALIDATE_EMAIL) || mb_strlen($formEmail) > 100) {
            $errors[] = 'Enter a valid email address.';
        } elseif (profile_email_taken($conn, $formEmail, $userId)) {
            $errors[] = 'That email is already used by another account.';
        }

        $changingPassword = ($newPassword !== '' || $confirmPassword !== '');
        if ($changingPassword) {
            if (mb_strlen($newPassword) < 8) {
                $errors[] = 'New password must be at least 8 characters.';
            }
            if ($newPassword !== $confirmPassword) {
                $errors[] = 'New password and confirmation do not match.';
            }
            if ($newPassword !== '' && password_verify($newPassword, $storedHash)) {
                $errors[] = 'New password must be different from your current password.';
            }
        }

        if ($errors === []) {
            $usernameChanged = strcasecmp($formUsername, (string) $user['username']) !== 0;
            $profileChanged =
                $usernameChanged
                || $formFirst !== (string) $user['firstname']
                || $formLast !== (string) $user['lastname']
                || strcasecmp($formEmail, (string) $user['email']) !== 0;

            if (!$profileChanged && !$changingPassword) {
                $errors[] = 'No changes to save.';
            } else {
                $ok = false;
                if ($changingPassword) {
                    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                    $upd = $conn->prepare(
                        'UPDATE tbl_user
                         SET username = ?, firstname = ?, lastname = ?, email = ?, password = ?
                         WHERE userID = ? LIMIT 1'
                    );
                    if ($upd) {
                        $upd->bind_param('sssssi', $formUsername, $formFirst, $formLast, $formEmail, $hash, $userId);
                        $ok = $upd->execute();
                        $upd->close();
                    }
                } else {
                    $upd = $conn->prepare(
                        'UPDATE tbl_user
                         SET username = ?, firstname = ?, lastname = ?, email = ?
                         WHERE userID = ? LIMIT 1'
                    );
                    if ($upd) {
                        $upd->bind_param('ssssi', $formUsername, $formFirst, $formLast, $formEmail, $userId);
                        $ok = $upd->execute();
                        $upd->close();
                    }
                }

                if ($ok) {
                    $_SESSION['username'] = $formUsername;
                    if ($changingPassword) {
                        session_regenerate_id(true);
                    }

                    try {
                        audit_log($conn, 'admin_profile', 'update', 'user', $userId, [
                            'username_changed' => $usernameChanged,
                            'password_changed' => $changingPassword,
                            'new_username' => $formUsername,
                        ]);
                    } catch (Throwable $e) {
                        // Profile save succeeded; audit failure should not block the user.
                        error_log('admin_profile audit_log failed: ' . $e->getMessage());
                    }

                    $_SESSION['flash_toast'] = [
                        'message' => $changingPassword
                            ? 'Profile and password updated successfully.'
                            : 'Profile updated successfully.',
                        'type' => 'success',
                    ];
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    header('Location: admin_profile.php');
                    exit;
                }

                $errors[] = 'Could not save your profile. Please try again.';
            }
        }
    }

    // Refresh hash/user id from DB if still on page after failed attempt.
    $user = profile_load_user($conn, $sessionUsername) ?: $user;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <script src="js/instant_theme_init.js"></script>
  <meta charset="utf-8" />
  <meta http-equiv="X-UA-Compatible" content="IE=edge" />
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
  <title>My Profile | Tatak Ormoc</title>
  <link rel="icon" type="image/png" href="<?php echo h($faviconPath ?? ''); ?>">
  <link href="css/styles.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
  <?php include __DIR__ . '/inline_style.php'; ?>
  <?php include __DIR__ . '/partials/admin_brand_styles.php'; ?>
  <style>
    .profile-card .form-label { font-weight: 600; }
    .profile-card .section-title {
      font-size: 0.95rem;
      font-weight: 700;
      color: #1e3a8a;
      margin: 0 0 0.85rem;
    }
    .profile-card .section-divider {
      border-top: 1px dashed rgba(1, 0, 102, 0.15);
      margin: 1.35rem 0 1.15rem;
    }
    .profile-hint { font-size: 0.85rem; color: #64748b; }
  </style>
</head>
<body class="sb-nav-fixed">
  <?php include __DIR__ . '/partials/admin_topnav.php'; ?>
  <div id="layoutSidenav">
    <?php include __DIR__ . '/partials/admin_sidebar.php'; ?>
    <div id="layoutSidenav_content">
      <main>
        <div class="container-fluid px-4">
          <div class="admin-page-header mt-4 mb-4">
            <div class="min-w-0">
              <h1 class="admin-page-title mb-2">My Profile</h1>
              <?php echo render_breadcrumb([
                  ['label' => 'Dashboard', 'url' => 'dashboard.php'],
                  ['label' => 'My Profile'],
              ]); ?>
            </div>
          </div>

          <div class="row justify-content-center">
            <div class="col-lg-8 col-xl-7">
              <?php if ($errors !== []): ?>
                <div class="alert alert-danger" role="alert">
                  <div class="fw-semibold mb-1">Please fix the following:</div>
                  <ul class="mb-0 ps-3">
                    <?php foreach ($errors as $err): ?>
                      <li><?php echo h($err); ?></li>
                    <?php endforeach; ?>
                  </ul>
                </div>
              <?php endif; ?>

              <div class="card profile-card mb-4 shadow-sm">
                <div class="card-header fw-bold">
                  <i class="bi bi-person-gear me-1" aria-hidden="true"></i> Account details
                </div>
                <div class="card-body">
                  <form method="post" action="admin_profile.php" autocomplete="off" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">

                    <p class="section-title">Profile</p>
                    <div class="row g-3">
                      <div class="col-md-6">
                        <label class="form-label" for="username">Username</label>
                        <input
                          type="text"
                          class="form-control"
                          id="username"
                          name="username"
                          required
                          maxlength="50"
                          value="<?php echo h($formUsername); ?>"
                          autocomplete="username"
                        >
                      </div>
                      <div class="col-md-6">
                        <label class="form-label" for="email">Email</label>
                        <input
                          type="email"
                          class="form-control"
                          id="email"
                          name="email"
                          required
                          maxlength="100"
                          value="<?php echo h($formEmail); ?>"
                          autocomplete="email"
                        >
                      </div>
                      <div class="col-md-6">
                        <label class="form-label" for="firstname">First name</label>
                        <input
                          type="text"
                          class="form-control"
                          id="firstname"
                          name="firstname"
                          required
                          maxlength="255"
                          value="<?php echo h($formFirst); ?>"
                          autocomplete="given-name"
                        >
                      </div>
                      <div class="col-md-6">
                        <label class="form-label" for="lastname">Last name</label>
                        <input
                          type="text"
                          class="form-control"
                          id="lastname"
                          name="lastname"
                          required
                          maxlength="255"
                          value="<?php echo h($formLast); ?>"
                          autocomplete="family-name"
                        >
                      </div>
                    </div>

                    <div class="section-divider"></div>
                    <p class="section-title">Change password <span class="fw-normal text-muted">(optional)</span></p>
                    <p class="profile-hint mb-3">Leave new password fields blank to keep your current password.</p>
                    <div class="row g-3">
                      <div class="col-md-6">
                        <label class="form-label" for="new_password">New password</label>
                        <input
                          type="password"
                          class="form-control"
                          id="new_password"
                          name="new_password"
                          minlength="8"
                          maxlength="128"
                          autocomplete="new-password"
                        >
                      </div>
                      <div class="col-md-6">
                        <label class="form-label" for="confirm_password">Confirm new password</label>
                        <input
                          type="password"
                          class="form-control"
                          id="confirm_password"
                          name="confirm_password"
                          minlength="8"
                          maxlength="128"
                          autocomplete="new-password"
                        >
                      </div>
                    </div>

                    <div class="section-divider"></div>
                    <p class="section-title">Confirm with current password</p>
                    <div class="mb-3">
                      <label class="form-label" for="current_password">Current password <span class="text-danger">*</span></label>
                      <input
                        type="password"
                        class="form-control"
                        id="current_password"
                        name="current_password"
                        required
                        maxlength="128"
                        autocomplete="current-password"
                      >
                      <div class="form-text">Required to save any profile or password changes.</div>
                    </div>

                    <div class="d-flex flex-wrap gap-2 justify-content-end">
                      <a href="dashboard.php" class="btn btn-outline-secondary">Cancel</a>
                      <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Save changes
                      </button>
                    </div>
                  </form>
                </div>
              </div>
            </div>
          </div>
        </div>
      </main>
      <footer class="py-4 bg-light mt-auto">
        <div class="container-fluid px-4">
          <div class="small text-muted">&copy; <?php echo date('Y'); ?> Tatak Ormoc Consumers&rsquo; Choice Awards</div>
        </div>
      </footer>
    </div>
  </div>

  <div class="toast-container position-fixed top-0 start-50 translate-middle-x p-3" style="z-index:1080">
    <div id="globalToast" class="toast align-items-center text-bg-success border-0 mx-auto" role="alert" aria-live="assertive" aria-atomic="true">
      <div class="d-flex">
        <div id="globalToastBody" class="toast-body">Done</div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
  <script src="js/scripts.js"></script>
  <?php if (is_array($flash) && !empty($flash['message'])): ?>
  <script>
  document.addEventListener('DOMContentLoaded', function () {
    var toastEl = document.getElementById('globalToast');
    var bodyEl = document.getElementById('globalToastBody');
    if (!toastEl || !bodyEl) return;
    var type = <?php echo json_encode((string) ($flash['type'] ?? 'success')); ?>;
    bodyEl.textContent = <?php echo json_encode((string) $flash['message']); ?>;
    toastEl.classList.remove('text-bg-success', 'text-bg-danger', 'text-bg-warning', 'text-bg-info');
    toastEl.classList.add(type === 'danger' ? 'text-bg-danger' : (type === 'warning' ? 'text-bg-warning' : 'text-bg-success'));
    bootstrap.Toast.getOrCreateInstance(toastEl, { delay: 3500 }).show();
  });
  </script>
  <?php endif; ?>
</body>
</html>
