// Synthesises the 47 s soundtrack (music + SFX) to audio.wav, timed to ad.html.
const fs = require('fs');
const SR = 44100, DUR = 47, N = SR * DUR;
const L = new Float32Array(N), R = new Float32Array(N);
const TAU = Math.PI * 2;
const hz = m => 440 * Math.pow(2, (m - 69) / 12);          // midi -> Hz
let seed = 7; const rnd = () => ((seed = (seed * 16807) % 2147483647) / 2147483647) * 2 - 1;

function add(t0, dur, fn, gain = 1, pan = 0) {
  const s0 = Math.max(0, Math.floor(t0 * SR)), s1 = Math.min(N, Math.floor((t0 + dur) * SR));
  const gl = gain * Math.cos((pan + 1) * Math.PI / 4), gr = gain * Math.sin((pan + 1) * Math.PI / 4);
  for (let i = s0; i < s1; i++) { const t = i / SR - t0; const v = fn(t); L[i] += v * gl; R[i] += v * gr; }
}
const env = (t, a, d) => (t < a ? t / a : Math.exp(-(t - a) / d));
const tri = x => 2 * Math.abs(2 * (x - Math.floor(x + .5))) - 1;

// ---------- SFX ----------
const ping = (t0, g = 1, pan = 0) => {
  add(t0, .09, t => Math.sin(TAU * 1568 * t) * env(t, .003, .03), .16 * g, pan);
  add(t0 + .075, .25, t => (Math.sin(TAU * 2093 * t) + .3 * Math.sin(TAU * 4186 * t)) * env(t, .003, .07), .14 * g, pan);
};
const tick = (t0, g = 1) => add(t0, .05, t => Math.sin(TAU * 2600 * t) * env(t, .001, .012), .08 * g);
const click = t0 => { let lp = 0; add(t0, .03, t => { lp += (rnd() - lp) * .5; return lp * env(t, .0005, .006); }, .35); };
const kick = (t0, g = 1) => add(t0, .4, t => Math.sin(TAU * (48 + 70 * Math.exp(-t * 30)) * t) * env(t, .002, .13), .5 * g);
const hat = (t0, g = 1) => { let hp = 0, prev = 0; add(t0, .06, t => { const n = rnd(); hp = .9 * (hp + n - prev); prev = n; return hp * env(t, .0005, .012); }, .05 * g, .2); };
const ring = t0 => add(t0, .7, t => (Math.sin(TAU * 440 * t) + Math.sin(TAU * 480 * t)) * (Math.sin(TAU * 20 * t) > 0 ? 1 : .2) * env(t, .01, .5) * (t < .6 ? 1 : 0), .07);
const swell = (t0, d) => { let lp = 0; add(t0, d, t => { lp += (rnd() - lp) * .02; return lp * Math.sin(Math.PI * t / d) ** 2; }, .5); };

// ---------- musical voices ----------
const pad = (t0, dur, notes, g = 1) => notes.forEach((m, k) => {
  [-0.07, 0.07].forEach((det, j) => add(t0, dur + 1.2, t => {
    const a = Math.min(1, t / 0.9) * (t > dur ? Math.exp(-(t - dur) / .5) : 1);
    const f = hz(m + det);
    return (Math.sin(TAU * f * t) + .25 * Math.sin(TAU * 2 * f * t) + .1 * tri(f * t)) * a;
  }, .028 * g, j ? .35 : -.35));
});
const pluck = (t0, m, g = 1, pan = 0) => add(t0, 1.2, t => {
  const f = hz(m); return (tri(f * t) * .6 + Math.sin(TAU * f * t) * .4 + .15 * Math.sin(TAU * 2 * f * t)) * env(t, .004, .28);
}, .085 * g, pan);
const bell = (t0, m, g = 1) => add(t0, 3, t => {
  const f = hz(m); return (Math.sin(TAU * f * t) + .4 * Math.sin(TAU * 2.76 * f * t) * Math.exp(-t * 3) + .2 * Math.sin(TAU * 5.4 * f * t) * Math.exp(-t * 6)) * env(t, .002, .9);
}, .1 * g);

