import sys, glob
from faster_whisper import WhisperModel
m = WhisperModel("base", device="cpu", compute_type="int8")
for f in sys.argv[2:]:
    segs, info = m.transcribe(f, language=sys.argv[1] if sys.argv[1] != 'auto' else None, beam_size=5)
    txt = " ".join(s.text.strip() for s in segs)
    print(f"{f.split('/')[-1]} [{info.language} {info.language_probability:.2f}] -> {txt}")
