const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const $ = (id) => document.getElementById(id);

const target = $('target');

import('./hero.js')
	.then(({ mountHero }) => mountHero({ canvas: $('hero-dots'), target, beam: $('beam'), reduced }))
	.catch(() => $('hero-dots').remove()); // No WebGL: the mountains, beam and logo still carry the hero.

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

requestAnimationFrame(() => document.body.classList.add('is-ready'));
