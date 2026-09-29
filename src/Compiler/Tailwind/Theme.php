<?php
/**
 * Tailwind v4 token registry: the built-in defaults plus a design's own theme.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Compiler\Tailwind;

/**
 * Every utility module resolves its named values through this registry.
 *
 * Two sources are merged and the design always wins. The built-in half is the
 * Tailwind v4 default theme, hard coded, so a design that declares no tokens of
 * its own still compiles like stock Tailwind. The design half is scraped from
 * the `@theme` blocks of the converted stylesheet, with a best effort pass over
 * a legacy `tailwind.config.js` when one is supplied.
 *
 * A design token resolves to a `var()` reference rather than to the literal it
 * was declared with: `--color-ara-navy: var(--ara-navy)` makes `bg-ara-navy`
 * emit `var(--color-ara-navy)`, and `custom_properties()` hands the caller that
 * declaration to place on the scope wrapper. The indirection is the whole point
 * — it keeps the design's own `:root` and `.dark` overrides live after
 * conversion. Built-in tokens have no custom property to point at, so they
 * resolve to their literal value instead.
 */
final class Theme {

	/** Value of `--spacing` when the design does not override it. */
	private const SPACING_BASE = '0.25rem';

	/**
	 * Namespaces `value()` answers for. Anything else is not a token space and
	 * gets a null so the calling module knows to stop looking.
	 *
	 * @var array<int, string>
	 */
	private const NAMESPACES = array(
		'color',
		'spacing',
		'breakpoint',
		'container',
		'radius',
		'text',
		'text-shadow',
		'font',
		'font-weight',
		'tracking',
		'leading',
		'shadow',
		'inset-shadow',
		'drop-shadow',
		'blur',
		'ease',
		'duration',
		'animate',
		'aspect',
		'z',
		'opacity',
		'order',
		'columns',
		'perspective',
	);

