#!/bin/sh
set -eu

# WP-CLI bootstrap script run by docker compose `wpcli` service.
# Idempotent: skips steps that already succeeded.

cd /var/www/html

if ! wp core is-installed --allow-root 2>/dev/null; then
    echo "[demo] installing WordPress"
    wp core install --allow-root \
        --url=http://localhost:${APP_PORT:-8080} \
        --title="Escalated Demo" \
        --admin_user=alice \
        --admin_email=alice@demo.test \
        --admin_password=password \
        --skip-email
fi

if ! wp plugin is-active escalated-wordpress --allow-root 2>/dev/null; then
    echo "[demo] activating escalated-wordpress plugin"
    wp plugin activate escalated-wordpress --allow-root
fi

echo "[demo] seeding additional users"
for u in "bob:bob@demo.test:editor" "carol:carol@demo.test:editor" "frank:frank@acme.example:subscriber" "grace:grace@acme.example:subscriber"; do
    user=$(echo "$u" | cut -d: -f1)
    email=$(echo "$u" | cut -d: -f2)
    role=$(echo "$u" | cut -d: -f3)
    wp user create "$user" "$email" --role="$role" --user_pass=password --allow-root 2>/dev/null \
        || echo "  $user exists"
done

echo "[demo] WP setup complete"
