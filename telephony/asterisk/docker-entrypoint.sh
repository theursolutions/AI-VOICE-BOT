#!/bin/sh
# Fill the config templates from the environment, then run Asterisk in the
# foreground as the asterisk user.
set -eu
: "${ASTERISK_ARI_PASSWORD:?set ASTERISK_ARI_PASSWORD}"
: "${ASTERISK_ARI_USER:=ai-crm}"
export ASTERISK_ARI_USER ASTERISK_ARI_PASSWORD

for template in /etc/asterisk-templates/*.conf; do
    envsubst '${ASTERISK_ARI_USER} ${ASTERISK_ARI_PASSWORD}' < "$template" > "/etc/asterisk/$(basename "$template")"
done
chown -R asterisk:asterisk /var/lib/asterisk /var/spool/asterisk /var/log/asterisk /var/run/asterisk 2>/dev/null || true

exec asterisk -f -U asterisk -G asterisk
