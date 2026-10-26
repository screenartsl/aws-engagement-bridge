#!/bin/bash
# Local account preparation only. Reviewed root execution required; never sudo
# from this file, install software, configure AWS, or touch production services.
set -euo pipefail
if [ "$(id -u)" -ne 0 ]; then
  echo 'Requires a root operator; no changes made.' >&2
  exit 1
fi
AUDIT_USER=muserelay-bridge-audit
AUDIT_HOME=/var/lib/muserelay-bridge-audit
if getent passwd "$AUDIT_USER" >/dev/null || getent group "$AUDIT_USER" >/dev/null || [ -e "$AUDIT_HOME" ]; then
  echo 'Account, group or home already exists; inspect manually before proceeding.' >&2
  exit 1
fi
useradd --system --user-group --create-home --home-dir "$AUDIT_HOME" \
  --shell /bin/bash "$AUDIT_USER"
chmod 0700 "$AUDIT_HOME"
install -d -m 0700 -o "$AUDIT_USER" -g "$AUDIT_USER" "$AUDIT_HOME/.aws"
echo 'Local account created. Password remains locked; no SSH keys, sudo grants or AWS configuration added.'
