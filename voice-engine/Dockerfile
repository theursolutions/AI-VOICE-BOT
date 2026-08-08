# syntax=docker/dockerfile:1
#
# CPU-only build. Coqui TTS (XTTS-v2) pins Python <3.12, so we use 3.11.
# Torch is installed from the CPU wheel index to avoid pulling ~2 GB of CUDA.
# For a GPU host, swap the base images for an nvidia/cuda runtime, drop the
# --index-url line, and set COQUI_USE_GPU=true / WHISPER_DEVICE=cuda.

###############################################################################
# Stage 1 — Builder: compile everything into a self-contained venv
###############################################################################
FROM python:3.11-slim AS builder

ENV PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1

RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      build-essential python3-dev pkg-config git libffi-dev \
 && rm -rf /var/lib/apt/lists/*

RUN python -m venv /opt/venv
ENV PATH="/opt/venv/bin:$PATH"
RUN pip install --upgrade pip setuptools wheel

# Install the EXACT resolved dependency set from the lock file.
#
# WHY A LOCK FILE: requirements.txt pins direct dependencies with open floors
# (`torch>=2.2`), so pip resolved whatever PyPI served that day. That produced
# three separate production outages, each of which reported "healthy":
#   • `openai`/`anthropic` absent entirely   -> every LLM provider crashed at boot
#   • transformers resolved to 5.x           -> XTTS ImportError (isin_mps_friendly)
#   • torch resolved to 2.13                 -> XTTS needs torchcodec, no voice
# The lock file makes a rebuild reproducible instead of a fresh version-drift
# hunt. requirements.txt remains the human-readable statement of intent.
#
# --extra-index-url is REQUIRED: the lock pins torch==2.8.0+cpu / torchaudio==
# 2.8.0+cpu, and those `+cpu` local-version builds exist only on PyTorch's own
# index. Without it pip either fails to find them or pulls the ~2GB CUDA build
# from PyPI onto a machine with no GPU.
#
# TO REGENERATE after intentionally changing requirements.txt:
#   docker compose build voice-engine            # builds from requirements.txt*
#   docker compose exec -T voice-engine pip freeze > voice-engine/requirements.lock.txt
#   # then commit the lock and rebuild to verify
#   (*temporarily point the RUN below at requirements.txt to re-resolve)
COPY requirements.lock.txt .
RUN pip install --extra-index-url https://download.pytorch.org/whl/cpu \
        -r requirements.lock.txt

###############################################################################
# Stage 2 — Runtime
###############################################################################
FROM python:3.11-slim AS runtime

# Runtime libs only: ffmpeg + libsndfile for audio I/O, curl for healthcheck.
RUN apt-get update \
 && apt-get install -y --no-install-recommends ffmpeg libsndfile1 curl \
 && apt-get clean && rm -rf /var/lib/apt/lists/*

ENV PATH="/opt/venv/bin:$PATH" \
    PYTHONUNBUFFERED=1 \
    HOME=/models \
    HF_HOME=/models/hf \
    TORCH_HOME=/models/torch \
    TTS_HOME=/models/tts \
    NUMBA_CACHE_DIR=/tmp/numba \
    COQUI_TOS_AGREED=1

COPY --from=builder /opt/venv /opt/venv

WORKDIR /app
COPY . .

# Model cache (/models volume) + per-turn audio dirs.
RUN mkdir -p /models /app/voice_outputs /app/uploads /tmp/numba

EXPOSE 8000

# Generous start period: first boot downloads ~2 GB of XTTS/Whisper weights.
HEALTHCHECK --interval=30s --timeout=10s --start-period=600s --retries=5 \
  CMD curl -fsS http://localhost:8000/healthz || exit 1

CMD ["uvicorn", "app.api.http:app", "--host", "0.0.0.0", "--port", "8000"]
