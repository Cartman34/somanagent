#!/usr/bin/env bash
# Description: Verify PHP 8.4+ is installed in the current environment (WSL/Linux)
# Usage: bash scripts/check-php.sh
# Usage: wsl bash scripts/check-php.sh

set -e

REQUIRED_MAJOR=8
REQUIRED_MINOR=4

# Detect whether stdin is a real terminal (interactive) or a pipe (called from PHP)
if [ -t 0 ]; then
  INTERACTIVE=true
else
  INTERACTIVE=false
fi

_install_php84_ubuntu() {
  echo "  → Installing PHP 8.4 via ppa:ondrej/php..."
  sudo apt-get update -qq
  sudo apt-get install -y software-properties-common
  sudo add-apt-repository -y ppa:ondrej/php
  sudo apt-get update -qq
  sudo apt-get install -y php8.4-cli php8.4-xml php8.4-curl php8.4-mbstring \
                          php8.4-pgsql php8.4-zip php8.4-intl
  # Make php8.4 the default
  sudo update-alternatives --set php /usr/bin/php8.4 2>/dev/null || true
  echo "  ✓ PHP 8.4 installed and set as default."
}

_ask_install() {
  local reason="$1"
  echo "$reason"

  if [ "$INTERACTIVE" = true ]; then
    read -r -p "   Install PHP 8.4 now? [y/N] " answer
    if [[ "$answer" =~ ^[Yy]$ ]]; then
      _install_php84_ubuntu
    else
      echo "   Manual install: https://www.php.net/downloads"
      exit 1
    fi
  else
    # Non-interactive (called from PHP passthru): install automatically
    echo "   Non-interactive mode — installing PHP 8.4 automatically..."
    _install_php84_ubuntu
  fi
}

# ── Check PHP is present ───────────────────────────────────────────────────────

if ! command -v php &>/dev/null; then
  if command -v apt-get &>/dev/null; then
    _ask_install "❌ PHP is not installed or not in PATH."
  else
    echo "❌ PHP is not installed or not in PATH."
    echo "   Install PHP $REQUIRED_MAJOR.$REQUIRED_MINOR+: https://www.php.net/downloads"
    exit 1
  fi
fi

# ── Check version ─────────────────────────────────────────────────────────────

VERSION=$(php -r 'echo PHP_VERSION;')
MAJOR=$(php -r 'echo PHP_MAJOR_VERSION;')
MINOR=$(php -r 'echo PHP_MINOR_VERSION;')

if [ "$MAJOR" -lt "$REQUIRED_MAJOR" ] || \
   ([ "$MAJOR" -eq "$REQUIRED_MAJOR" ] && [ "$MINOR" -lt "$REQUIRED_MINOR" ]); then

  if command -v apt-get &>/dev/null; then
    _ask_install "❌ PHP $VERSION detected — SoManAgent requires PHP $REQUIRED_MAJOR.$REQUIRED_MINOR+"
  else
    echo "❌ PHP $VERSION detected — SoManAgent requires PHP $REQUIRED_MAJOR.$REQUIRED_MINOR+"
    exit 1
  fi
fi

# ── All good ──────────────────────────────────────────────────────────────────

VERSION=$(php -r 'echo PHP_VERSION;')
echo "✓ PHP $VERSION"
exit 0
