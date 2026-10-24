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

$tier = (string) ($_POST['tier'] ?? '');
if (!isset(PLANS[$tier])) {
    back_with_error('Please select a valid membership tier.');
}

// 1. Must be logged in
if (empty($_SESSION['user_id'])) {
    redirect('login.php?mode=register');
}

$pdo  = db();
$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user) {
    redirect('login.php');
}

$email = $user['email'];

// 2. Already subscribed (or card failing)? Manage it from the dashboard / portal
if (in_array($user['membership_status'], ['active', 'past_due'], true)) {
    redirect('dashboard.php');
}

Stripe::setApiKey(STRIPE_SECRET_KEY);

try {
    // 3. Create or find the Stripe Customer
    $customerId = $user['stripe_customer_id'];

    if (!$customerId) {
        $existing = Customer::all(['email' => $email, 'limit' => 1]);

        if (!empty($existing->data)) {
            $customerId = $existing->data[0]->id;
        } else {
            $customerParams = [
                'email'    => $email,
                'name'     => $user['name'],
                'metadata' => ['user_id' => (string) $user['id']],
            ];

            // Attach to a test clock when testing renewals
            if ($clock = getenv('STRIPE_TEST_CLOCK')) {
                $customerParams['test_clock'] = $clock;
            }

            $customer   = Customer::create($customerParams);
            $customerId = $customer->id;
        }

        $upd = $pdo->prepare('UPDATE users SET stripe_customer_id = ? WHERE id = ?');
        $upd->execute([$customerId, $user['id']]);
    }

    // 4. Create the subscription Checkout Session (reusable Stripe Price)
    $session = CheckoutSession::create([
        'mode'                => 'subscription',
        'customer'            => $customerId,
        'client_reference_id' => (string) $user['id'],
        'line_items'          => [[
            'price'    => stripe_price_id($tier),
            'quantity' => 1,
        ]],
        'metadata'            => ['tier' => $tier],
        'subscription_data'   => [
            'metadata' => ['tier' => $tier, 'user_id' => (string) $user['id']],
        ],
        'success_url'         => BASE_URL . '/dashboard.php?session_id={CHECKOUT_SESSION_ID}',
        'cancel_url'          => BASE_URL . '/cancel.html',
    ]);

    // 5. Redirect to Stripe (HTTP 303)
    header('Location: ' . $session->url, true, 303);
    exit;

} catch (ApiErrorException $e) {
    error_log('Stripe checkout error: ' . $e->getMessage());
    back_with_error('We could not start checkout. Please try again in a moment.');
}
