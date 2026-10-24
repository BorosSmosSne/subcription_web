<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

start_session();

if (!empty($_SESSION['user_id'])) {
    redirect('dashboard.php');
}

$error = '';
$mode  = ($_GET['mode'] ?? 'login') === 'register' ? 'register' : 'login';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? null)) {
        $error = 'Invalid form submission. Please try again.';
    } else {
        $mode     = ($_POST['mode'] ?? 'login') === 'register' ? 'register' : 'login';
        $email    = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif ($mode === 'register') {
            $name = trim((string) ($_POST['name'] ?? ''));
            if ($name === '' || strlen($password) < 8) {
                $error = 'Enter your name and a password of at least 8 characters.';
            } else {
                $stmt = db()->prepare('SELECT id FROM users WHERE email = ?');
                $stmt->execute([$email]);
                if ($stmt->fetch()) {
                    $error = 'An account with that email already exists.';
                } else {
                    $ins = db()->prepare('INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)');
                    $ins->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = (int) db()->lastInsertId();
                    redirect('index.php');
                }
            }
        } else {
            $stmt = db()->prepare('SELECT id, password_hash FROM users WHERE email = ?');
            $stmt->execute([$email]);
            $row = $stmt->fetch();

            if ($row && password_verify($password, $row['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int) $row['id'];
                redirect('dashboard.php');
            }
            $error = 'Invalid email or password.';
        }
    }
}

$isRegister = $mode === 'register';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $isRegister ? 'Create account' : 'Log in' ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="flex min-h-screen items-center justify-center bg-[#f6fbf9] px-4 text-emerald-800">
<div class="w-full max-w-md rounded-2xl border border-emerald-100 bg-white p-8 shadow-sm">
    <h1 class="text-2xl font-extrabold"><?= $isRegister ? 'Create your account' : 'Welcome back' ?></h1>
    <p class="mt-1 text-sm text-emerald-700/70">
        <?= $isRegister ? 'Sign up to manage your membership.' : 'Log in to access your member dashboard.' ?>
    </p>

    <?php if ($error !== ''): ?>
        <div class="mt-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST" class="mt-6 space-y-4">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="mode" value="<?= $isRegister ? 'register' : 'login' ?>">

        <?php if ($isRegister): ?>
            <div>
                <label class="mb-1 block text-sm font-semibold">Name</label>
                <input name="name" type="text" required
                    class="w-full rounded-xl border border-emerald-100 px-4 py-3 outline-none focus:border-emerald-600 focus:ring-2 focus:ring-emerald-600/20">
            </div>
        <?php endif; ?>

        <div>
            <label class="mb-1 block text-sm font-semibold">Email</label>
            <input name="email" type="email" required
                value="<?= e($_POST['email'] ?? ($isRegister ? '' : 'test@example.com')) ?>"
                class="w-full rounded-xl border border-emerald-100 px-4 py-3 outline-none focus:border-emerald-600 focus:ring-2 focus:ring-emerald-600/20">
        </div>

        <div>
            <label class="mb-1 block text-sm font-semibold">Password</label>
            <input name="password" type="password" required
                class="w-full rounded-xl border border-emerald-100 px-4 py-3 outline-none focus:border-emerald-600 focus:ring-2 focus:ring-emerald-600/20">
            <?php if (!$isRegister): ?>
                <p class="mt-1 text-xs text-emerald-600">Demo account: test@example.com / password</p>
            <?php endif; ?>
        </div>

        <button type="submit" class="w-full rounded-xl bg-emerald-600 px-4 py-3 font-semibold text-white hover:bg-emerald-700">
            <?= $isRegister ? 'Create account' : 'Log in' ?>
        </button>
    </form>

    <p class="mt-6 text-center text-sm">
        <?php if ($isRegister): ?>
            Already a member? <a class="font-semibold text-emerald-600 hover:underline" href="login.php">Log in</a>
        <?php else: ?>
            New here? <a class="font-semibold text-emerald-600 hover:underline" href="login.php?mode=register">Create an account</a>
        <?php endif; ?>
        &middot; <a class="text-emerald-600 hover:underline" href="index.php">Plans</a>
    </p>
</div>
</body>
</html>
