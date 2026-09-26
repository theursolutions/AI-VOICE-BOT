# Expressive voiceover: every line is split into phrases, each with its own
# pace/pitch (the "emotion"), natural pauses between them, fitted to the scene
# slot, then warmed/roomed and mixed over the ducked music onto the video.
import asyncio, subprocess, sys, os
import edge_tts

HERE = os.path.dirname(os.path.abspath(__file__))
FF = os.path.join(HERE, 'node_modules', 'ffmpeg-static', 'ffmpeg.exe')
SR = 24000
VOICE = {'en': 'en-US-AvaMultilingualNeural', 'ur': 'ur-PK-UzmaNeural'}

# Each line: (start, latest_end, [(text, rate%, pitch Hz, pause_after s), ...])
# rate < 0 = slower/heavier, pitch < 0 = lower/serious, pitch > 0 = lifted/hopeful.
SCRIPT = {
 'en': [
  (0.7, 4.9,  [("Every day...", -12, -4, .25), ("your customers are asking questions.", -8, -3, 0)]),
  (5.2, 11.2, [("Some wait.", -12, -5, .55), ("Some ask twice.", -10, -5, .55), ("And some...", -16, -8, .35), ("just leave.", -18, -10, 0)]),
  (11.4, 14.7,[("Every unanswered message", -6, -6, .15), ("is a missed customer.", -10, -8, 0)]),
  (16.2, 19.6,[("What if...", -12, 2, .3), ("nobody had to wait?", -8, 3, 0)]),
  (19.8, 25.8,[("Surv A.I. answers in seconds,", 0, 4, .25), ("from your own information,", -4, 2, .12), ("in your customer's language.", -6, 1, 0)]),
  (26.0, 29.2,[("Need a person?", -4, 6, .25), ("It hands over to your team.", -6, 0, 0)]),
  (29.5, 35.2,[("WhatsApp, Instagram, Facebook, calls, and email.", 2, 4, .35), ("Every lead...", -8, 0, .15), ("captured.", -10, -2, 0)]),
  (35.5, 39.7,[("A.I. handles the routine.", -4, 2, .4), ("Your team handles what matters.", -8, -1, 0)]),
  (40.0, 43.0,[("Every conversation,", -4, 0, .12), ("in your own private database.", -6, -3, 0)]),
  (43.0, 46.9,[("Let Surv A.I. answer.", -6, 3, .35), ("Start free today.", -8, 5, 0)]),
 ],
 'ur': [
  (0.7, 4.9,  [("ہر روز،", -8, -3, .2), ("آپ کے گاہک سوال پوچھ رہے ہیں۔", -4, -2, 0)]),
  (5.2, 11.3, [("کچھ انتظار کرتے ہیں۔", -6, -4, .4), ("کچھ بار بار پوچھتے ہیں۔", -4, -4, .4), ("اور کچھ،", -10, -7, .3), ("بس چلے جاتے ہیں۔", -12, -9, 0)]),
  (11.4, 14.7,[("ہر بے جواب میسج،", -2, -5, .12), ("ایک کھویا ہوا گاہک۔", -6, -7, 0)]),
  (16.2, 19.6,[("اگر کسی کو", -6, 2, .1), ("انتظار نہ کرنا پڑے؟", -6, 5, 0)]),
  (19.8, 25.8,[("سرو اے۔آئی، سیکنڈوں میں جواب دیتا ہے۔", 0, 4, .25), ("آپ کی اپنی معلومات سے، گاہک کی اپنی زبان میں۔", -2, 1, 0)]),
  (26.0, 29.3,[("انسان کی ضرورت ہو؟", -2, 6, .2), ("تو بات آپ کی ٹیم تک۔", -4, 0, 0)]),
  (29.5, 35.2,[("واٹس ایپ، انسٹاگرام، فیس بک، کالز اور ای میل۔", 2, 4, .35), ("ہر لیڈ محفوظ۔", -8, -1, 0)]),
  (35.5, 39.7,[("روزمرہ کام اے آئی کا۔", -2, 2, .35), ("اہم کام، آپ کی ٹیم کا۔", -6, -1, 0)]),
  (40.0, 43.1,[("ہر گفتگو،", -2, 0, .1), ("آپ کے اپنے پرائیویٹ ڈیٹا بیس میں۔", -2, -3, 0)]),
  (43.0, 46.9,[("جواب سرو اے۔آئی دے گا۔", -4, 3, .3), ("مفت شروع کریں۔", -6, 5, 0)]),
 ]}

