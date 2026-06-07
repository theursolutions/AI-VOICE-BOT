import os
import torch
import librosa
import soundfile as sf
import numpy as np
# ✅ Fix for torch.load weights_only issue
orig_load = torch.load
def torch_load_wrapper(*args, **kwargs):
    kwargs["weights_only"] = False
    return orig_load(*args, **kwargs)
torch.load = torch_load_wrapper

from TTS.api import TTS
from TTS.tts.configs.xtts_config import XttsConfig

# Allowlist the XTTS config class
torch.serialization.add_safe_globals([XttsConfig])

class VoiceResponseService:
    def __init__(self, model_name="tts_models/multilingual/multi-dataset/xtts_v2", gpu=False, upload_dir="voice_outputs"):
        self.model_name = model_name
        self.upload_dir = os.path.abspath(upload_dir)
        os.makedirs(self.upload_dir, exist_ok=True)
        try:
            print(f"Loading TTS model: {model_name} (GPU={gpu})...")
            self.tts = TTS(model_name=self.model_name, gpu=gpu)
            print(f"TTS model loaded successfully: {self.tts.model_name}")
        except Exception as e:
            raise RuntimeError(f"Failed to load XTTS-v2 model: {e}")
    def generate_voice(self, text: str, speaker_wav_path: str, language: str = "en") -> str:
        """
        Generates speech with cloned voice based on speaker_wav_path and user text.
        Returns the output WAV filepath.
        """
        if not os.path.exists(speaker_wav_path):
            raise ValueError(f"Speaker audio not found: {speaker_wav_path}")

        unique_filename = f"voice_{int(os.times()[4]*1000)}.wav"
        output_path = os.path.join(self.upload_dir, unique_filename)

        try:
            # self.tts.tts_to_file(
            #     text=text,
            #     file_path=output_path,
            #     speaker_wav=speaker_wav_path,
            #     language=language
            # )
            self.tts.tts_to_file(
                text=text,
                file_path=output_path,
                speaker_wav=speaker_wav_path,
                language=language,
                # ✅ Tweaks for realism
                # temperature=0.75,  # Controls randomness in tone
                # length_scale=1.05,  # Slightly longer pacing
                # speech_speed=0.97,  # Slower, more human pace
                # enable_text_splitting=True  # Avoids long monotone output

                temperature=0.75,  # Adds slight variation for realism
                top_p=0.9,  # Controls diversity
                top_k=50,  # Controls randomness in token choice
                repetition_penalty=1.05  # Avoids monotone repetition
            )

            # ✅ Post-process for realism
            audio, sr = librosa.load(output_path, sr=None)
            audio = librosa.util.normalize(audio)  # Normalize loudness
            # Light denoise (simple noise gate)
            audio = np.where(np.abs(audio) < 0.001, 0, audio)
            sf.write(output_path, audio, sr)
        except Exception as e:
            raise RuntimeError(f"XTTS-v2 generation failed: {e}")

        if not os.path.exists(output_path):
            raise RuntimeError(f"Voice file not generated: {output_path}")

        return output_path
