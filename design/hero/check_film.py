#!/usr/bin/env python3
# SPDX-License-Identifier: CC-BY-4.0
# © 2026 Design House (https://designhouse.me)
"""Checks the film and the composition before delivery.

Usage (every part is optional; only what you pass gets checked):
  python3 check_film.py --composition index.html \\
      --film out/film.mp4 --length 12 --format 1080x1920 --fps 60 --audio no \\
      --small out/film-to-send.mp4 --max-mb 10 --webp ../../docs/film.webp

Composition: things that break frame repeatability (Math.random, Date.now, CSS animations
and transitions, setInterval, will-change), resources from the internet, missing font files,
em dashes, and a missing window.__render / __ready / __DURATION. As warnings, the look
(references/look.md): the template's demo palette and font, default typefaces, gradient text,
backdrop-filter, default purples and emoji.
Film: length, dimensions, fps, frame count, yuv420p, an audio track as agreed,
a first frame with content (not a flat patch of colour).
Small file: size under the limit and the same length as the master. WebP: that it is an animation with frames.

Only the standard library, ffprobe and ffmpeg, no network. Exit code: 0 when there are no errors,
1 when there are errors, 2 when a file or ffprobe is missing.
"""
import argparse
import json
import re
import shutil
import struct
import subprocess
import sys
from pathlib import Path

errors, warnings, checked = [], [], []


def error(msg):
    errors.append(msg)
    print(f"ERROR: {msg}")


def warn(msg):
    warnings.append(msg)
    print(f"WARNING: {msg}")


def need(path, what):
    p = Path(path)
    if not p.is_file():
        print(f"ERROR: file not found: {path} ({what})")
        sys.exit(2)
    return p


def line_of(text, index):
    return text.count("\n", 0, index) + 1


def strip_comments(text):
    """Replaces HTML, CSS and JS comments with spaces (newlines are kept, so line numbers still match).
    Without this, the script raised warnings from the comments that warn against these very patterns."""
    blank = lambda m: re.sub(r"[^\n]", " ", m.group(0))
    text = re.sub(r"<!--.*?-->", blank, text, flags=re.S)
    text = re.sub(r"/\*.*?\*/", blank, text, flags=re.S)
    return re.sub(r"(?<![:\\\"'])//[^\n]*", blank, text)  # "//" after a colon is an address (http://), not a comment


def check_composition(path):
    p = need(path, "composition")
    text = strip_comments(p.read_text(encoding="utf-8"))
    checked.append(f"composition {p.name}")
    rules = [
        (r"Math\.random\s*\(", "Math.random() gives different frames on every render; use hash()"),
        (r"Date\.now\s*\(", "Date.now() ties the frame to the clock, not to time t"),
        (r"setInterval\s*\(", "setInterval() runs in real time, not in time t"),
        (r"@keyframes|\banimation\s*:|\banimation-name\s*:", "a CSS animation does not follow time t; compute the motion in update(t)"),
        (r"\btransition\s*:", "a CSS transition does not follow time t; compute the motion in update(t)"),
        (r"will-change", "will-change blurs the image when zooming (the layer is painted once, at the old scale)"),
    ]
    for pattern, msg in rules:
        for m in re.finditer(pattern, text):
            error(f"{p.name}:{line_of(text, m.start())}: {msg}")
    for m in re.finditer(r"setTimeout\s*\(", text):
        line = text.splitlines()[line_of(text, m.start()) - 1]
        if "decode" not in line:  # in the template, setTimeout only caps the time spent decoding images
            warn(f"{p.name}:{line_of(text, m.start())}: setTimeout() outside the wait for images; check that it does not change frames")
    for m in re.finditer(r"<iframe", text, re.I):
        warn(f"{p.name}:{line_of(text, m.start())}: an iframe with a running app has its own timers; recreate the screen as static")
    for m in re.finditer(r"""(?:src\s*=\s*["']|url\(\s*["']?|@import\s+["']|<link[^>]+href\s*=\s*["'])(https?:)?//""", text, re.I):
        error(f"{p.name}:{line_of(text, m.start())}: resource from the internet; the render depends on the network, save the file locally")
    for m in re.finditer(r"—", text):
        error(f"{p.name}:{line_of(text, m.start())}: em dash (U+2014); in on-screen text use a full stop, comma, colon or en dash")
    for name in ("window.__render", "window.__ready", "window.__DURATION"):
        if name not in text:
            error(f"{p.name}: missing {name}; render.mjs needs it")
    for m in re.finditer(r"url\(\s*['\"]?([^)\s'\"]+\.(?:woff2?|ttf|otf))['\"]?\s*\)", text):
        font = p.parent / m.group(1)
        if not font.is_file():
            error(f"{p.name}:{line_of(text, m.start())}: font file {m.group(1)} not found; the film will render in a fallback font")
    check_look(p, text)


