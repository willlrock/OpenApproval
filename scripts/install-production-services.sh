#!/usr/bin/env bash
set -euo pipefail
source_dir=${1:?Candidate script directory is required}
fpm_service=${2:?PHP-FPM service is required}
[[ "$fpm_service" =~ ^php[0-9]+\.[0-9]+-fpm\.service$ ]]
[ "$(id -u)" = 0 ] || { echo 'Run service preparation as root.' >&2; exit 1; }
systemd_root=${SYSTEMD_ROOT:-/etc/systemd/system}
dropin="$systemd_root/$fpm_service.d"
install -d -m 755 "$dropin"
printf '[Service]\nKillSignal=SIGQUIT\nTimeoutStopSec=300\n' > "$dropin/90-teg-graceful-stop.conf"
chmod 0644 "$dropin/90-teg-graceful-stop.conf"
install -m 644 "$source_dir/ops/teg-approval-backup.service" "$systemd_root/teg-approval-backup.service"
install -m 644 "$source_dir/ops/teg-approval-backup.timer" "$systemd_root/teg-approval-backup.timer"
systemctl daemon-reload
case "$(systemctl show --property=KillSignal --value "$fpm_service")" in
    3|SIGQUIT) ;;
    *) echo 'Another service override prevents graceful PHP-FPM stop.' >&2; exit 1 ;;
esac
