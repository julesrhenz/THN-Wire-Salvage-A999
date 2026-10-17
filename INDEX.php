<?php
// index.php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    $u = (string)($_POST['username'] ?? '');
    $p = (string)($_POST['password'] ?? '');
    $redirect = (string)($_POST['redirect'] ?? 'index.php?tab=scan');

    if (attempt_login($u, $p)) {
        header('Location: ' . $redirect);
        exit;
    }
    $_SESSION['login_error'] = 'Invalid username or password.';
    header('Location: index.php?tab=scan');
    exit;
}

if (($_GET['action'] ?? '') === 'logout') {
    logout();
    header('Location: index.php');
    exit;
}

if (!is_logged_in()) {
    include __DIR__ . '/login.php';
    exit;
}

$autoload = __DIR__ . '/vendor/autoload.php';
if (is_file($autoload)) require_once $autoload;

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/upload.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_files') {
    if (!is_admin()) {
        $_SESSION['flash'] = ['error', 'Admin access required for uploads.'];
        header('Location: index.php?tab=scan');
        exit;
    }
    if (!empty($_FILES['files']['name'][0])) {
        $res = import_files($_FILES['files']);
        $summary = sprintf('Imported %d row(s); %d skipped.',
                           $res['inserted'], $res['skipped']);
        if (!empty($res['errors'])) {
            $summary .= ' ' . implode(' | ', array_slice($res['errors'], 0, 3));
            if (count($res['errors']) > 3) {
                $summary .= ' … (+' . (count($res['errors']) - 3) . ' more)';
            }
            $_SESSION['flash'] = ['warning', $summary];
        } elseif ($res['inserted'] > 0) {
            $_SESSION['flash'] = ['success', $summary];
        } else {
            $_SESSION['flash'] = ['warning', $summary];
        }
    } else {
        $_SESSION['flash'] = ['error', 'No files selected.'];
    }
    header('Location: index.php?tab=upload');
    exit;
}

$tab = (string)($_GET['tab'] ?? 'scan');
if (!can_access($tab)) {
    $_SESSION['flash'] = ['warning', 'You do not have access to that tab.'];
    header('Location: index.php?tab=scan');
    exit;
}

$pdo   = db();
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$user  = $_SESSION['user'];
$role  = $_SESSION['role'];

// Remember each tab's last view (query string minus "tab") so switching tabs
// and coming back restores it instead of showing an empty page.
$extra = array_diff_key($_GET, ['tab' => 1]);
if ($extra) {
    $_SESSION['tab_state'][$tab] = http_build_query($extra);
}
$tabState = $_SESSION['tab_state'] ?? [];

// Tab rendering can perform expensive read-only work. Release the PHP session
// lock once all session values needed by this response have been copied.
session_write_close();

$HAS_SPREADSHEET = class_exists('\PhpOffice\PhpSpreadsheet\IOFactory');

$allTabs = [
    'scan'      => 'Scan Obsolete Wire',
    'pending'   => 'Pending Lot Entry',
    'upload'    => 'Upload Reference DB',
    'inventory' => 'Add Reference Row',
    'kpi'       => 'History & Clear Data',
    'guide'     => 'Guide',
];
$visibleTabs = [];
foreach ($allTabs as $k => $label) {
    if (can_access($k)) $visibleTabs[$k] = $label;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h(APP_NAME) ?> · <?= h(APP_VER) ?></title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>

<header class="topbar">
  <div class="brand">
    <div>
      <h1><?= h(APP_NAME) ?></h1>
      <p>Obsolete Wire Reuse · <em><?= h(APP_VER) ?></em></p>
    </div>
  </div>
  <div class="user">
    <b><?= h($user) ?></b>
    <span class="badge <?= $role === 'admin' ? 'reference' : 'available' ?>"><?= h($role) ?></span>
    <a href="?action=logout" class="logout-link">Logout</a>
    <?php if (!$HAS_SPREADSHEET): ?>
      <span class="badge scrap">CSV only</span>
    <?php endif; ?>
  </div>
</header>

<nav class="tabs">
  <?php foreach ($visibleTabs as $k => $label):
    $active = $tab === $k ? 'active' : '';
    $href   = '?tab=' . $k;
    if (!empty($tabState[$k])) $href .= '&' . $tabState[$k];
  ?>
    <a class="<?= $active ?>" href="<?= h($href) ?>"><?= h($label) ?></a>
  <?php endforeach; ?>
</nav>

<?php
  $mainClass = $tab === 'scan' ? 'scan-main'
             : (in_array($tab, ['pending', 'upload', 'inventory'], true) ? 'fit-main' : '');
?>
<main class="<?= $mainClass ?>">
<?php if ($flash): ?>
  <div class="flash <?= h($flash[0]) ?>"><?= h($flash[1]) ?></div>
<?php endif; ?>

<?php
  $file = __DIR__ . "/tabs/{$tab}.php";
  if (preg_match('/^[a-z]+$/', $tab) && is_file($file)) {
      include $file;
  } else {
      echo "<p class='error'>Unknown tab.</p>";
  }
?>
</main>

<footer>
  <p>© <?= date('Y') ?> WireSalvage AI · SQLite @ <code><?= h(DB_PATH) ?></code></p>
</footer>
</body>
</html>