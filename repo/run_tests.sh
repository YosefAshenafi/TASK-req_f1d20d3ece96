#!/usr/bin/env bash
# run_tests.sh — Build, start stack, run PHPUnit API tests, tear down.
# Usage:  bash run_tests.sh
# Requirements: docker, docker compose (or docker-compose)
set -euo pipefail

COMPOSE="docker compose"
command -v docker &>/dev/null || { echo "ERROR: docker not found"; exit 1; }
docker compose version &>/dev/null 2>&1 || COMPOSE="docker-compose"

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT"

cleanup() {
  echo ""
  echo "==> Stopping stack…"
  $COMPOSE down --volumes --remove-orphans 2>/dev/null || true
}
trap cleanup EXIT

echo "==> Building images…"
$COMPOSE build --quiet

echo "==> Starting services (db + backend + nginx)…"
$COMPOSE up -d db backend nginx

echo "==> Waiting for backend to be ready…"
for i in $(seq 1 30); do
  if $COMPOSE exec -T backend curl -sf http://localhost:9000/ >/dev/null 2>&1 || \
     curl -sf http://localhost:3000/api/auth/login \
       -X POST -H "Content-Type: application/json" \
       -d '{"username":"x","password":"xxxxxxxxxx"}' >/dev/null 2>&1; then
    break
  fi
  sleep 2
done

echo "==> Running database seed…"
$COMPOSE exec -T backend php think db:seed 2>&1 || true

echo "==> Running PHPUnit tests…"
$COMPOSE exec -T backend \
  php vendor/bin/phpunit \
    --configuration /app/phpunit.xml \
    --testdox \
    2>&1

echo "==> Running frontend unit tests…"
docker run --rm -e TZ=UTC \
  -v "${ROOT}:/app" \
  node:18-alpine \
  sh -c "node /app/tests/frontend/test-fmt.js && node /app/tests/frontend/test-tags.js && node /app/tests/frontend/test-render.js"

echo "==> All tests complete."
