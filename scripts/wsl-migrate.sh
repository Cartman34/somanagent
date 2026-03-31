#!/usr/bin/env bash
# Author: Florent HAZARD <f.hazard@sowapps.com>
# =============================================================================
# wsl-migrate.sh  —  Migrate SoManAgent to the WSL native filesystem
# =============================================================================
# Description: Copy the project from /mnt/... to the WSL native filesystem for fast Docker I/O
#
# Problem: the project is in C:\... which Docker mounts via the 9P filesystem
# protocol over Hyper-V (/mnt/c/...). This causes 5-20x slower I/O.
#
# Fix: copy the project to ~/somanagent (WSL ext4), run Docker from there.
# Docker bind mounts from the WSL native filesystem use the virtio-fs driver
# and reach near-native Linux performance.
#
# Usage:  bash scripts/wsl-migrate.sh
#         bash scripts/wsl-migrate.sh --dest ~/projects/somanagent
# =============================================================================

set -euo pipefail

# Help option
if [[ "${1:-}" == "-h" || "${1:-}" == "--help" ]]; then
    echo "Copy the project from /mnt/... to the WSL native filesystem for fast Docker I/O"
    echo ""
    echo "Usage: bash scripts/wsl-migrate.sh"
    echo "Usage: bash scripts/wsl-migrate.sh --dest ~/projects/somanagent"
    exit 0
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC="$(dirname "$SCRIPT_DIR")"   # project root (current location)
DEST="${HOME}/somanagent"

# Parse optional --dest argument
while [[ $# -gt 0 ]]; do
    case "$1" in
        --dest) DEST="$2"; shift 2 ;;
        *)      echo "Unknown option: $1"; exit 1 ;;
    esac
done

# ── Already on WSL filesystem? ───────────────────────────────────────────────
if [[ ! "$SRC" =~ ^/mnt/[a-z]/ ]]; then
    echo "✓  Project is already on the WSL native filesystem:"
    echo "   $SRC"
    echo "   No migration needed."
    exit 0
fi

# ── Header ───────────────────────────────────────────────────────────────────
echo
echo "══════════════════════════════════════════════════════"
echo "   SoManAgent — Migrate to WSL native filesystem"
echo "══════════════════════════════════════════════════════"
echo
echo "   Source  (Windows NTFS, slow) : $SRC"
echo "   Dest    (WSL ext4, fast)     : $DEST"
echo

# ── Confirm ──────────────────────────────────────────────────────────────────
if [[ -d "$DEST" ]]; then
    read -rp "   $DEST already exists. Overwrite/update? [y/N] " confirm
    [[ "$confirm" =~ ^[Yy]$ ]] || { echo "   Cancelled."; exit 0; }
    echo
fi

# ── Copy ─────────────────────────────────────────────────────────────────────
echo "   Copying project files (this may take a moment)..."
echo

rsync -a --info=progress2 \
    --exclude='.git/' \
    --exclude='docker/data/' \
    --exclude='frontend/node_modules/' \
    --exclude='backend/vendor/' \
    --exclude='backend/var/cache/' \
    --exclude='backend/var/log/' \
    "$SRC/" "$DEST/"

# ── Git ──────────────────────────────────────────────────────────────────────
# If there's a .git directory, re-init the remote to preserve history
if [[ -d "$SRC/.git" ]]; then
    echo
    echo "   Copying .git history..."
    rsync -a "$SRC/.git/" "$DEST/.git/"
fi

# ── .env ─────────────────────────────────────────────────────────────────────
if [[ -f "$SRC/.env" ]] && [[ ! -f "$DEST/.env" ]]; then
    cp "$SRC/.env" "$DEST/.env"
    echo "   ✓ Copied .env"
fi

# ── Summary ──────────────────────────────────────────────────────────────────
DISTRO="${WSL_DISTRO_NAME:-Ubuntu}"
# Convert WSL path to Windows UNC path: /home/user/somanagent → \\wsl.localhost\Ubuntu\home\user\somanagent
WIN_UNC="\\\\wsl.localhost\\${DISTRO}$(echo "$DEST" | sed 's|/|\\|g')"

echo
echo "══════════════════════════════════════════════════════"
echo "   ✓ Migration complete!"
echo
echo "   Start working from the new location:"
echo "     cd $DEST"
echo "     php scripts/setup.php"
echo
echo "   Access files from Windows:"
echo "     Explorer : $WIN_UNC"
echo "     VS Code  : code $DEST   (requires WSL Remote extension)"
echo
echo "   Your original files at $SRC are untouched."
echo "   Once everything works, you can delete them."
echo "══════════════════════════════════════════════════════"
echo
