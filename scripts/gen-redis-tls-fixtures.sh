#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DIR="${ROOT}/tests/fixtures/redis-tls"

mkdir -p "${DIR}"

if [[ -f "${DIR}/ca.crt" && -f "${DIR}/server.crt" && -f "${DIR}/server.key" ]]; then
  echo "Redis TLS fixtures already present in ${DIR}"
  exit 0
fi

openssl req -x509 -newkey rsa:2048 -sha256 -days 3650 -nodes \
  -keyout "${DIR}/ca.key" -out "${DIR}/ca.crt" \
  -subj "/CN=PureCache Test CA" 2>/dev/null

openssl req -newkey rsa:2048 -nodes \
  -keyout "${DIR}/server.key" -out "${DIR}/server.csr" \
  -subj "/CN=localhost" 2>/dev/null

openssl x509 -req -in "${DIR}/server.csr" \
  -CA "${DIR}/ca.crt" -CAkey "${DIR}/ca.key" -CAcreateserial \
  -out "${DIR}/server.crt" -days 3650 -sha256 2>/dev/null

rm -f "${DIR}/server.csr" "${DIR}/ca.srl"

echo "Wrote Redis TLS fixtures to ${DIR}"
