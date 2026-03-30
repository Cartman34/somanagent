#!/usr/bin/env bash
# Description: Install Claude CLI inside the configured WSL distro for local project usage
# Usage: bash scripts/wsl-claude-install.sh

set -euo pipefail

DISTRO="Ubuntu-24.04"
PROJECT_DIR="/home/sowapps/projects/somanagent"

echo "==> Checking WSL distro..."
wsl -d "$DISTRO" -- bash -lc "echo 'WSL distro OK:' \$(uname -a)" >/dev/null

echo "==> Installing Claude Code in WSL..."
wsl -d "$DISTRO" -- bash -lic '
set -euo pipefail

if ! command -v curl >/dev/null 2>&1; then
  echo "Installing curl..."
  sudo apt-get update
  sudo apt-get install -y curl
fi

curl -fsSL https://claude.ai/install.sh | bash
'

echo "==> Verifying installation..."
wsl -d "$DISTRO" -- bash -lic '
set -euo pipefail

echo "claude path: $(command -v claude || true)"
echo "claude version:"
claude --version
'

cat <<EOF

Claude Code is installed in WSL.

To start it in your project:
wsl -d $DISTRO -- bash -lic "cd $PROJECT_DIR && claude"

Then inside Claude Code, enable remote control with:
/remote-control

Remote Control connects the Claude app or claude.ai/code to a Claude Code session
running on your machine, and execution/file access stay local.
EOF
