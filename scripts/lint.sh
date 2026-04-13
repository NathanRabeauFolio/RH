#!/usr/bin/env bash
set -Eeuo pipefail
find app public -name '*.php' -print0 | xargs -0 -n1 php -l
