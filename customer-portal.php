<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

use Stripe\Stripe;
use Stripe\BillingPortal\Session as PortalSession;
use Stripe\Exception\ApiErrorException;

start_session();

if (empty($_SESSION['user_id'])) {
    redirect('login.php');
}

$stmt = db()->prepare('SELECT stripe_customer_id FROM users WHERE id = ?');
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user || empty($user['stripe_customer_id'])) {
    redirect('index.php?error=' . urlencode('No billing profile found. Please choose a plan first.'));
}

Stripe::setApiKey(STRIPE_SECRET_KEY);

try {
    $portal = PortalSession::create([
        'customer'   => $user['stripe_customer_id'],
        'return_url' => BASE_URL . '/dashboard.php',
    ]);

    header('Location: ' . $portal->url, true, 303);
    exit;
} catch (ApiErrorException $e) {
    error_log('Stripe portal error: ' . $e->getMessage());
    http_response_code(500);
    exit('Unable to open the billing portal right now. Make sure the Customer Portal is enabled in your Stripe Dashboard (Settings → Billing → Customer portal).');
}
