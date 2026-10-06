// Hero: two halftone hands reaching for the logo, and a pixel beam falling through it onto the panel.
// Everything moves on the GPU: positions, breathing, cursor push and the beam's flicker are
// computed in shaders from per-point attributes, so the CPU does no per-frame work.
import * as THREE from 'three';

const HANDS = [
	{ src: 'assets/hand-robot.webp', side: -1 },
	{ src: 'assets/hand-human.webp', side: 1 },
];

const mix = (a, b, t) => a.map((v, i) => v + (b[i] - v) * t);

// Robot: silver turning into signal blue in the shadows. Human: warm, toward ember.
const robotColour = (l) => (l > 0.6 ? mix([156, 199, 255], [255, 255, 255], (l - 0.6) / 0.4) : mix([47, 90, 230], [156, 199, 255], l / 0.6));
const humanColour = (l) => (l > 0.55 ? mix([242, 180, 92], [255, 236, 214], (l - 0.55) / 0.45) : mix([150, 74, 52], [242, 180, 92], l / 0.55));

function loadImage(src) {
	return new Promise((resolve, reject) => {
		const img = new Image();
		img.onload = () => resolve(img);
		img.onerror = reject;
		img.src = src;
	});
}

function pixels(img) {
	const c = document.createElement('canvas');
	c.width = img.naturalWidth;
	c.height = img.naturalHeight;
	const g = c.getContext('2d', { willReadFrequently: true });
	g.drawImage(img, 0, 0);
	return { w: c.width, h: c.height, data: g.getImageData(0, 0, c.width, c.height).data };
}

const DOT_VERTEX = /* glsl */ `
	attribute vec2 aHome;
	attribute vec2 aStart;
	attribute vec3 aColor;
	attribute float aSize;
	attribute float aPhase;
	attribute float aDelay;
	attribute float aSide;
	uniform float uTime;
	uniform vec2 uRes;
	uniform vec2 uPointer;
	uniform float uPush;
	uniform float uDpr;
	uniform float uMotion;
	varying vec3 vColor;
	varying float vAlpha;

	float easeOutExpo(float t) { return t >= 1.0 ? 1.0 : 1.0 - pow(2.0, -10.0 * t); }

	void main() {
		float intro = uMotion > 0.5 ? easeOutExpo(clamp((uTime - aDelay) / 2.0, 0.0, 1.0)) : 1.0;
		// Both hands lean in and out together, so the gap between the fingertips breathes.
		float breathe = (sin(uTime * 0.8) * 0.5 + 0.5) * 7.0 * -aSide * uMotion;
		float bob = sin(uTime * 0.55 + (aSide > 0.0 ? 3.14159 : 0.0)) * 3.0 * uMotion;
		vec2 home = aHome + vec2(breathe, bob);
		vec2 d = home - uPointer;
		float dist = length(d);
		if (dist < 90.0 && dist > 0.001) {
			home += d / dist * pow(1.0 - dist / 90.0, 2.0) * 34.0 * uPush;
		}
		vec2 pos = mix(aStart, home, intro);
		float twinkle = uMotion > 0.5 ? 0.82 + 0.18 * sin(uTime * 2.2 + aPhase) : 1.0;
		gl_PointSize = aSize * 2.0 * twinkle * (0.4 + 0.6 * intro) * uDpr;
		gl_Position = vec4(pos.x / uRes.x * 2.0 - 1.0, 1.0 - pos.y / uRes.y * 2.0, 0.0, 1.0);
		vColor = aColor;
		vAlpha = intro;
	}
`;

const DOT_FRAGMENT = /* glsl */ `
	varying vec3 vColor;
	varying float vAlpha;
	void main() {
		float d = length(gl_PointCoord - 0.5);
		float a = smoothstep(0.5, 0.38, d) * vAlpha;
		if (a < 0.01) discard;
		gl_FragColor = vec4(vColor, a);
	}
`;

// The beam is a grid of square pixels around a vertical axis: narrow at the top, flaring where
// it lands on the panel. Brightness is stepped and faint pixels are dithered out, so it reads as
// pixels rather than a smooth glow. Packets of light fall down each column.
const BEAM_PITCH = 6;
const beamWidth = (depth) => 14 + 64 * depth * depth + 280 * Math.pow(Math.min(1, Math.max(0, (depth - 0.8) / 0.2)), 2);

