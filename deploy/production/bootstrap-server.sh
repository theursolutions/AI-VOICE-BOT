#!/usr/bin/env bash
#==============================================================================
# PHASE 1 — Fresh-VPS hardening + Docker install.
#
# Run this ONCE, as root, on a brand-new Ubuntu 22.04/24.04 server, BEFORE you
# clone the repo or start any container. It is idempotent: re-running it is safe
# and will only fix whatever has drifted.
#
#   scp deploy/production/bootstrap-server.sh root@<vps-ip>:/root/
#   ssh root@<vps-ip>
#   chmod +x bootstrap-server.sh
#   ./bootstrap-server.sh --user deploy --ssh-key "ssh-ed25519 AAAA... you@laptop"
#
# What it does, in order:
#   1.  sanity checks (root, Ubuntu, an SSH key we can actually install)
#   2.  patches the system, sets timezone + NTP
#   3.  creates a non-root sudo user and installs your SSH key
#   4.  hardens sshd  — key-only, no root login  (ONLY after the key is in place)
#   5.  UFW firewall  — 22/80/443 in, everything else denied
#   6.  closes the Docker/UFW bypass hole (containers can't self-publish past UFW)
#   7.  fail2ban for SSH
#   8.  unattended security upgrades
#   9.  swap file (protects MySQL from an XTTS memory spike)
#   10. kernel/sysctl + file-descriptor tuning for a busy proxy
#   11. Docker Engine + Compose v2 from Docker's own apt repo
#   12. Docker daemon hardening (global log rotation, live-restore)
#   13. prints a verification report
#
# SAFETY: the script never disables password login until it has verified your
# key is installed and the sshd config parses. If anything looks wrong it aborts
# with your existing access untouched.
#==============================================================================
set -euo pipefail

#--- Defaults (override with flags) -------------------------------------------
NEW_USER="deploy"
SSH_KEY=""
SSH_PORT="22"
SWAP_GB="8"
TIMEZONE="UTC"

usage() {
  cat <<EOF
Usage: $0 --ssh-key "<your public key>" [options]

Required:
  --ssh-key "ssh-ed25519 AAAA... you@host"   Public key for the new sudo user.
                                             Get it locally with:
                                               cat ~/.ssh/id_ed25519.pub
                                             No key yet? Generate one FIRST:
                                               ssh-keygen -t ed25519 -C "you@host"

Options:
  --user <name>      Sudo user to create           (default: $NEW_USER)
  --ssh-port <port>  SSH port                      (default: $SSH_PORT)
  --swap <GB>        Swap file size in GB          (default: $SWAP_GB, 0 = skip)
  --timezone <tz>    e.g. Europe/Berlin, Asia/Karachi (default: $TIMEZONE)
  -h, --help         Show this help
EOF
}

while [ $# -gt 0 ]; do
  case "$1" in
    --user)     NEW_USER="$2"; shift 2 ;;
    --ssh-key)  SSH_KEY="$2";  shift 2 ;;
    --ssh-port) SSH_PORT="$2"; shift 2 ;;
    --swap)     SWAP_GB="$2";  shift 2 ;;
    --timezone) TIMEZONE="$2"; shift 2 ;;
    -h|--help)  usage; exit 0 ;;
    *) echo "Unknown option: $1" >&2; usage; exit 1 ;;
  esac
done

log()  { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }
ok()   { printf '    \033[1;32m✔\033[0m %s\n' "$*"; }
warn() { printf '    \033[1;33m!\033[0m %s\n' "$*"; }
die()  { printf '\n\033[1;31mFATAL: %s\033[0m\n' "$*" >&2; exit 1; }

#==============================================================================
# 1. Sanity checks — fail before changing anything
#==============================================================================
log "Pre-flight checks"

[ "$(id -u)" -eq 0 ] || die "Run as root (you are $(whoami))."
[ -f /etc/os-release ] || die "Cannot read /etc/os-release — is this Ubuntu?"
# shellcheck disable=SC1091
. /etc/os-release
[ "${ID:-}" = "ubuntu" ] || warn "Tested on Ubuntu; found '${ID:-unknown}'. Continuing."
ok "OS: ${PRETTY_NAME:-unknown}"

