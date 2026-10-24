<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

use Stripe\Stripe;
use Stripe\Subscription;
use Stripe\SubscriptionSchedule;
use Stripe\Invoice;
use Stripe\InvoiceItem;
use Stripe\Exception\ApiErrorException;

/*
 * Business rules
 * --------------
 * UPGRADE  (Silver -> Gold): charge the flat price difference NOW (Gold - Silver = $15.00).
 *          Gold is unlocked only after Stripe confirms the invoice is paid.
 *          The billing date does not move; the next renewal is billed at the Gold price.
 * DOWNGRADE (Gold -> Silver): no refund, no credit. Gold stays until the end of the paid
 *          period; a Stripe Subscription Schedule switches the price to Silver on renewal.
 * KEEP GOLD: cancels a scheduled downgrade.
 */

start_session();

function go(string $query): never
{
    redirect('dashboard.php?' . $query);
}

function fail(string $message): never
{
    go('error=' . urlencode($message));
}

/** Remove an unpaid invoice so a failed upgrade leaves nothing behind. */
function discard_invoice(?string $invoiceId): void
{
    if (!$invoiceId) {
        return;
    }
    try {
        $inv = Invoice::retrieve($invoiceId);
        if ($inv->status === 'draft') {
            $inv->delete();
        } elseif ($inv->status === 'open') {
            $inv->voidInvoice();
        }
    } catch (\Throwable $e) {
        error_log('discard_invoice failed: ' . $e->getMessage());
    }
}

function ref_id($value): ?string
{
    if (is_string($value) && $value !== '') {
        return $value;
    }
    if (is_object($value) && !empty($value->id)) {
        return (string) $value->id;
    }
    return null;
}

// ---------------------------------------------------------------- guards
if (empty($_SESSION['user_id'])) {
    redirect('login.php');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check($_POST['csrf'] ?? null)) {
    fail('Invalid request.');
}

$action = (string) ($_POST['action'] ?? '');
if (!in_array($action, ['upgrade', 'downgrade', 'keep_gold'], true)) {
    fail('Unknown action.');
}

$token = consume_action_token($_POST['action_token'] ?? null);
if ($token === null) {
    fail('That request was already processed or has expired. Please try again.');
}

$pdo  = db();
$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user || $user['membership_status'] !== 'active' || empty($user['stripe_subscription_id'])) {
    redirect('index.php?error=' . urlencode('You need an active subscription first.'));
}

Stripe::setApiKey(STRIPE_SECRET_KEY);