# The reflex list from references/look.md (the same as the ui-without-slop skill's): fonts models pick without a reason.
# Urbanist is caught separately, as the template's demo font.
REFLEX_FONTS = (r"\b(Inter|Roboto|Open Sans|Lato|Montserrat|Poppins|Nunito|Raleway|Work Sans|Geist|Space Grotesk|DM Sans|Manrope"
                r"|Plus Jakarta Sans|Outfit|Sora|Figtree|Lexend|Satoshi|General Sans|Cabinet Grotesk|Clash Display"
                r"|Bricolage Grotesque|Syne|Unbounded|PP Neue Montreal|Bebas Neue|Anton|Oswald|Fraunces|Instrument Serif"
                r"|Playfair Display|Cormorant|DM Serif Display|Lora|PP Editorial New|JetBrains Mono|IBM Plex Mono|Space Mono"
                r"|Geist Mono|Fira Code)\b")


def check_look(p, text):
    """Warnings, not errors: the brand takes precedence, so a warning is fixed or explained in the reply."""
    accent = re.search(r"--accent\s*:\s*#([0-9a-f]{6}|[0-9a-f]{3})\b", text, re.I)
    if re.search(r"--bg\s*:\s*#ececec\b", text, re.I) and accent and accent.group(1).lower() == "8a8a8a":
        warn(f"{p.name}: the template's grey demo palette is still in :root; set the brand's colours or a palette you decided on (references/look.md)")
    elif accent:
        h = accent.group(1) if len(accent.group(1)) == 6 else "".join(c * 2 for c in accent.group(1))
        rgb = [int(h[i:i + 2], 16) for i in (0, 2, 4)]
        if max(rgb) - min(rgb) <= 8:
            warn(f"{p.name}: --accent is a neutral grey; fine for a monochrome brand, otherwise it's still a placeholder (references/look.md)")
    if re.search(r"""\|\|\s*["']Motion["']""", text):
        warn(f"{p.name}: NAME is still the template's \"Motion\"; set the product's name")
    demo = [s for s in ("Frame by frame", "frames per second", "With motion blur", '"MOVE"') if s in text]
    if demo:
        warn(f"{p.name}: the template's demo copy is still on screen ({', '.join(demo)}); replace it with the product's lines, or keep it and say so; never invent facts to fill it")
    if re.search(r"urbanist-latin|FAMILY\s*=\s*[\"']Urbanist", text):
        warn(f"{p.name}: the template's demo font (Urbanist) is still in use; use the brand's font or choose one for the subject and write down why (references/look.md)")
    families = [(m.start(), m.group(1)) for m in re.finditer(r"font-family\s*:\s*([^;{}]+)", text, re.I)]
    families += [(m.start(), m.group(1)) for m in re.finditer(r"FAMILY\s*=\s*[\"']([^\"']+)", text)]
    seen = set()
    for at, value in families:
        for m in re.finditer(REFLEX_FONTS, value, re.I):
            if m.group(1).lower() not in seen:
                seen.add(m.group(1).lower())
                warn(f"{p.name}:{line_of(text, at)}: {m.group(1)} is a default model choice; keep it only if it's the brand's font and say so, otherwise choose a typeface for the subject (references/look.md)")
    rules = [
        (r"background-clip\s*:\s*text|backgroundClip\s*=\s*[\"']text", "gradient text; use a solid colour, emphasis through weight, size or motion"),
        (r"backdrop-filter|backdropFilter", "glassmorphism (backdrop-filter); use solid surfaces separated by tone, it also slows every frame"),
        (r"#(?:667eea|764ba2|4f46e5|8b5cf6)\b", "a default purple or indigo; use one accent from the brand's world"),
        (r"[\U0001F300-\U0001FAFF\u2728]", "an emoji as an icon; draw the icon as SVG in one style"),
    ]
    for pattern, msg in rules:
        for m in re.finditer(pattern, text, re.I):
            warn(f"{p.name}:{line_of(text, m.start())}: {msg} (references/look.md)")


