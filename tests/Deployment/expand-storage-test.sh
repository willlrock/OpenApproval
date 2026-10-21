#!/usr/bin/env bash
set -euo pipefail
repo=$(cd "$(dirname "$0")/../.." && pwd)
fixture=$(mktemp -d)
trap 'rm -rf -- "$fixture"' EXIT
mkdir -p "$fixture/bin" "$fixture/metadata"
export STORAGE_LOCK="$fixture/lock" STORAGE_METADATA_ROOT="$fixture/metadata" EXPANSION_FIXTURE="$fixture"
cat > "$fixture/bin/double" <<'STUB'
#!/usr/bin/env bash
set -euo pipefail
name=${0##*/}
case "$name" in
  id) echo 0 ;;
  findmnt) if [[ "$*" = *FSTYPE* ]]; then echo ext4; else echo /dev/ubuntu-vg/ubuntu-lv; fi ;;
  readlink) echo /dev/ubuntu-vg/ubuntu-lv ;;
  lsblk) if [ "$SCENARIO" = wrong-disk ]; then echo 85899345920; else echo 64424509440; fi ;;
  pvs) if [ "$SCENARIO" = wrong-pv ]; then echo /dev/sdb3; else echo /dev/sda3; fi ;;
  lvs) if [ -f "$EXPANSION_FIXTURE/expanded" ]; then echo 62277025792.00; else echo 20396900352.00; fi ;;
  sfdisk) echo fixture-partition-layout ;;
  vgcfgbackup) printf 'fixture-lvm-layout\n' > "$2" ;;
  growpart)
    echo "$name $*" >> "$EXPANSION_FIXTURE/mutations"
    if [ "$SCENARIO" = grow-failure ]; then echo 'Failed to grow'; exit 2; fi
    if [ "$SCENARIO" = no-change ]; then echo 'NOCHANGE: partition already fills the disk'; exit 1; fi ;;
  pvresize) echo "$name $*" >> "$EXPANSION_FIXTURE/mutations" ;;
  vgs) if [ "$SCENARIO" = already-expanded ]; then echo 0; else echo 9000; fi ;;
  lvextend|resize2fs)
    echo "$name $*" >> "$EXPANSION_FIXTURE/mutations"
    touch "$EXPANSION_FIXTURE/expanded" ;;
esac
STUB
chmod +x "$fixture/bin/double"
for tool in id findmnt readlink lsblk pvs lvs sfdisk vgcfgbackup growpart pvresize vgs lvextend resize2fs; do ln -s double "$fixture/bin/$tool"; done
export PATH="$fixture/bin:$PATH"
checks=0
for SCENARIO in success no-change already-expanded wrong-disk wrong-pv grow-failure; do
    export SCENARIO
    rm -f "$fixture/expanded" "$fixture/mutations"
    if bash "$repo/scripts/expand-ubuntu-root.sh" > "$fixture/result" 2>&1; then
        case "$SCENARIO" in wrong-disk|wrong-pv|grow-failure) echo "Unsafe expansion accepted: $SCENARIO" >&2; exit 1 ;; esac
        [ -f "$fixture/expanded" ]
        if [ "$SCENARIO" = already-expanded ]; then
            rg_command=resize2fs
        else
            rg_command='lvextend --resizefs --extents +100%FREE /dev/ubuntu-vg/ubuntu-lv'
        fi
        grep -Fq "$rg_command" "$fixture/mutations"
        [ -n "$(find "$fixture/metadata" -name partitions.dump -print -quit)" ]
    else
        case "$SCENARIO" in success|no-change|already-expanded) cat "$fixture/result" >&2; exit 1 ;; esac
        [ ! -f "$fixture/expanded" ]
        if [ "$SCENARIO" != grow-failure ]; then [ ! -f "$fixture/mutations" ]; fi
    fi
    checks=$((checks+1))
done
echo "$checks storage expansion scenarios passed"