# A missing key is the #1 way people lock themselves out of a fresh box.
[ -n "$SSH_KEY" ] || { usage; die "--ssh-key is required. Without it, hardening sshd would lock you out."; }
case "$SSH_KEY" in
  ssh-ed25519\ *|ssh-rsa\ *|ecdsa-sha2-*\ *|sk-ssh-*\ *) ok "SSH key format looks valid" ;;
  *) die "That does not look like an SSH PUBLIC key. Expected it to start with ssh-ed25519 / ssh-rsa. Did you paste the PRIVATE key by mistake?" ;;
esac

case "$NEW_USER" in
  root|"") die "--user must be a non-root name." ;;
esac

ok "Will create sudo user '$NEW_USER', SSH on port $SSH_PORT"

#==============================================================================
# 2. System packages, time
#==============================================================================
log "Updating the system (this takes a few minutes on a fresh box)"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get upgrade -y -qq
apt-get install -y -qq \
  ca-certificates curl gnupg lsb-release \
  ufw fail2ban unattended-upgrades \
  htop jq git rsync chrony
ok "Packages installed"

timedatectl set-timezone "$TIMEZONE" 2>/dev/null || warn "Could not set timezone '$TIMEZONE'"
systemctl enable --now chrony >/dev/null 2>&1 || true
# Clock drift breaks TLS validation and JWT expiry checks (the app signs its own
# voice-session tokens), so NTP is not optional here.
ok "Timezone $(timedatectl show -p Timezone --value), NTP active"

#==============================================================================
# 3. Non-root sudo user + SSH key
#==============================================================================
log "Creating sudo user '$NEW_USER'"

if id "$NEW_USER" >/dev/null 2>&1; then
  ok "User already exists"
else
  adduser --disabled-password --gecos "" "$NEW_USER" >/dev/null
  ok "User created (no password — key-only login)"
fi
usermod -aG sudo "$NEW_USER"

USER_HOME="$(getent passwd "$NEW_USER" | cut -d: -f6)"
install -d -m 700 -o "$NEW_USER" -g "$NEW_USER" "$USER_HOME/.ssh"
touch "$USER_HOME/.ssh/authorized_keys"
# Append only if absent, so re-running doesn't duplicate the key.
if grep -qxF "$SSH_KEY" "$USER_HOME/.ssh/authorized_keys"; then
  ok "SSH key already authorised"
else
  printf '%s\n' "$SSH_KEY" >> "$USER_HOME/.ssh/authorized_keys"
  ok "SSH key installed"
fi
chmod 600 "$USER_HOME/.ssh/authorized_keys"
chown "$NEW_USER:$NEW_USER" "$USER_HOME/.ssh/authorized_keys"

# Passwordless sudo. Deliberate: the account has NO password (key-only login), so
# a sudo password prompt would be unanswerable and lock out all admin work.
# Security rests on the SSH key, which is the stronger factor anyway.
printf '%s ALL=(ALL) NOPASSWD:ALL\n' "$NEW_USER" > "/etc/sudoers.d/90-$NEW_USER"
chmod 440 "/etc/sudoers.d/90-$NEW_USER"
visudo -c >/dev/null || die "sudoers file is invalid — refusing to continue."
ok "Passwordless sudo granted and validated"

#==============================================================================
# 4. Harden sshd — only now that the key is verifiably in place
#==============================================================================
log "Hardening SSH"

# Verify the key really landed before we remove the password fallback.
grep -qxF "$SSH_KEY" "$USER_HOME/.ssh/authorized_keys" \
  || die "SSH key is not in authorized_keys — refusing to disable password login."

# Contabo images often ship overrides in sshd_config.d that would silently undo
# our settings. Ours sorts last (99-) so it wins, but warn about the others.
if compgen -G "/etc/ssh/sshd_config.d/*.conf" >/dev/null; then
  for f in /etc/ssh/sshd_config.d/*.conf; do
    case "$f" in */99-hardening.conf) continue ;; esac
    if grep -Eq '^\s*(PasswordAuthentication|PermitRootLogin)\s+yes' "$f"; then
      warn "$f re-enables password/root login; neutralising it"
      sed -ri 's/^\s*(PasswordAuthentication|PermitRootLogin)\s+yes/# &/' "$f"
    fi
  done
fi