def probe(path):
    out = subprocess.run(
        ["ffprobe", "-v", "error", "-show_entries", "format=duration,size:stream=codec_type,width,height,r_frame_rate,nb_frames,pix_fmt",
         "-of", "json", str(path)],
        capture_output=True, text=True,
    )
    if out.returncode != 0:
        error(f"{path}: ffprobe could not read the file ({out.stderr.strip()[:200]})")
        return None
    data = json.loads(out.stdout)
    video = next((s for s in data.get("streams", []) if s.get("codec_type") == "video"), None)
    audio = [s for s in data.get("streams", []) if s.get("codec_type") == "audio"]
    num, _, den = (video or {}).get("r_frame_rate", "0/1").partition("/")
    fps = float(num) / float(den or 1) if video else 0
    return {
        "duration": float(data.get("format", {}).get("duration", 0)),
        "size": int(data.get("format", {}).get("size", 0)),
        "video": video,
        "audio": bool(audio),
        "fps": fps,
        "frames": int((video or {}).get("nb_frames", 0) or 0),
    }


def check_audio(name, info, audio):
    if audio == "no" and info["audio"]:
        error(f"{name}: has an audio track, but the film was meant to be silent")
    if audio == "yes" and not info["audio"]:
        error(f"{name}: no audio track, but sound was agreed")


def check_film(path, length, fmt, fps, audio):
    p = need(path, "film")
    checked.append(f"film {p.name}")
    if p.stat().st_size == 0:
        error(f"{p.name}: the file is 0 bytes (interrupted render?)")
        return None
    info = probe(p)
    if not info:
        return None
    v = info["video"]
    if not v:
        error(f"{p.name}: no video stream")
        return None
    if length is not None and abs(info["duration"] - length) > 0.15:
        error(f"{p.name}: lasts {info['duration']:.2f} s, expected {length:.2f} s")
    if fmt:
        w, _, h = fmt.lower().partition("x")
        if (v.get("width"), v.get("height")) != (int(w), int(h)):
            error(f"{p.name}: is {v.get('width')}×{v.get('height')}, expected {w}×{h}")
    if fps and abs(info["fps"] - fps) > 0.01:
        error(f"{p.name}: is {info['fps']:g} fps, expected {fps:g}")
    if v.get("pix_fmt") != "yuv420p":
        error(f"{p.name}: pixel format {v.get('pix_fmt')}; players and browsers need yuv420p")
    expected = round(info["duration"] * info["fps"])
    if info["frames"] and abs(info["frames"] - expected) > 2:
        warn(f"{p.name}: {info['frames']} frames, but length and fps give {expected}")
    check_audio(p.name, info, audio)
    check_first_frame(p)
    print(f"film {p.name}: {info['duration']:.2f} s, {v.get('width')}×{v.get('height')}, {info['fps']:.0f} fps, "
          f"{info['frames']} frames, {info['size'] / 1e6:.1f} MB, audio: {'yes' if info['audio'] else 'no'}")
    return info


