# Asterisk for production (deploy/production/docker-compose.prod.yml).
#
# SIP + WebRTC for client extensions and website "Call us" buttons, driven by
# the voice engine over ARI. Same Asterisk (Ubuntu 24.04's 20.x) and the same
# dialplan as the WSL lab (telephony/README.md); production-only settings live
# in conf-prod/. Runs on the host network: RTP needs a wide UDP range.
#
# No accounts are baked in. Client extensions and one-call website accounts
# are pushed over ARI at runtime and kept in astdb (the asterisk_data volume).
FROM ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive
RUN apt-get update \
 && apt-get install -y --no-install-recommends asterisk asterisk-core-sounds-en-gsm gettext-base \
 && rm -rf /var/lib/apt/lists/*

# Shared with the lab: dialplan, account storage, modules.
COPY conf/extensions.conf conf/sorcery.conf conf/modules.conf /etc/asterisk-templates/
# Production: no test phones, ARI password from the environment.
COPY conf-prod/ /etc/asterisk-templates/
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

ENTRYPOINT ["docker-entrypoint.sh"]