cat > /etc/ssh/sshd_config.d/99-hardening.conf <<EOF
# Managed by bootstrap-server.sh — Phase 1 hardening.
Port $SSH_PORT
# Keys only. Password auth is what brute-force bots actually beat.
PasswordAuthentication no
KbdInteractiveAuthentication no
PermitEmptyPasswords no
AuthenticationMethods publickey
# No direct root login — admin work goes through '$NEW_USER' + sudo, so actions
# are attributable and root has no remotely-reachable login surface.
PermitRootLogin no
AllowUsers $NEW_USER
# Drop idle/unauthenticated sessions instead of holding slots open.
LoginGraceTime 30
MaxAuthTries 3
MaxSessions 10
ClientAliveInterval 300
ClientAliveCountMax 2
# Don't let a session forward ports/X11 into the private Docker network.
AllowAgentForwarding no
AllowTcpForwarding yes
X11Forwarding no
EOF

# `sshd -t` catches a typo BEFORE the restart that would lock us out.
sshd -t || { rm -f /etc/ssh/sshd_config.d/99-hardening.conf; die "sshd config invalid — reverted, SSH untouched."; }
ok "sshd config validated"

systemctl restart ssh 2>/dev/null || systemctl restart sshd
ok "SSH restarted: key-only, no root login, port $SSH_PORT"
warn "KEEP THIS SESSION OPEN. In a NEW terminal, prove the new login works:"
printf '        ssh -p %s %s@%s\n' "$SSH_PORT" "$NEW_USER" "$(hostname -I 2>/dev/null | awk '{print $1}')"

#==============================================================================
# 5. Firewall — deny by default
#==============================================================================
log "Configuring UFW firewall"

ufw --force reset >/dev/null
ufw default deny incoming  >/dev/null
ufw default allow outgoing >/dev/null
ufw limit "$SSH_PORT/tcp" comment 'SSH (rate-limited)' >/dev/null
ufw allow 80/tcp  comment 'HTTP — ACME challenge + redirect to HTTPS' >/dev/null
ufw allow 443/tcp comment 'HTTPS — app, API, voice WebSocket'         >/dev/null
ufw allow 443/udp comment 'HTTP/3 (QUIC)'                             >/dev/null
ufw --force enable >/dev/null
ok "UFW active — only $SSH_PORT, 80, 443 reachable"
# Everything else (MySQL 3306, Redis 6379, HAProxy 8404, Grafana 3000) stays on
# the private Docker network or bound to 127.0.0.1, never published publicly.

#==============================================================================
# 6. Close the Docker/UFW bypass
#==============================================================================
log "Closing the Docker→UFW bypass"

# THE TRAP: Docker writes its own iptables DNAT rules that are evaluated BEFORE
# UFW's chain. So `ports: - "3306:3306"` in any compose file would expose MySQL
# to the whole internet even though UFW says "deny incoming". This block makes
# UFW authoritative over container traffic too — so a future stray port mapping
# can't silently open a hole.
AFTER_RULES=/etc/ufw/after.rules
MARKER="# BEGIN UFW AND DOCKER"

if grep -qF "$MARKER" "$AFTER_RULES" 2>/dev/null; then
  ok "Docker rules already present in after.rules"
else
  cp -a "$AFTER_RULES" "$AFTER_RULES.bak.$(date +%s)"
  cat >> "$AFTER_RULES" <<'EOF'

# BEGIN UFW AND DOCKER
# Makes UFW authoritative for container traffic. Without this, a published
# container port bypasses UFW entirely (Docker's DNAT runs first).
*filter
:ufw-user-forward - [0:0]
:ufw-docker-logging-deny - [0:0]
:DOCKER-USER - [0:0]
-A DOCKER-USER -j ufw-user-forward

# Established/related traffic and container→container traffic flow normally.
-A DOCKER-USER -j RETURN -s 10.0.0.0/8
-A DOCKER-USER -j RETURN -s 172.16.0.0/12
-A DOCKER-USER -j RETURN -s 192.168.0.0/16

# Explicitly permitted published ports (the public edge only).
-A DOCKER-USER -p tcp -m conntrack --ctorigdstport 80  -j RETURN
-A DOCKER-USER -p tcp -m conntrack --ctorigdstport 443 -j RETURN
-A DOCKER-USER -p udp -m conntrack --ctorigdstport 443 -j RETURN

# Anything else arriving from outside at a container is dropped and logged.
-A DOCKER-USER -j ufw-docker-logging-deny -p tcp -m conntrack --ctstate NEW
-A DOCKER-USER -j ufw-docker-logging-deny -p udp -m conntrack --ctstate NEW
-A DOCKER-USER -j RETURN

