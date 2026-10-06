#!/usr/bin/env bash
# SPDX-License-Identifier: CC-BY-4.0
# © 2026 Design House (https://designhouse.me)
#
# Derived versions from the MP4 master. Each result is written under a temporary name and moved
# into place only when complete, so an interrupted export never leaves an empty file.
#
#   export.sh mp4    master.mp4 output.mp4 [MB=24]      # for sending: 2 x264 passes to a target size
#   export.sh webp   master.mp4 output.webp [WIDTH=800] [FPS=15] [Q=55]   # animated WebP, e.g. for a README
#   export.sh sheet  film.mp4 sheet.png [COLUMNS=6]     # one frame per second, to see the whole film
#   export.sh poster film.mp4 SECOND poster.png         # a single frame at full resolution
#   export.sh stills out/stills sheet.png [COLUMNS=4]   # stills from render.mjs --still in one image
#
# Only ffmpeg and ffprobe (optionally Pillow to count WebP frames), no network.
set -euo pipefail

cmd="${1:-}"; shift || true
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT

duration() { ffprobe -v error -show_entries format=duration -of csv=p=0 "$1"; }
fps_of() { ffprobe -v error -select_streams v:0 -show_entries stream=r_frame_rate -of csv=p=0 "$1" | awk -F/ '{ printf "%d", ($2 ? $1 / $2 : $1) + 0.5 }'; }

case "$cmd" in
  mp4)
    src="$1"; out="$2"; mb="${3:-24}"
    # A master that already fits is sent as it is: a second encode at the same size only costs quality.
    size=$(wc -c < "$src" | tr -d ' ')
    if awk -v s="$size" -v mb="$mb" 'BEGIN { exit !(s <= mb * 1e6 * 0.95) }'; then
      echo "the master already fits under ${mb} MB: copied without re-encoding"
      cp "$src" "$tmp/out.mp4"
      mv "$tmp/out.mp4" "$out"
      ls -la "$out"
      exit 0
    fi
    # Size in MB (10^6 bytes) with 5% headroom for the container; audio, if present, gets 128 kb/s.
    has_audio=$(ffprobe -v error -select_streams a -show_entries stream=index -of csv=p=0 "$src" | head -1)
    audio_k=0; audio_args=(-an)
    if [ -n "$has_audio" ]; then audio_k=128; audio_args=(-c:a aac -b:a 128k); fi
    kbps=$(awk -v mb="$mb" -v d="$(duration "$src")" -v a="$audio_k" 'BEGIN { printf "%d", mb * 1e6 * 8 * 0.95 / d / 1000 - a }')
    echo "video bitrate: ${kbps} kb/s"
    for pass in 1 2; do
      if [ "$pass" = 1 ]; then o=(-an -f null /dev/null); else o=("${audio_args[@]}" -movflags +faststart -f mp4 "$tmp/out.mp4"); fi
      ffmpeg -v error -y -i "$src" -c:v libx264 -preset slow -tune animation -b:v "${kbps}k" -maxrate "$((kbps * 16 / 10))k" \
        -bufsize "$((kbps * 2))k" -pix_fmt yuv420p -pass "$pass" -passlogfile "$tmp/x264" "${o[@]}"
    done
    mv "$tmp/out.mp4" "$out"
    ls -la "$out"
    ;;
  webp)
    src="$1"; out="$2"; width="${3:-800}"; fps="${4:-15}"; q="${5:-55}"
    if ffmpeg -hide_banner -encoders 2>/dev/null | grep -q libwebp_anim; then
      # libwebp_anim keeps the frames in memory and writes the file only at the end: a few minutes of silence is normal.
      ffmpeg -v error -y -i "$src" -vf "fps=${fps},scale=${width}:-2:flags=lanczos" \
        -c:v libwebp_anim -lossless 0 -q:v "$q" -compression_level 6 -loop 0 -an -f webp "$tmp/out.webp"
    else
      # ffmpeg without libwebp (e.g. Homebrew's): PNG frames from ffmpeg, the animation from libwebp's img2webp.
      command -v img2webp >/dev/null || { echo "ERROR: ffmpeg has no libwebp_anim and img2webp is missing (brew install webp)" >&2; exit 1; }
      mkdir -p "$tmp/frames"
      ffmpeg -v error -y -i "$src" -vf "fps=${fps},scale=${width}:-2:flags=lanczos" "$tmp/frames/f-%05d.png"
      img2webp -loop 0 -min_size -lossy -q "$q" -m 6 -d $((1000 / fps)) "$tmp"/frames/f-*.png -o "$tmp/out.webp" >/dev/null
    fi
    mv "$tmp/out.webp" "$out"
    # ffmpeg does not decode animated WebP, so we check it with Pillow, if it is installed.
    python3 -c "from PIL import Image; im=Image.open('$out'); print('$out', im.size, im.n_frames, 'frames')" 2>/dev/null || ls -la "$out"
    ;;
  sheet)
    src="$1"; out="$2"; cols="${3:-6}"
    f=$(fps_of "$src")
    # as many tiles as there really are frames on whole seconds (12.000 s at 60 fps gives 12, not 13)
    frames=$(ffprobe -v error -select_streams v:0 -count_packets -show_entries stream=nb_read_packets -of csv=p=0 "$src")
    rows=$(awk -v n="$frames" -v f="$f" -v c="$cols" 'BEGIN { k = int((n + f - 1) / f); printf "%d", (k + c - 1) / c }')
    ffmpeg -v error -y -i "$src" -vf "select='not(mod(n\,${f}))',scale=320:-1,drawtext=text='%{n} s':x=6:y=6:fontsize=16:fontcolor=white:box=1:boxcolor=black@0.7,tile=${cols}x${rows}" \
      -frames:v 1 -f image2 "$tmp/out.png" 2>/dev/null \
      || ffmpeg -v error -y -i "$src" -vf "select='not(mod(n\,${f}))',scale=320:-1,tile=${cols}x${rows}" -frames:v 1 -f image2 "$tmp/out.png"
    mv "$tmp/out.png" "$out"
    ls -la "$out"
    ;;
  stills)
    dir="$1"; out="$2"; cols="${3:-4}"
    # render.mjs clears out/stills/ before each round and names the files still-0012.40.png,
    # so the sheet holds only fresh frames, and alphabetical order = time order.
    n=$( (ls "$dir"/still-*.png 2>/dev/null || true) | wc -l)
    [ "$n" -gt 0 ] || { echo "ERROR: no $dir/still-*.png found; render them first with render.mjs --still" >&2; exit 1; }
    rows=$(( (n + cols - 1) / cols ))
    ffmpeg -v error -y -pattern_type glob -i "$dir/still-*.png" -vf "scale=640:-1,tile=${cols}x${rows}:padding=4:color=black" -frames:v 1 -f image2 "$tmp/out.png"
    mv "$tmp/out.png" "$out"
    ls -la "$out"
    ;;
  poster)
    src="$1"; at="$2"; out="$3"
    ffmpeg -v error -y -ss "$at" -i "$src" -frames:v 1 -f image2 "$tmp/out.png"
    mv "$tmp/out.png" "$out"
    ls -la "$out"
    ;;
  *)
    sed -n '5,13p' "$0"
    exit 1
    ;;
esac
