/**
 * Render the wizard's own screens outside wp-admin.
 *
 * Same loader as bin/fidelity-preview.cjs: Babel does webpack's two transforms
 * and the real component renders, so what is looked at is what ships. The
 * wizard needs a few WordPress globals its imports reach for, which are stubbed
 * here at the level of "renders its markup", not "works".
 *
 * usage: node bin/wizard-preview.cjs <out.html>
 *
 * The file has to be served from somewhere the stylesheet's own `#dxai-ui-app`
 * scope behaves — copying it into the Local site's webroot and opening it there
 * is what this was checked with; a file:// page renders but the app cannot be
 * driven.
 *
 * @package DXAI_UI
 */
const fs = require('fs');
const path = require('path');
const babel = require('@babel/core');
const { renderToStaticMarkup } = require('react-dom/server');

const repo = path.join(__dirname, '..');
const out = process.argv[2] || path.join(repo, 'wizard-preview.html');

function load(relative, cache = {}) {
	const file = path.join(repo, relative);
	if (cache[file]) return cache[file].exports;
	const code = babel.transformFileSync(file, {
		configFile: false,
		babelrc: false,
		presets: [
			[require.resolve('@babel/preset-react', { paths: [repo] }), { runtime: 'classic', pragma: 'createElement', pragmaFrag: 'Fragment' }],
			[require.resolve('@babel/preset-env', { paths: [repo] }), { targets: { node: 'current' }, modules: 'commonjs' }],
		],
	}).code;
	const mod = { exports: {} };
	cache[file] = mod;
	const req = (request) => {
		/*
		 * @wordpress/components is a webpack external in the real build: the
		 * bundle reads it off window.wp at runtime and never installs its
		 * dependency tree for node. These stubs render the same elements the
		 * controls render, which is all a look at the layout needs.
		 */
		if (request === '@wordpress/components') {
			const { createElement: h } = require(require.resolve('@wordpress/element', { paths: [repo] }));
			const box = (tag, extra) => (props = {}) => {
				const { children, label, help, value, options, ...rest } = props;
				delete rest.onChange; delete rest.onClick; delete rest.isDismissible;
				delete rest.variant; delete rest.status; delete rest.rows; delete rest.type;
				return h('div', { className: 'components-base-control ' + extra },
					label ? h('label', { className: 'components-base-control__label' }, label) : null,
					tag === 'select'
						? h('select', rest, (options || []).map((o, i) => h('option', { key: i, value: o.value }, o.label)))
						: h(tag, { ...rest, value: value === undefined ? undefined : String(value) }, children),
					help ? h('p', { className: 'components-base-control__help' }, help) : null
				);
			};
			return {
				Button: (props) => h('button', { type: 'button', className: 'components-button is-' + (props.variant || 'secondary'), disabled: props.disabled, href: props.href }, props.children),
				Notice: (props) => h('div', { className: 'components-notice is-' + (props.status || 'info') }, props.children),
				Spinner: () => h('span', { className: 'components-spinner' }),
				TextControl: box('input', 'is-text'),
				TextareaControl: box('textarea', 'is-textarea'),
				SelectControl: box('select', 'is-select'),
			};
		}
		if (request.startsWith('.')) {
			const next = path.relative(repo, path.resolve(path.dirname(file), request)).replace(/\\/g, '/');
			return load(next.endsWith('.js') ? next : next + '.js', cache);
		}
		return require(require.resolve(request, { paths: [repo] }));
	};
	const { createElement, Fragment } = require(require.resolve('@wordpress/element', { paths: [repo] }));
	new Function('require', 'module', 'exports', 'createElement', 'Fragment', code)(req, mod, mod.exports, createElement, Fragment);
	return mod.exports;
}

const { createElement } = require(require.resolve('@wordpress/element', { paths: [repo] }));

// The wizard fetches settings on mount; server rendering never runs the effect.
global.window = global.window || { FormData: class {} };
global.navigator = global.navigator || {};

const Wizard = load('assets/src/admin/screens/ConvertWizard.js').default;
const markup = renderToStaticMarkup(createElement(Wizard));
const css = fs.readFileSync(path.join(repo, 'assets/css/admin.css'), 'utf8');

fs.writeFileSync(
	out,
	`<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Convert wizard</title><style>${css}</style>
<style>body{margin:0;background:#090d12;} .harness{max-width:1080px;margin:0 auto;padding:28px 20px 64px;}</style>
</head><body class="dxai-ui-wrap">
<div id="dxai-ui-app" class="dxai-ui-admin"><div class="harness">${markup}</div></div>
</body></html>
`
);
console.log('wrote ' + out);