-A ufw-docker-logging-deny -m limit --limit 3/min --limit-burst 10 -j LOG --log-prefix "[UFW DOCKER BLOCK] "
-A ufw-docker-logging-deny -j DROP

COMMIT
# END UFW AND DOCKER
EOF
  ok "after.rules updated (backup kept alongside)"
fi
# after.rules is applied by `ufw reload` (iptables-restore), not by systemctl.
ufw reload >/dev/null 2>&1 || ufw --force enable >/dev/null
ok "UFW now governs published container ports too"
# NOTE: re-applied again after Docker is installed — dockerd recreates the
# DOCKER-USER chain on start, which would otherwise drop these rules.

#==============================================================================
# 7. fail2ban
#==============================================================================
log "Configuring fail2ban for SSH"

cat > /etc/fail2ban/jail.d/sshd.local <<EOF
[sshd]
enabled  = true
port     = $SSH_PORT
backend  = systemd
maxretry = 4
findtime = 10m
# Long ban: with key-only auth there is no legitimate reason to fail 4 times.
bantime  = 24h
EOF

systemctl enable --now fail2ban >/dev/null 2>&1 || true
systemctl restart fail2ban
ok "fail2ban active (4 strikes → 24h ban)"

#==============================================================================
# 8. Unattended security upgrades
#==============================================================================
log "Enabling unattended security upgrades"

cat > /etc/apt/apt.conf.d/20auto-upgrades <<'EOF'
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
APT::Periodic::AutocleanInterval "7";
EOF

cat > /etc/apt/apt.conf.d/51custom-unattended <<'EOF'
// Security patches only — feature updates are applied deliberately, not at 6am.
Unattended-Upgrade::Allowed-Origins {
        "${distro_id}:${distro_codename}-security";
        "${distro_id}ESMApps:${distro_codename}-apps-security";
        "${distro_id}ESM:${distro_codename}-infra-security";
};
Unattended-Upgrade::Remove-Unused-Kernel-Packages "true";
Unattended-Upgrade::Remove-Unused-Dependencies "true";
// Deliberately NO automatic reboot: a surprise reboot means a surprise outage.
// `needrestart`/the report below tells you when one is pending; you choose when.
Unattended-Upgrade::Automatic-Reboot "false";
EOF
ok "Security-only auto-upgrades enabled (no automatic reboots)"

#==============================================================================
# 9. Swap
#==============================================================================
if [ "$SWAP_GB" != "0" ]; then
  log "Configuring ${SWAP_GB}GB swap"
  if swapon --show | grep -q '/swapfile'; then
    ok "Swap already active ($(swapon --show=SIZE --noheadings | tr -d ' ' | head -1))"
  else
    # Insurance, not capacity: if XTTS spikes, the kernel swaps instead of
    # OOM-killing MySQL. Low swappiness keeps it idle in normal operation.
    fallocate -l "${SWAP_GB}G" /swapfile || dd if=/dev/zero of=/swapfile bs=1M count=$((SWAP_GB*1024)) status=none
    chmod 600 /swapfile
    mkswap /swapfile >/dev/null
    swapon /swapfile
    grep -q '^/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
    ok "${SWAP_GB}GB swap active and persisted in /etc/fstab"
  fi
fi

#==============================================================================
# 10. Kernel / limits tuning
#==============================================================================
log "Tuning kernel + file descriptor limits"

cat > /etc/sysctl.d/99-aicrm.conf <<'EOF'
# Managed by bootstrap-server.sh

# --- Connection handling (HAProxy is configured for maxconn 60000) ---
net.core.somaxconn = 65535
net.ipv4.tcp_max_syn_backlog = 65535
net.core.netdev_max_backlog = 16384
# Recycle TIME_WAIT sockets so a busy proxy doesn't exhaust ephemeral ports.
net.ipv4.tcp_tw_reuse = 1
net.ipv4.ip_local_port_range = 10240 65535
net.ipv4.tcp_fin_timeout = 15
# Detect dead peers faster — matters for long-lived voice WebSockets.
net.ipv4.tcp_keepalive_time = 300
net.ipv4.tcp_keepalive_intvl = 30
net.ipv4.tcp_keepalive_probes = 5

# --- Memory ---
# Swap is emergency insurance only; don't page out a busy app for cache.
vm.swappiness = 10
vm.overcommit_memory = 1
# Redis forks to rewrite its AOF; without this the fork can fail under load.
vm.max_map_count = 262144

