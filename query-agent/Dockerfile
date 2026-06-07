FROM python:3.12-slim

RUN groupadd -r agent && useradd --no-log-init -r -g agent agent \
 && mkdir -p /data && chown agent:agent /data

WORKDIR /app

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY agent.py .

USER agent
VOLUME ["/data"]

HEALTHCHECK --interval=60s --timeout=10s --retries=3 \
  CMD python -c "import os, httpx; httpx.get(os.environ['LARAVEL_BASE_URL'].rstrip('/') + '/api/v1/agent/poll', headers={'Authorization': 'Bearer ' + open('/data/agent.token').read().strip()}, params={'wait': 0}, timeout=5).raise_for_status()"

CMD ["python", "-u", "agent.py"]
