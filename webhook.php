<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

use Stripe\Webhook;
use Stripe\Exception\SignatureVerificationException;

$payload   = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

// 1. Verify the signature so only Stripe can call this endpoint
try {
    $event = Webhook::constructEvent($payload, $sigHeader, STRIPE_WEBHOOK_SECRET);
} catch (\UnexpectedValueException $e) {
    http_response_code(400);
    exit('Invalid payload');
} catch (SignatureVerificationException $e) {
    http_response_code(400);
    exit('Invalid signature');
}

$pdo = db();

try {
    switch ($event->type) {

        // First successful checkout: activate membership
        case 'checkout.session.completed':
            $session = $event->data->object;

            if ($session->mode !== 'subscription') {
                break;
            }
            if (!in_array($session->payment_status, ['paid', 'no_payment_required'], true)) {
                break;
            }

            $userId = (int) ($session->client_reference_id ?? 0);
            $tier   = (string) ($session->metadata->tier ?? '');

            if ($userId > 0 && in_array($tier, ['silver', 'gold'], true)) {
                $stmt = $pdo->prepare(
                    "UPDATE users
                        SET membership_plan = ?,
                            membership_status = 'active',
                            stripe_customer_id = ?,
                            stripe_subscription_id = ?
                      WHERE id = ?"
                );
                $stmt->execute([$tier, $session->customer, $session->subscription, $userId]);
            }
            break;

        // Every successful renewal: keep access active (also recovers past_due accounts)
        case 'invoice.payment_succeeded':
            $invoice = $event->data->object;

            if (!empty($invoice->customer)) {
                $stmt = $pdo->prepare(
                    "UPDATE users
                        SET membership_status = 'active'
                      WHERE stripe_customer_id = ?
                        AND membership_plan <> 'free'"
                );
                $stmt->execute([$invoice->customer]);
            }
            break;

        // Renewal payment failed: flag the account
        case 'invoice.payment_failed':
            $invoice = $event->data->object;

            if (!empty($invoice->customer)) {
                $stmt = $pdo->prepare(
                    "UPDATE users
                        SET membership_status = 'past_due'
                      WHERE stripe_customer_id = ?
                        AND membership_status = 'active'"
                );
                $stmt->execute([$invoice->customer]);
            }
            break;

        // Subscription canceled (by user in portal, or after failed retries)
        case 'customer.subscription.deleted':
            $subscription = $event->data->object;

            $stmt = $pdo->prepare(
                "UPDATE users
                    SET membership_status = 'canceled',
                        membership_plan = 'free',
                        pending_plan = NULL,
                        current_period_end = NULL,
                        stripe_subscription_id = NULL
                  WHERE stripe_subscription_id = ?
                     OR (stripe_customer_id = ? AND stripe_subscription_id IS NULL)"
            );
            $stmt->execute([$subscription->id, $subscription->customer]);
            break;

        // Subscription created / plan changed / renewed (incl. the scheduled downgrade firing)
        case 'customer.subscription.created':
        case 'customer.subscription.updated':
            $sub    = $event->data->object;
            $tier   = (string) ($sub->items->data[0]->price->metadata->tier
                      ?? $sub->metadata->tier ?? '');
            $end    = subscription_period_end($sub);
            $endSql = $end ? gmdate('Y-m-d H:i:s', $end) : null;

            if (in_array($tier, ['silver', 'gold'], true)
                && in_array($sub->status, ['active', 'trialing'], true)) {
                // Also clears pending_plan once the scheduled tier has actually taken effect.
                $stmt = $pdo->prepare(
                    "UPDATE users
                        SET membership_plan = ?,
                            membership_status = 'active',
                            current_period_end = COALESCE(?, current_period_end),
                            pending_plan = CASE WHEN pending_plan = ? THEN NULL ELSE pending_plan END
                      WHERE stripe_subscription_id = ?"
                );
                $stmt->execute([$tier, $endSql, $tier, $sub->id]);
            } elseif ($sub->status === 'past_due') {
                $stmt = $pdo->prepare(
                    "UPDATE users SET membership_status = 'past_due'
                      WHERE stripe_subscription_id = ?"
                );
                $stmt->execute([$sub->id]);
            }
            break;

        default:
            // Ignore unhandled event types
            break;
    }
} catch (\Throwable $e) {
    // Return 500 so Stripe retries delivery
    error_log('Webhook handler error [' . $event->type . ']: ' . $e->getMessage());
    http_response_code(500);
    exit('Webhook handler error');
}

http_response_code(200);
echo json_encode(['received' => true]);
