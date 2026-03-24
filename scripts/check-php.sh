#!/usr/bin/env bash
# Description: Vérifie que PHP 8.4+ est installé et accessible dans le PATH
# Usage: bash scripts/check-php.sh

set -e

REQUIRED_MAJOR=8
REQUIRED_MINOR=4

if ! command -v php &>/dev/null; then
  echo "❌ PHP n'est pas installé ou n'est pas dans le PATH."
  echo "   Installez PHP $REQUIRED_MAJOR.$REQUIRED_MINOR+ : https://www.php.net/downloads"
  exit 1
fi

VERSION=$(php -r 'echo PHP_VERSION;')
MAJOR=$(php -r 'echo PHP_MAJOR_VERSION;')
MINOR=$(php -r 'echo PHP_MINOR_VERSION;')

if [ "$MAJOR" -lt "$REQUIRED_MAJOR" ] || ([ "$MAJOR" -eq "$REQUIRED_MAJOR" ] && [ "$MINOR" -lt "$REQUIRED_MINOR" ]); then
  echo "❌ PHP $VERSION détecté — SoManAgent requiert PHP $REQUIRED_MAJOR.$REQUIRED_MINOR+"
  exit 1
fi

echo "✓ PHP $VERSION détecté"
exit 0
