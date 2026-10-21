#!/usr/bin/env bash
set -euo pipefail
root=$(cd "$(dirname "$0")/.." && pwd)
if [ -L "$root/current" ]; then root=$(realpath "$root/current"); fi
exec bash "$root/scripts/backup.sh" "$@"
