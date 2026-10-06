// Hero: two halftone hands reaching for the logo and the stream of light falling from it.
// Everything moves on the GPU: positions, breathing, cursor push and the falling streaks are
// computed in shaders from per-dot attributes, so the CPU does no per-frame work.
import * as THREE from 'three';

const HANDS = [
	{ src: 'assets/hand-robot.webp', side: -1 },
	{ src: 'assets/hand-human.webp', side: 1 },
];

const randn = () => (Math.random() + Math.random() + Math.random() - 1.5) / 1.5;
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
		float intro = uMotion > 0.5 ? easeOutExpo(clamp((uTime - aDelay) / 1.6, 0.0, 1.0)) : 1.0;
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
		vAlpha = 0.25 + 0.75 * intro;
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

const STREAK_VERTEX = /* glsl */ `
	attribute float aSeed;
	attribute float aSpeed;
	attribute float aDrift;
	attribute float aWarm;
	uniform float uTime;
	uniform vec2 uRes;
	uniform vec2 uEmitter;
	uniform float uFade;
	uniform float uDpr;
	varying float vAlpha;
	varying float vWarm;
	void main() {
		float span = uRes.y - uEmitter.y;
		float travel = mod(aSeed * span + uTime * aSpeed * 60.0, span);
		float depth = travel / span;
		// Falls slowly near the logo, faster and wider as it drops.
		float y = uEmitter.y + travel * (0.6 + depth * 0.4);
		float x = uEmitter.x + aDrift * depth * depth * 140.0;
		gl_PointSize = (10.0 + 26.0 * depth) * uDpr;
		gl_Position = vec4(x / uRes.x * 2.0 - 1.0, 1.0 - y / uRes.y * 2.0, 0.0, 1.0);
		vAlpha = (1.0 - depth) * 0.9 * uFade;
		vWarm = aWarm;
	}
`;

const STREAK_FRAGMENT = /* glsl */ `
	varying float vAlpha;
	varying float vWarm;
	void main() {
		vec2 p = gl_PointCoord - 0.5;
		float line = smoothstep(0.06, 0.0, abs(p.x)) * smoothstep(0.5, 0.0, abs(p.y));
		vec3 cold = vec3(0.61, 0.78, 1.0);
		vec3 warm = vec3(0.95, 0.71, 0.36);
		float a = line * vAlpha;
		if (a < 0.01) discard;
		gl_FragColor = vec4(mix(cold, warm, vWarm) * a, a);
	}
`;

export async function mountHero({ canvas, target, beam, reduced }) {
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
		uEmitter: { value: new THREE.Vector2() },
		uFade: { value: reduced ? 0 : 0 },
	};

	const dotMaterial = new THREE.ShaderMaterial({ uniforms, vertexShader: DOT_VERTEX, fragmentShader: DOT_FRAGMENT, transparent: true, depthTest: false });
	const streakMaterial = new THREE.ShaderMaterial({
		uniforms,
		vertexShader: STREAK_VERTEX,
		fragmentShader: STREAK_FRAGMENT,
		transparent: true,
		depthTest: false,
		blending: THREE.AdditiveBlending,
	});
	let dots = null;
	let streaks = null;
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
		uniforms.uEmitter.value.set(emitter.x, emitter.y + emitter.size * 0.36);

		if (dots) scene.remove(dots), dots.geometry.dispose();
		if (streaks) scene.remove(streaks), streaks.geometry.dispose();
		dots = new THREE.Points(buildDots(rect.width, rect.height), dotMaterial);
		streaks = new THREE.Points(buildStreaks(rect.width < 700 ? 140 : 260), streakMaterial);
		dots.frustumCulled = false;
		streaks.frustumCulled = false;
		scene.add(streaks, dots);

		// The CSS beam core sits on the same x.
		beam.style.left = `${emitter.x}px`;
		beam.style.top = `${emitter.y + emitter.size * 0.34}px`;
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
			const drawW = Math.max(w * (w < 700 ? 0.7 : 0.6), 380);
			const scale = drawW / px.w;
			const drawH = px.h * scale;
			const raw = [];
			let tip = null;
			for (let y = 0; y < drawH; y += spacing) {
				for (let x = 0; x < drawW; x += spacing) {
					const u = Math.min(px.w - 1, Math.floor(x / scale));
					const v = Math.min(px.h - 1, Math.floor(y / scale));
					const i = (v * px.w + u) * 4;
					const l = (0.2126 * px.data[i] + 0.7152 * px.data[i + 1] + 0.0722 * px.data[i + 2]) / 255;
					if (l < 0.06) continue;
					raw.push(x, y, l);
					if (!tip || (source.side < 0 ? x > tip.x : x < tip.x)) tip = { x, y };
				}
			}
			const gap = emitter.size * 0.62;
			const dx = (source.side < 0 ? emitter.x - gap : emitter.x + gap) - tip.x;
			const dy = emitter.y + emitter.size * 0.08 - tip.y;
			for (let k = 0; k < raw.length; k += 3) {
				const l = Math.min(1, raw[k + 2] * 1.2);
				const hx = raw[k] + dx;
				const hy = raw[k + 1] + dy;
				home.push(hx, hy);
				start.push(hx + randn() * w * 0.45, hy + randn() * h * 0.45);
				const [r, g, b] = source.colour(l);
				colour.push(r / 255, g / 255, b / 255);
				size.push(spacing * 0.48 * Math.max(0.28, Math.pow(l, 0.45)));
				phase.push(Math.random() * Math.PI * 2);
				delay.push(Math.random() * 0.55);
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

	function buildStreaks(count) {
		const geometry = new THREE.BufferGeometry();
		geometry.setAttribute('position', new THREE.Float32BufferAttribute(new Float32Array(count * 3), 3));
		geometry.setAttribute('aSeed', new THREE.Float32BufferAttribute(Array.from({ length: count }, Math.random), 1));
		geometry.setAttribute('aSpeed', new THREE.Float32BufferAttribute(Array.from({ length: count }, () => 0.6 + Math.random() * 1.6), 1));
		geometry.setAttribute('aDrift', new THREE.Float32BufferAttribute(Array.from({ length: count }, () => randn()), 1));
		geometry.setAttribute('aWarm', new THREE.Float32BufferAttribute(Array.from({ length: count }, () => (Math.random() < 0.12 ? 1 : 0)), 1));
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
		uniforms.uFade.value = reduced ? 0 : Math.min(1, Math.max(0, (t - 1.2) / 1.2));
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
