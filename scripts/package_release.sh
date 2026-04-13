#!/usr/bin/env bash
set -Eeuo pipefail
outfile="${1:-release.tar.gz}"
tar   --exclude='.git'   --exclude='.github'   --exclude='storage/uploads/*'   --exclude='storage/submissions/*.json'   --exclude='docker'   -czf "$outfile" .
echo "Archive créée : $outfile"
