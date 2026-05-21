#!/bin/bash
set -e

export JWT_SECRET="${JWT_SECRET:-$(openssl rand -hex 32)}"
export ENCRYPTION_KEY="${ENCRYPTION_KEY:-$(openssl rand -hex 32)}"

# Wait for MySQL
echo "Waiting for MySQL..."
until mysqladmin ping -h "${DB_HOST:-db}" -u "${DB_USER:-campus}" -p"${DB_PASSWORD:-campus}" --silent 2>/dev/null; do
    sleep 2
done
echo "MySQL is ready."

exec "$@"
