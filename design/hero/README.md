# README hero

The animation at the top of the repository README. `index.html` is the composition: every frame is a function of time, so the same file plays in a browser and renders frame by frame to video.

- Open `index.html` in a browser to watch it live: space pauses, the arrow keys jump a second, `?t=8.6` shows one frame.
- The plugin slugs and the module each one folds into are copied from `PluginHealth::REPLACEABLE` (`plugin/src/Modules/PluginHealth.php`), and the module names from each module's `label()`. When either changes, update `GROUPS` in `index.html` and render again.
- The "99+" bubble is a WordPress-style count meaning "a lot", not a number from the code. It is the `PILE` constant.
- Fonts: Geist and Geist Mono, as on the landing page, under the SIL Open Font License (`fonts/OFL.txt`).

## Render

You need Node 22+, ffmpeg, Chrome or Chromium and Python 3. If your ffmpeg has no `libwebp_anim` encoder (Homebrew's doesn't), `export.sh` uses `img2webp` from libwebp instead (`brew install webp`).

```sh
cd design/hero
node render.mjs --src index.html --still 0,3.8,8.6          # stills in out/stills/
node render.mjs --src index.html --out film.mp4              # the 60 fps master, out/film.mp4
./export.sh webp out/film.mp4 hero.webp 1200 20 70           # the README animation
./export.sh poster out/film.mp4 8.6 out/poster.png && cwebp -quiet -q 82 out/poster.png -o hero-still.webp
python3 check_film.py --composition index.html --film out/film.mp4 --webp hero.webp
```

`out/` is ignored by git. Commit `hero.webp` and `hero-still.webp` (the frame shown to visitors who ask for reduced motion).

`render.mjs`, `export.sh` and `check_film.py` come from the Design House motion-design toolkit (CC BY 4.0).