const BEAM_VERTEX = /* glsl */ `
	attribute vec2 aCell;
	attribute float aSeed;
	uniform float uTime;
	uniform vec2 uRes;
	uniform float uAxis;
	uniform float uFloor;
	uniform float uFade;
	uniform float uDpr;
	varying vec3 vColor;
	varying float vAlpha;

	float hash(float n) { return fract(sin(n) * 43758.5453); }

	void main() {
		float depth = clamp(aCell.y / uFloor, 0.0, 1.0);
		float flare = clamp((depth - 0.8) / 0.2, 0.0, 1.0);
		float width = 14.0 + 64.0 * depth * depth + 280.0 * flare * flare;
		float d = abs(aCell.x) / width;
		float body = exp(-d * d * 2.2);
		float core = exp(-pow(aCell.x / 5.0, 2.0)) * (0.55 + 0.45 * depth);

		float column = hash(aCell.x * 0.173 + 3.1);
		float head = fract(column * 5.3 + uTime * (0.16 + column * 0.28));
		float tail = head - depth;
		float packet = tail >= 0.0 && tail < 0.14 ? 1.0 - tail / 0.14 : 0.0;
		float twinkle = hash(aSeed * 71.0 + floor(uTime * 9.0 + aSeed * 13.0));

		float light = body * (0.3 + 0.3 * twinkle) + packet * body * 0.85 + core + flare * flare * body * 0.6;
		light *= smoothstep(0.0, 0.3, depth) * uFade;
		if (light < 0.16 && aSeed > light * 6.0) light = 0.0;
		light = floor(clamp(light, 0.0, 1.0) * 5.0 + 0.5) / 5.0;

		vec3 cold = vec3(0.24, 0.42, 1.0);
		vec3 ice = vec3(0.66, 0.8, 1.0);
		vec3 warm = vec3(1.0, 0.72, 0.42);
		vec3 colour = mix(cold, ice, smoothstep(0.2, 0.6, light));
		colour = mix(colour, vec3(1.0), smoothstep(0.7, 1.0, light));
		colour = mix(colour, warm, flare * step(0.0, aCell.x) * (1.0 - light) * 0.8);

		vColor = colour;
		vAlpha = light;
		gl_PointSize = float(${BEAM_PITCH - 1}) * uDpr;
		gl_Position = vec4((uAxis + aCell.x) / uRes.x * 2.0 - 1.0, 1.0 - aCell.y / uRes.y * 2.0, 0.0, 1.0);
	}
`;

const BEAM_FRAGMENT = /* glsl */ `
	varying vec3 vColor;
	varying float vAlpha;
	void main() {
		if (vAlpha < 0.01) discard;
		gl_FragColor = vec4(vColor * vAlpha, vAlpha);
	}
`;

