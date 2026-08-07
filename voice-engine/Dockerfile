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

# CPU-only torch/torchaudio first; the rest resolve against it.
#
# PINNED, and it must stay pinned. From torch 2.9 coqui-tts requires the extra
# `torchcodec` package for audio I/O (torchaudio's own backends were removed),
# so an unpinned install silently grabs the newest torch and XTTS then dies at
# load time with:
#   ImportError: From Pytorch 2.9, the torchcodec library is required
# The container still reports healthy, so this surfaces only as "no voice".
# coqui-tts 0.27.5 declares torch>=2.2 with no upper bound, so 2.8.0 is well
# within its supported range. To move past 2.9 later, add torchcodec (matched to
# the torch version) rather than just bumping this line.
RUN pip install --index-url https://download.pytorch.org/whl/cpu \
        "torch==2.8.0" "torchaudio==2.8.0"

COPY requirements.txt .
RUN pip install -r requirements.txt

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