	/**
	 * `--color-*` from the v4 default theme, plus the four keyword colours v4
	 * resolves inside the colour namespace.
	 *
	 * @var array<string, string>
	 */
	private const COLORS = array(
		'inherit'       => 'inherit',
		'current'       => 'currentColor',
		'transparent'   => 'transparent',
		'black'         => '#000',
		'white'         => '#fff',

		'red-50'        => 'oklch(97.1% 0.013 17.38)',
		'red-100'       => 'oklch(93.6% 0.032 17.717)',
		'red-200'       => 'oklch(88.5% 0.062 18.334)',
		'red-300'       => 'oklch(80.8% 0.114 19.571)',
		'red-400'       => 'oklch(70.4% 0.191 22.216)',
		'red-500'       => 'oklch(63.7% 0.237 25.331)',
		'red-600'       => 'oklch(57.7% 0.245 27.325)',
		'red-700'       => 'oklch(50.5% 0.213 27.518)',
		'red-800'       => 'oklch(44.4% 0.177 26.899)',
		'red-900'       => 'oklch(39.6% 0.141 25.723)',
		'red-950'       => 'oklch(25.8% 0.092 26.042)',

		'orange-50'     => 'oklch(98% 0.016 73.684)',
		'orange-100'    => 'oklch(95.4% 0.038 75.164)',
		'orange-200'    => 'oklch(90.1% 0.076 70.697)',
		'orange-300'    => 'oklch(83.7% 0.128 66.29)',
		'orange-400'    => 'oklch(75% 0.183 55.934)',
		'orange-500'    => 'oklch(70.5% 0.213 47.604)',
		'orange-600'    => 'oklch(64.6% 0.222 41.116)',
		'orange-700'    => 'oklch(55.3% 0.195 38.402)',
		'orange-800'    => 'oklch(47% 0.157 37.304)',
		'orange-900'    => 'oklch(40.8% 0.123 38.172)',
		'orange-950'    => 'oklch(26.6% 0.079 36.259)',

		'amber-50'      => 'oklch(98.7% 0.022 95.277)',
		'amber-100'     => 'oklch(96.2% 0.059 95.617)',
		'amber-200'     => 'oklch(92.4% 0.12 95.746)',
		'amber-300'     => 'oklch(87.9% 0.169 91.605)',
		'amber-400'     => 'oklch(82.8% 0.189 84.429)',
		'amber-500'     => 'oklch(76.9% 0.188 70.08)',
		'amber-600'     => 'oklch(66.6% 0.179 58.318)',
		'amber-700'     => 'oklch(55.5% 0.163 48.998)',
		'amber-800'     => 'oklch(47.3% 0.137 46.201)',
		'amber-900'     => 'oklch(41.4% 0.112 45.904)',
		'amber-950'     => 'oklch(27.9% 0.077 45.635)',

		'yellow-50'     => 'oklch(98.7% 0.026 102.212)',
		'yellow-100'    => 'oklch(97.3% 0.071 103.193)',
		'yellow-200'    => 'oklch(94.5% 0.129 101.54)',
		'yellow-300'    => 'oklch(90.5% 0.182 98.111)',
		'yellow-400'    => 'oklch(85.2% 0.199 91.936)',
		'yellow-500'    => 'oklch(79.5% 0.184 86.047)',
		'yellow-600'    => 'oklch(68.1% 0.162 75.834)',
		'yellow-700'    => 'oklch(55.4% 0.135 66.442)',
		'yellow-800'    => 'oklch(47.6% 0.114 61.907)',
		'yellow-900'    => 'oklch(42.1% 0.095 57.708)',
		'yellow-950'    => 'oklch(28.6% 0.066 53.813)',

		'lime-50'       => 'oklch(98.6% 0.031 120.757)',
		'lime-100'      => 'oklch(96.7% 0.067 122.328)',
		'lime-200'      => 'oklch(93.8% 0.127 124.321)',
		'lime-300'      => 'oklch(89.7% 0.196 126.665)',
		'lime-400'      => 'oklch(84.1% 0.238 128.85)',
		'lime-500'      => 'oklch(76.8% 0.233 130.85)',
		'lime-600'      => 'oklch(64.8% 0.2 131.684)',
		'lime-700'      => 'oklch(53.2% 0.157 131.589)',
		'lime-800'      => 'oklch(45.3% 0.124 130.933)',
		'lime-900'      => 'oklch(40.5% 0.101 131.063)',
		'lime-950'      => 'oklch(27.4% 0.072 132.109)',

		'green-50'      => 'oklch(98.2% 0.018 155.826)',
		'green-100'     => 'oklch(96.2% 0.044 156.743)',
		'green-200'     => 'oklch(92.5% 0.084 155.995)',
		'green-300'     => 'oklch(87.1% 0.15 154.449)',
		'green-400'     => 'oklch(79.2% 0.209 151.711)',
		'green-500'     => 'oklch(72.3% 0.219 149.579)',
		'green-600'     => 'oklch(62.7% 0.194 149.214)',
		'green-700'     => 'oklch(52.7% 0.154 150.069)',
		'green-800'     => 'oklch(44.8% 0.119 151.328)',
		'green-900'     => 'oklch(39.3% 0.095 152.535)',
		'green-950'     => 'oklch(26.6% 0.065 152.934)',

		'emerald-50'    => 'oklch(97.9% 0.021 166.113)',
		'emerald-100'   => 'oklch(95% 0.052 163.051)',
		'emerald-200'   => 'oklch(90.5% 0.093 164.15)',
		'emerald-300'   => 'oklch(84.5% 0.143 164.978)',
		'emerald-400'   => 'oklch(76.5% 0.177 163.223)',
		'emerald-500'   => 'oklch(69.6% 0.17 162.48)',
		'emerald-600'   => 'oklch(59.6% 0.145 163.225)',
		'emerald-700'   => 'oklch(50.8% 0.118 165.612)',
		'emerald-800'   => 'oklch(43.2% 0.095 166.913)',
		'emerald-900'   => 'oklch(37.8% 0.077 168.94)',
		'emerald-950'   => 'oklch(26.2% 0.051 172.552)',

		'teal-50'       => 'oklch(98.4% 0.014 180.72)',
		'teal-100'      => 'oklch(95.3% 0.051 180.801)',
		'teal-200'      => 'oklch(91% 0.096 180.426)',
		'teal-300'      => 'oklch(85.5% 0.138 181.071)',
		'teal-400'      => 'oklch(77.7% 0.152 181.912)',
		'teal-500'      => 'oklch(70.4% 0.14 182.503)',
		'teal-600'      => 'oklch(60% 0.118 184.704)',
		'teal-700'      => 'oklch(51.1% 0.096 186.391)',
		'teal-800'      => 'oklch(43.7% 0.078 188.216)',
		'teal-900'      => 'oklch(38.6% 0.063 188.416)',
		'teal-950'      => 'oklch(27.7% 0.046 192.524)',

		'cyan-50'       => 'oklch(98.4% 0.019 200.873)',
		'cyan-100'      => 'oklch(95.6% 0.045 203.388)',
		'cyan-200'      => 'oklch(91.7% 0.08 205.041)',
		'cyan-300'      => 'oklch(86.5% 0.127 207.078)',
		'cyan-400'      => 'oklch(78.9% 0.154 211.53)',
		'cyan-500'      => 'oklch(71.5% 0.143 215.221)',
		'cyan-600'      => 'oklch(60.9% 0.126 221.723)',
		'cyan-700'      => 'oklch(52% 0.105 223.128)',
		'cyan-800'      => 'oklch(45% 0.085 224.283)',
		'cyan-900'      => 'oklch(39.8% 0.07 227.392)',
		'cyan-950'      => 'oklch(30.2% 0.056 229.695)',

		'sky-50'        => 'oklch(97.7% 0.013 236.62)',
		'sky-100'       => 'oklch(95.1% 0.026 236.824)',
		'sky-200'       => 'oklch(90.1% 0.058 230.902)',
		'sky-300'       => 'oklch(82.8% 0.111 230.318)',
		'sky-400'       => 'oklch(74.6% 0.16 232.661)',
		'sky-500'       => 'oklch(68.5% 0.169 237.323)',
		'sky-600'       => 'oklch(58.8% 0.158 241.966)',
		'sky-700'       => 'oklch(50% 0.134 242.749)',
		'sky-800'       => 'oklch(44.3% 0.11 240.79)',
		'sky-900'       => 'oklch(39.1% 0.09 240.876)',
		'sky-950'       => 'oklch(29.3% 0.066 243.157)',

		'blue-50'       => 'oklch(97% 0.014 254.604)',
		'blue-100'      => 'oklch(93.2% 0.032 255.585)',
		'blue-200'      => 'oklch(88.2% 0.059 254.128)',
		'blue-300'      => 'oklch(80.9% 0.105 251.813)',
		'blue-400'      => 'oklch(70.7% 0.165 254.624)',
		'blue-500'      => 'oklch(62.3% 0.214 259.815)',
		'blue-600'      => 'oklch(54.6% 0.245 262.881)',
		'blue-700'      => 'oklch(48.8% 0.243 264.376)',
		'blue-800'      => 'oklch(42.4% 0.199 265.638)',
		'blue-900'      => 'oklch(37.9% 0.146 265.522)',
		'blue-950'      => 'oklch(28.2% 0.091 267.935)',

		'indigo-50'     => 'oklch(96.2% 0.018 272.314)',
		'indigo-100'    => 'oklch(93% 0.034 272.788)',
		'indigo-200'    => 'oklch(87% 0.065 274.039)',
		'indigo-300'    => 'oklch(78.5% 0.115 274.713)',
		'indigo-400'    => 'oklch(67.3% 0.182 276.935)',
		'indigo-500'    => 'oklch(58.5% 0.233 277.117)',
		'indigo-600'    => 'oklch(51.1% 0.262 276.966)',
		'indigo-700'    => 'oklch(45.7% 0.24 277.023)',
		'indigo-800'    => 'oklch(39.8% 0.195 277.366)',
		'indigo-900'    => 'oklch(35.9% 0.144 278.697)',
		'indigo-950'    => 'oklch(25.7% 0.09 281.288)',

		'violet-50'     => 'oklch(96.9% 0.016 293.756)',
		'violet-100'    => 'oklch(94.3% 0.029 294.588)',
		'violet-200'    => 'oklch(89.4% 0.057 293.283)',
		'violet-300'    => 'oklch(81.1% 0.111 293.571)',
		'violet-400'    => 'oklch(70.2% 0.183 293.541)',
		'violet-500'    => 'oklch(60.6% 0.25 292.717)',
		'violet-600'    => 'oklch(54.1% 0.281 293.009)',
		'violet-700'    => 'oklch(49.1% 0.27 292.581)',
		'violet-800'    => 'oklch(43.2% 0.232 292.759)',
		'violet-900'    => 'oklch(38% 0.189 293.745)',
		'violet-950'    => 'oklch(28.3% 0.141 291.089)',

		'purple-50'     => 'oklch(97.7% 0.014 308.299)',
		'purple-100'    => 'oklch(94.6% 0.033 307.174)',
		'purple-200'    => 'oklch(90.2% 0.063 306.703)',
		'purple-300'    => 'oklch(82.7% 0.119 306.383)',
		'purple-400'    => 'oklch(71.4% 0.203 305.504)',
		'purple-500'    => 'oklch(62.7% 0.265 303.9)',
		'purple-600'    => 'oklch(55.8% 0.288 302.321)',
		'purple-700'    => 'oklch(49.6% 0.265 301.924)',
		'purple-800'    => 'oklch(43.8% 0.218 303.724)',
		'purple-900'    => 'oklch(38.1% 0.176 304.987)',
		'purple-950'    => 'oklch(29.1% 0.149 302.717)',

		'fuchsia-50'    => 'oklch(97.7% 0.017 320.058)',
		'fuchsia-100'   => 'oklch(95.2% 0.037 318.852)',
		'fuchsia-200'   => 'oklch(90.3% 0.076 319.62)',
		'fuchsia-300'   => 'oklch(83.3% 0.145 321.434)',
		'fuchsia-400'   => 'oklch(74% 0.238 322.16)',
		'fuchsia-500'   => 'oklch(66.7% 0.295 322.15)',
		'fuchsia-600'   => 'oklch(59.1% 0.293 322.896)',
		'fuchsia-700'   => 'oklch(51.8% 0.253 323.949)',
		'fuchsia-800'   => 'oklch(45.2% 0.211 324.591)',
		'fuchsia-900'   => 'oklch(40.1% 0.17 325.612)',
		'fuchsia-950'   => 'oklch(29.3% 0.136 325.661)',

		'pink-50'       => 'oklch(97.1% 0.014 343.198)',
		'pink-100'      => 'oklch(94.8% 0.028 342.258)',
		'pink-200'      => 'oklch(89.9% 0.061 343.231)',
		'pink-300'      => 'oklch(82.3% 0.12 346.018)',
		'pink-400'      => 'oklch(71.8% 0.202 349.761)',
		'pink-500'      => 'oklch(65.6% 0.241 354.308)',
		'pink-600'      => 'oklch(59.2% 0.249 0.584)',
		'pink-700'      => 'oklch(52.5% 0.223 3.958)',
		'pink-800'      => 'oklch(45.9% 0.187 3.815)',
		'pink-900'      => 'oklch(40.8% 0.153 2.432)',
		'pink-950'      => 'oklch(28.4% 0.109 3.907)',

		'rose-50'       => 'oklch(96.9% 0.015 12.422)',
		'rose-100'      => 'oklch(94.1% 0.03 12.58)',
		'rose-200'      => 'oklch(89.2% 0.058 10.001)',
		'rose-300'      => 'oklch(81% 0.117 11.638)',
		'rose-400'      => 'oklch(71.2% 0.194 13.428)',
		'rose-500'      => 'oklch(64.5% 0.246 16.439)',
		'rose-600'      => 'oklch(58.6% 0.253 17.585)',
		'rose-700'      => 'oklch(51.4% 0.222 16.935)',
		'rose-800'      => 'oklch(45.5% 0.188 13.697)',
		'rose-900'      => 'oklch(41% 0.159 10.272)',
		'rose-950'      => 'oklch(27.1% 0.105 12.094)',

		'slate-50'      => 'oklch(98.4% 0.003 247.858)',
		'slate-100'     => 'oklch(96.8% 0.007 247.896)',
		'slate-200'     => 'oklch(92.9% 0.013 255.508)',
		'slate-300'     => 'oklch(86.9% 0.022 252.894)',
		'slate-400'     => 'oklch(70.4% 0.04 256.788)',
		'slate-500'     => 'oklch(55.4% 0.046 257.417)',
		'slate-600'     => 'oklch(44.6% 0.043 257.281)',
		'slate-700'     => 'oklch(37.2% 0.044 257.287)',
		'slate-800'     => 'oklch(27.9% 0.041 260.031)',
		'slate-900'     => 'oklch(20.8% 0.042 265.755)',
		'slate-950'     => 'oklch(12.9% 0.042 264.695)',

		'gray-50'       => 'oklch(98.5% 0.002 247.839)',
		'gray-100'      => 'oklch(96.7% 0.003 264.542)',
		'gray-200'      => 'oklch(92.8% 0.006 264.531)',
		'gray-300'      => 'oklch(87.2% 0.01 258.338)',
		'gray-400'      => 'oklch(70.7% 0.022 261.325)',
		'gray-500'      => 'oklch(55.1% 0.027 264.364)',
		'gray-600'      => 'oklch(44.6% 0.03 256.802)',
		'gray-700'      => 'oklch(37.3% 0.034 259.733)',
		'gray-800'      => 'oklch(27.8% 0.033 256.848)',
		'gray-900'      => 'oklch(21% 0.034 264.665)',
		'gray-950'      => 'oklch(13% 0.028 261.692)',

		'zinc-50'       => 'oklch(98.5% 0 0)',
		'zinc-100'      => 'oklch(96.7% 0.001 286.375)',
		'zinc-200'      => 'oklch(92% 0.004 286.32)',
		'zinc-300'      => 'oklch(87.1% 0.006 286.286)',
		'zinc-400'      => 'oklch(70.5% 0.015 286.067)',
		'zinc-500'      => 'oklch(55.2% 0.016 285.938)',
		'zinc-600'      => 'oklch(44.2% 0.017 285.786)',
		'zinc-700'      => 'oklch(37% 0.013 285.805)',
		'zinc-800'      => 'oklch(27.4% 0.006 286.033)',
		'zinc-900'      => 'oklch(21% 0.006 285.885)',
		'zinc-950'      => 'oklch(14.1% 0.005 285.823)',

		'neutral-50'    => 'oklch(98.5% 0 0)',
		'neutral-100'   => 'oklch(97% 0 0)',
		'neutral-200'   => 'oklch(92.2% 0 0)',
		'neutral-300'   => 'oklch(87% 0 0)',
		'neutral-400'   => 'oklch(70.8% 0 0)',
		'neutral-500'   => 'oklch(55.6% 0 0)',
		'neutral-600'   => 'oklch(43.9% 0 0)',
		'neutral-700'   => 'oklch(37.1% 0 0)',
		'neutral-800'   => 'oklch(26.9% 0 0)',
		'neutral-900'   => 'oklch(20.5% 0 0)',
		'neutral-950'   => 'oklch(14.5% 0 0)',

		'stone-50'      => 'oklch(98.5% 0.001 106.423)',
		'stone-100'     => 'oklch(97% 0.001 106.424)',
		'stone-200'     => 'oklch(92.3% 0.003 48.717)',
		'stone-300'     => 'oklch(86.9% 0.005 56.366)',
		'stone-400'     => 'oklch(70.9% 0.01 56.259)',
		'stone-500'     => 'oklch(55.3% 0.013 58.071)',
		'stone-600'     => 'oklch(44.4% 0.011 73.639)',
		'stone-700'     => 'oklch(37.4% 0.01 67.558)',
		'stone-800'     => 'oklch(26.8% 0.007 34.298)',
		'stone-900'     => 'oklch(21.6% 0.006 56.043)',
		'stone-950'     => 'oklch(14.7% 0.004 49.25)',

		/*
		 * Added by Tailwind 4.2. A design need not declare these to use them,
		 * so `bg-mauve-500` is a plain utility that silently painted nothing
		 * until the parity harness compared our palette against the reference
		 * build's. Four palettes, and they multiply across every colour-taking
		 * prefix: 2,284 of the classes we did not emit were these.
		 */
		'mauve-50'      => 'oklch(98.5% 0 0)',
		'mauve-100'     => 'oklch(96% 0.003 325.6)',
		'mauve-200'     => 'oklch(92.2% 0.005 325.62)',
		'mauve-300'     => 'oklch(86.5% 0.012 325.68)',
		'mauve-400'     => 'oklch(71.1% 0.019 323.02)',
		'mauve-500'     => 'oklch(54.2% 0.034 322.5)',
		'mauve-600'     => 'oklch(43.5% 0.029 321.78)',
		'mauve-700'     => 'oklch(36.4% 0.029 323.89)',
		'mauve-800'     => 'oklch(26.3% 0.024 320.12)',
		'mauve-900'     => 'oklch(21.2% 0.019 322.12)',
		'mauve-950'     => 'oklch(14.5% 0.008 326)',
		'mist-50'       => 'oklch(98.7% 0.002 197.1)',
		'mist-100'      => 'oklch(96.3% 0.002 197.1)',
		'mist-200'      => 'oklch(92.5% 0.005 214.3)',
		'mist-300'      => 'oklch(87.2% 0.007 219.6)',
		'mist-400'      => 'oklch(72.3% 0.014 214.4)',
		'mist-500'      => 'oklch(56% 0.021 213.5)',
		'mist-600'      => 'oklch(45% 0.017 213.2)',
		'mist-700'      => 'oklch(37.8% 0.015 216)',
		'mist-800'      => 'oklch(27.5% 0.011 216.9)',
		'mist-900'      => 'oklch(21.8% 0.008 223.9)',
		'mist-950'      => 'oklch(14.8% 0.004 228.8)',
		'olive-50'      => 'oklch(98.8% 0.003 106.5)',
		'olive-100'     => 'oklch(96.6% 0.005 106.5)',
		'olive-200'     => 'oklch(93% 0.007 106.5)',
		'olive-300'     => 'oklch(88% 0.011 106.6)',
		'olive-400'     => 'oklch(73.7% 0.021 106.9)',
		'olive-500'     => 'oklch(58% 0.031 107.3)',
		'olive-600'     => 'oklch(46.6% 0.025 107.3)',
		'olive-700'     => 'oklch(39.4% 0.023 107.4)',
		'olive-800'     => 'oklch(28.6% 0.016 107.4)',
		'olive-900'     => 'oklch(22.8% 0.013 107.4)',
		'olive-950'     => 'oklch(15.3% 0.006 107.1)',
		'taupe-50'      => 'oklch(98.6% 0.002 67.8)',
		'taupe-100'     => 'oklch(96% 0.002 17.2)',
		'taupe-200'     => 'oklch(92.2% 0.005 34.3)',
		'taupe-300'     => 'oklch(86.8% 0.007 39.5)',
		'taupe-400'     => 'oklch(71.4% 0.014 41.2)',
		'taupe-500'     => 'oklch(54.7% 0.021 43.1)',
		'taupe-600'     => 'oklch(43.8% 0.017 39.3)',
		'taupe-700'     => 'oklch(36.7% 0.016 35.7)',
		'taupe-800'     => 'oklch(26.8% 0.011 36.5)',
		'taupe-900'     => 'oklch(21.4% 0.009 43.1)',
		'taupe-950'     => 'oklch(14.7% 0.004 49.3)',
	);

