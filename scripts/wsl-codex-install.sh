#!/usr/bin/env bash
# Author: Florent HAZARD <f.hazard@sowapps.com>
# Description: Install or upgrade OpenAI Codex CLI inside WSL
# Usage: bash scripts/wsl-codex-install.sh
# Usage: bash scripts/wsl-codex-install.sh --skip-login

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SKIP_LOGIN=0

for arg in "$@"; do
  case "$arg" in
    --skip-login)
      SKIP_LOGIN=1
      ;;
    *)
      echo "Unknown argument: $arg" >&2
      echo "Usage: bash scripts/wsl-codex-install.sh [--skip-login]" >&2
      exit 1
      ;;
  esac
done

if [[ -z "${WSL_DISTRO_NAME:-}" ]] && ! grep -qi microsoft /proc/version 2>/dev/null; then
  echo "This script must be run inside WSL." >&2
  exit 1
fi

echo "==> WSL distro: ${WSL_DISTRO_NAME:-unknown}"

if ! command -v apt-get >/dev/null 2>&1; then
  echo "This script requires apt-get on the WSL distribution." >&2
  exit 1
fi

if ! command -v sudo >/dev/null 2>&1; then
  echo "This script requires sudo to install Codex CLI globally." >&2
  exit 1
fi

if ! command -v curl >/dev/null 2>&1; then
  echo "==> Installing curl..."
  sudo apt-get update
  sudo apt-get install -y curl
fi

if ! command -v npm >/dev/null 2>&1; then
  echo "==> Installing Node.js and npm..."
  sudo apt-get update
  sudo apt-get install -y nodejs npm
fi

if ! command -v bwrap >/dev/null 2>&1; then
  echo "==> Installing bubblewrap..."
  sudo apt-get update
  sudo apt-get install -y bubblewrap
fi

if command -v codex >/dev/null 2>&1; then
  echo "==> Upgrading Codex CLI..."
  if ! codex --upgrade; then
    echo "==> Retrying upgrade with sudo..."
    sudo npm install -g @openai/codex
  fi
else
  echo "==> Installing Codex CLI..."
  sudo npm install -g @openai/codex
fi

echo "==> Verifying Codex installation..."
echo "codex path: $(command -v codex)"
codex --version

echo "bubblewrap path: $(command -v bwrap)"
bwrap --version

if [[ "$SKIP_LOGIN" -eq 0 ]]; then
  cat <<EOF

==> Next step: authenticate Codex
Run:
  codex --login

Then start Codex from the project root:
  cd "$ROOT_DIR"
  codex
EOF
else
  cat <<EOF

Codex CLI is installed.
Start it from the project root with:
  cd "$ROOT_DIR"
  codex
EOF
fi