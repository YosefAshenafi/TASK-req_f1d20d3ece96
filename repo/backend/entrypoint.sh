#!/bin/bash
set -e

export JWT_SECRET="${JWT_SECRET:-$(openssl rand -hex 32)}"
export ENCRYPTION_KEY="${ENCRYPTION_KEY:-$(openssl rand -hex 32)}"

DB_HOST="${DB_HOST:-db}"
DB_USER="${DB_USER:-campus}"
DB_PASSWORD="${DB_PASSWORD:-campus}"

# Wait for MySQL to accept connections.
echo "Waiting for MySQL..."
until mysqladmin ping -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASSWORD" --skip-ssl --silent 2>/dev/null; do
    sleep 2
done
echo "MySQL is ready."

# Seed demo accounts.
#
# The schema and placeholder user rows are created by the MySQL init scripts
# (db/migrations/*.sql mounted into /docker-entrypoint-initdb.d) on first boot.
# `php think db:seed` then replaces the placeholder password hashes with real
# bcrypt hashes so the documented demo credentials authenticate.
#
# The DB healthcheck can report ready while the init scripts are still running,
# so the `users` table may not exist the instant the backend starts. The seed
# command is idempotent (it inserts missing rows and refreshes existing hashes),
# so we retry until it succeeds rather than failing the boot.
echo "Seeding database..."
seeded=0
for attempt in $(seq 1 30); do
    if php think db:seed; then
        seeded=1
        echo "Seed complete."
        break
    fi
    echo "Seed attempt ${attempt}/30 not ready yet (schema may still be initializing); retrying in 2s..."
    sleep 2
done
if [ "$seeded" -ne 1 ]; then
    echo "WARNING: database seed did not complete after retries; continuing startup."
fi

exec "$@"
