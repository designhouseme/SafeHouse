// SPDX-License-Identifier: CC-BY-4.0
// © 2026 Design House (https://designhouse.me)
//
// Renders a film from an HTML composition: frames from a local Chromium → ffmpeg → MP4.
// No npm packages: Chromium is driven over the DevTools Protocol on a local port
// (127.0.0.1), through the WebSocket built into Node 22+. The script does not connect to the internet.
//
//   node render.mjs --src index.html --still 1,2.5      # stills: out/stills/still-0001.00.png …
//   node render.mjs --src index.html --from 4 --to 6    # a passage, e.g. to measure ms/frame
//   node render.mjs --src index.html --out film.mp4     # the whole film
//
// Options: --sub 5 (subframes per frame, motion blur; default: SUB in the composition), --shutter 0.5, --fps 60,
// --workers N, --outdir out, --speed 0.75, --name Name, --params "w=1080&h=1920",
// --audio track.wav (only a supplied track; by default the film is silent),
// --chrome /path/to/chromium.
//
// The composition exposes: window.__ready (Promise), window.__render(t), window.__DURATION (s),
// and optionally window.__SIZE {w, h} and window.__CUES.
//
// Each worker is a separate Chromium with a contiguous range of frames and its own ffmpeg (segment crf 4,
// yuv444p). tmix averages the subframes within a segment, then concat and the final encode
// (crf 17, yuv420p). Exit code: 0 = done, 1 = render error, 2 = bad input.

import { spawn } from "node:child_process";
import { existsSync, mkdirSync, mkdtempSync, readdirSync, renameSync, rmSync, writeFileSync } from "node:fs";
import { cpus, homedir, tmpdir } from "node:os";
import { basename, dirname, join, resolve } from "node:path";
import { pathToFileURL } from "node:url";

const fail = (msg, code = 2) => {
  console.error(`ERROR: ${msg}`);
  process.exit(code);
};
if (typeof WebSocket === "undefined") fail(`Node 22 or newer is required (built-in WebSocket), found ${process.version}.`);

const args = Object.fromEntries(
  process.argv.slice(2).reduce((acc, a, i, all) => {
    if (a.startsWith("--")) acc.push([a.slice(2), all[i + 1] !== undefined && !all[i + 1].startsWith("--") ? all[i + 1] : "1"]);
    return acc;
  }, []),
);

const src = resolve(args.src ?? "index.html");
if (!existsSync(src)) fail(`composition not found: ${src}`);
const fps = Number(args.fps ?? 60);
let sub = args.sub !== undefined ? Number(args.sub) : null; // otherwise window.__SUB from the composition, otherwise 5
const shutter = Number(args.shutter ?? 0.5); // fraction of the frame interval with the "shutter open" (0.5 = 180°)
const workers = Number(args.workers ?? Math.max(2, Math.min(8, Math.floor(cpus().length / 2))));
const outDir = resolve(dirname(src), args.outdir ?? "out");
const query = new URLSearchParams(args.params ?? "");
query.set("capture", "1");
if (args.name) query.set("name", args.name);
if (args.speed) query.set("speed", args.speed);
const url = `${pathToFileURL(src).href}?${query}`;
mkdirSync(outDir, { recursive: true });

function findChrome() {
  if (args.chrome || process.env.CHROME) return args.chrome || process.env.CHROME;
  const fixed = [
    "/usr/bin/chromium", "/usr/bin/chromium-browser", "/usr/bin/google-chrome", "/usr/bin/google-chrome-stable",
    "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome", "/Applications/Chromium.app/Contents/MacOS/Chromium",
  ];
  const found = fixed.find((p) => existsSync(p));
  if (found) return found;
  const cache = join(homedir(), ".cache/ms-playwright");
  const dirs = existsSync(cache) ? readdirSync(cache).filter((d) => /^chromium-\d+$/.test(d)).sort().reverse() : [];
  for (const d of dirs) for (const rel of ["chrome-linux64/chrome", "chrome-linux/chrome"]) if (existsSync(join(cache, d, rel))) return join(cache, d, rel);
  fail("could not find Chromium or Chrome. Give the path: --chrome /path/to/chromium");
}
const chrome = findChrome();

