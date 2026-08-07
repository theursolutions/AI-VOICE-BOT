"""Streaming Coqui XTTS-v2 wrapper.

The legacy :class:`VoiceResponseService` rendered the entire utterance
into a wav file before returning. The WS pipeline needs sub-sentence
latency, so this module exposes :meth:`stream` which uses
``model.inference_stream`` and yields raw PCM16 chunks as they're
produced. :meth:`synthesize_to_file` keeps the file-based behaviour
for the ``/tts`` HTTP route.
"""

from __future__ import annotations

import io
import logging
import os
import time
from pathlib import Path
from typing import Iterator, Optional

import numpy as np
import soundfile as sf
import torch

logger = logging.getLogger(__name__)


# torch.load weights_only patch (XTTS-v2 ships pickled configs)
_ORIG_TORCH_LOAD = torch.load


def _torch_load_wrapper(*args, **kwargs):  # noqa: D401
    kwargs.setdefault("weights_only", False)
    return _ORIG_TORCH_LOAD(*args, **kwargs)


torch.load = _torch_load_wrapper  # type: ignore[assignment]


class TTSService:
    """Streaming wrapper around XTTS-v2.

    Two modes:

    * :meth:`stream` — yields PCM16 byte chunks for the WS path.
    * :meth:`synthesize_to_file` — writes a wav file (used by ``/tts``
      and by the HTTP ``/llm/respond`` fallback when ``respond_with``
      includes audio).
    """

    def __init__(
        self,
        model_name: str = "tts_models/multilingual/multi-dataset/xtts_v2",
        gpu: bool = False,
        output_dir: str = "voice_outputs",
        checkpoint_dir: str = "",
    ) -> None:
        self.model_name = model_name
        self.gpu = gpu
        # When set, XTTS-v2 is loaded from local checkpoint files in this dir
        # (model.pth + config.json + vocab.json) rather than the Coqui
        # registry. This is how fine-tuned checkpoints — e.g. the Urdu
        # XTTS-v2-Urdu-FT — are run. The streaming + cloning code below is
        # identical; only the load path differs.
        self.checkpoint_dir = checkpoint_dir or ""
        self.output_dir = os.path.abspath(output_dir)
        os.makedirs(self.output_dir, exist_ok=True)
        self._tts = None  # high-level TTS API (used for file synth, registry mode)
        self._xtts = None  # low-level model for streaming (always set)
        self._gpt_cond_cache: dict = {}

    # -- model lifecycle ----------------------------------------------------
    def load(self) -> None:
        if self._xtts is not None or self._tts is not None:
            return
        if self.checkpoint_dir:
            self._load_from_checkpoint()
        else:
            self._load_from_registry()

    def _load_from_registry(self) -> None:
        from TTS.api import TTS  # local import; heavy
        from TTS.tts.configs.xtts_config import XttsConfig

        torch.serialization.add_safe_globals([XttsConfig])
        logger.info("Loading Coqui XTTS-v2: %s (gpu=%s)", self.model_name, self.gpu)
        self._tts = TTS(model_name=self.model_name, gpu=self.gpu)
        # The underlying torch model is exposed as `.synthesizer.tts_model`.
        try:
            self._xtts = self._tts.synthesizer.tts_model
        except AttributeError:  # pragma: no cover — defensive
            self._xtts = None

    def _load_from_checkpoint(self) -> None:
        """Load a fine-tuned XTTS-v2 checkpoint from a local directory.

        Expects ``config.json``, ``model.pth`` and ``vocab.json`` in
        ``self.checkpoint_dir`` (the standard XTTS fine-tune layout, e.g.
        the Urdu fine-tune). Only the low-level model is created; the
        high-level ``TTS`` helper isn't used in this mode, so
        :meth:`synthesize_to_file` falls back to ``model.inference``.
        """

        from TTS.tts.configs.xtts_config import XttsConfig
        from TTS.tts.models.xtts import Xtts

        torch.serialization.add_safe_globals([XttsConfig])
        config_path = os.path.join(self.checkpoint_dir, "config.json")
        if not os.path.exists(config_path):
            raise FileNotFoundError(
                f"XTTS checkpoint config not found: {config_path} "
                f"(expected config.json + model.pth + vocab.json in {self.checkpoint_dir})"
            )
        logger.info(
            "Loading fine-tuned XTTS-v2 from checkpoint dir=%s (gpu=%s)",
            self.checkpoint_dir,
            self.gpu,
        )
        config = XttsConfig()
        config.load_json(config_path)
        model = Xtts.init_from_config(config)
        model.load_checkpoint(
            config,
            checkpoint_dir=self.checkpoint_dir,
            use_deepspeed=False,
        )
        if self.gpu:
            model.cuda()
        model.eval()
        self._xtts = model
        self._tts = None

    @property
    def sample_rate(self) -> int:
        if self._xtts is not None:
            try:
                return int(self._xtts.config.audio.output_sample_rate)
            except Exception:  # noqa: BLE001
                pass
        return 24000  # XTTS-v2 default

    # -- streaming ----------------------------------------------------------
    def _conditioning(self, speaker_wav_path: str):
        """Cache GPT/diffusion conditioning per speaker for fast re-use."""

        if speaker_wav_path in self._gpt_cond_cache:
            return self._gpt_cond_cache[speaker_wav_path]
        assert self._xtts is not None
        gpt_cond_latent, speaker_embedding = self._xtts.get_conditioning_latents(
            audio_path=speaker_wav_path
        )
        self._gpt_cond_cache[speaker_wav_path] = (gpt_cond_latent, speaker_embedding)
        return gpt_cond_latent, speaker_embedding

    def stream(
        self,
        text: str,
        speaker_wav_path: str,
        language: str = "en",
        chunk_seconds: float = 0.6,
    ) -> Iterator[bytes]:
        """Yield PCM16 little-endian bytes as XTTS produces audio.

        Quality tuning notes — these directly affect how human the
        output sounds versus the default "wobbly tape" XTTS produces:

        * ``temperature=0.65`` — lower than the 0.75 default. Higher
          values give more expressive prosody but also more pitch
          drift / "wavy" output. 0.6-0.7 is the sweet spot for natural
          speech without going monotone.
        * ``repetition_penalty=2.0`` — sharply penalises stuttering,
          which is the #1 source of bad XTTS streaming output. The
          default of 1.0 leaves the model free to loop on certain
          phonemes when latency drops.
        * ``length_penalty=1.0`` — keeps phrasing balanced.
        * ``top_k=50``, ``top_p=0.85`` — moderate sampling that doesn't
          collapse the voice into a robotic monotone.
        * ``speed=1.0`` — explicit; some Coqui builds default this off.
        * ``enable_text_splitting=False`` — we already split sentences
          upstream in ws.py / phone.py before calling stream(). Letting
          XTTS split again creates audible seams between fragments.
        * ``chunk_seconds=0.6`` — bigger chunks = fewer boundaries =
          smoother audio. Latency tradeoff: ~200ms extra to first
          sound but the speech sounds far more coherent.

        Falls back to a single chunk if the underlying model doesn't
        expose ``inference_stream`` (older Coqui builds).
        """

        self.load()
        if not os.path.exists(speaker_wav_path):
            raise FileNotFoundError(f"speaker_wav not found: {speaker_wav_path}")

        if self._xtts is None or not hasattr(self._xtts, "inference_stream"):
            # Older Coqui — fall through to file synth which DOES use
            # quality params.
            logger.warning("XTTS inference_stream unavailable; falling back to one-shot synth")
            wav_path = self.synthesize_to_file(text, speaker_wav_path, language)
            audio, sr = sf.read(wav_path, dtype="int16")
            yield audio.tobytes()
            return

        gpt_cond_latent, speaker_embedding = self._conditioning(speaker_wav_path)
        chunk_size = int(self.sample_rate * chunk_seconds)

        stream = self._xtts.inference_stream(
            text,
            language,
            gpt_cond_latent,
            speaker_embedding,
            stream_chunk_size=chunk_size,
            # Quality knobs — see docstring for rationale.
            temperature=0.65,
            top_p=0.85,
            top_k=50,
            repetition_penalty=2.0,
            length_penalty=1.0,
            speed=1.0,
            enable_text_splitting=False,
        )

        # Per-chunk gain so XTTS's quiet output doesn't fade out on
        # phone lines. We aim for ~80% of max amplitude — loud enough
        # to feel present, headroom enough to avoid clipping when the
        # speaker emphasises a syllable. Computed PER CHUNK so we don't
        # need to buffer the whole utterance, but capped so quiet
        # chunks (silences, breaths) don't get pumped up to noise.
        TARGET_PEAK = 0.8
        MAX_GAIN    = 2.5

        for piece in stream:
            if piece is None:
                continue
            if isinstance(piece, torch.Tensor):
                np_audio = piece.detach().cpu().numpy()
            else:
                np_audio = np.asarray(piece)
            if np_audio.size == 0:
                continue

            # Per-chunk peak normalisation with a hard ceiling on gain.
            # XTTS streamed chunks routinely peak at 0.2-0.4 — multiplying
            # by ~2.0-2.5 makes the voice "present" instead of distant
            # without ever exceeding ±1.0.
            peak = float(np.max(np.abs(np_audio)))
            if peak > 1e-4:
                gain = min(TARGET_PEAK / peak, MAX_GAIN)
                np_audio = np_audio * gain

            np_audio = np.clip(np_audio, -1.0, 1.0)
            pcm16 = (np_audio * 32767.0).astype(np.int16).tobytes()
            if pcm16:
                yield pcm16

    # -- file synthesis -----------------------------------------------------
    def synthesize_to_file(
        self,
        text: str,
        speaker_wav_path: str,
        language: str = "en",
        output_path: Optional[str] = None,
    ) -> str:
        self.load()
        if not os.path.exists(speaker_wav_path):
            raise FileNotFoundError(f"speaker_wav not found: {speaker_wav_path}")

        if output_path is None:
            output_path = os.path.join(
                self.output_dir, f"voice_{int(time.time() * 1000)}.wav"
            )

        if self._tts is not None:
            # Registry mode — use the high-level helper.
            self._tts.tts_to_file(
                text=text,
                file_path=output_path,
                speaker_wav=speaker_wav_path,
                language=language,
                temperature=0.75,
                top_p=0.9,
                top_k=50,
                repetition_penalty=1.05,
            )
        else:
            # Checkpoint mode (e.g. Urdu fine-tune) — the high-level TTS
            # helper isn't loaded, so synthesize via the low-level model and
            # write the wav ourselves. Same conditioning/cloning path as
            # stream().
            assert self._xtts is not None
            gpt_cond_latent, speaker_embedding = self._conditioning(speaker_wav_path)
            out = self._xtts.inference(
                text=text,
                language=language,
                gpt_cond_latent=gpt_cond_latent,
                speaker_embedding=speaker_embedding,
                temperature=0.75,
                top_p=0.9,
                top_k=50,
                repetition_penalty=1.05,
                enable_text_splitting=True,
            )
            wav = np.asarray(out["wav"], dtype=np.float32)
            sf.write(output_path, wav, self.sample_rate)

        if not os.path.exists(output_path):
            raise RuntimeError(f"TTS file not generated: {output_path}")
        return output_path
