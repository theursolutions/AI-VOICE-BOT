# Generates the voiceover for each language, fitted to the ad's scene slots,
# then mixes it over the music (ducked) and muxes it onto the rendered video.
import asyncio, json, subprocess, sys, os
import edge_tts

HERE = os.path.dirname(os.path.abspath(__file__))
FF = os.path.join(HERE, 'node_modules', 'ffmpeg-static', 'ffmpeg.exe')
VOICE = {'en': 'en-US-AvaNeural', 'ur': 'ur-PK-UzmaNeural'}

# (start s, latest end s, EN, UR)
LINES = [
    (0.7, 4.9, "Every day, your customers are asking questions.", "ہر روز، آپ کے گاہک سوال پوچھ رہے ہیں۔"),
    (5.2, 11.0, "Some wait. Some ask twice. And some... just leave.", "کچھ انتظار کرتے ہیں۔ کچھ بار بار پوچھتے ہیں۔ اور کچھ، چلے جاتے ہیں۔"),
    (11.4, 14.6, "Every unanswered message is a missed customer.", "ہر بے جواب میسج، ایک کھویا ہوا گاہک۔"),
    (16.2, 19.6, "What if nobody had to wait?", "اگر کسی کو انتظار نہ کرنا پڑے؟"),
    (19.8, 25.8, "serve A.I. answers in seconds, from your own information, in your customer's language.",
                 "سَرو اے آئی، آپ کی اپنی معلومات سے، گاہک کی زبان میں، فوراً جواب دیتا ہے۔"),
    (26.0, 29.2, "Need a person? It hands over to your team.", "انسان کی ضرورت ہو، تو بات آپ کی ٹیم تک۔"),
    (29.5, 35.2, "WhatsApp, Instagram, Facebook, calls and email. Every lead, captured.",
                 "واٹس ایپ، انسٹاگرام، فیس بک، کالز اور ای میل۔ ہر لیڈ محفوظ۔"),
    (35.5, 39.7, "A.I. handles the routine. Your team handles what matters.", "روزمرہ کام اے آئی کا، اہم کام آپ کی ٹیم کا۔"),
    (40.0, 43.1, "Every conversation, in your own private database.", "ہر گفتگو، آپ کے پرائیویٹ ڈیٹا بیس میں۔"),
    (43.0, 46.9, "Let serve A.I. answer. Start free today.", "جواب سَرو اے آئی دے گا۔ مفت شروع کریں۔"),
]

def duration(path):
    out = subprocess.run([FF, '-hide_banner', '-i', path], capture_output=True, text=True).stderr
    h, m, s = out.split('Duration: ')[1].split(',')[0].split(':')
    return int(h) * 3600 + int(m) * 60 + float(s)

async def synth(text, voice, rate, path):
    await edge_tts.Communicate(text, voice, rate=f"{rate:+d}%", pitch="-2Hz").save(path)

async def main(lang):
    os.makedirs(os.path.join(HERE, 'vo'), exist_ok=True)
    clips = []
    for i, (start, end, en, ur) in enumerate(LINES):
        text = en if lang == 'en' else ur
        path = os.path.join(HERE, 'vo', f'{lang}_{i:02d}.mp3')
        slot = end - start
        rate = -4 if lang == 'en' else 0           # calm, unhurried default
        while True:
            await synth(text, VOICE[lang], rate, path)
            d = duration(path)
            if d <= slot or rate >= 10: break
            rate += 2
        print(f'{lang} line {i}: {d:.2f}s in {slot:.2f}s slot (rate {rate:+d}%)' + ('  OVER' if d > slot else ''))
        clips.append((start, path))

    # VO bus: each clip delayed to its start; music ducked under it; master loudness for web
    args = [FF, '-y', '-hide_banner', '-loglevel', 'error', '-i', os.path.join(HERE, 'audio.wav')]
    for _, p in clips: args += ['-i', p]
    f = []
    for k, (st, _) in enumerate(clips, start=1):
        ms = int(st * 1000)
        f.append(f'[{k}:a]aresample=44100,aformat=channel_layouts=stereo,adelay={ms}|{ms},volume=1.6[v{k}]')
    f.append(''.join(f'[v{k}]' for k in range(1, len(clips) + 1)) + f'amix=inputs={len(clips)}:normalize=0,apad=whole_dur=47[vo]')
    f.append('[vo]asplit=2[vo1][vo2]')
    f.append('[0:a]volume=0.8[mus]')
    f.append('[mus][vo1]sidechaincompress=threshold=0.03:ratio=6:attack=15:release=350[duck]')
    f.append('[duck][vo2]amix=inputs=2:normalize=0,loudnorm=I=-16:TP=-1.5:LRA=11,atrim=0:47[out]')
    mix = os.path.join(HERE, f'mix_{lang}.wav')
    subprocess.run(args + ['-filter_complex', ';'.join(f), '-map', '[out]', '-ar', '44100', mix], check=True)

    src = os.path.join(HERE, f'serveai-hero-{lang}.mp4')
    out = os.path.join(HERE, f'serveai-hero-{lang}-vo.mp4')
    subprocess.run([FF, '-y', '-hide_banner', '-loglevel', 'error', '-i', src, '-i', mix, '-map', '0:v', '-map', '1:a',
                    '-c:v', 'copy', '-c:a', 'aac', '-b:a', '192k', '-movflags', '+faststart', out], check=True)
    print('wrote', out)

asyncio.run(main(sys.argv[1]))
