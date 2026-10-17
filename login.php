<?php
// login.php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

$error    = $_SESSION['login_error'] ?? null;
unset($_SESSION['login_error']);

$redirect = $_GET['redirect'] ?? ($_POST['redirect'] ?? 'index.php?tab=scan');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in · <?= htmlspecialchars(APP_NAME, ENT_QUOTES) ?></title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body class="login-body">

<div class="login-wrap">
  <div class="login-card">
    <div class="login-brand">
      <h1><?= htmlspecialchars(APP_NAME, ENT_QUOTES) ?></h1>
      <p>Sign in to continue</p>
    </div>

    <?php if ($error): ?>
      <div class="flash error"><?= htmlspecialchars($error, ENT_QUOTES) ?></div>
    <?php endif; ?>

    <form method="post" action="index.php" class="login-form">
      <input type="hidden" name="action" value="login">
      <input type="hidden" name="redirect"
             value="<?= htmlspecialchars($redirect, ENT_QUOTES) ?>">

      <label>Username
        <input type="text" name="username" autocomplete="username"
               required autofocus>
      </label>
      <label>Password
        <input type="password" name="password"
               autocomplete="current-password" required>
      </label>

      <button class="btn approve" type="submit">Sign in</button>
    </form>
  </div>
</div>

</body>
</html>