async def phrase_pcm(text, voice, rate, pitch, path):
    await edge_tts.Communicate(text, voice, rate=f"{rate:+d}%", pitch=f"{pitch:+d}Hz").save(path)
    trim = 'silenceremove=start_periods=1:start_threshold=-50dB:start_silence=0.06,areverse,silenceremove=start_periods=1:start_threshold=-50dB:start_silence=0.12,areverse'
    return subprocess.run([FF, '-hide_banner', '-loglevel', 'error', '-i', path, '-af', trim, '-ac', '1', '-ar', str(SR), '-f', 's16le', '-'],
                          capture_output=True, check=True).stdout

async def render_line(lang, idx, start, end, phrases):
    slot = end - start
    for boost in range(0, 13, 3):                     # speed up gently only if needed
        pcms = []
        for k, (txt, rate, pitch, _) in enumerate(phrases):
            pcms.append(await phrase_pcm(txt, VOICE[lang], rate + boost, pitch, os.path.join(HERE, 'vo', f'{lang}_{idx:02d}_{k}.mp3')))
        speech = sum(len(p) for p in pcms) / 2 / SR
        pauses = [p[3] for p in phrases]
        scale = 1.0
        if speech + sum(pauses) > slot:              # tighten pauses first (keep >= 55%)
            scale = max(.55, (slot - speech) / max(1e-6, sum(pauses)))
        total = speech + sum(pauses) * scale
        if total <= slot or boost == 12: break
    out = bytearray()
    for p, pause in zip(pcms, pauses):
        out += p + b'\x00\x00' * int(pause * scale * SR)
    print(f'{lang} line {idx}: {total:.2f}s / {slot:.2f}s slot (speed +{boost}%, pauses x{scale:.2f})' + ('  OVER' if total > slot else ''))
    return bytes(out)

async def main(lang):
    os.makedirs(os.path.join(HERE, 'vo'), exist_ok=True)
    track = bytearray(b'\x00\x00' * int(47 * SR))
    for i, (start, end, phrases) in enumerate(SCRIPT[lang]):
        pcm = await render_line(lang, i, start, end, phrases)
        o = int(start * SR) * 2
        seg = pcm[: len(track) - o]
        track[o:o + len(seg)] = seg
    raw = os.path.join(HERE, f'vo_{lang}.raw')
    open(raw, 'wb').write(track)

    # voice: de-mud, warmth, presence, gentle compression and a small room; music ducked underneath
    vchain = ('highpass=f=75,equalizer=f=220:t=q:w=1:g=2.5,equalizer=f=3200:t=q:w=1.2:g=1.5,'
              'equalizer=f=7500:t=q:w=1:g=-2,acompressor=threshold=0.1:ratio=3:attack=8:release=120:makeup=2,'
              'aecho=0.85:0.6:28|47:0.07|0.05,volume=1.5')
    mix = os.path.join(HERE, f'mix2_{lang}.wav')
    f = (f'[1:a]aresample=44100,aformat=channel_layouts=stereo,{vchain}[vo];[vo]asplit=2[vo1][vo2];'
         f'[0:a]volume=0.7[mus];[mus][vo1]sidechaincompress=threshold=0.025:ratio=8:attack=20:release=450[duck];'
         f'[duck][vo2]amix=inputs=2:normalize=0,loudnorm=I=-16:TP=-1.5:LRA=11,atrim=0:47[out]')
    subprocess.run([FF, '-y', '-hide_banner', '-loglevel', 'error', '-i', os.path.join(HERE, 'audio.wav'),
                    '-f', 's16le', '-ar', str(SR), '-ac', '1', '-i', raw,
                    '-filter_complex', f, '-map', '[out]', '-ar', '44100', mix], check=True)
    src = os.path.join(HERE, f'serveai-hero-{lang}.mp4')
    out = os.path.join(HERE, f'serveai-hero-{lang}-vo2.mp4')
    subprocess.run([FF, '-y', '-hide_banner', '-loglevel', 'error', '-i', src, '-i', mix, '-map', '0:v', '-map', '1:a',
                    '-c:v', 'copy', '-c:a', 'aac', '-b:a', '192k', '-movflags', '+faststart', out], check=True)
    # dry voice-only file for checking intelligibility
    subprocess.run([FF, '-y', '-hide_banner', '-loglevel', 'error', '-f', 's16le', '-ar', str(SR), '-ac', '1', '-i', raw,
                    os.path.join(HERE, f'vo_{lang}.wav')], check=True)
    print('wrote', out)

asyncio.run(main(sys.argv[1]))