// ===== 0 – 14.5 : tension =====
[0.35, 0.95, 1.5, 2.05, 2.6, 3.15].forEach((t, i) => ping(t, 1, [-.3, .3, -.1, .2, -.25, .15][i]));
for (let i = 0; i < 12; i++) ping(5.2 + i * .42, .38, rnd() * .7);           // bubble pops, softer
for (let t = 0.6, k = 0; t < 14.4; t += 0.6, k++) {                          // 100 bpm pulse
  const g = Math.min(1, t / 4) * (t > 12 ? 1 - (t - 12) / 2.4 : 1);
  kick(t, .55 * g);
  pluck(t, k % 2 ? 57 : 60, .55 * g, -.2); pluck(t + .3, 64, .35 * g, .2);
  if (t > 5) { hat(t + .15, g); hat(t + .45, g); }
}
pad(0.5, 13.5, [45, 52, 57], .7);                                           // low A-minor bed
ping(11.1, .6, .3);                                                          // the "lost" DM
// 14.5 – 15.35 : silence

// ===== 15.35 – 19 : turning point =====
swell(15.2, 1.4);
pad(15.4, 3.6, [48, 55, 59, 62, 64], 1.1);                                  // Cmaj9 bloom
bell(15.75, 76, .6); bell(16.0, 79, .45);

// ===== 19 – 43 : confident progression (96 bpm, bar = 2.5 s) =====
const prog = [[48, 55, 59, 64], [45, 52, 55, 60], [41, 48, 57, 60], [43, 50, 55, 59]]; // C Am F G
const arp = [[72, 76, 79, 83], [69, 72, 76, 79], [65, 69, 72, 76], [67, 71, 74, 79]];
for (let b = 0, t = 19; t < 42.9; b++, t += 2.5) {
  const c = b % 4;
  pad(t, 2.5, prog[c], .9);
  for (let s = 0; s < 8; s++) {
    const ts = t + s * .3125; if (ts > 42.9) break;
    const quiet = ts > 35.2 && ts < 39.6 ? .6 : 1;                           // ease under scene 7
    pluck(ts, arp[c][[0, 1, 2, 3, 2, 1, 2, 3][s]], .5 * quiet, s % 2 ? .3 : -.3);
    if (ts > 29.2 && s % 2 === 0) kick(ts, (s % 4 ? .18 : .32) * quiet);
    if (ts > 29.2) hat(ts + .156, .8 * quiet);
  }
}
[19.9, 20.95, 22.9, 23.95, 25.9, 26.95, 27.9].forEach(t => tick(t));        // chat messages
[29.7, 29.92, 30.14, 30.36, 30.58, 30.8].forEach(t => tick(t, .6));         // channel tiles
ring(31.0);
ping(32.6, .5);                                                             // lead captured
click(36.62);
ping(38.65, .4);                                                            // Ahmed's reply sent

// ===== 43 – 47 : resolve =====
pad(43.0, 3.0, [48, 55, 59, 62, 64, 67], 1.2);
add(43.0, 3.8, t => Math.sin(TAU * hz(36) * t) * Math.min(1, t / .3) * Math.exp(-t / 2), .12);
bell(43.95, 76, .9); bell(44.15, 79, .8); bell(44.35, 84, .5);
swell(42.6, .9);

// ---------- master: gentle fade, soft clip, write ----------
let peak = 0;
for (let i = 0; i < N; i++) {
  const t = i / SR, f = Math.min(1, t / .2) * Math.min(1, (DUR - t) / 1.2);
  L[i] = Math.tanh(L[i] * 1.2) * f; R[i] = Math.tanh(R[i] * 1.2) * f;
  peak = Math.max(peak, Math.abs(L[i]), Math.abs(R[i]));
}
const norm = .89 / peak;
const buf = Buffer.alloc(44 + N * 4);
buf.write('RIFF', 0); buf.writeUInt32LE(36 + N * 4, 4); buf.write('WAVEfmt ', 8);
buf.writeUInt32LE(16, 16); buf.writeUInt16LE(1, 20); buf.writeUInt16LE(2, 22); buf.writeUInt32LE(SR, 24);
buf.writeUInt32LE(SR * 4, 28); buf.writeUInt16LE(4, 32); buf.writeUInt16LE(16, 34); buf.write('data', 36); buf.writeUInt32LE(N * 4, 40);
for (let i = 0; i < N; i++) { buf.writeInt16LE(Math.round(L[i] * norm * 32767), 44 + i * 4); buf.writeInt16LE(Math.round(R[i] * norm * 32767), 46 + i * 4); }
fs.writeFileSync(__dirname + '/audio.wav', buf);
console.log('audio.wav written, peak', peak.toFixed(2));