// A timeout that clears its timer. Without clearTimeout, Promise.race holds on to the result
// (a ~4 MB frame) until the timer expires, and at a dozen or so frames per second Node dies with OOM.
const within = (ms, p, what) => {
  let timer;
  const limit = new Promise((_, reject) => (timer = setTimeout(() => reject(new Error(`${what}: timed out after ${ms / 1000} s`)), ms)));
  return Promise.race([p, limit]).finally(() => clearTimeout(timer));
};

// ------------------------------------------------------------------
// Chromium over the DevTools Protocol
// ------------------------------------------------------------------
// Profiles are removed only after the process exits, and all of them once more at the end:
// a closing Chromium can still write files into the profile after the folder has been removed.
const profiles = new Set();
const sweep = () => {
  for (const p of profiles) rmSync(p, { recursive: true, force: true });
};
// Every Chromium of this run, from launch until it closes. An abort kills all of them, including one that is
// still opening its page; before this, such a browser outlived the render.
const running = new Set();
const killAll = () => {
  for (const c of running) c.kill();
};
process.on("exit", () => (killAll(), sweep()));
process.on("SIGINT", () => process.exit(130));
process.on("SIGTERM", () => process.exit(143));

// Chromium's sockets in the profile have a path length limit (about 100 characters): with a long TMPDIR
// Chromium dies with "Socket path too long", so in that case the profile goes to /tmp.
const tmpBase = tmpdir().length > 40 && existsSync("/tmp") ? "/tmp" : tmpdir();
// The profile name carries this run's PID, so cleaning up after a crash touches only this run, not renders in other folders.
const profilePrefix = join(tmpBase, `motion-chrome-${process.pid}-`);

