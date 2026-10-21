#!/usr/bin/env bash
# Install root-owned as /usr/local/sbin/teg-approval-deploy. Called by a forced SSH command.
set -euo pipefail

if [ "$#" -ne 1 ] || [[ ! "$1" =~ ^deploy\ ([0-9a-f]{40})$ ]]; then
    echo 'Only deploy followed by a full commit SHA is accepted.' >&2
    exit 2
fi
DEPLOY_SHA=${BASH_REMATCH[1]}
[ "$(id -u)" = 0 ] || { echo 'Root deployment wrapper required.' >&2; exit 1; }
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
export GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=safe.directory GIT_CONFIG_VALUE_0=/var/www/teg-approval-bot
export GIT_SSH_COMMAND='ssh -i /etc/teg-approval-deploy/github-readonly -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile=/etc/teg-approval-deploy/github-known-hosts'
cd /var/www/teg-approval-bot
[ "$(git remote get-url origin)" = 'git@github.com:willlrock/teg-approval.git' ] || {
    echo 'Unexpected production repository.' >&2
    exit 1
}
git fetch --no-tags origin master
[ "$(git rev-parse origin/master)" = "$DEPLOY_SHA" ] || {
    echo 'Requested commit is no longer the master head; deployment skipped.'
    exit 0
}
script=$(mktemp)
trap 'rm -f -- "$script"' EXIT
git show "$DEPLOY_SHA:deploy.sh" > "$script"
DEPLOY_SHA="$DEPLOY_SHA" bash "$script"
