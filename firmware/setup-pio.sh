#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")" && pwd)"
python3 -m venv --without-pip "$ROOT/.venv"
curl -sS https://bootstrap.pypa.io/get-pip.py | "$ROOT/.venv/bin/python"
"$ROOT/.venv/bin/pip" install --upgrade pip
"$ROOT/.venv/bin/pip" install platformio
echo "PlatformIO is in $ROOT/.venv. Build with: $ROOT/pio run"