class Chrome {
  static async launch() {
    const profile = mkdtempSync(profilePrefix);
    profiles.add(profile);
    const flags = [
      "--headless=new", "--remote-debugging-port=0", "--remote-debugging-address=127.0.0.1", `--user-data-dir=${profile}`,
      "--no-first-run", "--no-default-browser-check", "--disable-extensions", "--disable-background-networking", "--mute-audio",
      "--hide-scrollbars", "--force-color-profile=srgb", "--font-render-hinting=none", "--disable-lcd-text", "--allow-file-access-from-files",
      ...(process.getuid?.() === 0 ? ["--no-sandbox"] : []), // Chromium does not start as root without this flag
      "about:blank",
    ];
    // its own process group, so a kill also ends the renderer and GPU processes
    const proc = spawn(chrome, flags, { stdio: ["ignore", "ignore", "pipe"], detached: true });
    const c = new Chrome(proc, profile);
    running.add(c);
    try {
      const ws = await new Promise((res, rej) => {
        let buf = "";
        let found = false;
        // read stderr to the end, otherwise a clogged pipe can stall Chromium
        proc.stderr.on("data", (d) => {
          if (found) return;
          buf += d;
          const m = buf.match(/DevTools listening on (ws:\/\/\S+)/);
          if (m) {
            found = true;
            res(m[1]);
          }
        });
        proc.on("exit", (code) => !found && rej(new Error(`Chromium exited before startup (code ${code}): ${buf.slice(-300)}`)));
        proc.on("error", rej);
      });
      await c.connect(ws);
      return c;
    } catch (error) {
      await c.close();
      throw error;
    }
  }
  constructor(proc, profile) {
    this.proc = proc;
    this.profile = profile;
    this.seq = 0;
    this.pending = new Map();
    this.listeners = new Set();
  }
  connect(wsUrl) {
    return new Promise((res, rej) => {
      const ws = new WebSocket(wsUrl);
      ws.onopen = () => res();
      ws.onerror = () => rej(new Error("could not connect to DevTools"));
      ws.onclose = () => {
        for (const { reject } of this.pending.values()) reject(new Error("connection to Chromium closed"));
        this.pending.clear();
      };
      ws.onmessage = (ev) => {
        const msg = JSON.parse(ev.data);
        if (msg.id && this.pending.has(msg.id)) {
          const { resolve, reject } = this.pending.get(msg.id);
          this.pending.delete(msg.id);
          if (msg.error) reject(new Error(`${msg.error.message}`));
          else resolve(msg.result);
        } else if (msg.method) for (const listen of this.listeners) listen(msg);
      };
      this.ws = ws;
    });
  }
  send(method, params = {}, sessionId) {
    const id = ++this.seq;
    this.ws.send(JSON.stringify({ id, method, params, ...(sessionId ? { sessionId } : {}) }));
    return new Promise((resolve, reject) => this.pending.set(id, { resolve, reject }));
  }
  once(method, sessionId) {
    return new Promise((resolve) => {
      const listen = (msg) => {
        if (msg.method === method && msg.sessionId === sessionId) {
          this.listeners.delete(listen);
          resolve(msg.params);
        }
      };
      this.listeners.add(listen);
    });
  }
  // Graceful close: Browser.close also ends the child processes (network, GPU), which after a bare
  // SIGKILL of the main process live on for a moment and recreate the profile folder.
  async close() {
    const exited = new Promise((r) => (this.proc.exitCode !== null || this.proc.signalCode !== null ? r() : this.proc.once("exit", r)));
    try {
      await within(2000, this.send("Browser.close"), "closing Chromium");
    } catch {}
    await within(5000, exited, "Chromium exit").catch(() => this.hardKill());
    try { this.ws?.close(); } catch {}
    rmSync(this.profile, { recursive: true, force: true });
    profiles.delete(this.profile);
    running.delete(this);
  }
  hardKill() {
    try { process.kill(-this.proc.pid, "SIGKILL"); } catch { try { this.proc.kill("SIGKILL"); } catch {} }
  }
  // Emergency (Ctrl+C, error): no waiting; the sweep at exit removes the profile folder.
  kill() {
    try { this.ws?.close(); } catch {}
    this.hardKill();
    running.delete(this);
  }
}

async function preparePage(c) {
  const { targetInfos } = await c.send("Target.getTargets");
  const target = targetInfos.find((t) => t.type === "page") ?? (await c.send("Target.createTarget", { url: "about:blank" }));
  const { sessionId } = await c.send("Target.attachToTarget", { targetId: target.targetId, flatten: true });
  const s = (method, params) => c.send(method, params, sessionId);
  let logged = 0;
  c.listeners.add((msg) => {
    if (msg.sessionId !== sessionId) return;
    if (msg.method === "Runtime.exceptionThrown") {
      const d = msg.params.exceptionDetails;
      console.error("[page]", d?.exception?.description ?? d?.text);
    }
    if (msg.method === "Runtime.consoleAPICalled") {
      const text = msg.params.args.map((a) => a.value ?? a.description).join(" ");
      if (msg.params.type === "error") console.error("[console]", text);
      // console.log and the like: at most 50 per browser, so a log inside update(t) can't flood the log
      else if (logged < 50) console.error(`[console.${msg.params.type}]`, text, ++logged === 50 ? "(further messages from this browser are not shown)" : "");
    }
  });
  const evaluate = async (expression) => {
    const r = await s("Runtime.evaluate", { expression, awaitPromise: true, returnByValue: true });
    if (r.exceptionDetails) throw new Error(r.exceptionDetails.exception?.description ?? r.exceptionDetails.text);
    return r.result.value;
  };
  await s("Page.enable");
  await s("Runtime.enable");
  await s("Emulation.setDeviceMetricsOverride", { width: 1920, height: 1080, deviceScaleFactor: 1, mobile: false });
  const loaded = c.once("Page.loadEventFired", sessionId);
  await s("Page.navigate", { url });
  await loaded;
  await evaluate("window.__ready");
  const size = (await evaluate("window.__SIZE ?? null")) ?? { w: 1920, h: 1080 };
  await s("Emulation.setDeviceMetricsOverride", { width: size.w, height: size.h, deviceScaleFactor: 1, mobile: false });
  await s("Page.bringToFront");
  const duration = await evaluate("window.__DURATION");
  if (!(duration > 0)) throw new Error("the composition did not set window.__DURATION");
  const cues = (await evaluate("window.__CUES ?? []")) ?? [];
  writeFileSync(join(outDir, "cues.json"), JSON.stringify({ duration, cues }, null, 1));
  const subframes = await evaluate("window.__SUB ?? null");
  return { c, s, evaluate, duration, subframes };
}

