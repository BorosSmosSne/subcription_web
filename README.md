# Membership App (PHP + MySQL + Stripe Subscriptions)

```bash
composer install
mysql -u root -p < schema.sql
export STRIPE_SECRET_KEY="sk_test_..."
export DB_USER="root"
export DB_PASS="your_mysql_password"
php -S localhost:8000

# second terminal
stripe login
stripe listen --forward-to localhost:8000/webhook.php
export STRIPE_WEBHOOK_SECRET="whsec_..."   # restart PHP after setting
```

Demo login: test@example.com / password
Test card: 4242 4242 4242 4242 (any future expiry, any CVC)
Enable the Customer Portal in Stripe Dashboard -> Settings -> Billing -> Customer portal.
