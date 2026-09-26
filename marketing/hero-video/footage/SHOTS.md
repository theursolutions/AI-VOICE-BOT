# serveAI hero ad: character shots

These are the human shots to generate in Weave (https://app.weavy.ai). Save each result in this folder under the file name shown, and the edit will slot it into both the English and Urdu videos.

Everyone in these shots is AI-generated and fictional. Keep the "Dramatization" line on the final video.

## Step 1: character stills (Nano Banana Pro, 16:9, 2K, about 11 credits each)

| File | Prompt |
| --- | --- |
| `still-presenter.png` | Photorealistic medium shot of a confident, warm, beautiful Pakistani woman presenter around 28 years old, looking directly into the camera with a friendly, subtle closed-mouth smile, shoulder-length dark hair, elegant navy blue blazer over a white top, standing in a bright modern minimalist studio with a soft light-blue gradient backdrop, soft key light and gentle rim light, premium technology commercial look, subject framed center-right leaving clean space on the left, 50mm lens, shallow depth of field, realistic skin texture, natural makeup. No text, no logos. |
| `still-owner-night.png` | Cinematic film still. A Pakistani woman in her early 30s who runs a small online clothing business sits at a wooden desk in a cozy home office in Lahore late at night. She wears a simple elegant kameez with a dupatta over her shoulders. She looks tired and stressed, staring at her smartphone which lights her face; an open laptop glows beside her; folded clothes and fabric rolls on a shelf behind her. Warm practical desk lamp, cool blue screen light, shallow depth of field, 35mm lens, realistic skin texture, natural expression. No readable text, no logos. |
| `still-owner-calm.png` | Edit `still-owner-night.png` (give it as the input image): Same woman, same room, same outfit and camera angle. She is now relaxed and smiling softly, her phone lies face down on the desk, and she gently half-closes the laptop. Slightly warmer light. No readable text, no logos. |
| `still-customer.png` | Cinematic film still. A young Pakistani woman in her mid-20s, a shopper, sits on a sofa in a softly lit living room at night holding her smartphone, looking mildly frustrated and disappointed while waiting for a reply, about to give up. Soft lamp light, shallow depth of field, 50mm lens, realistic skin texture, natural expression. Phone screen not visible. No readable text, no logos. |
| `still-agent.png` | Cinematic film still. A Pakistani man in his late 20s, a customer support team member, wearing a light blue shirt and a slim headset, calm and focused, typing a reply on a laptop at a tidy modern office desk in the evening, warm office light, plants in the background, shallow depth of field, 50mm lens, realistic skin texture. Laptop screen not visible. No readable text, no logos. |

## Step 2: moving footage (Veo 3.1 Image to Video, Fast, 16:9, 1080p, audio off, about 90 credits each)

Use each still as the first frame.

| File | From | Length | Prompt |
| --- | --- | --- | --- |
| `clip-owner-night.mp4` | still-owner-night | 6s | Her phone buzzes repeatedly on the desk; she picks it up, scrolls, sighs and rubs her forehead. Subtle handheld push-in, cinematic, realistic motion. |
| `clip-customer.mp4` | still-customer | 4s | She checks her phone, waits, shakes her head slightly and puts the phone down on the sofa, disappointed. Slow push-in. |
| `clip-agent.mp4` | still-agent | 4s | He reads the screen, gives a small confident nod and types a reply, calm and friendly. Static camera, gentle depth. |
| `clip-owner-calm.mp4` | still-owner-calm | 4s | She exhales, relaxed, gives a small smile and half-closes the laptop. Slow pull-out, warm and calm. |

Negative prompt for all four: `distorted hands, extra fingers, text, subtitles, logos, watermark`

## Step 3: talking presenter (Veed Fabric 1.0, 720p, about 180 credits each)

Run it four times, each with `still-presenter.png` plus one audio file from `presenter-audio/`. The narrator's voice is the presenter's, so on-camera and off-camera lines match.

| File | Audio | Says |
| --- | --- | --- |
| `talk-en-1.mp4` | presenter-en-1-what-if.mp3 | "What if nobody had to wait?" |
| `talk-en-2.mp4` | presenter-en-2-cta.mp3 | "Let serveAI answer. Start free today." |
| `talk-ur-1.mp4` | presenter-ur-1-what-if.mp3 | "اگر کسی کو انتظار نہ کرنا پڑے؟" |
| `talk-ur-2.mp4` | presenter-ur-2-cta.mp3 | "جواب سَرو اے آئی دے گا۔ مفت شروع کریں۔" |

Omnihuman V1.5 (about 352 credits) usually looks more natural. Use it for the presenter if the budget allows.

## Where each shot goes in the 47 s edit

| Time | Shot |
| --- | --- |
| 0–5 s | clip-owner-night, full frame behind the headline, with the phone notifications on top |
| 11–15 s | clip-customer behind the "never mind, ordered somewhere else" message |
| 15–19 s | talk-*-1: the presenter on camera, then the serveAI logo |
| 35–40 s | clip-agent in a picture-in-picture card beside the inbox, labelled "Ahmed, your team" |
| 42–44 s | clip-owner-calm, the transformation beat |
| 44–47 s | talk-*-2: the presenter beside the Start free end card |

Rough total: about 55 credits for stills, 360 for footage and 720 for the presenter (Fabric), or about 1,135 credits in all.
