#!/usr/bin/env bash
# Existing system nginx/php-fpm site: /srv/rh/public, not a release symlink.
set -Eeuo pipefail
ROOT=/srv/rh
EXPECTED_HOST=ip-172-26-0-220
DOMAIN=rh.kohn.international
[[ "$(hostname -s)" == "$EXPECTED_HOST" ]] || { echo "Wrong RH instance; refusing deployment" >&2; exit 1; }
[[ -d "$ROOT/public" && -f "$ROOT/public/index.php" && -f "$ROOT/app/config.default.php" ]] || { echo "Missing RH app at $ROOT" >&2; exit 1; }
cd "$ROOT"
[[ "$(git rev-parse --show-toplevel)" == "$ROOT" ]] || { echo "RH document root is not a Git checkout" >&2; exit 1; }
case "$(git remote get-url origin)" in
  git@github.com:NathanRabeauFolio/RH.git|git@github.com:NathanRabeauFolio/RH|https://github.com/NathanRabeauFolio/RH.git|https://github.com/NathanRabeauFolio/RH) ;;
  *) echo "Unexpected Git origin; refusing deployment" >&2; exit 1 ;;
esac
[[ "$(git symbolic-ref --quiet --short HEAD)" == main ]] || { echo "Expected main branch" >&2; exit 1; }
[[ -z "$(git status --porcelain --untracked-files=no)" ]] || { echo "Tracked local changes detected; refusing deployment" >&2; exit 1; }
exec 9>"$(git rev-parse --git-dir)/rh-deploy.lock"
flock -n 9 || { echo "RH deployment already running" >&2; exit 1; }
git fetch origin main
target="${RELEASE_SHA:-$(git rev-parse origin/main)}"
[[ "$target" =~ ^[0-9a-f]{40}$ ]] || { echo "Invalid release SHA" >&2; exit 1; }
[[ "$(git rev-parse origin/main)" == "$target" ]] || { echo "Requested SHA is not current origin/main" >&2; exit 1; }
before="$(git rev-parse HEAD)"
git merge-base --is-ancestor "$before" "$target" || { echo "Local history diverged; refusing deployment" >&2; exit 1; }
tmp="$(mktemp -d)"
trap 'rm -rf -- "$tmp"' EXIT
git archive "$target" | tar -x -C "$tmp"
find "$tmp/app" "$tmp/public" "$tmp/scripts" -name '*.php' -print0 | xargs -0 -n1 php -l
# CLI validation uses the disposable archive, never the production storage.
php "$tmp/public/health.php" >/dev/null
php "$tmp/scripts/check_v2bis.php"
git merge --ff-only "$target"
verify_live() {
  local html health
  html="$(curl --fail --silent --show-error --max-time 15 --resolve "$DOMAIN:443:127.0.0.1" "https://$DOMAIN/")" || return 1
  grep -Fq 'styles.css?v=2bis' <<<"$html" || return 1
  grep -Fq 'data-user-id="laura"' <<<"$html" || return 1
  health="$(curl --fail --silent --show-error --max-time 15 --resolve "$DOMAIN:443:127.0.0.1" "https://$DOMAIN/health.php")" || return 1
  printf '%s' "$health" | php -r '$j=json_decode(stream_get_contents(STDIN), true); exit(($j["status"] ?? "") === "ok" && ($j["users"] ?? 0) === 6 ? 0 : 1);'
}
# PHP-FPM may revalidate its opcode cache after a short interval.
for attempt in 1 2 3 4 5; do
  if verify_live; then echo "RH release active: $target (HTTPS, V2 bis, Laura, 6 users)"; exit 0; fi
  sleep 2
done
if [[ "$before" != "$target" && "$(git rev-parse HEAD)" == "$target" ]]; then
  git reset --keep "$before"
  echo "Live checks failed; restored $before" >&2
else
  echo "Live checks failed; no new checkout changes to roll back" >&2
fi
exit 1