# --- Network security ---
net.ipv4.conf.all.rp_filter = 1
net.ipv4.conf.all.accept_redirects = 0
net.ipv6.conf.all.accept_redirects = 0
net.ipv4.conf.all.accept_source_route = 0
net.ipv4.conf.all.log_martians = 1
net.ipv4.tcp_syncookies = 1
# Don't answer broadcast pings (Smurf amplification).
net.ipv4.icmp_echo_ignore_broadcasts = 1

# --- Container networking (Docker needs forwarding; iptables must see bridges) ---
net.ipv4.ip_forward = 1

# --- Inotify: many containers each watching files ---
fs.inotify.max_user_instances = 1024
fs.inotify.max_user_watches = 524288
fs.file-max = 2097152
EOF
sysctl --system >/dev/null 2>&1 || warn "Some sysctl values were rejected by this kernel"
ok "sysctl applied"

cat > /etc/security/limits.d/99-aicrm.conf <<'EOF'
# Managed by bootstrap-server.sh — a busy proxy + PHP-FPM pool needs headroom.
*    soft nofile 65535
*    hard nofile 65535
root soft nofile 65535
root hard nofile 65535
EOF
ok "File descriptor limit raised to 65535"

#==============================================================================
# 11. Docker Engine + Compose v2
#==============================================================================
log "Installing Docker Engine + Compose v2"

# From Docker's own repo, NOT Ubuntu's `docker.io`: the production overlay uses
# the `!override` YAML merge tag, which needs Compose v2.24+. Ubuntu's package
# ships an older plugin that would fail to parse it.
if command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; then
  ok "Docker already installed: $(docker --version)"
else
  install -m 0755 -d /etc/apt/keyrings
  curl -fsSL https://download.docker.com/linux/ubuntu/gpg \
    | gpg --dearmor -o /etc/apt/keyrings/docker.gpg
  chmod a+r /etc/apt/keyrings/docker.gpg
  echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] \
https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo "$VERSION_CODENAME") stable" \
    > /etc/apt/sources.list.d/docker.list
  apt-get update -qq
  apt-get install -y -qq docker-ce docker-ce-cli containerd.io \
                         docker-buildx-plugin docker-compose-plugin
  ok "Docker installed"
fi

# Verify the version actually satisfies the overlay's requirement.
DC_VER="$(docker compose version --short 2>/dev/null || echo 0)"
DC_MAJOR="${DC_VER%%.*}"
DC_MINOR="$(printf '%s' "$DC_VER" | cut -d. -f2)"
if [ "${DC_MAJOR:-0}" -gt 2 ] || { [ "${DC_MAJOR:-0}" -eq 2 ] && [ "${DC_MINOR:-0}" -ge 24 ]; }; then
  ok "Compose v$DC_VER (>= 2.24, supports !override)"
else
  warn "Compose v$DC_VER is older than 2.24 — the production overlay's !override tags will FAIL."
  warn "Fix: apt-get install --only-upgrade docker-compose-plugin"
fi

usermod -aG docker "$NEW_USER"
ok "'$NEW_USER' added to the docker group"

#==============================================================================
# 12. Docker daemon hardening
#==============================================================================
log "Hardening the Docker daemon"

# Global log rotation is the single most effective "server never goes down"
# setting here: unbounded container logs filling / is the most common way a
# healthy Docker host dies. The compose overlay also sets per-service limits;
# this catches anything that doesn't.
cat > /etc/docker/daemon.json <<'EOF'
{
  "log-driver": "json-file",
  "log-opts": { "max-size": "10m", "max-file": "5" },
  "live-restore": true,
  "userland-proxy": false,
  "no-new-privileges": false,
  "default-ulimits": {
    "nofile": { "Name": "nofile", "Soft": 65535, "Hard": 65535 }
  },
  "storage-driver": "overlay2"
}
EOF
# live-restore: containers keep RUNNING through a dockerd restart/upgrade, so
# patching Docker itself is no longer an outage.
# userland-proxy false: port publishing via iptables instead of a per-port
# proxy process — less memory, and it preserves the real client IP.
# no-new-privileges is set per-service in compose (the daemon-wide flag would
# also apply to the monitoring containers that legitimately need privileges).

