// The WordPress mark rebuilt from glowing voxels: blue light from the robot's side,
// warm light from the human's side. Assembles once, then breathes; click to spin it.
import * as THREE from 'three';

const GRID = 30;
const DEPTH = 2;
const easeOutCubic = (t) => 1 - Math.pow(1 - Math.min(1, Math.max(0, t)), 3);
const easeInOutCubic = (t) => (t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2);

function loadImage(src) {
	return new Promise((resolve, reject) => {
		const img = new Image();
		img.onload = () => resolve(img);
		img.onerror = reject;
		img.src = src;
	});
}

async function cellsFromSvg(url) {
	const img = await loadImage(url);
	const c = document.createElement('canvas');
	c.width = GRID;
	c.height = GRID;
	const g = c.getContext('2d', { willReadFrequently: true });
	g.drawImage(img, 0, 0, GRID, GRID);
	const data = g.getImageData(0, 0, GRID, GRID).data;
	const cells = [];
	for (let y = 0; y < GRID; y++) {
		for (let x = 0; x < GRID; x++) {
			if (data[(y * GRID + x) * 4 + 3] > 100) cells.push([x, y]);
		}
	}
	return cells;
}

export async function mountVoxel(canvas, { svgUrl, reduced, onAssembled }) {
	const cells = await cellsFromSvg(svgUrl);

	const renderer = new THREE.WebGLRenderer({ canvas, alpha: true, antialias: true, powerPreference: 'high-performance' });
	renderer.setClearColor(0x000000, 0);
	renderer.outputColorSpace = THREE.SRGBColorSpace;
	renderer.toneMapping = THREE.ACESFilmicToneMapping;
	renderer.toneMappingExposure = 1.15;

	const scene = new THREE.Scene();
	const camera = new THREE.PerspectiveCamera(30, 1, 0.1, 400);
	camera.position.set(0, 0, GRID * 2.35);

	scene.add(new THREE.AmbientLight(0x3a4f8f, 1.1));
	const key = new THREE.DirectionalLight(0xffffff, 1.6);
	key.position.set(6, 12, 18);
	scene.add(key);
	const blue = new THREE.PointLight(0x2f6bff, 2600, 120, 1.6);
	blue.position.set(-26, 4, 22);
	scene.add(blue);
	const gold = new THREE.PointLight(0xf2b45c, 1700, 120, 1.6);
	gold.position.set(26, -6, 20);
	scene.add(gold);

	const material = new THREE.MeshPhysicalMaterial({
		color: 0xffffff,
		emissive: 0x1a3fd6,
		emissiveIntensity: 0.55,
		roughness: 0.22,
		metalness: 0.12,
		clearcoat: 1,
		clearcoatRoughness: 0.12,
		iridescence: 0.75,
		iridescenceIOR: 1.35,
	});
	const geometry = new THREE.BoxGeometry(0.9, 0.9, 0.9);
	const count = cells.length * DEPTH;
	const mesh = new THREE.InstancedMesh(geometry, material, count);
	mesh.instanceMatrix.setUsage(THREE.DynamicDrawUsage);

	const group = new THREE.Group();
	group.add(mesh);
	scene.add(group);

	const half = GRID / 2 - 0.5;
	const ice = new THREE.Color(0x9cc7ff);
	const signal = new THREE.Color(0x4f86ff);
	const ember = new THREE.Color(0xf2c48a);
	const voxels = [];
	const tmp = new THREE.Color();
	for (const [x, y] of cells) {
		for (let z = 0; z < DEPTH; z++) {
			const home = new THREE.Vector3(x - half, half - y, z - (DEPTH - 1) / 2);
			const dir = new THREE.Vector3().randomDirection().multiplyScalar(GRID * (1.2 + Math.random()));
			voxels.push({
				home,
				from: home.clone().add(dir),
				delay: Math.random() * 0.5 + (Math.hypot(home.x, home.y) / GRID) * 0.4,
				phase: (x + y) * 0.35,
			});
			// Cool at the top-left, warming toward the human hand at the bottom-right.
			const warm = Math.max(0, (x - y * 0.4) / GRID - 0.35) * 1.4;
			tmp.copy(ice).lerp(signal, y / GRID).lerp(ember, Math.min(0.55, warm));
			mesh.setColorAt(voxels.length - 1, tmp);
		}
	}
	mesh.instanceColor.needsUpdate = true;

	const dummy = new THREE.Object3D();
	const pointer = { x: 0, y: 0 };
	const spin = { from: 0, start: -1 };
	let born = performance.now();
	let assembled = false;
	let running = false;
	let raf = 0;

	function place(t) {
		for (let i = 0; i < voxels.length; i++) {
			const v = voxels[i];
			const k = reduced ? 1 : easeOutCubic((t - v.delay) / 1.3);
			dummy.position.lerpVectors(v.from, v.home, k);
			if (!reduced && k >= 1) {
				// A slow wave runs across the mark once it has assembled.
				dummy.position.z += Math.sin(t * 1.7 - v.phase) * 0.22;
			}
			const s = reduced ? 1 : 0.35 + 0.65 * k;
			dummy.scale.setScalar(s);
			dummy.rotation.set((1 - k) * 2.2, (1 - k) * 1.4, 0);
			dummy.updateMatrix();
			mesh.setMatrixAt(i, dummy.matrix);
		}
		mesh.instanceMatrix.needsUpdate = true;
	}

	function frame(now) {
		const t = (now - born) / 1000;
		place(t);
		if (!reduced) {
			let extra = 0;
			if (spin.start >= 0) {
				const p = (now - spin.start) / 1300;
				extra = easeInOutCubic(Math.min(1, p)) * Math.PI * 2;
				if (p >= 1) spin.start = -1;
			}
			group.rotation.y = Math.sin(t * 0.45) * 0.42 + pointer.x * 0.35 + extra;
			group.rotation.x = Math.sin(t * 0.33) * 0.12 - pointer.y * 0.25;
			group.position.y = Math.sin(t * 0.9) * 0.4;
			material.emissiveIntensity = 0.5 + Math.sin(t * 2.1) * 0.12;
		}
		renderer.render(scene, camera);

		if (!assembled && (reduced || t > 2.2)) {
			assembled = true;
			onAssembled?.();
		}
		if (running) raf = requestAnimationFrame(frame);
	}

	function resize() {
		const { width, height } = canvas.getBoundingClientRect();
		renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
		renderer.setSize(width, height, false);
		camera.aspect = width / height || 1;
		camera.updateProjectionMatrix();
		if (!running) frame(performance.now());
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

	new ResizeObserver(resize).observe(canvas);
	new IntersectionObserver(([e]) => (e.isIntersecting ? start() : stop())).observe(canvas);
	document.addEventListener('visibilitychange', () => (document.hidden ? stop() : start()));
	window.addEventListener('pointermove', (e) => {
		pointer.x = (e.clientX / window.innerWidth) * 2 - 1;
		pointer.y = (e.clientY / window.innerHeight) * 2 - 1;
	});
	canvas.addEventListener('click', () => {
		if (!reduced && spin.start < 0) spin.start = performance.now();
	});

	born = performance.now();
	resize();
	start();
}
