#!/usr/bin/env bash
# scripts/import-skill.sh — Importe un skill depuis skills.sh
# Usage : scripts/import-skill.sh owner/skill-name
# Ex    : scripts/import-skill.sh anthropics/code-reviewer
set -e

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

if [ -z "$1" ]; then
  echo "Usage: $0 owner/skill-name"
  exit 1
fi

echo "▶ Import du skill $1..."
"$ROOT/scripts/console.sh" somanagent:skill:import "$1"
