const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const $ = (id) => document.getElementById(id);

const target = $('target');

import('./hero.js')
	.then(({ mountHero }) => mountHero({ canvas: $('hero-dots'), target, panel: $('panel'), reduced }))
	.catch(() => $('hero-dots').remove()); // No WebGL: the mountains, logo and panel still carry the hero.

import('./voxel.js')
	.then(({ mountVoxel }) => mountVoxel($('voxel'), { svgUrl: 'assets/wordpress.svg', reduced }))
	.catch(() => {
		// No WebGL or the CDN is blocked: show the flat mark instead.
		const img = new Image();
		img.src = 'assets/wordpress.svg';
		img.alt = '';
		img.className = 'voxel-fallback';
		$('voxel').replaceWith(img);
	});

// The plugin strip moves on its own, so it gets a button to stop it.
const strip = $('zastepuje');
strip.querySelector('.mq-toggle').addEventListener('click', (e) => {
	const paused = strip.classList.toggle('is-paused');
	e.currentTarget.setAttribute('aria-pressed', String(paused));
	e.currentTarget.textContent = paused ? 'Wznów' : 'Zatrzymaj';
});

requestAnimationFrame(() => document.body.classList.add('is-ready'));