try {
    $sub = Subscription::retrieve($user['stripe_subscription_id']);

    if ($sub->status !== 'active') {
        fail('Your subscription is not active. Please update your card in Manage Billing.');
    }

    $item        = $sub->items->data[0];
    $currentTier = (string) ($item->price->metadata->tier ?? $user['membership_plan']);
    $periodEnd   = subscription_period_end($sub);
    $scheduleId  = ref_id($sub->schedule ?? null);
    $customerId  = (string) $user['stripe_customer_id'];

    // ====================================================== UPGRADE
    if ($action === 'upgrade') {
        if ($currentTier !== 'silver') {
            fail('Upgrade is only available from the Silver plan.');
        }

        $diff = PLANS['gold']['amount'] - PLANS['silver']['amount'];   // 1500 = $15.00
        $pm   = ref_id($sub->default_payment_method ?? null);

        // 1) Charge first. Nothing changes on the subscription until this succeeds.
        $invoiceId = null;
        try {
            $invoice = Invoice::create([
                'customer'                       => $customerId,
                'collection_method'              => 'charge_automatically',
                'auto_advance'                   => false,
                'pending_invoice_items_behavior' => 'exclude',
                'description'                    => 'Upgrade from Silver to Gold VIP (price difference)',
                'metadata'                       => [
                    'type'            => 'tier_upgrade',
                    'user_id'         => (string) $user['id'],
                    'subscription_id' => $sub->id,
                    'to_tier'         => 'gold',
                ],
            ] + ($pm ? ['default_payment_method' => $pm] : []), ['idempotency_key' => 'upg-inv-' . $token]);
            $invoiceId = $invoice->id;

            InvoiceItem::create([
                'customer'    => $customerId,
                'invoice'     => $invoice->id,
                'amount'      => $diff,
                'currency'    => PLANS['gold']['currency'],
                'description' => 'Gold VIP upgrade: ' . money(PLANS['gold']['amount']) . ' - ' . money(PLANS['silver']['amount']),
            ], ['idempotency_key' => 'upg-item-' . $token]);

            $invoice = $invoice->finalizeInvoice();
            $invoice = $invoice->pay($pm ? ['payment_method' => $pm] : []);

            if ($invoice->status !== 'paid') {
                throw new \RuntimeException('Invoice not paid, status: ' . $invoice->status);
            }
        } catch (ApiErrorException | \RuntimeException $e) {
            error_log('Upgrade payment failed: ' . $e->getMessage());
            discard_invoice($invoiceId);
            fail('Your card could not be charged ' . money($diff) . '. Nothing was changed. Update your card in Manage Billing and try again.');
        }

        // 2) Paid. Move the subscription to the Gold price (no proration, billing date unchanged).
        $swapOk = true;
        try {
            if ($scheduleId) {
                SubscriptionSchedule::retrieve($scheduleId)->release();
            }
            Subscription::update($sub->id, [
                'items'              => [['id' => $item->id, 'price' => stripe_price_id('gold')]],
                'proration_behavior' => 'none',
                'metadata'           => ['tier' => 'gold', 'user_id' => (string) $user['id']],
            ]);
        } catch (ApiErrorException $e) {
            $swapOk = false;
            error_log('CRITICAL: upgrade PAID (invoice ' . $invoice->id . ') but subscription price swap failed: ' . $e->getMessage());
        }

        // 3) Unlock Gold right away, because payment is confirmed.
        $upd = $pdo->prepare(
            "UPDATE users
                SET membership_plan = 'gold',
                    pending_plan = NULL,
                    current_period_end = COALESCE(?, current_period_end)
              WHERE id = ?"
        );
        $upd->execute([$periodEnd ? gmdate('Y-m-d H:i:s', $periodEnd) : null, $user['id']]);

        if (!$swapOk) {
            go('changed=gold&error=' . urlencode('Gold is unlocked, but we could not update your renewal price. Please contact support.'));
        }
        go('changed=gold');
    }

    // ====================================================== DOWNGRADE (scheduled)
    if ($action === 'downgrade') {
        if ($currentTier !== 'gold') {
            fail('Downgrade is only available from the Gold plan.');
        }

        $schedule = $scheduleId
            ? SubscriptionSchedule::retrieve($scheduleId)
            : SubscriptionSchedule::create(['from_subscription' => $sub->id]);

        $phase0 = $schedule->phases[0];

        SubscriptionSchedule::update($schedule->id, [
            'end_behavior' => 'release',
            'phases'       => [
                // Phase 1: keep the current Gold price until the paid period ends.
                [
                    'items'              => [['price' => $item->price->id, 'quantity' => 1]],
                    'start_date'         => $phase0->start_date,
                    'end_date'           => $phase0->end_date,
                    'proration_behavior' => 'none',
                ],
                // Phase 2: Silver price from the next renewal onward. No refund, no credit.
                [
                    'items'              => [['price' => stripe_price_id('silver'), 'quantity' => 1]],
                    'iterations'         => 1,
                    'proration_behavior' => 'none',
                ],
            ],
            'metadata' => ['pending_tier' => 'silver', 'user_id' => (string) $user['id']],
        ]);

        $endTs = $periodEnd ?? (int) $phase0->end_date;
        $upd   = $pdo->prepare("UPDATE users SET pending_plan = 'silver', current_period_end = ? WHERE id = ?");
        $upd->execute([gmdate('Y-m-d H:i:s', $endTs), $user['id']]);

        go('scheduled=silver');
    }

    // ====================================================== KEEP GOLD (cancel scheduled downgrade)
    if ($scheduleId) {
        SubscriptionSchedule::retrieve($scheduleId)->release();
    }
    $upd = $pdo->prepare('UPDATE users SET pending_plan = NULL WHERE id = ?');
    $upd->execute([$user['id']]);
    go('kept=gold');

} catch (ApiErrorException $e) {
    error_log('Plan change error [' . $action . ']: ' . $e->getMessage());
    fail('Could not change your plan right now. Please try again in a moment.');
}
