import { mountCollapse } from './collapse.js';

const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const $ = (id) => document.getElementById(id);

const target = $('target');

import('./hero.js')
	.then(({ mountHero }) => mountHero({ canvas: $('hero-dots'), target, headline: $('headline'), beam: $('beam'), reduced }))
	.catch(() => $('hero-dots').remove()); // No WebGL: the mountains, beam and logo still carry the hero.

import('./voxel.js')
	.then(({ mountVoxel }) =>
		mountVoxel($('voxel'), {
			svgUrl: 'assets/wordpress.svg',
			reduced,
			onAssembled: () => target.classList.add('is-locked'),
		})
	)
	.catch(() => {
		// No WebGL or the CDN is blocked: show the flat mark instead.
		const img = new Image();
		img.src = 'assets/wordpress.svg';
		img.alt = '';
		img.className = 'voxel-fallback';
		$('voxel').replaceWith(img);
		target.classList.add('is-locked');
	});

mountCollapse({
	canvas: $('plugins-grid'),
	section: $('dlaczego'),
	count: $('plugin-count'),
	word: $('plugin-word'),
	reduced,
});

// Module switches behave like the ones in the plugin's settings page.
for (const row of document.querySelectorAll('.switch-row')) {
	const button = row.querySelector('.switch');
	const out = row.querySelector('.switch-out');
	const render = () => {
		const on = button.getAttribute('aria-checked') === 'true';
		row.classList.toggle('is-on', on);
		out.textContent = on ? button.dataset.out : 'wyłączony';
	};
	button.addEventListener('click', () => {
		button.setAttribute('aria-checked', String(button.getAttribute('aria-checked') !== 'true'));
		render();
	});
	render();
}

// One orchestrated entrance, once the type is ready.
(document.fonts?.ready ?? Promise.resolve()).then(() => {
	requestAnimationFrame(() => document.body.classList.add('is-ready'));
});
