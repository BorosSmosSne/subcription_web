<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

use Stripe\Stripe;
use Stripe\Customer;
use Stripe\Checkout\Session as CheckoutSession;
use Stripe\Exception\ApiErrorException;

start_session();

function back_with_error(string $message): never
{
    redirect('index.php?error=' . urlencode($message));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}

if (!csrf_check($_POST['csrf'] ?? null)) {
    back_with_error('Invalid form submission. Please try again.');
}

$tier  = (string) ($_POST['tier'] ?? '');
$email = trim((string) ($_POST['email'] ?? ''));

if (!isset(PLANS[$tier])) {
    back_with_error('Please select a valid membership tier.');
}

$pdo  = db();
$user = null;

// 1. Must be logged in
if (empty($_SESSION['user_id'])) {
    redirect('login.php?mode=register');
}

$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user) {
    redirect('login.php');
}

$email = $user['email'];

// 2. Already subscribed (or card failing)? Manage it in the portal, don't buy twice
if (in_array($user['membership_status'], ['active', 'past_due'], true)) {
    redirect('customer-portal.php');
}

// 1. Resolve the local user (logged-in session wins over submitted email)
if (!empty($_SESSION['user_id'])) {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch() ?: null;
    if ($user) {
        $email = $user['email'];
    }
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    back_with_error('Please enter a valid email address.');
}

if (!$user) {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch() ?: null;
}

// Guest checkout: create a local account with a random password
if (!$user) {
    $name = ucfirst(strstr($email, '@', true) ?: 'Member');
    $ins  = $pdo->prepare('INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)');
    $ins->execute([$name, $email, password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT)]);

    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([(int) $pdo->lastInsertId()]);
    $user = $stmt->fetch();
}

// Already subscribed? Send them to the dashboard / billing portal instead of double-billing.
if ($user['membership_status'] === 'active' && !empty($_SESSION['user_id']) && (int) $_SESSION['user_id'] === (int) $user['id']) {
    redirect('dashboard.php');
}

Stripe::setApiKey(STRIPE_SECRET_KEY);

try {
    // 2. Create or find the Stripe Customer
    $customerId = $user['stripe_customer_id'];

    if (!$customerId) {
        $existing = Customer::all(['email' => $email, 'limit' => 1]);

        if (!empty($existing->data)) {
            $customerId = $existing->data[0]->id;
        } else {
            $customer = Customer::create([
                'email'    => $email,
                'name'     => $user['name'],
                'metadata' => ['user_id' => (string) $user['id']],
            ]);
            // $customerId = $customer->id;
            if ($clock = getenv('STRIPE_TEST_CLOCK')) {
                $customerParams['test_clock'] = $clock;
            }
            $customer   = Customer::create($customerParams);
            $customerId = $customer->id;
        }

        $upd = $pdo->prepare('UPDATE users SET stripe_customer_id = ? WHERE id = ?');
        $upd->execute([$customerId, $user['id']]);
    }

    // 3. Create the subscription Checkout Session
    $plan = PLANS[$tier];

    $session = CheckoutSession::create([
        'mode'                => 'subscription',
        'customer'            => $customerId,
        'client_reference_id' => (string) $user['id'],
        'line_items'          => [[
            'quantity'   => 1,
            'price_data' => [
                'currency'     => $plan['currency'],
                'unit_amount'  => $plan['amount'],
                // 'recurring'    => ['interval' => 'month'],
                'recurring'    => ['interval' => getenv('BILLING_INTERVAL') ?: 'month'],
                'product_data' => [
                    'name'        => $plan['name'],
                    'description' => $plan['description'],
                ],
            ],
        ]],
        'metadata'            => ['tier' => $tier],
        'subscription_data'   => [
            'metadata' => ['tier' => $tier, 'user_id' => (string) $user['id']],
        ],
        'success_url'         => BASE_URL . '/dashboard.php?session_id={CHECKOUT_SESSION_ID}',
        'cancel_url'          => BASE_URL . '/cancel.html',
    ]);

    // 4. Redirect to Stripe (HTTP 303)
    header('Location: ' . $session->url, true, 303);
    exit;
} catch (ApiErrorException $e) {
    error_log('Stripe checkout error: ' . $e->getMessage());
    back_with_error('We could not start checkout. Please try again in a moment.');
}