// With many browsers at once, a single one can get stuck at startup: a timeout and a retry.
async function openPage(tries = 3) {
  for (let k = 1; ; k++) {
    let c;
    try {
      c = await within(60000, Chrome.launch(), "Chromium start");
      return await within(90000, preparePage(c), "page setup");
    } catch (error) {
      await c?.close();
      if (k >= tries) throw error;
      console.error(`\n${error.message}, retrying (${k}/${tries - 1})`);
    }
  }
}

async function frame(ctx, t) {
  // After __render(t) we wait one animation frame, so that style and layout are computed.
  await within(30000, ctx.evaluate(`new Promise((r) => { window.__render(${t}); requestAnimationFrame(() => r()); })`), `frame ${t.toFixed(3)} s`);
  const { data } = await within(30000, ctx.s("Page.captureScreenshot", { format: "png", optimizeForSpeed: true }), `screenshot ${t.toFixed(3)} s`);
  return Buffer.from(data, "base64");
}

// ------------------------------------------------------------------
// Stills
// ------------------------------------------------------------------
if (args.still) {
  // Each round of stills goes into a clean folder, so the sheet never mixes old frames with new ones.
  const stillDir = join(outDir, "stills");
  rmSync(stillDir, { recursive: true, force: true });
  mkdirSync(stillDir, { recursive: true });
  const ctx = await openPage();
  try {
    for (const v of String(args.still).split(",")) {
      const t = Number(v);
      const file = join(stillDir, `still-${t.toFixed(2).padStart(7, "0")}.png`); // still-0012.40.png: sorts by time
      writeFileSync(file, await frame(ctx, t));
      console.log(file);
    }
  } finally {
    await ctx.c.close();
  }
  process.exit(0);
}

// ------------------------------------------------------------------
// Film or passage
// ------------------------------------------------------------------
const probe = await openPage();
const duration = probe.duration;
sub ??= Number(probe.subframes ?? 5); // the composition's SUB, so a re-render keeps the subframes the film was checked with
await probe.c.close();
if (!(Number.isInteger(sub) && sub >= 1)) fail(`--sub must be a whole number from 1 up, got ${args.sub ?? probe.subframes}`);

const t0 = Number(args.from ?? 0);
const t1 = Math.min(Number(args.to ?? duration), duration);
if (![t0, t1].every(Number.isFinite)) fail(`--from and --to must be numbers of seconds, got "${args.from}" and "${args.to}"`);
const first = Math.round(t0 * fps);
const total = Math.round(t1 * fps) - first;
if (total <= 0) fail(`empty range: from ${t0} to ${t1} s`);
const per = Math.ceil(total / workers);
const segDir = join(outDir, "seg");
rmSync(segDir, { recursive: true, force: true });
mkdirSync(segDir, { recursive: true });

console.log(`${total} frames × ${sub} subframes, ${workers} workers, ${fps} fps, ${(t1 - t0).toFixed(2)} s`);
// [x] in the pattern: pkill -f would otherwise also match the shell that runs it; find instead of a glob, which zsh aborts on
const notSelf = (p) => `${p.slice(0, -2)}[${p.slice(-2, -1)}]${p.slice(-1)}`;
console.log(`if this run is killed, clean up only its own processes: pkill -f '${notSelf(profilePrefix)}'; pkill -f '${notSelf(`${segDir}/`)}'; find ${tmpBase} -maxdepth 1 -name '${basename(profilePrefix)}*' -exec rm -rf {} +`);
const started = Date.now();
let done = 0;

