// 99 plugins fold into one as the section scrolls by. A few of them blink: those are the
// ones with an open vulnerability right now.

const COLS = 11;
const ROWS = 9;
const SHAKY = new Set([4, 9, 17, 23, 31, 38, 46, 52, 60, 67, 75, 83, 91]);

const ease = (t) => (t < 0.5 ? 2 * t * t : 1 - Math.pow(-2 * t + 2, 2) / 2);
const clamp = (v) => Math.min(1, Math.max(0, v));

export function pluralPlugins(n) {
	if (n === 1) return 'wtyczka';
	const tens = n % 100;
	const ones = n % 10;
	return ones >= 2 && ones <= 4 && (tens < 12 || tens > 14) ? 'wtyczki' : 'wtyczek';
}

export function mountCollapse({ canvas, section, count, word, reduced }) {
	const ctx = canvas.getContext('2d');
	let w = 0;
	let h = 0;
	let tiles = [];
	let progress = 0;
	let visible = false;
	let raf = 0;
	let shown = 99;

	function layout() {
		const r = canvas.getBoundingClientRect();
		const dpr = Math.min(window.devicePixelRatio || 1, 2);
		w = r.width;
		h = r.height;
		canvas.width = Math.round(w * dpr);
		canvas.height = Math.round(h * dpr);
		ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
		const cell = Math.min(w / (COLS + 1), h / (ROWS + 1));
		const ox = (w - cell * COLS) / 2;
		const oy = (h - cell * ROWS) / 2;
		tiles = [];
		for (let i = 0; i < COLS * ROWS; i++) {
			const c = i % COLS;
			const r = Math.floor(i / COLS);
			const x = ox + c * cell + cell / 2;
			const y = oy + r * cell + cell / 2;
			tiles.push({ x, y, size: cell * 0.62, order: Math.hypot(x - w / 2, y - h / 2), shaky: SHAKY.has(i), phase: i * 1.7 });
		}
		// Outer plugins go first, the centre ones last.
		const max = Math.max(...tiles.map((t) => t.order));
		tiles.forEach((t) => (t.order = 1 - t.order / max));
	}

	function measure() {
		const r = section.getBoundingClientRect();
		const vh = window.innerHeight;
		// Starts once the grid is in view and finishes as the section scrolls away.
		progress = clamp((vh * 0.45 - r.top) / (vh * 0.45 + r.height * 0.45));
	}

	function draw(now) {
		ctx.clearRect(0, 0, w, h);
		const cx = w / 2;
		const cy = h / 2;
		let merged = 0;

		for (const t of tiles) {
			const p = ease(clamp((progress - t.order * 0.55) / 0.45));
			if (p >= 1) {
				merged++;
				continue;
			}
			const x = t.x + (cx - t.x) * p;
			const y = t.y + (cy - t.y) * p;
			const s = t.size * (1 - p * 0.7);
			const blink = t.shaky && !reduced ? 0.5 + 0.5 * Math.sin(now / 260 + t.phase) : 0;
			ctx.globalAlpha = 1 - p * 0.6;
			ctx.fillStyle = t.shaky ? `rgba(242, 180, 92, ${0.35 + blink * 0.55})` : 'rgba(138, 149, 179, 0.32)';
			ctx.fillRect(x - s / 2, y - s / 2, s, s);
		}
		ctx.globalAlpha = 1;

		// The one that remains.
		const core = clamp((progress - 0.55) / 0.45);
		if (core > 0) {
			const s = tiles[0].size * (0.8 + core * 1.6);
			const glow = ctx.createRadialGradient(cx, cy, 0, cx, cy, s * 2.4);
			glow.addColorStop(0, `rgba(47, 107, 255, ${0.55 * core})`);
			glow.addColorStop(1, 'rgba(47, 107, 255, 0)');
			ctx.fillStyle = glow;
			ctx.fillRect(cx - s * 2.4, cy - s * 2.4, s * 4.8, s * 4.8);
			const q = s / 3;
			ctx.fillStyle = `rgba(156, 199, 255, ${core})`;
			ctx.fillRect(cx - q / 2, cy - q * 1.5, q, q);
			ctx.fillRect(cx - q / 2, cy + q / 2, q, q);
			ctx.fillStyle = `rgba(47, 107, 255, ${core})`;
			ctx.fillRect(cx - q * 1.5, cy - q / 2, q, q);
			ctx.fillStyle = `rgba(242, 180, 92, ${core})`;
			ctx.fillRect(cx + q / 2, cy - q / 2, q, q);
		}

		const n = Math.max(1, COLS * ROWS - merged);
		if (n !== shown) {
			shown = n;
			count.textContent = String(n);
			word.textContent = pluralPlugins(n);
		}
		if (visible && !reduced) raf = requestAnimationFrame(draw);
	}

	layout();
	measure();
	draw(performance.now());

	window.addEventListener('scroll', () => {
		measure();
		if (reduced) draw(performance.now());
	}, { passive: true });
	window.addEventListener('resize', () => {
		layout();
		measure();
		draw(performance.now());
	});
	new IntersectionObserver(([e]) => {
		visible = e.isIntersecting;
		cancelAnimationFrame(raf);
		if (visible) raf = requestAnimationFrame(draw);
	}).observe(canvas);
}
