#!/usr/bin/env bash
# Explicit, one-time administrator operation for a 60 GiB Ubuntu LVM disk.
set -euo pipefail
umask 077
[ "$(id -u)" = 0 ] || { echo 'Run storage expansion as root.' >&2; exit 1; }
for tool in findmnt readlink lsblk pvs vgs lvs growpart pvresize lvextend resize2fs sfdisk vgcfgbackup flock; do command -v "$tool" >/dev/null; done
exec 9>"${STORAGE_LOCK:-/var/lock/teg-approval-bot-deploy.lock}"
flock -n 9 || { echo 'A deployment or backup is running; retry after it finishes.' >&2; exit 1; }
volume=/dev/ubuntu-vg/ubuntu-lv
[ "$(readlink -f "$(findmnt -n -o SOURCE /)")" = "$(readlink -f "$volume")" ]
[ "$(findmnt -n -o FSTYPE /)" = ext4 ]
[ "$(lsblk -bdno SIZE /dev/sda)" = 64424509440 ] || { echo 'Unexpected disk size; inspect the layout before expansion.' >&2; exit 1; }
[ "$(pvs --noheadings -o pv_name --select vg_name=ubuntu-vg | xargs)" = /dev/sda3 ] || { echo 'Unexpected volume group layout.' >&2; exit 1; }
before=$(lvs --noheadings --units b --nosuffix -o lv_size "$volume" | xargs)
[[ "$before" =~ ^[0-9]+([.][0-9]+)?$ ]]
metadata=$(mktemp -d "${STORAGE_METADATA_ROOT:-/root}/teg-root-metadata-XXXXXXXX")
sfdisk --dump /dev/sda > "$metadata/partitions.dump"
vgcfgbackup -f "$metadata/volume-group.conf" ubuntu-vg
if output=$(growpart /dev/sda 3 2>&1); then
    printf '%s\n' "$output"
else
    result=$?
    if [ "$result" != 1 ] || [[ "$output" != NOCHANGE:* ]]; then printf '%s\n' "$output" >&2; exit "$result"; fi
fi
pvresize /dev/sda3
free=$(vgs --noheadings -o vg_free_count ubuntu-vg | xargs)
[[ "$free" =~ ^[0-9]+$ ]]
if [ "$free" -gt 0 ]; then
    lvextend --resizefs --extents +100%FREE "$volume"
else
    # Complete the filesystem resize if a previous invocation stopped after LVM.
    resize2fs "$volume"
fi
after=$(lvs --noheadings --units b --nosuffix -o lv_size "$volume" | xargs)
awk -v before="$before" -v after="$after" 'BEGIN {exit !(after >= before && after >= 55 * 1024 * 1024 * 1024)}'
df -h /
echo "Storage expansion complete; partition and LVM metadata retained at $metadata"
