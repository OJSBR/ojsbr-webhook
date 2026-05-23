#!/usr/bin/env bash
set -euo pipefail

ORG="${1:-OJSBR}"
PACKAGE="${2:-ojsbr-webhook}"
ORG_LC=$(echo "$ORG" | tr '[:upper:]' '[:lower:]')

echo "Verificando escopos do gh..."
if ! gh auth status 2>&1 | grep -q 'read:packages'; then
  echo "O token atual não tem escopo read:packages."
  echo "Execute:"
  echo "  gh auth refresh -h github.com -s read:packages,write:packages"
  exit 1
fi

echo "Tornando ghcr.io/${ORG_LC}/${PACKAGE} público..."
gh api \
  --method PUT \
  "/orgs/${ORG}/packages/container/${PACKAGE}/visibility" \
  -f visibility=public

echo "Pacote publicado como público."