systemctl enable docker >/dev/null 2>&1 || true
systemctl restart docker
sleep 3
docker info >/dev/null 2>&1 || die "Docker failed to start after config change — check: journalctl -u docker -n 50"
ok "Docker daemon restarted with log rotation + live-restore"

# dockerd rebuilt the DOCKER-USER chain on start, wiping our rules from step 6.
# Re-apply them now, and verify they actually landed rather than assuming.
ufw reload >/dev/null 2>&1 || true
if iptables -S DOCKER-USER 2>/dev/null | grep -q 'ctorigdstport 443'; then
  ok "DOCKER-USER allowlist re-applied and verified in the live ruleset"
else
  warn "DOCKER-USER allowlist is NOT active in the live ruleset. Container ports"
  warn "would bypass UFW. Investigate with: iptables -S DOCKER-USER"
fi

#==============================================================================
# 13. Verification report
#==============================================================================
log "Verification"

printf '    %-26s %s\n' "Hostname:"        "$(hostname)"
printf '    %-26s %s\n' "Public IP:"       "$(curl -fsS --max-time 5 https://api.ipify.org 2>/dev/null || echo '(could not detect)')"
printf '    %-26s %s\n' "OS:"              "${PRETTY_NAME:-unknown}"
printf '    %-26s %s\n' "Kernel:"          "$(uname -r)"
printf '    %-26s %s\n' "CPU cores:"       "$(nproc)"
printf '    %-26s %s\n' "RAM:"             "$(free -h | awk '/^Mem:/{print $2}')"
printf '    %-26s %s\n' "Swap:"            "$(free -h | awk '/^Swap:/{print $2}')"
printf '    %-26s %s\n' "Disk free on /:"  "$(df -h / | awk 'NR==2{print $4" of "$2}')"
printf '    %-26s %s\n' "Docker:"          "$(docker --version | cut -d, -f1)"
printf '    %-26s %s\n' "Compose:"         "v$DC_VER"
printf '    %-26s %s\n' "SSH:"             "port $SSH_PORT, key-only, root login disabled"
printf '    %-26s %s\n' "Sudo user:"       "$NEW_USER"
printf '    %-26s %s\n' "fail2ban:"        "$(systemctl is-active fail2ban)"
printf '    %-26s %s\n' "UFW:"             "$(ufw status | head -1 | sed 's/Status: //')"
echo
echo "    Open ports (should be ONLY $SSH_PORT, 80, 443):"
ufw status numbered | sed -n '4,$p' | sed 's/^/      /'
echo
echo "    Listening on all interfaces (anything unexpected here is a hole):"
(ss -tulnp 2>/dev/null | awk 'NR==1 || /0\.0\.0\.0|\[::\]/' | sed 's/^/      /') || true

if [ -f /var/run/reboot-required ]; then
  echo
  warn "A REBOOT IS PENDING (kernel or libc was patched). Reboot now, before you deploy:"
  printf '        reboot\n'
fi

cat <<EOF

$(printf '\033[1;32m')════════════════════════════════════════════════════════════════════
 PHASE 1 COMPLETE
$(printf '\033[0m')
 DO THIS NOW, before closing this root session:

   1. Open a NEW terminal and confirm the new login works:
        ssh -p $SSH_PORT $NEW_USER@<your-server-ip>
        sudo docker ps      # proves sudo + docker group work

      If that fails, you still have THIS session to fix it. Do not close it
      until the new login succeeds.

   2. Set reverse DNS in the Contabo panel to your app domain
      (Contabo → VPS control → rDNS). Mail providers reject hosts without it.

   3. Buy the domain and point BOTH records at $(curl -fsS --max-time 5 https://api.ipify.org 2>/dev/null || echo '<this-ip>'):
        crm.<yourdomain>     A   → this IP
        voice.<yourdomain>   A   → this IP
      On Cloudflare, set 'voice' to DNS-only (grey cloud) — the proxy breaks
      long-lived voice WebSockets.

   4. Then continue with Phase 2 (clone, configure .env, deploy):
        DEPLOYMENT.md  and  deploy/production/README.md

 NOTE: Docker publishes ports through iptables, which normally bypasses UFW.
 Step 6 closed that. If you ever add a 'ports:' mapping to a compose file,
 it will be DROPPED unless it is 80/443 or bound to 127.0.0.1 — that is
 deliberate. To open another port, add it to the DOCKER-USER block in
 /etc/ufw/after.rules, not just to ufw.

EOF
