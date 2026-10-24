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

$flash = [
    'changed'   => (string) ($_GET['changed'] ?? ''),
    'scheduled' => (string) ($_GET['scheduled'] ?? ''),
    'kept'      => (string) ($_GET['kept'] ?? ''),
    'error'     => (string) ($_GET['error'] ?? ''),
];

// Self-heal: store the renewal date the first time we see an active subscription without one.
if ($isActive && !empty($user['stripe_subscription_id']) && empty($user['current_period_end'])) {
    try {
        Stripe::setApiKey(STRIPE_SECRET_KEY);
        $liveSub = \Stripe\Subscription::retrieve($user['stripe_subscription_id']);
        if ($ts = subscription_period_end($liveSub)) {
            $user['current_period_end'] = gmdate('Y-m-d H:i:s', $ts);
            $upd = db()->prepare('UPDATE users SET current_period_end = ? WHERE id = ?');
            $upd->execute([$user['current_period_end'], $user['id']]);
        }
    } catch (\Throwable $e) {
        error_log('Could not load renewal date: ' . $e->getMessage());
    }
}

// Exact billing figures shown in the confirmation dialogs
$silverCents = PLANS['silver']['amount'];
$goldCents   = PLANS['gold']['amount'];
$diffCents   = $goldCents - $silverCents;
$periodEndTs = !empty($user['current_period_end']) ? strtotime($user['current_period_end'] . ' UTC') : null;
$renewDate   = $periodEndTs ? date('M j, Y', $periodEndTs) : 'your next renewal date';
$pendingPlan = $user['pending_plan'] ?? null;
$actionToken = new_action_token();
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

    <?php if ($flash['changed'] === 'gold'): ?>
        <div class="mt-6 rounded-xl border border-emerald-100 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
            ✅ Upgrade complete. Gold VIP is unlocked and <?= e(money($diffCents)) ?> was charged today.
            Your next renewal is <?= e(money($goldCents)) ?>.
        </div>
    <?php elseif ($flash['scheduled'] === 'silver'): ?>
        <div class="mt-6 rounded-xl border border-emerald-100 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
            ✅ Downgrade scheduled. You keep Gold VIP until <strong><?= e($renewDate) ?></strong>, then switch to Silver.
        </div>
    <?php elseif ($flash['kept'] === 'gold'): ?>
        <div class="mt-6 rounded-xl border border-emerald-100 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
            ✅ Your scheduled downgrade was canceled. You will stay on Gold VIP.
        </div>
    <?php endif; ?>
    <?php if ($flash['error'] !== ''): ?>
        <div class="mt-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"><?= e($flash['error']) ?></div>
    <?php endif; ?>

    <section class="mt-6 rounded-2xl border border-emerald-100 bg-white p-6 shadow-sm">
        <h2 class="text-lg font-bold">Change plan</h2>

        <?php if ($user['membership_plan'] === 'gold' && $pendingPlan === 'silver'): ?>

            <p class="mt-2 text-sm text-emerald-700/80">
                Your plan will switch to <strong>Silver</strong> on <strong><?= e($renewDate) ?></strong>
                and renew at <?= e(money($silverCents)) ?> / month. Until then you keep all Gold benefits.
            </p>
            <form action="change-plan.php" method="POST" data-once class="mt-4">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action_token" value="<?= e($actionToken) ?>">
                <input type="hidden" name="action" value="keep_gold">
                <button type="submit" class="rounded-xl border border-emerald-600 px-5 py-3 text-sm font-semibold text-emerald-600 hover:bg-emerald-50">
                    Keep Gold VIP (cancel downgrade)
                </button>
            </form>

        <?php elseif ($user['membership_plan'] === 'silver'): ?>

            <p class="mt-2 text-sm text-emerald-700/80">
                Upgrade to Gold VIP for <?= e(money($diffCents)) ?> today. Your billing date stays the same.
            </p>
            <button type="button" data-open-dialog="upgradeDialog"
                    class="mt-4 rounded-xl bg-emerald-600 px-5 py-3 text-sm font-semibold text-white hover:bg-emerald-700">
                Upgrade to Gold VIP
            </button>

            <dialog id="upgradeDialog" class="w-full max-w-md rounded-2xl border border-emerald-100 p-0 text-emerald-800 shadow-xl backdrop:bg-black/40">
                <form action="change-plan.php" method="POST" data-once class="p-6">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action_token" value="<?= e($actionToken) ?>">
                    <input type="hidden" name="action" value="upgrade">

                    <h3 class="text-xl font-extrabold">Confirm upgrade to Gold VIP</h3>
                    <dl class="mt-4 divide-y divide-emerald-100 rounded-xl border border-emerald-100 text-sm">
                        <div class="flex justify-between px-4 py-3">
                            <dt>Gold VIP <?= e(money($goldCents)) ?> − Silver <?= e(money($silverCents)) ?></dt>
                            <dd class="font-semibold"><?= e(money($diffCents)) ?></dd>
                        </div>
                        <div class="flex justify-between bg-emerald-50 px-4 py-3 font-bold">
                            <dt>Charged to your card today</dt>
                            <dd><?= e(money($diffCents)) ?></dd>
                        </div>
                        <div class="flex justify-between px-4 py-3">
                            <dt>Next renewal (<?= e($renewDate) ?>)</dt>
                            <dd class="font-semibold"><?= e(money($goldCents)) ?> / month</dd>
                        </div>
                    </dl>
                    <p class="mt-3 text-xs text-emerald-700/80">
                        This is a flat price difference, regardless of how many days are left in your period.
                        Gold benefits unlock as soon as the payment is confirmed. If the payment fails, nothing changes.
                    </p>

                    <div class="mt-5 flex justify-end gap-3">
                        <button type="button" data-close-dialog class="rounded-xl border border-emerald-100 px-4 py-2 text-sm font-semibold hover:bg-emerald-50">Cancel</button>
                        <button type="submit" class="rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
                            Pay <?= e(money($diffCents)) ?> &amp; upgrade
                        </button>
                    </div>
                </form>
            </dialog>

        <?php else: ?>

            <p class="mt-2 text-sm text-emerald-700/80">
                Downgrade to Silver at the end of your paid period. You keep Gold until <?= e($renewDate) ?>.
            </p>
            <button type="button" data-open-dialog="downgradeDialog"
                    class="mt-4 rounded-xl border border-emerald-600 px-5 py-3 text-sm font-semibold text-emerald-600 hover:bg-emerald-50">
                Downgrade to Silver
            </button>

            <dialog id="downgradeDialog" class="w-full max-w-md rounded-2xl border border-emerald-100 p-0 text-emerald-800 shadow-xl backdrop:bg-black/40">
                <form action="change-plan.php" method="POST" data-once class="p-6">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action_token" value="<?= e($actionToken) ?>">
                    <input type="hidden" name="action" value="downgrade">

                    <h3 class="text-xl font-extrabold">Confirm downgrade to Silver</h3>
                    <dl class="mt-4 divide-y divide-emerald-100 rounded-xl border border-emerald-100 text-sm">
                        <div class="flex justify-between px-4 py-3">
                            <dt>Charged or refunded today</dt>
                            <dd class="font-semibold">$0.00</dd>
                        </div>
                        <div class="flex justify-between px-4 py-3">
                            <dt>Gold VIP benefits stay active until</dt>
                            <dd class="font-semibold"><?= e($renewDate) ?></dd>
                        </div>
                        <div class="flex justify-between bg-emerald-50 px-4 py-3 font-bold">
                            <dt>From <?= e($renewDate) ?></dt>
                            <dd><?= e(money($silverCents)) ?> / month (Silver)</dd>
                        </div>
                    </dl>
                    <p class="mt-3 text-xs text-emerald-700/80">
                        <strong>No refund and no account credit</strong> is given for the unused part of your current Gold period.
                        You can cancel this downgrade any time before <?= e($renewDate) ?>.
                    </p>

                    <div class="mt-5 flex justify-end gap-3">
                        <button type="button" data-close-dialog class="rounded-xl border border-emerald-100 px-4 py-2 text-sm font-semibold hover:bg-emerald-50">Keep Gold</button>
                        <button type="submit" class="rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
                            Schedule downgrade
                        </button>
                    </div>
                </form>
            </dialog>

        <?php endif; ?>
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

<script>
document.querySelectorAll('[data-open-dialog]').forEach(function (b) {
    b.addEventListener('click', function () { document.getElementById(b.dataset.openDialog).showModal(); });
});
document.querySelectorAll('[data-close-dialog]').forEach(function (b) {
    b.addEventListener('click', function () { b.closest('dialog').close(); });
});
document.querySelectorAll('form[data-once]').forEach(function (f) {
    f.addEventListener('submit', function () {
        var btn = f.querySelector('button[type=submit]');
        if (btn) { btn.disabled = true; btn.textContent = 'Processing…'; }
    });
});
</script>
</body>
</html>