def check_first_frame(path):
    """The first frame is the thumbnail and the start of autoplay: it must not be an almost flat patch of colour."""
    out = subprocess.run(
        ["ffmpeg", "-v", "error", "-i", str(path), "-vf", "select=eq(n\\,0),signalstats,metadata=print:file=-", "-frames:v", "1", "-f", "null", "-"],
        capture_output=True, text=True,
    )
    stats = dict(re.findall(r"lavfi\.signalstats\.(YMIN|YMAX)=(\d+)", out.stdout))
    if len(stats) == 2 and int(stats["YMAX"]) - int(stats["YMIN"]) < 60:
        warn(f"{path.name}: the first frame is almost uniform (brightness {stats['YMIN']}–{stats['YMAX']}); "
             "it is the thumbnail and the first shot of autoplay, so put content in it")


def check_small(path, max_mb, master, audio):
    p = need(path, "version to send")
    checked.append(f"small file {p.name}")
    size = p.stat().st_size
    if size == 0:
        error(f"{p.name}: the file is 0 bytes (interrupted export?)")
        return
    if max_mb is not None and size >= max_mb * 1_000_000:
        error(f"{p.name}: is {size / 1e6:.2f} MB, but the limit is {max_mb} MB")
    info = probe(p)
    if info:
        if master and abs(info["duration"] - master["duration"]) > 0.1:
            error(f"{p.name}: lasts {info['duration']:.2f} s, but the master lasts {master['duration']:.2f} s")
        check_audio(p.name, info, audio)
        print(f"small file {p.name}: {size / 1e6:.2f} MB, {info['duration']:.2f} s")


def check_webp(path):
    p = need(path, "WebP")
    checked.append(f"WebP {p.name}")
    data = p.read_bytes()
    if len(data) == 0:
        error(f"{p.name}: the file is 0 bytes (interrupted export?); GitHub will show a broken image")
        return
    if data[:4] != b"RIFF" or data[8:12] != b"WEBP":
        error(f"{p.name}: this is not a WebP file")
        return
    frames, animated, pos = 0, False, 12
    while pos + 8 <= len(data):
        tag, size = data[pos:pos + 4], struct.unpack("<I", data[pos + 4:pos + 8])[0]
        if tag == b"ANIM":
            animated = True
        if tag == b"ANMF":
            frames += 1
        pos += 8 + size + (size & 1)
    if not animated or frames < 2:
        error(f"{p.name}: the WebP is not an animation (frames: {frames})")
    else:
        print(f"WebP {p.name}: {frames} frames, {len(data) / 1e6:.1f} MB")


def main():
    ap = argparse.ArgumentParser(description="Checks the film and the composition before delivery.")
    ap.add_argument("--composition")
    ap.add_argument("--film")
    ap.add_argument("--length", type=float, help="expected length in seconds")
    ap.add_argument("--format", help="e.g. 1920x1080 or 1080x1920")
    ap.add_argument("--fps", type=float, default=60)
    ap.add_argument("--audio", choices=("yes", "no"), default="no")
    ap.add_argument("--small", help="version to send")
    ap.add_argument("--max-mb", type=float)
    ap.add_argument("--webp")
    a = ap.parse_args()
    if not any((a.composition, a.film, a.small, a.webp)):
        ap.print_help()
        sys.exit(2)
    if (a.film or a.small) and not shutil.which("ffprobe"):
        print("ERROR: ffprobe not found (ffmpeg package)")
        sys.exit(2)
    if a.composition:
        check_composition(a.composition)
    master = check_film(a.film, a.length, a.format, a.fps, a.audio) if a.film else None
    if a.small:
        check_small(a.small, a.max_mb, master, a.audio)
    if a.webp:
        check_webp(a.webp)
    e, w = len(errors), len(warnings)
    print(f"Checked: {', '.join(checked)}. Result: {e} {'error' if e == 1 else 'errors'}, "
          f"{w} {'warning' if w == 1 else 'warnings'}.")
    sys.exit(1 if errors else 0)


if __name__ == "__main__":
    main()
