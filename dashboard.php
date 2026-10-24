<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

use Stripe\Stripe;
use Stripe\Checkout\Session as CheckoutSession;

start_session();

/*
 * Returning from Stripe Checkout: verify the session server-side.
 * - Logs in the buyer if they checked out as a guest (session ids are unguessable and only
 *   known to the payer).
 * - Lets us show a "processing" state while the webhook is still on its way.
 */
$checkedOutUserId = null;
$sessionId = (string) ($_GET['session_id'] ?? '');

if ($sessionId !== '' && preg_match('/^cs_(test|live)_[A-Za-z0-9]+$/', $sessionId)) {
    try {
        Stripe::setApiKey(STRIPE_SECRET_KEY);
        $cs = CheckoutSession::retrieve($sessionId);

        if ($cs->status === 'complete' && !empty($cs->client_reference_id)) {
            $checkedOutUserId = (int) $cs->client_reference_id;
            if (empty($_SESSION['user_id'])) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = $checkedOutUserId;
            }
        }
    } catch (\Throwable $e) {
        error_log('Dashboard session verification failed: ' . $e->getMessage());
    }
}

if (empty($_SESSION['user_id'])) {
    redirect('login.php');
}

$stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user) {
    session_destroy();
    redirect('login.php');
}

$isActive = $user['membership_status'] === 'active';
$pending  = !$isActive && $checkedOutUserId === (int) $user['id'];

$tierLabels = ['free' => 'Free', 'silver' => 'Silver', 'gold' => 'Gold VIP'];
$tierLabel  = $tierLabels[$user['membership_plan']] ?? 'Free';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Member Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <?php if ($pending): ?>
        <meta http-equiv="refresh" content="3">
    <?php endif; ?>
</head>
<body class="min-h-screen bg-[#f6fbf9] text-emerald-800">

<header class="border-b border-emerald-100 bg-white/70 backdrop-blur">
    <div class="mx-auto flex max-w-5xl items-center justify-between px-6 py-4">
        <a href="index.php" class="text-lg font-bold text-emerald-600">🌿 GreenClub</a>
        <nav class="flex items-center gap-4 text-sm font-medium">
            <span class="hidden sm:inline"><?= e($user['name']) ?></span>
            <a href="logout.php" class="rounded-lg border border-emerald-100 px-3 py-1.5 hover:bg-emerald-50">Log out</a>
        </nav>
    </div>
</header>

<main class="mx-auto max-w-5xl px-6 py-12">

<?php if ($pending): ?>

    <div class="mx-auto max-w-lg rounded-2xl border border-emerald-100 bg-white p-10 text-center shadow-sm">
        <div class="mx-auto mb-4 h-10 w-10 animate-spin rounded-full border-4 border-emerald-100 border-t-emerald-600"></div>
        <h1 class="text-2xl font-extrabold">Payment received — activating your membership…</h1>
        <p class="mt-2 text-sm text-emerald-700/80">This page will refresh automatically in a few seconds.</p>
    </div>

<?php elseif (!$isActive): ?>

    <div class="mx-auto max-w-lg rounded-2xl border border-emerald-100 bg-white p-10 text-center shadow-sm">
        <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-emerald-50 text-2xl">🔒</div>
        <h1 class="text-2xl font-extrabold">Access Denied</h1>
        <p class="mt-2 text-emerald-700/80">
            Your membership is currently
            <strong class="text-emerald-800"><?= e(str_replace('_', ' ', $user['membership_status'])) ?></strong>.
            Choose a plan to unlock the member area.
        </p>
        <a href="index.php" class="mt-6 inline-block rounded-xl bg-emerald-600 px-6 py-3 font-semibold text-white hover:bg-emerald-700">
            View membership plans
        </a>
        <?php if (!empty($user['stripe_customer_id']) && $user['membership_status'] === 'past_due'): ?>
            <p class="mt-4 text-sm">
                Payment failed?
                <a class="font-semibold text-emerald-600 hover:underline" href="customer-portal.php">Update your card</a>
            </p>
        <?php endif; ?>
    </div>

<?php else: ?>

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-3xl font-extrabold">Welcome, <?= e($user['name']) ?> 👋</h1>
            <p class="mt-1 text-emerald-700/80">Your membership is active. Enjoy the member area.</p>
        </div>
        <a href="customer-portal.php"
           class="rounded-xl bg-emerald-600 px-5 py-3 text-sm font-semibold text-white hover:bg-emerald-700">
            Manage Billing &amp; Subscription
        </a>
    </div>

    <section class="mt-8 grid gap-6 md:grid-cols-3">
        <div class="rounded-2xl border border-emerald-100 bg-white p-6 shadow-sm">
            <p class="text-xs font-semibold uppercase tracking-wide text-emerald-600">Current tier</p>
            <p class="mt-2 text-2xl font-extrabold"><?= e($tierLabel) ?></p>
        </div>
        <div class="rounded-2xl border border-emerald-100 bg-white p-6 shadow-sm">
            <p class="text-xs font-semibold uppercase tracking-wide text-emerald-600">Status</p>
            <p class="mt-2">
                <span class="inline-flex items-center rounded-full bg-emerald-50 px-3 py-1 text-sm font-semibold text-emerald-700 ring-1 ring-emerald-100">
                    ● Active
                </span>
            </p>
        </div>
        <div class="rounded-2xl border border-emerald-100 bg-white p-6 shadow-sm">
            <p class="text-xs font-semibold uppercase tracking-wide text-emerald-600">Account email</p>
            <p class="mt-2 break-all font-semibold"><?= e($user['email']) ?></p>
            <p class="mt-1 text-xs text-emerald-600">Member since <?= e(date('M j, Y', strtotime($user['created_at']))) ?></p>
        </div>
    </section>

    <section class="mt-10">
        <h2 class="text-xl font-bold">Members-only content</h2>
        <div class="mt-4 grid gap-6 md:grid-cols-2">
            <article class="rounded-2xl border border-emerald-100 bg-white p-6 shadow-sm">
                <h3 class="font-bold">📚 The Complete Growth Playbook</h3>
                <p class="mt-2 text-sm text-emerald-700/80">
                    A mock protected article, visible only to active members. Replace this with your real gated content.
                </p>
            </article>
            <article class="rounded-2xl border border-emerald-100 bg-white p-6 shadow-sm">
                <h3 class="font-bold">🎥 Monthly Masterclass Recording</h3>
                <p class="mt-2 text-sm text-emerald-700/80">
                    Mock video section. Embed your protected player or download links here.
                </p>
            </article>
            <?php if ($user['membership_plan'] === 'gold'): ?>
                <article class="rounded-2xl border-2 border-emerald-600 bg-white p-6 shadow-sm md:col-span-2">
                    <h3 class="font-bold">⭐ Gold VIP Lounge</h3>
                    <p class="mt-2 text-sm text-emerald-700/80">
                        Exclusive VIP-only content: early releases, private events and priority support channels.
                    </p>
                </article>
            <?php endif; ?>
        </div>
    </section>

<?php endif; ?>

</main>
</body>
</html>
