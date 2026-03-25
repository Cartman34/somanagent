#!/usr/bin/env bash
# Description: Verify PHP 8.4+ is installed in the current environment (WSL/Linux)
# Usage: bash scripts/check-php.sh
# Usage: wsl bash scripts/check-php.sh

set -e

REQUIRED_MAJOR=8
REQUIRED_MINOR=4

_install_php84_ubuntu() {
  echo "  → Installing PHP 8.4 via ppa:ondrej/php..."
  sudo apt-get update -qq
  sudo apt-get install -y software-properties-common
  sudo add-apt-repository -y ppa:ondrej/php
  sudo apt-get update -qq
  sudo apt-get install -y php8.4-cli php8.4-xml php8.4-curl php8.4-mbstring \
                          php8.4-pgsql php8.4-zip php8.4-intl
  echo "  ✓ PHP 8.4 installed."
}

if ! command -v php &>/dev/null; then
  echo "❌ PHP is not installed or not in PATH."

  # Offer auto-install on Ubuntu/Debian (typical WSL distro)
  if command -v apt-get &>/dev/null; then
    read -r -p "   Install PHP 8.4 now? [y/N] " answer
    if [[ "$answer" =~ ^[Yy]$ ]]; then
      _install_php84_ubuntu
    else
      echo "   Install PHP $REQUIRED_MAJOR.$REQUIRED_MINOR+: https://www.php.net/downloads"
      exit 1
    fi
  else
    echo "   Install PHP $REQUIRED_MAJOR.$REQUIRED_MINOR+: https://www.php.net/downloads"
    exit 1
  fi
fi

VERSION=$(php -r 'echo PHP_VERSION;')
MAJOR=$(php -r 'echo PHP_MAJOR_VERSION;')
MINOR=$(php -r 'echo PHP_MINOR_VERSION;')

if [ "$MAJOR" -lt "$REQUIRED_MAJOR" ] || \
   ([ "$MAJOR" -eq "$REQUIRED_MAJOR" ] && [ "$MINOR" -lt "$REQUIRED_MINOR" ]); then
  echo "❌ PHP $VERSION detected — SoManAgent requires PHP $REQUIRED_MAJOR.$REQUIRED_MINOR+"

  if command -v apt-get &>/dev/null; then
    read -r -p "   Install PHP 8.4 alongside current version? [y/N] " answer
    if [[ "$answer" =~ ^[Yy]$ ]]; then
      _install_php84_ubuntu
      # Switch default php to 8.4
      sudo update-alternatives --set php /usr/bin/php8.4 2>/dev/null || true
      echo "  ✓ Default PHP switched to 8.4."
    else
      exit 1
    fi
  else
    exit 1
  fi
fi

VERSION=$(php -r 'echo PHP_VERSION;')
echo "✓ PHP $VERSION detected"
exit 0
