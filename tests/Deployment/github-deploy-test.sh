#!/usr/bin/env bash
set -euo pipefail
repo=$(cd "$(dirname "$0")/../.." && pwd)
fixture=$(mktemp -d)
trap 'rm -rf -- "$fixture"' EXIT
mkdir -p "$fixture/bin" "$fixture/app" "$fixture/tmp"
export FIXTURE="$fixture" TMPDIR="$fixture/tmp"
export EXPECT_SHA=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
export TEST_ORIGIN=git@github.com:willlrock/teg-approval.git TEST_HEAD="$EXPECT_SHA"
cat > "$fixture/bin/id" <<'STUB'
#!/usr/bin/env bash
echo 0
STUB
cat > "$fixture/bin/git" <<'STUB'
#!/usr/bin/env bash
set -eu
printf '%s\n' "$*" >> "$FIXTURE/calls"
case "$*" in
    'remote get-url origin') echo "$TEST_ORIGIN" ;;
    'fetch --no-tags origin master') exit "${FETCH_RESULT:-0}" ;;
    'rev-parse origin/master') echo "$TEST_HEAD" ;;
    "show $EXPECT_SHA:deploy.sh")
        cat <<'SCRIPT'
#!/usr/bin/env bash
set -eu
test "$DEPLOY_SHA" = "$EXPECT_SHA"
touch "$FIXTURE/deployed"
exit "${SCRIPT_RESULT:-0}"
SCRIPT
        ;;
    *) echo 'Unexpected git command' >&2; exit 99 ;;
esac
STUB
chmod +x "$fixture/bin/"*
# Isolate fixed system paths; execute the real parser, git arguments and script extraction.
sed -e "s|/var/www/teg-approval-bot|$fixture/app|g" \
    -e "s|^export PATH=.*|export PATH=$fixture/bin:\$PATH|" \
    "$repo/scripts/github-deploy.sh" > "$fixture/wrapper"
export PATH="$fixture/bin:$PATH"
reject() {
    if bash "$fixture/wrapper" "$@" > "$fixture/result" 2>&1; then
        echo 'Unsafe SSH command was accepted' >&2
        exit 1
    fi
    test ! -f "$fixture/deployed"
}
reject
for value in '' 'master' 'deploy master' 'deploy aaaaaaa' "deploy ${EXPECT_SHA^^}" \
    "deploy $EXPECT_SHA; id" "deploy $EXPECT_SHA extra" $'deploy aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\nid' \
    "deploy $EXPECT_SHA " " deploy $EXPECT_SHA"; do
    reject "$value"
done
reject "deploy $EXPECT_SHA" extra
test ! -e "$fixture/calls"
TEST_ORIGIN=https://github.com/other/repository.git reject "deploy $EXPECT_SHA"
! grep -q '^fetch' "$fixture/calls"
FETCH_RESULT=7 reject "deploy $EXPECT_SHA"
TEST_HEAD=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb bash "$fixture/wrapper" "deploy $EXPECT_SHA"
test ! -f "$fixture/deployed"
bash "$fixture/wrapper" "deploy $EXPECT_SHA"
test -f "$fixture/deployed"
rm "$fixture/deployed"
if SCRIPT_RESULT=12 bash "$fixture/wrapper" "deploy $EXPECT_SHA"; then
    echo 'Deployment failure was hidden' >&2
    exit 1
else
    test "$?" = 12
fi
test -z "$(find "$fixture/tmp" -mindepth 1 -print -quit)"
echo 'GitHub deployment command validation, repository guard, stale SHA and failure handling passed'
