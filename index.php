<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

start_session();

$user = null;
if (!empty($_SESSION['user_id'])) {
    $stmt = db()->prepare('SELECT id, name, email, membership_plan, membership_status FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch() ?: null;
}

$error = isset($_GET['error']) ? (string) $_GET['error'] : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Membership Plans</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-[#f6fbf9] text-emerald-800">

<header class="border-b border-emerald-100 bg-white/70 backdrop-blur">
    <div class="mx-auto flex max-w-5xl items-center justify-between px-6 py-4">
        <a href="index.php" class="text-lg font-bold text-emerald-600">🌿 GreenClub</a>
        <nav class="flex items-center gap-4 text-sm font-medium">
            <?php if ($user): ?>
                <span class="hidden text-emerald-700 sm:inline"><?= e($user['name']) ?></span>
                <a href="dashboard.php" class="hover:text-emerald-600">Dashboard</a>
                <a href="logout.php" class="rounded-lg border border-emerald-100 px-3 py-1.5 hover:bg-emerald-50">Log out</a>
            <?php else: ?>
                <a href="login.php" class="rounded-lg border border-emerald-100 px-3 py-1.5 hover:bg-emerald-50">Log in</a>
            <?php endif; ?>
        </nav>
    </div>
</header>

<main class="mx-auto max-w-5xl px-6 py-14">
    <div class="text-center">
        <h1 class="text-4xl font-extrabold tracking-tight text-emerald-800">Choose your membership</h1>
        <p class="mx-auto mt-3 max-w-xl text-emerald-700/80">
            Simple monthly billing. Cancel or update your card anytime from your billing portal.
        </p>
    </div>

    <?php if ($error !== ''): ?>
        <div class="mx-auto mt-8 max-w-xl rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <?php if (!$user): ?>
        <div class="mx-auto mt-8 max-w-xl rounded-xl border border-emerald-100 bg-emerald-50/60 px-4 py-3 text-center text-sm text-emerald-700">
            Please <a href="login.php?mode=register" class="font-semibold underline">create an account</a>
            or <a href="login.php" class="font-semibold underline">log in</a> before choosing a plan.
        </div>
    <?php elseif (in_array($user['membership_status'], ['active', 'past_due'], true)): ?>
        <div class="mx-auto mt-8 max-w-xl rounded-xl border border-emerald-100 bg-emerald-50/60 px-4 py-3 text-center text-sm text-emerald-700">
            You already have a membership. To upgrade, downgrade or cancel, go to your
            <a href="dashboard.php" class="font-semibold underline">dashboard</a>.
        </div>
    <?php endif; ?>

    <form action="checkout-subscription.php" method="POST" class="mt-10">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

        <?php if ($user): ?>
            <div class="mx-auto max-w-md">
                <label for="email" class="mb-1 block text-sm font-semibold text-emerald-800">Your email</label>
                <input
                    id="email"
                    name="email"
                    type="email"
                    value="<?= e($user['email']) ?>"
                    readonly
                    class="w-full rounded-xl border border-emerald-100 bg-emerald-50 px-4 py-3 text-emerald-800 shadow-sm outline-none"
                >
                <p class="mt-1 text-xs text-emerald-600">Linked to your logged-in account.</p>
            </div>
        <?php endif; ?>

        <div class="mt-10 grid gap-8 md:grid-cols-2">

            <!-- Silver -->
            <div class="flex flex-col rounded-2xl border border-emerald-100 bg-white p-8 shadow-sm">
                <h2 class="text-xl font-bold text-emerald-800">Silver Tier</h2>
                <p class="mt-1 text-sm text-emerald-700/70">Everything you need to get started.</p>
                <p class="mt-6 text-4xl font-extrabold text-emerald-800">
                    $10.00 <span class="text-base font-medium text-emerald-600">/ month</span>
                </p>
                <ul class="mt-6 flex-1 space-y-3 text-sm text-emerald-800">
                    <li>✔ Access to member library</li>
                    <li>✔ Monthly community newsletter</li>
                    <li>✔ Standard email support</li>
                </ul>
                <button type="submit" name="tier" value="silver"
                    class="mt-8 w-full rounded-xl border border-emerald-600 px-4 py-3 font-semibold text-emerald-600 transition hover:bg-emerald-50">
                    Choose Silver
                </button>
            </div>

            <!-- Gold -->
            <div class="relative flex flex-col rounded-2xl border-2 border-emerald-600 bg-white p-8 shadow-lg shadow-emerald-600/10">
                <span class="absolute -top-3 left-1/2 -translate-x-1/2 rounded-full bg-emerald-600 px-4 py-1 text-xs font-bold uppercase tracking-wide text-white">
                    Recommended
                </span>
                <h2 class="text-xl font-bold text-emerald-800">Gold VIP Tier</h2>
                <p class="mt-1 text-sm text-emerald-700/70">The full experience, with VIP perks.</p>
                <p class="mt-6 text-4xl font-extrabold text-emerald-800">
                    $25.00 <span class="text-base font-medium text-emerald-600">/ month</span>
                </p>
                <ul class="mt-6 flex-1 space-y-3 text-sm text-emerald-800">
                    <li>✔ Everything in Silver</li>
                    <li>✔ Exclusive VIP content &amp; events</li>
                    <li>✔ Early access to new releases</li>
                    <li>✔ Priority support</li>
                </ul>
                <button type="submit" name="tier" value="gold"
                    class="mt-8 w-full rounded-xl bg-emerald-600 px-4 py-3 font-semibold text-white transition hover:bg-emerald-700">
                    Choose Gold VIP
                </button>
            </div>
        </div>
    </form>

    <p class="mt-10 text-center text-xs text-emerald-600">
        🔒 Payments are processed securely by Stripe. Your card details never touch our servers.
    </p>
</main>

</body>
</html>