	/**
	 * Spacing tokens that are keywords rather than steps on the numeric scale.
	 *
	 * @var array<string, string>
	 */
	private const SPACING_KEYWORDS = array(
		'px'     => '1px',
		'auto'   => 'auto',
		'full'   => '100%',
		'screen' => '100vh',
		'min'    => 'min-content',
		'max'    => 'max-content',
		'fit'    => 'fit-content',
		'svw'    => '100svw',
		'svh'    => '100svh',
		'lvw'    => '100lvw',
		'lvh'    => '100lvh',
		'dvw'    => '100dvw',
		'dvh'    => '100dvh',
	);

	/**
	 * Every non-colour namespace of the v4 default theme.
	 *
	 * The empty token is v4's namespace root, which is what a bare utility such
	 * as `backdrop-blur` or `rounded` reads. A `text` entry suffixed
	 * `--line-height` is the paired default line height v4 declares alongside
	 * the font size.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const SCALES = array(
		'text'         => array(
			'xs'                => '0.75rem',
			'xs--line-height'   => '1rem',
			'sm'                => '0.875rem',
			'sm--line-height'   => '1.25rem',
			'base'              => '1rem',
			'base--line-height' => '1.5rem',
			'lg'                => '1.125rem',
			'lg--line-height'   => '1.75rem',
			'xl'                => '1.25rem',
			'xl--line-height'   => '1.75rem',
			'2xl'               => '1.5rem',
			'2xl--line-height'  => '2rem',
			'3xl'               => '1.875rem',
			'3xl--line-height'  => '2.25rem',
			'4xl'               => '2.25rem',
			'4xl--line-height'  => '2.5rem',
			'5xl'               => '3rem',
			'5xl--line-height'  => '1',
			'6xl'               => '3.75rem',
			'6xl--line-height'  => '1',
			'7xl'               => '4.5rem',
			'7xl--line-height'  => '1',
			'8xl'               => '6rem',
			'8xl--line-height'  => '1',
			'9xl'               => '8rem',
			'9xl--line-height'  => '1',
		),
		'font'         => array(
			'sans'  => 'ui-sans-serif, system-ui, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol", "Noto Color Emoji"',
			'serif' => 'ui-serif, Georgia, Cambria, "Times New Roman", Times, serif',
			'mono'  => 'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace',
		),
		'font-weight'  => array(
			'thin'       => '100',
			'extralight' => '200',
			'light'      => '300',
			'normal'     => '400',
			'medium'     => '500',
			'semibold'   => '600',
			'bold'       => '700',
			'extrabold'  => '800',
			'black'      => '900',
		),
		'tracking'     => array(
			'tighter' => '-0.05em',
			'tight'   => '-0.025em',
			'normal'  => '0em',
			'wide'    => '0.025em',
			'wider'   => '0.05em',
			'widest'  => '0.1em',
		),
		'leading'      => array(
			'none'    => '1',
			'tight'   => '1.25',
			'snug'    => '1.375',
			'normal'  => '1.5',
			'relaxed' => '1.625',
			'loose'   => '2',
		),
		'radius'       => array(
			''     => '0.25rem',
			'none' => '0',
			'xs'   => '0.125rem',
			'sm'   => '0.25rem',
			'md'   => '0.375rem',
			'lg'   => '0.5rem',
			'xl'   => '0.75rem',
			'2xl'  => '1rem',
			'3xl'  => '1.5rem',
			'4xl'  => '2rem',
			'full' => 'calc(infinity * 1px)',
		),
		'shadow'       => array(
			''     => '0 1px 3px 0 rgb(0 0 0 / 0.1), 0 1px 2px -1px rgb(0 0 0 / 0.1)',
			'none' => '0 0 #0000',
			'2xs'  => '0 1px rgb(0 0 0 / 0.05)',
			'xs'   => '0 1px 2px 0 rgb(0 0 0 / 0.05)',
			'sm'   => '0 1px 3px 0 rgb(0 0 0 / 0.1), 0 1px 2px -1px rgb(0 0 0 / 0.1)',
			'md'   => '0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1)',
			'lg'   => '0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1)',
			'xl'   => '0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1)',
			'2xl'  => '0 25px 50px -12px rgb(0 0 0 / 0.25)',
		),
		'inset-shadow' => array(
			'none' => '0 0 #0000',
			'2xs'  => 'inset 0 1px rgb(0 0 0 / 0.05)',
			'xs'   => 'inset 0 1px 1px rgb(0 0 0 / 0.05)',
			'sm'   => 'inset 0 2px 4px rgb(0 0 0 / 0.05)',
		),
		'drop-shadow'  => array(
			'none' => '0 0 #0000',
			'xs'   => '0 1px 1px rgb(0 0 0 / 0.05)',
			'sm'   => '0 1px 2px rgb(0 0 0 / 0.15)',
			'md'   => '0 3px 3px rgb(0 0 0 / 0.12)',
			'lg'   => '0 4px 4px rgb(0 0 0 / 0.15)',
			'xl'   => '0 9px 7px rgb(0 0 0 / 0.1)',
			'2xl'  => '0 25px 25px rgb(0 0 0 / 0.15)',
		),
		'text-shadow'  => array(
			'none' => '0 0 #0000',
			'2xs'  => '0px 1px 0px rgb(0 0 0 / 0.15)',
			'xs'   => '0px 1px 1px rgb(0 0 0 / 0.2)',
			'sm'   => '0px 1px 0px rgb(0 0 0 / 0.075), 0px 1px 1px rgb(0 0 0 / 0.075), 0px 2px 2px rgb(0 0 0 / 0.075)',
			'md'   => '0px 1px 1px rgb(0 0 0 / 0.1), 0px 1px 2px rgb(0 0 0 / 0.1), 0px 2px 4px rgb(0 0 0 / 0.1)',
			'lg'   => '0px 1px 2px rgb(0 0 0 / 0.1), 0px 3px 2px rgb(0 0 0 / 0.1), 0px 4px 8px rgb(0 0 0 / 0.1)',
		),
		'blur'         => array(
			''     => '8px',
			'none' => '0',
			'xs'   => '4px',
			'sm'   => '8px',
			'md'   => '12px',
			'lg'   => '16px',
			'xl'   => '24px',
			'2xl'  => '40px',
			'3xl'  => '64px',
		),
		'ease'         => array(
			'linear'  => 'linear',
			'in'      => 'cubic-bezier(0.4, 0, 1, 1)',
			'out'     => 'cubic-bezier(0, 0, 0.2, 1)',
			'in-out'  => 'cubic-bezier(0.4, 0, 0.2, 1)',
			'initial' => 'initial',
		),
		'duration'     => array(
			''        => '150ms',
			'initial' => 'initial',
		),
		'animate'      => array(
			'none'   => 'none',
			'spin'   => 'spin 1s linear infinite',
			'ping'   => 'ping 1s cubic-bezier(0, 0, 0.2, 1) infinite',
			'pulse'  => 'pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite',
			'bounce' => 'bounce 1s infinite',
		),
		'container'    => array(
			'3xs' => '16rem',
			'2xs' => '18rem',
			'xs'  => '20rem',
			'sm'  => '24rem',
			'md'  => '28rem',
			'lg'  => '32rem',
			'xl'  => '36rem',
			'2xl' => '42rem',
			'3xl' => '48rem',
			'4xl' => '56rem',
			'5xl' => '64rem',
			'6xl' => '72rem',
			'7xl' => '80rem',
		),
		'aspect'       => array(
			'auto'   => 'auto',
			'square' => '1 / 1',
			'video'  => '16 / 9',
		),
		'perspective'  => array(
			'dramatic' => '100px',
			'near'     => '300px',
			'normal'   => '500px',
			'midrange' => '800px',
			'distant'  => '1200px',
		),
	);

	/**
	 * Breakpoints of the v4 default theme.
	 *
	 * @var array<string, string>
	 */
	private const BREAKPOINTS = array(
		'sm'  => '40rem',
		'md'  => '48rem',
		'lg'  => '64rem',
		'xl'  => '80rem',
		'2xl' => '96rem',
	);

