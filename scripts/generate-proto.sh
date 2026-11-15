#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."
protoc_bin=${PROTOC:-protoc}
if [[ "$("$protoc_bin" --version)" != 'libprotoc 28.2' ]]; then
    echo 'Use protoc 28.2 to reproduce the checked-in PHP bindings.' >&2
    exit 1
fi

mkdir -p src/Generated
"$protoc_bin" --proto_path=proto --php_out=src/Generated proto/org/polypheny/prism/*.proto