async function worker(w) {
  const a = first + w * per;
  const b = Math.min(first + total, a + per);
  if (a >= b) return null;
  const ctx = await openPage();
  const seg = join(segDir, `seg-${String(w).padStart(2, "0")}.mkv`);
  // tmix averages the last `sub` subframes; select keeps every `sub`-th one, which is a full frame.
  const vf = sub > 1 ? [`tmix=frames=${sub}`, `select='eq(mod(n\\,${sub})\\,${sub - 1})'`, `setpts=N/${fps}/TB`] : [];
  const ff = spawn(
    "ffmpeg",
    ["-v", "error", "-y", "-f", "image2pipe", "-framerate", String(fps * sub), "-i", "-", ...(vf.length ? ["-vf", vf.join(",")] : []),
      "-r", String(fps), "-c:v", "libx264", "-preset", "veryfast", "-crf", "4", "-pix_fmt", "yuv444p", seg],
    { stdio: ["pipe", "inherit", "inherit"] },
  );
  const closed = new Promise((r, j) => ff.on("close", (code) => (code === 0 ? r() : j(new Error(`ffmpeg for segment ${w}: code ${code}`)))));
  try {
    for (let i = a; i < b; i++) {
      for (let k = 0; k < sub; k++) {
        const t = (i + (sub > 1 ? (k / (sub - 1) - 1) * shutter : 0)) / fps; // subframes end at the frame's time
        const png = await frame(ctx, Math.max(0, t));
        if (!ff.stdin.write(png)) await new Promise((r) => ff.stdin.once("drain", r));
      }
      done++;
      if (done % 30 === 0) {
        const el = (Date.now() - started) / 1000;
        process.stdout.write(`\r${done}/${total}  ${((el / done) * 1000).toFixed(0)} ms/frame  ETA ${(((total - done) * el) / done).toFixed(0)} s   `);
      }
    }
    ff.stdin.end();
    await closed;
  } finally {
    await ctx.c.close();
  }
  return seg;
}

let segs;
try {
  segs = (await Promise.all(Array.from({ length: workers }, (_, w) => worker(w)))).filter(Boolean);
} catch (error) {
  killAll();
  fail(`render aborted: ${error.message}`, 1);
}
const secs = (Date.now() - started) / 1000;
console.log(`\nframes done in ${secs.toFixed(0)} s (${((secs / total) * 1000).toFixed(0)} ms/frame overall)`);

const list = join(segDir, "list.txt");
writeFileSync(list, segs.map((s) => `file '${s}'`).join("\n"));
const name = args.out ?? (args.from || args.to ? `part-${t0}-${t1}.mp4` : "film.mp4");
const audio = args.audio && args.audio !== "1" ? resolve(args.audio) : null;
if (audio && !existsSync(audio)) fail(`audio file not found: ${audio}`);
const withAudio = Boolean(audio) && !args.from && !args.to;
const final = join(outDir, name);
const tmp = join(segDir, "final.mp4");
const enc = ["-v", "error", "-y", "-f", "concat", "-safe", "0", "-i", list, ...(withAudio ? ["-i", audio] : []),
  "-c:v", "libx264", "-preset", "slow", "-crf", "17", "-pix_fmt", "yuv420p", "-tune", "animation",
  ...(withAudio ? ["-c:a", "aac", "-b:a", "192k", "-shortest"] : ["-an"]), "-movflags", "+faststart", "-f", "mp4", tmp];
const ok = await new Promise((r) => spawn("ffmpeg", enc, { stdio: "inherit" }).on("close", (code) => r(code === 0)));
if (!ok) fail("ffmpeg could not assemble the film from the segments", 1);
// The finished file is moved into place at the end: an interrupted render never leaves a cut-off MP4 under the target name.
renameSync(tmp, final);
rmSync(segDir, { recursive: true, force: true });
console.log(final);