	/**
	 * `@keyframes` the v4 default theme ships to back its `animate-*` tokens.
	 *
	 * @var array<string, string>
	 */
	private const KEYFRAMES = array(
		'spin'   => '@keyframes spin { to { transform: rotate(360deg); } }',
		'ping'   => '@keyframes ping { 75%, 100% { transform: scale(2); opacity: 0; } }',
		'pulse'  => '@keyframes pulse { 50% { opacity: 0.5; } }',
		'bounce' => '@keyframes bounce { 0%, 100% { transform: translateY(-25%); animation-timing-function: cubic-bezier(0.8, 0, 1, 1); } 50% { transform: none; animation-timing-function: cubic-bezier(0, 0, 0.2, 1); } }',
	);

	/**
	 * Custom property prefixes mapped to the namespace they feed, longest
	 * first so `--font-weight-bold` lands in `font-weight` and not in `font`.
	 *
	 * @var array<string, string>
	 */
	private const PREFIXES = array(
		'--inset-shadow-' => 'inset-shadow',
		'--font-weight-'  => 'font-weight',
		'--drop-shadow-'  => 'drop-shadow',
		'--text-shadow-'  => 'text-shadow',
		'--perspective-'  => 'perspective',
		'--breakpoint-'   => 'breakpoint',
		'--container-'    => 'container',
		'--tracking-'     => 'tracking',
		'--duration-'     => 'duration',
		'--spacing-'      => 'spacing',
		'--leading-'      => 'leading',
		'--animate-'      => 'animate',
		'--columns-'      => 'columns',
		'--opacity-'      => 'opacity',
		'--shadow-'       => 'shadow',
		'--radius-'       => 'radius',
		'--aspect-'       => 'aspect',
		'--color-'        => 'color',
		'--order-'        => 'order',
		'--blur-'         => 'blur',
		'--ease-'         => 'ease',
		'--font-'         => 'font',
		'--text-'         => 'text',
		'--z-'            => 'z',
	);

