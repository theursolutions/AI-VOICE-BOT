#!/usr/bin/env bash
# =============================================================================
# Phase 0 — install the Asterisk lab on Ubuntu 24.04 (WSL2 or a Linux VM).
#
#   sudo bash telephony/asterisk/install.sh
#
# Safe to re-run: it reinstalls our configs and restarts Asterisk, but keeps
# the passwords it generated the first time.
# =============================================================================
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CRED=/etc/asterisk/lab-credentials.env
CONFS=(pjsip.conf extensions.conf http.conf ari.conf rtp.conf modules.conf)

[[ $EUID -eq 0 ]] || { echo "Run with sudo: sudo bash $0"; exit 1; }

# The lab config serves ARI on plain HTTP and has test phones. That is fine
# inside WSL (not reachable from the network), not on a public server.
if ! grep -qi microsoft /proc/version && [[ "${LAB_ON_SERVER:-0}" != "1" ]]; then
    echo "This is the LAB config (ARI over plain HTTP on 8088, test phones)."
    echo "On a server, firewall ports 5060/8088 to your own IP first, then run:"
    echo "    sudo LAB_ON_SERVER=1 bash $0"
    exit 1
fi

echo "==> Installing Asterisk"
apt-get update -qq
DEBIAN_FRONTEND=noninteractive apt-get install -y -qq asterisk asterisk-core-sounds-en-gsm

echo "==> Lab credentials"
if [[ ! -f $CRED ]]; then
    gen() { head -c 15 /dev/urandom | base64 | tr -d '+/='; }
    umask 077
    cat >"$CRED" <<EOF
PASS_1001=$(gen)
PASS_1002=$(gen)
ARI_PASSWORD=$(gen)
EOF
fi
# shellcheck source=/dev/null
source "$CRED"

echo "==> Installing configs (originals kept as *.dist)"
for f in "${CONFS[@]}"; do
    dst=/etc/asterisk/$f
    [[ -f $dst && ! -f $dst.dist ]] && cp "$dst" "$dst.dist"
    sed -e "s/__PASS_1001__/$PASS_1001/" \
        -e "s/__PASS_1002__/$PASS_1002/" \
        -e "s/__ARI_PASSWORD__/$ARI_PASSWORD/" \
        "$HERE/conf/$f" >"$dst"
    chown asterisk:asterisk "$dst"
    chmod 640 "$dst"
done

echo "==> Installing the WebRTC test page"
DATADIR=$(awk -F'=> *' '/^astdatadir/ {print $2}' /etc/asterisk/asterisk.conf)
DATADIR=${DATADIR:-/usr/share/asterisk}
install -d "$DATADIR/static-http"
install -m 644 "$HERE/static/webrtc-lab.html" "$DATADIR/static-http/webrtc-lab.html"

echo "==> Restarting Asterisk"
if [[ -d /run/systemd/system ]]; then
    systemctl enable -q asterisk
    systemctl restart asterisk
else
    service asterisk restart
fi

# Asterisk takes a moment before its CLI answers.
for _ in $(seq 1 20); do
    asterisk -rx "core show version" >/dev/null 2>&1 && break
    sleep 0.5
done

echo
echo "==> Checks"
# Checks report problems; they never abort the script.
set +e +o pipefail
asterisk -rx "core show version"
asterisk -rx "pjsip show transports" | grep -E "transport-(udp|tcp|ws)" || echo "!! PJSIP transports missing"
asterisk -rx "pjsip show endpoints" | grep -E "Endpoint: +100[12]" || echo "!! lab endpoints missing"
asterisk -rx "http show status" | grep -E "/ws|/ari" || echo "!! HTTP routes missing"
echo "AudioSocket modules loaded: $(asterisk -rx 'module show like audiosocket' | grep -c audiosocket)"
asterisk -rx "module show like chan_sip.so" | grep -q "chan_sip.so" && echo "!! chan_sip is loaded (should not be)"

IP=$(hostname -I | awk '{print $1}')
cat <<EOF

==============================================================================
 Asterisk lab is up.   Server address: $IP
------------------------------------------------------------------------------
 MicroSIP (ext 1001)   Domain: $IP   User: 1001   Password: $PASS_1001
 Browser  (ext 1002)   http://localhost:8088/static/webrtc-lab.html
                       User: 1002   Password: $PASS_1002
 ARI                   http://localhost:8088/ari   User: ai-crm   Password: $ARI_PASSWORD
------------------------------------------------------------------------------
 Dial 600 = echo test · 1001/1002 = each other · 1122 = ARI app "ai-crm"
 Credentials are kept in $CRED
==============================================================================
EOF