export async function mountHero({ canvas, target, panel, reduced }) {
	const [robot, human] = await Promise.all(HANDS.map((h) => loadImage(h.src)));
	const sources = [
		{ ...HANDS[0], px: pixels(robot), colour: robotColour },
		{ ...HANDS[1], px: pixels(human), colour: humanColour },
	];

	const renderer = new THREE.WebGLRenderer({ canvas, alpha: true, antialias: false, powerPreference: 'high-performance' });
	renderer.setClearColor(0x000000, 0);
	const scene = new THREE.Scene();
	const camera = new THREE.Camera(); // positions are already in clip space

	const uniforms = {
		uTime: { value: 0 },
		uRes: { value: new THREE.Vector2(1, 1) },
		uPointer: { value: new THREE.Vector2(-9999, -9999) },
		uPush: { value: 0 },
		uDpr: { value: 1 },
		uMotion: { value: reduced ? 0 : 1 },
		uAxis: { value: 0 },
		uFloor: { value: 1 },
		uFade: { value: reduced ? 1 : 0 },
	};

	const dotMaterial = new THREE.ShaderMaterial({ uniforms, vertexShader: DOT_VERTEX, fragmentShader: DOT_FRAGMENT, transparent: true, depthTest: false });
	const beamMaterial = new THREE.ShaderMaterial({
		uniforms,
		vertexShader: BEAM_VERTEX,
		fragmentShader: BEAM_FRAGMENT,
		transparent: true,
		depthTest: false,
		blending: THREE.AdditiveBlending,
	});
	let dots = null;
	let beam = null;
	let emitter = { x: 0, y: 0, size: 0 };

	function layout() {
		const rect = canvas.getBoundingClientRect();
		const dpr = Math.min(window.devicePixelRatio || 1, 2);
		renderer.setPixelRatio(dpr);
		renderer.setSize(rect.width, rect.height, false);
		uniforms.uRes.value.set(rect.width, rect.height);
		uniforms.uDpr.value = dpr;

		const t = target.getBoundingClientRect();
		emitter = { x: t.left - rect.left + t.width / 2, y: t.top - rect.top + t.height / 2, size: t.width };
		// Layout offsets, not the bounding box: the panel is still sliding in when this first runs.
		const floor = panel.offsetTop;
		uniforms.uAxis.value = emitter.x;
		uniforms.uFloor.value = floor;
		panel.style.setProperty('--impact', `${(emitter.x - panel.offsetLeft).toFixed(1)}px`);

		if (dots) scene.remove(dots), dots.geometry.dispose();
		if (beam) scene.remove(beam), beam.geometry.dispose();
		dots = new THREE.Points(buildDots(rect.width, rect.height), dotMaterial);
		beam = new THREE.Points(buildBeam(floor), beamMaterial);
		dots.frustumCulled = false;
		beam.frustumCulled = false;
		scene.add(beam, dots);
	}

	// Dots for one photo drawn drawW wide. The edge the arm comes in from stays sharp (it sits past
	// the screen edge); the others fade so the photo's frame never shows as a straight cut.
	function sample(px, drawW, spacing, side) {
		const scale = drawW / px.w;
		const drawH = px.h * scale;
		const raw = [];
		let tip = null;
		for (let y = 0; y < drawH; y += spacing) {
			for (let x = 0; x < drawW; x += spacing) {
				const u = Math.min(px.w - 1, Math.floor(x / scale));
				const v = Math.min(px.h - 1, Math.floor(y / scale));
				const i = (v * px.w + u) * 4;
				const e = Math.min(1, Math.min(v, px.h - 1 - v, side < 0 ? px.w - 1 - u : u) / (px.w * 0.2));
				const edge = e * e * (3 - 2 * e);
				const l = (edge * (0.2126 * px.data[i] + 0.7152 * px.data[i + 1] + 0.0722 * px.data[i + 2])) / 255;
				if (l < 0.06) continue;
				raw.push(x, y, l);
				if (!tip || (side < 0 ? x > tip.x : x < tip.x)) tip = { x, y };
			}
		}
		return { raw, tip };
	}

	// Sample each photo on a dot grid, then slide the hand so its fingertip waits beside the logo.
	function buildDots(w, h) {
		const spacing = w < 700 ? 6 : w < 1100 ? 7 : 8;
		const home = [];
		const start = [];
		const colour = [];
		const size = [];
		const phase = [];
		const delay = [];
		const side = [];

		for (const source of sources) {
			const { px } = source;
			const gap = emitter.size * 0.62;
			const tipX = source.side < 0 ? emitter.x - gap : emitter.x + gap;
			let drawW = Math.max(w * (w < 700 ? 0.7 : 0.6), 380);
			let { raw, tip } = sample(px, drawW, spacing, source.side);
			// Grow the hand until its arm runs past the screen edge instead of stopping short of it.
			const reach = source.side < 0 ? tip.x : drawW - tip.x;
			const room = (source.side < 0 ? tipX : w - tipX) + spacing * 2;
			if (reach < room) {
				drawW = Math.min((drawW * room) / reach, w * 1.2);
				({ raw, tip } = sample(px, drawW, spacing, source.side));
			}
			const dx = tipX - tip.x;
			const dy = emitter.y + emitter.size * 0.08 - tip.y;
			for (let k = 0; k < raw.length; k += 3) {
				const l = Math.min(1, raw[k + 2] * 1.2);
				const hx = raw[k] + dx;
				const hy = raw[k + 1] + dy;
				home.push(hx, hy);
				// Each dot arrives from far off-screen along a random bearing, so nothing appears in a box.
				const angle = Math.random() * Math.PI * 2;
				const reach = Math.hypot(w, h) * (0.7 + Math.random() * 0.6);
				start.push(hx + Math.cos(angle) * reach, hy + Math.sin(angle) * reach);
				const [r, g, b] = source.colour(l);
				colour.push(r / 255, g / 255, b / 255);
				size.push(spacing * 0.48 * Math.max(0.28, Math.pow(l, 0.45)));
				phase.push(Math.random() * Math.PI * 2);
				delay.push(Math.random() * 0.7);
				side.push(source.side);
			}
		}

		const geometry = new THREE.BufferGeometry();
		geometry.setAttribute('position', new THREE.Float32BufferAttribute(new Float32Array(size.length * 3), 3));
		geometry.setAttribute('aHome', new THREE.Float32BufferAttribute(home, 2));
		geometry.setAttribute('aStart', new THREE.Float32BufferAttribute(start, 2));
		geometry.setAttribute('aColor', new THREE.Float32BufferAttribute(colour, 3));
		geometry.setAttribute('aSize', new THREE.Float32BufferAttribute(size, 1));
		geometry.setAttribute('aPhase', new THREE.Float32BufferAttribute(phase, 1));
		geometry.setAttribute('aDelay', new THREE.Float32BufferAttribute(delay, 1));
		geometry.setAttribute('aSide', new THREE.Float32BufferAttribute(side, 1));
		return geometry;
	}

	// Only cells inside the beam's envelope get a point; the shader decides how bright each one is.
	function buildBeam(floor) {
		const cells = [];
		const seeds = [];
		for (let y = BEAM_PITCH / 2; y < floor; y += BEAM_PITCH) {
			const reach = beamWidth(y / floor) * 2.2;
			for (let x = BEAM_PITCH / 2; x < reach; x += BEAM_PITCH) {
				cells.push(x, y, -x, y);
				seeds.push(Math.random(), Math.random());
			}
		}
		const geometry = new THREE.BufferGeometry();
		geometry.setAttribute('position', new THREE.Float32BufferAttribute(new Float32Array(seeds.length * 3), 3));
		geometry.setAttribute('aCell', new THREE.Float32BufferAttribute(cells, 2));
		geometry.setAttribute('aSeed', new THREE.Float32BufferAttribute(seeds, 1));
		return geometry;
	}

	// Smooth the cursor so the push eases in and out instead of snapping.
	const pointer = { x: -9999, y: -9999, active: false, push: 0 };
	const hero = canvas.parentElement;
	hero.addEventListener('pointermove', (e) => {
		const r = canvas.getBoundingClientRect();
		pointer.x = e.clientX - r.left;
		pointer.y = e.clientY - r.top;
		pointer.active = true;
	});
	hero.addEventListener('pointerleave', () => (pointer.active = false));

	let born = performance.now();
	let running = false;
	let raf = 0;

	function frame(now) {
		const t = (now - born) / 1000;
		uniforms.uTime.value = reduced ? 10 : t;
		uniforms.uFade.value = reduced ? 1 : Math.min(1, Math.max(0, (t - 0.9) / 1.4));
		pointer.push += ((pointer.active && !reduced ? 1 : 0) - pointer.push) * 0.12;
		uniforms.uPush.value = pointer.push;
		const p = uniforms.uPointer.value;
		if (pointer.active) p.set(p.x < -9000 ? pointer.x : p.x + (pointer.x - p.x) * 0.25, p.y < -9000 ? pointer.y : p.y + (pointer.y - p.y) * 0.25);
		renderer.render(scene, camera);
		if (running) raf = requestAnimationFrame(frame);
	}

	function start() {
		if (reduced || running || document.hidden) return;
		running = true;
		raf = requestAnimationFrame(frame);
	}
	function stop() {
		running = false;
		cancelAnimationFrame(raf);
	}

	let resizeTimer = 0;
	window.addEventListener('resize', () => {
		clearTimeout(resizeTimer);
		resizeTimer = setTimeout(() => {
			layout();
			if (!running) frame(performance.now());
		}, 150);
	});
	new IntersectionObserver(([e]) => (e.isIntersecting ? start() : stop())).observe(canvas);
	document.addEventListener('visibilitychange', () => (document.hidden ? stop() : start()));

	layout();
	born = performance.now();
	frame(born);
	start();
}