	/**
	 * `theme.extend` keys of a legacy config mapped onto v4 namespaces.
	 *
	 * @var array<string, string>
	 */
	private const CONFIG_GROUPS = array(
		'colors'                   => 'color',
		'spacing'                  => 'spacing',
		'screens'                  => 'breakpoint',
		'borderRadius'             => 'radius',
		'fontSize'                 => 'text',
		'fontFamily'               => 'font',
		'fontWeight'               => 'font-weight',
		'letterSpacing'            => 'tracking',
		'lineHeight'               => 'leading',
		'boxShadow'                => 'shadow',
		'dropShadow'               => 'drop-shadow',
		'textShadow'               => 'text-shadow',
		'blur'                     => 'blur',
		'maxWidth'                 => 'container',
		'aspectRatio'              => 'aspect',
		'zIndex'                   => 'z',
		'opacity'                  => 'opacity',
		'order'                    => 'order',
		'columns'                  => 'columns',
		'perspective'              => 'perspective',
		'transitionDuration'       => 'duration',
		'transitionTimingFunction' => 'ease',
		'animation'                => 'animate',
	);

	/**
	 * Design tokens by namespace and token name.
	 *
	 * `property` is the custom property the value must be referenced through,
	 * or null when the value has to be used literally because there is no
	 * property backing it — which is the case for a legacy config scrape.
	 *
	 * @var array<string, array<string, array{property: ?string, value: string}>>
	 */
	private array $design = array();

	/**
	 * A v3 `theme.container`, which v4 has no equivalent for.
	 *
	 * @var array{center?: bool, padding?: string, screens?: array<string, string>}
	 */
	private array $container = array();

	/**
	 * Whether the design was built with Tailwind v3.
	 *
	 * Set by ingesting a `tailwind.config.*`, which only a v3 project has. The
	 * two majors do not merely differ in where tokens live: v3 writes an alpha
	 * into the colour function while v4 uses `color-mix`, and v3's `text-*`
	 * sets a literal line-height where v4 routes it through `--tw-leading`. A
	 * design gets the semantics its own build had.
	 */
	private bool $legacy = false;

	/**
	 * `@theme` declarations in author order, keyed by property name.
	 *
	 * @var array<string, string>
	 */
	private array $properties = array();

	/**
	 * `@keyframes` found in the design CSS, keyed by animation name.
	 *
	 * @var array<string, string>
	 */
	private array $frames = array();

	/**
	 * Namespaces the design wiped with `--<namespace>-*: initial`.
	 *
	 * @var array<string, bool>
	 */
	private array $cleared = array();

	/**
	 * Read a design's tokens out of its own stylesheet.
	 *
	 * `@theme` and `@theme inline` are treated alike: v4 inlines the latter's
	 * values into the utilities, but a converted page needs the indirection
	 * kept so the design's `:root`/`.dark` blocks still drive the colours.
	 */
	public static function from_css( string $design_css, string $config_js = '' ): self {
		$theme = new self();
		$css   = self::strip_comments( $design_css );

		foreach ( self::theme_blocks( $css ) as $block ) {
			$theme->ingest_theme_block( $block );
		}

		$theme->frames = self::keyframe_blocks( $css );

		if ( trim( $config_js ) !== '' ) {
			$theme->ingest_config( $config_js );
		}

		return $theme;
	}

	/**
	 * A colour token as a CSS value, or null when the name is not a colour.
	 */
	public function color( string $name ): ?string {
		$name = trim( $name );
		if ( $name === '' ) {
			return null;
		}

		$design = $this->design_value( 'color', $name );
		if ( $design !== null ) {
			return $design;
		}

		if ( isset( $this->cleared['color'] ) ) {
			return null;
		}

		return self::COLORS[ $name ] ?? null;
	}

	/**
	 * Fade a resolved colour the way v4's `/opacity` modifier does.
	 *
	 * `70` and `70%` both mean 70%, and a fractional `0.03` — how v3 designs
	 * wrote a bracketed alpha — means 3%. Anything else is passed through as an
	 * expression so a `var()` or `calc()` modifier still produces valid CSS.
	 */
	public function with_alpha( string $color, string $modifier ): string {
		$color = trim( $color );
		if ( $color === '' ) {
			return $color;
		}

		$modifier = trim( Candidate::decode_arbitrary( $modifier ) );
		if ( str_starts_with( $modifier, '[' ) && str_ends_with( $modifier, ']' ) ) {
			$modifier = trim( substr( $modifier, 1, -1 ) );
		}
		/*
		 * `/(--o)` names a custom property and has to become a `var()` before
		 * it can be arithmetic. Callers used to do this themselves, which meant
		 * each new colour family had to remember: the one that did not emitted
		 * `calc((--o) * 100%)`, invalid at parse time, so the browser dropped
		 * the whole declaration and the colour silently did not apply. Doing it
		 * here is idempotent for the callers that already expand it.
		 */
		if ( str_starts_with( $modifier, '(' ) && str_ends_with( $modifier, ')' ) ) {
			$inner = trim( substr( $modifier, 1, -1 ) );
			if ( ! str_starts_with( $inner, '--' ) ) {
				return '';
			}

			$modifier = 'var(' . $inner . ')';
		}
		if ( $modifier === '' ) {
			return $color;
		}

		$percent = $this->alpha_percentage( $modifier );

		/*
		 * v3 had no `color-mix`. It wrote the alpha into the colour function
		 * itself — which is why a v3 config declares `hsl(var(--primary))`
		 * rather than a finished colour: the channels are separate so the
		 * plugin can slot an alpha in. The two forms composite to the same
		 * pixels, but they are not the same computed value, and against the
		 * design's own build every faded colour on the page read as a
		 * difference. Emitting what the design's Tailwind emits keeps the
		 * comparison about colour rather than about notation.
		 */
		if ( $this->legacy ) {
			$alpha  = $percent === null ? 'calc(' . $modifier . ' * 100%)' : $percent . '%';
			$legacy = self::slot_alpha( $color, $alpha );
			if ( $legacy !== null ) {
				return $legacy;
			}
		}

		if ( $percent === null ) {
			return 'color-mix(in oklab, ' . $color . ' calc(' . $modifier . ' * 100%), transparent)';
		}

		return 'color-mix(in oklab, ' . $color . ' ' . $percent . '%, transparent)';
	}

	/**
	 * Put an alpha inside a colour the way Tailwind v3 does, or null when the
	 * colour has no slot for one and `color-mix` has to be used after all.
	 */
	private static function slot_alpha( string $color, string $alpha ): ?string {
		// `hsl(var(--primary))` — the shape every shadcn v3 token has.
		if ( preg_match( '/^(hsl|rgb|oklch|lab|lch|oklab)\(\s*(.+?)\s*\)$/i', $color, $match ) === 1
			&& ! str_contains( $match[2], '/' ) ) {
			return strtolower( $match[1] ) . '(' . $match[2] . ' / ' . $alpha . ')';
		}

		// A literal, which v3 expands to channels so the alpha has somewhere
		// to go. Six and three digit forms only; eight already carries one.
		if ( preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $color, $match ) === 1 ) {
			$hex = $match[1];
			if ( strlen( $hex ) === 3 ) {
				$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
			}

			return sprintf(
				'rgb(%d %d %d / %s)',
				hexdec( substr( $hex, 0, 2 ) ),
				hexdec( substr( $hex, 2, 2 ) ),
				hexdec( substr( $hex, 4, 2 ) ),
				$alpha
			);
		}

		return null;
	}

	/**
	 * A `/opacity` modifier as a CSS alpha value.
	 *
	 * `50` and `50%` both become `50%`; a `var()` or anything else the scale
	 * does not recognise is passed through as written, which is what upstream
	 * does with a custom property.
	 */
	public function alpha_value( string $modifier ): string {
		$modifier = trim( Candidate::decode_arbitrary( $modifier ) );
		if ( str_starts_with( $modifier, '[' ) && str_ends_with( $modifier, ']' ) ) {
			$modifier = trim( substr( $modifier, 1, -1 ) );
		}
		if ( str_starts_with( $modifier, '(' ) && str_ends_with( $modifier, ')' ) ) {
			$inner = trim( substr( $modifier, 1, -1 ) );

			return str_starts_with( $inner, '--' ) ? 'var(' . $inner . ')' : '';
		}

		$percent = $this->alpha_percentage( $modifier );

		return $percent === null ? $modifier : $percent . '%';
	}

	/**
	 * The colour with its alpha *replaced* rather than composited.
	 *
	 * {@see self::with_alpha()} mixes towards transparent, which is what a
	 * `bg-red-500/50` means: half of whatever opacity the colour already had.
	 * A shadow's `/opacity` is not that — upstream writes
	 * `oklab(from <colour> l a b / <alpha>)`, setting the alpha outright. The
	 * distinction is invisible on an opaque colour and glaring on a
	 * translucent one, and every built-in text-shadow token is translucent:
	 * `text-shadow-lg/50` composited to 0.1 × 0.5 = 0.05, a shadow ten times
	 * fainter than the design asked for.
	 */
	public function with_absolute_alpha( string $color, string $modifier ): string {
		$color = trim( $color );
		if ( $color === '' ) {
			return $color;
		}

		$modifier = trim( Candidate::decode_arbitrary( $modifier ) );
		if ( str_starts_with( $modifier, '[' ) && str_ends_with( $modifier, ']' ) ) {
			$modifier = trim( substr( $modifier, 1, -1 ) );
		}
		if ( str_starts_with( $modifier, '(' ) && str_ends_with( $modifier, ')' ) ) {
			$inner = trim( substr( $modifier, 1, -1 ) );
			if ( ! str_starts_with( $inner, '--' ) ) {
				return '';
			}

			$modifier = 'var(' . $inner . ')';
		}
		if ( $modifier === '' ) {
			return $color;
		}

		$percent = $this->alpha_percentage( $modifier );

		return 'oklab(from ' . $color . ' l a b / ' . ( $percent === null ? $modifier : $percent . '%' ) . ')';
	}

	/**
	 * A step on the spacing scale, a spacing keyword, or a named
	 * `--spacing-*` token. v4 computes the numeric scale from `--spacing`, so
	 * `4` is four times whatever that is.
	 */
	public function spacing( string $token ): ?string {
		$token = trim( $token );
		if ( $token === '' ) {
			return null;
		}

		$design = $this->design_value( 'spacing', $token );
		if ( $design !== null ) {
			return $design;
		}

		if ( isset( self::SPACING_KEYWORDS[ $token ] ) ) {
			return self::SPACING_KEYWORDS[ $token ];
		}

		if ( preg_match( '/^\d+(?:\.\d+)?$/', $token ) !== 1 ) {
			return null;
		}

		if ( (float) $token === 0.0 ) {
			return '0px';
		}

		return $this->spacing_step( $token );
	}

	/**
	 * A token from any other namespace, or null when nothing declares it.
	 */
	public function value( string $ns, string $token ): ?string {
		$ns    = trim( $ns );
		$token = trim( $token );

		if ( ! in_array( $ns, self::NAMESPACES, true ) ) {
			return null;
		}

		if ( $ns === 'spacing' ) {
			return $this->spacing( $token );
		}

		if ( $ns === 'color' ) {
			return $this->color( $token );
		}

		$design = $this->design_value( $ns, $token );
		if ( $design !== null ) {
			return $design;
		}

		if ( $ns === 'breakpoint' ) {
			return $this->screen( $token );
		}

		if ( isset( $this->cleared[ $ns ] ) ) {
			return null;
		}

		$scale = self::SCALES[ $ns ] ?? array();
		if ( isset( $scale[ $token ] ) ) {
			return $scale[ $token ];
		}

		// v4 has no duration tokens: `duration-300` is a bare millisecond count.
		if ( $ns === 'duration' && preg_match( '/^\d+(?:\.\d+)?$/', $token ) === 1 ) {
			return $token . 'ms';
		}

		return null;
	}

	/**
	 * One breakpoint as a media-query length. Never a `var()`, which a media
	 * feature cannot resolve.
	 */
	public function screen( string $name ): ?string {
		$name = trim( $name );
		if ( $name === '' ) {
			return null;
		}

		$screens = $this->screens();

		return $screens[ $name ] ?? null;
	}

	/**
	 * Every breakpoint, narrowest first, so a caller can order media queries
	 * by taking the array in order.
	 *
	 * @return array<string, string>
	 */
	public function screens(): array {
		$screens = isset( $this->cleared['breakpoint'] ) ? array() : self::BREAKPOINTS;

		foreach ( array_keys( $this->design['breakpoint'] ?? array() ) as $token ) {
			$token = (string) $token;
			if ( $token === '' ) {
				continue;
			}

			$value = $this->design_literal( 'breakpoint', $token );
			if ( $value !== null ) {
				$screens[ $token ] = $value;
			}
		}

		uasort(
			$screens,
			static function ( string $a, string $b ): int {
				return self::length_in_px( $a ) <=> self::length_in_px( $b );
			}
		);

		return $screens;
	}

	/**
	 * Whether the design itself declared this token, as opposed to inheriting
	 * it from the built-in scale.
	 */
	public function declares( string $ns, string $token ): bool {
		return isset( $this->design[ $ns ][ $token ] );
	}

	/**
	 * Whether this design was built with Tailwind v3, which emits several
	 * utilities differently from v4.
	 */
	public function is_legacy(): bool {
		return $this->legacy;
	}

	/**
	 * A v3 `theme.container`; empty on a v4 design, which has no such key.
	 *
	 * @return array{center?: bool, padding?: string, screens?: array<string, string>}
	 */
	public function container_config(): array {
		return $this->container;
	}

	/**
	 * The `@keyframes` blocks this stylesheet needs, keyed by animation name.
	 *
	 * @return array<string, string>
	 */
	public function keyframes(): array {
		$frames = self::KEYFRAMES;

		foreach ( $this->frames as $name => $body ) {
			$frames[ $name ] = $body;
		}

		return $frames;
	}

	/**
	 * The `@theme` declarations, ready to drop inside a rule of the caller's
	 * choosing. Only what the design declared: its `:root` block is emitted
	 * separately, and re-emitting it here would shadow the `.dark` override.
	 */
	public function custom_properties(): string {
		$out = '';

		foreach ( $this->properties as $name => $value ) {
			$out .= $name . ': ' . $value . ";\n";
		}

		return $out;
	}

	/**
	 * Register one `@theme` block's declarations.
	 */
	private function ingest_theme_block( string $block ): void {
		foreach ( self::declarations( $block ) as $declaration ) {
			list( $name, $value ) = $declaration;

			if ( str_ends_with( $name, '*' ) ) {
				$this->reset( $name, $value );
				continue;
			}

			$this->properties[ $name ] = $value;

			$parsed = self::namespace_of( $name );
			if ( $parsed === null ) {
				continue;
			}

			$this->design[ $parsed[0] ][ $parsed[1] ] = array(
				'property' => $name,
				'value'    => $value,
			);
		}
	}

	/**
	 * v4 lets a design wipe a namespace with `--color-*: initial` before
	 * declaring its own, which has to drop the built-in defaults too.
	 */
	private function reset( string $name, string $value ): void {
		if ( strtolower( $value ) !== 'initial' ) {
			return;
		}

		if ( $name === '--*' ) {
			$this->design     = array();
			$this->properties = array();
			$this->cleared    = array_fill_keys( array_values( self::PREFIXES ), true );

			return;
		}

		$prefix    = substr( $name, 0, -1 );
		$namespace = self::PREFIXES[ $prefix ] ?? null;
		if ( $namespace === null ) {
			return;
		}

		unset( $this->design[ $namespace ] );
		$this->cleared[ $namespace ] = true;

		foreach ( array_keys( $this->properties ) as $property ) {
			if ( str_starts_with( (string) $property, $prefix ) ) {
				unset( $this->properties[ $property ] );
			}
		}
	}

	/**
	 * Best-effort scrape of a v3 `tailwind.config.js`. Text only, never eval:
	 * quoted scalars and one level of nesting, everything else ignored.
	 */
	private function ingest_config( string $config_js ): void {
		$source = self::strip_comments( $config_js );

		$this->legacy = true;

		$theme = self::config_object( $source, 'theme', null );
		if ( $theme === null ) {
			return;
		}

		$scopes = array( $theme );
		$extend = self::config_object( $theme, 'extend', 0 );
		if ( $extend !== null ) {
			$scopes[] = $extend;
		}

		foreach ( $scopes as $scope ) {
			foreach ( self::CONFIG_GROUPS as $key => $namespace ) {
				$group = self::config_object( $scope, $key, 0 );
				if ( $group === null ) {
					continue;
				}

				foreach ( self::config_pairs( $group ) as $token => $value ) {
					$this->design[ $namespace ][ (string) $token ] = array(
						'property' => null,
						'value'    => $value,
					);
				}
			}

			/*
			 * `animation` names a `keyframes` entry, and a v3 config declares
			 * both. Registering only the name emitted `animation: rise-in .5s`
			 * against a `@keyframes rise-in` that did not exist, so the element
			 * rendered at its unanimated state — for a shadcn accordion that is
			 * a panel whose height never leaves `0`.
			 */
			$frames = self::config_object( $scope, 'keyframes', 0 );
			if ( $frames !== null ) {
				$this->ingest_keyframes( $frames );
			}

			/*
			 * `theme.container` has no v4 equivalent — v4 dropped the config
			 * key — and the classic Lovable template sets all three of its
			 * parts. `center` and `padding` are what make every section of such
			 * a design sit in a gutter, and `screens` REPLACES the per
			 * breakpoint max-widths rather than adding to them.
			 */
			$container = self::config_object( $scope, 'container', 0 );
			if ( $container !== null ) {
				$this->ingest_container( $container );
			}
		}
	}

	/**
	 * Turn a v3 `theme.keyframes` object into real `@keyframes` blocks.
	 */
	private function ingest_keyframes( string $group ): void {
		foreach ( self::config_children( $group ) as $name => $body ) {
			$stops = '';
			foreach ( self::config_children( $body ) as $stop => $block ) {
				$declarations = '';
				foreach ( self::config_pairs( $block ) as $property => $value ) {
					$declarations .= self::css_property( (string) $property ) . ': ' . $value . '; ';
				}
				if ( $declarations !== '' ) {
					$stops .= '  ' . $stop . ' { ' . trim( $declarations ) . " }\n";
				}
			}
			if ( $stops !== '' ) {
				$this->frames[ $name ] = '@keyframes ' . $name . " {\n" . $stops . '}';
			}
		}
	}

	/**
	 * Record a v3 `theme.container`.
	 */
	private function ingest_container( string $group ): void {
		$screens = self::config_object( $group, 'screens', 0 );
		$pairs   = self::config_pairs( $group );

		$this->container = array(
			'center'  => preg_match( '/[\'"]?center[\'"]?\s*:\s*true/', $group ) === 1,
			// `padding` is either a scalar or an object keyed by breakpoint,
			// whose DEFAULT `config_pairs` flattens to the bare `padding`.
			'padding' => (string) ( $pairs['padding'] ?? '' ),
			'screens' => $screens !== null ? self::config_pairs( $screens ) : array(),
		);
	}

	/**
	 * Top-level `key: { … }` entries of a config object, bodies unparsed.
	 *
	 * `config_pairs()` flattens one level of nesting into hyphenated tokens,
	 * which is right for a colour scale and wrong for keyframes: the stop, the
	 * property and the animation name all contain hyphens of their own and the
	 * pieces cannot be told apart again.
	 *
	 * @return array<string, string>
	 */
	private static function config_children( string $group ): array {
		$out     = array();
		$offset  = 0;
		$pattern = '/[\'"]?([A-Za-z0-9_.%-]+)[\'"]?\s*:\s*\{/';

		while ( preg_match( $pattern, $group, $match, PREG_OFFSET_CAPTURE, $offset ) === 1 ) {
			$name  = (string) $match[1][0];
			$open  = (int) $match[0][1] + strlen( (string) $match[0][0] );
			$close = self::closing( $group, $open, '{', '}' );
			if ( $close === null ) {
				break;
			}

			if ( self::depth_at( $group, (int) $match[1][1] ) === 0 ) {
				$out[ $name ] = substr( $group, $open, $close - $open );
			}

			$offset = $close + 1;
		}

		return $out;
	}

	/**
	 * A JS config writes CSS properties in camelCase.
	 */
	private static function css_property( string $name ): string {
		return strtolower( (string) preg_replace( '/([a-z0-9])([A-Z])/', '$1-$2', $name ) );
	}

	/**
	 * The CSS value a registered design token resolves to.
	 */
	private function design_value( string $ns, string $token ): ?string {
		$entry = $this->design[ $ns ][ $token ] ?? null;
		if ( $entry === null ) {
			return null;
		}

		$property = $entry['property'];
		if ( is_string( $property ) && $property !== '' ) {
			return 'var(' . $property . ')';
		}

		return $entry['value'] === '' ? null : $entry['value'];
	}

	/**
	 * The text a design token was declared with, for the places a `var()`
	 * cannot be used: media queries and the spacing scale's own multiplier.
	 */
	private function design_literal( string $ns, string $token ): ?string {
		$entry = $this->design[ $ns ][ $token ] ?? null;
		if ( $entry === null || $entry['value'] === '' ) {
			return null;
		}

		return $entry['value'];
	}

	/**
	 * `mt-7` is seven multiples of `--spacing`, resolved to a real length so
	 * the generated sheet does not depend on a property the host may not have.
	 */
	private function spacing_step( string $steps ): string {
		$base = $this->design_literal( 'spacing', '' ) ?? self::SPACING_BASE;

		if ( preg_match( '/^(-?\d*\.?\d+)([a-z%]*)$/i', $base, $match ) === 1 ) {
			$number = (float) $match[1] * (float) $steps;

			return self::format_number( $number ) . $match[2];
		}

		return 'calc(var(--spacing) * ' . $steps . ')';
	}

	/**
	 * The percentage figure an opacity modifier stands for, or null when it is
	 * an expression that has to be multiplied out in CSS instead.
	 */
	private function alpha_percentage( string $modifier ): ?string {
		if ( preg_match( '/^(\d+(?:\.\d+)?)%$/', $modifier, $match ) === 1 ) {
			return self::format_number( (float) $match[1] );
		}

		if ( preg_match( '/^\d+(?:\.\d+)?$/', $modifier ) === 1 ) {
			$number = (float) $modifier;

			// A bracketed `0.03` is v3 shorthand for 3%; a bare `70` is 70%.
			if ( str_contains( $modifier, '.' ) && $number <= 1.0 ) {
				$number *= 100.0;
			}

			return self::format_number( $number );
		}

		$named = $this->value( 'opacity', $modifier );
		if ( $named === null || $named === '' || str_starts_with( $named, 'var(' ) ) {
			return null;
		}

		return $this->alpha_percentage( $named );
	}

	/**
	 * Trim a computed float back to the shortest exact CSS number.
	 */
	private static function format_number( float $value ): string {
		return rtrim( rtrim( number_format( $value, 6, '.', '' ), '0' ), '.' );
	}

	/**
	 * Breakpoints sort by length, so a rem value has to be comparable with a
	 * pixel one. An unparseable value sorts last rather than reordering others.
	 */
	private static function length_in_px( string $value ): float {
		if ( preg_match( '/^\s*(-?\d*\.?\d+)\s*([a-z%]*)\s*$/i', $value, $match ) !== 1 ) {
			return PHP_FLOAT_MAX;
		}

		$number = (float) $match[1];
		$unit   = strtolower( $match[2] );

		return ( $unit === 'rem' || $unit === 'em' ) ? $number * 16.0 : $number;
	}

	/**
	 * The namespace and token name a custom property feeds.
	 *
	 * @return array{0: string, 1: string}|null
	 */
	private static function namespace_of( string $name ): ?array {
		foreach ( self::PREFIXES as $prefix => $namespace ) {
			if ( str_starts_with( $name, $prefix ) ) {
				return array( $namespace, substr( $name, strlen( $prefix ) ) );
			}

			if ( $name === rtrim( $prefix, '-' ) ) {
				return array( $namespace, '' );
			}
		}

		return null;
	}

	private static function strip_comments( string $source ): string {
		return (string) preg_replace( '#/\*.*?\*/#s', '', $source );
	}

	/**
	 * Bodies of every `@theme` block, `@theme inline` included.
	 *
	 * @return array<int, string>
	 */
	private static function theme_blocks( string $css ): array {
		$out    = array();
		$offset = 0;

		while ( preg_match( '/@theme\b[^{;]*\{/i', $css, $match, PREG_OFFSET_CAPTURE, $offset ) === 1 ) {
			$open  = (int) $match[0][1] + strlen( (string) $match[0][0] );
			$close = self::closing( $css, $open, '{', '}' );
			if ( $close === null ) {
				break;
			}

			$out[]  = substr( $css, $open, $close - $open );
			$offset = $close + 1;
		}

		return $out;
	}

	/**
	 * Every `@keyframes` in the design CSS, keyed by animation name.
	 *
	 * @return array<string, string>
	 */
	private static function keyframe_blocks( string $css ): array {
		$out    = array();
		$offset = 0;

		while ( preg_match( '/@(?:-[a-z]+-)?keyframes\s+("[^"]*"|\'[^\']*\'|[A-Za-z0-9_-]+)\s*\{/i', $css, $match, PREG_OFFSET_CAPTURE, $offset ) === 1 ) {
			$open  = (int) $match[0][1] + strlen( (string) $match[0][0] );
			$close = self::closing( $css, $open, '{', '}' );
			if ( $close === null ) {
				break;
			}

			$name = trim( (string) $match[1][0], '"\'' );
			if ( $name !== '' ) {
				$out[ $name ] = '@keyframes ' . $name . ' {' . substr( $css, $open, $close - $open ) . '}';
			}

			$offset = $close + 1;
		}

		return $out;
	}

	/**
	 * Custom property declarations of one block, in author order.
	 *
	 * @return array<int, array{0: string, 1: string}>
	 */
	private static function declarations( string $block ): array {
		$out = array();

		foreach ( Candidate::split_top_level( $block, ';' ) as $chunk ) {
			if ( preg_match( '/^\s*(--[A-Za-z0-9_*-]+)\s*:\s*(\S.*?)\s*$/s', $chunk, $match ) !== 1 ) {
				continue;
			}

			$out[] = array( $match[1], (string) preg_replace( '/\s+/', ' ', $match[2] ) );
		}

		return $out;
	}

	/**
	 * Body of the object literal a config key holds, or null when the key is
	 * absent. `$depth` pins how deeply nested the key has to be.
	 */
	private static function config_object( string $source, string $key, ?int $depth ): ?string {
		$pattern = '/[\'"]?' . preg_quote( $key, '/' ) . '[\'"]?\s*:\s*\{/';
		$offset  = 0;

		while ( preg_match( $pattern, $source, $match, PREG_OFFSET_CAPTURE, $offset ) === 1 ) {
			$start  = (int) $match[0][1];
			$open   = $start + strlen( (string) $match[0][0] );
			$offset = $open;

			if ( $depth !== null && self::depth_at( $source, $start ) !== $depth ) {
				continue;
			}

			$close = self::closing( $source, $open, '{', '}' );
			if ( $close === null ) {
				return null;
			}

			return substr( $source, $open, $close - $open );
		}

		return null;
	}

	/**
	 * Scalar and one-level-nested entries of a config object.
	 *
	 * @return array<string, string>
	 */
	private static function config_pairs( string $group ): array {
		$out     = array();
		$offset  = 0;
		$pattern = '/[\'"]?([A-Za-z0-9_.-]+)[\'"]?\s*:\s*(\{|\[|\'[^\']*\'|"[^"]*"|`[^`]*`|[^,\s\}\]]+)/';

		while ( preg_match( $pattern, $group, $match, PREG_OFFSET_CAPTURE, $offset ) === 1 ) {
			$name   = (string) $match[1][0];
			$value  = (string) $match[2][0];
			$start  = (int) $match[2][1];
			$offset = $start + strlen( $value );

			if ( self::depth_at( $group, (int) $match[1][1] ) !== 0 ) {
				continue;
			}

			if ( $value === '{' || $value === '[' ) {
				$close = self::closing( $group, $start + 1, $value, '{' === $value ? '}' : ']' );
				if ( $close === null ) {
					break;
				}

				$body   = substr( $group, $start + 1, $close - $start - 1 );
				$offset = $close + 1;

				if ( $value === '[' ) {
					$joined = self::config_list( $body );
					if ( $joined !== null ) {
						$out[ self::config_token( $name ) ] = $joined;
					}

					continue;
				}

				foreach ( self::config_pairs( $body ) as $child => $child_value ) {
					$token         = '' === $child ? self::config_token( $name ) : self::config_token( $name ) . '-' . $child;
					$out[ $token ] = $child_value;
				}

				continue;
			}

			$value = trim( $value, '\'"`' );
			if ( $value === '' || preg_match( '/^[#A-Za-z0-9][A-Za-z0-9_.,%#\/() -]*$/', $value ) !== 1 ) {
				continue;
			}

			$out[ self::config_token( $name ) ] = $value;
		}

		return $out;
	}

	/**
	 * A quoted array becomes a comma list, the way a v3 `fontFamily` reads.
	 * When the array mixes in an object — a v3 `fontSize` pair — only the first
	 * string is the value.
	 */
	private static function config_list( string $list ): ?string {
		if ( preg_match_all( '/([\'"`])([^\'"`]*)\1/', $list, $matches ) < 1 ) {
			return null;
		}

		$values = $matches[2];

		if ( str_contains( $list, '{' ) ) {
			return $values[0] === '' ? null : (string) $values[0];
		}

		return implode( ', ', $values );
	}

	/**
	 * A config's `DEFAULT` is v4's namespace root, which is the empty token.
	 */
	private static function config_token( string $name ): string {
		return $name === 'DEFAULT' ? '' : $name;
	}

	/**
	 * Offset of the delimiter closing the block that starts at `$start`, with
	 * quoted text skipped so a `"}"` inside a value cannot end it early.
	 */
	private static function closing( string $source, int $start, string $open, string $close ): ?int {
		$depth  = 1;
		$length = strlen( $source );
		$quote  = '';

		for ( $i = $start; $i < $length; $i++ ) {
			$char = $source[ $i ];

			if ( $quote !== '' ) {
				if ( $char === '\\' ) {
					++$i;
					continue;
				}
				if ( $char === $quote ) {
					$quote = '';
				}
				continue;
			}

			if ( $char === '"' || $char === "'" || $char === '`' ) {
				$quote = $char;
				continue;
			}

			if ( $char === $open ) {
				++$depth;
				continue;
			}

			if ( $char === $close ) {
				--$depth;
				if ( $depth === 0 ) {
					return $i;
				}
			}
		}

		return null;
	}

	/**
	 * How many unclosed brackets stand before `$offset`.
	 */
	private static function depth_at( string $source, int $offset ): int {
		$depth = 0;
		$quote = '';
		$limit = min( $offset, strlen( $source ) );

		for ( $i = 0; $i < $limit; $i++ ) {
			$char = $source[ $i ];

			if ( $quote !== '' ) {
				if ( $char === '\\' ) {
					++$i;
					continue;
				}
				if ( $char === $quote ) {
					$quote = '';
				}
				continue;
			}

			if ( $char === '"' || $char === "'" || $char === '`' ) {
				$quote = $char;
				continue;
			}

			if ( $char === '{' || $char === '[' ) {
				++$depth;
				continue;
			}

			if ( $char === '}' || $char === ']' ) {
				$depth = max( 0, $depth - 1 );
			}
		}

		return $depth;
	}
}
