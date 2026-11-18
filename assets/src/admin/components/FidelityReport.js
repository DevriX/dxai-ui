import { __, _n, sprintf } from '@wordpress/i18n';
import { measureFidelity, worstElements } from '../fidelity';

/**
 * What a Lovable export carries, counted on both sides of the conversion.
 *
 * Grouped the way someone looks for a loss: the styles and animation first,
 * because a page can be pixel-identical in a screenshot and never move; then
 * the artwork, because an `<svg>` whose shapes were flattened is the same box
 * with nothing in it; then the behaviour, because a control that is there and
 * does nothing looks exactly like one that works.
 *
 * Server-side measurement (DX_UI\Verification\Design_Coverage) — this only
 * arranges it. A row marked informational cannot fail: the purger removes rules
 * a page does not use, and demanding the same count on both sides would make
 * the table lie in the other direction.
 */
const COVERAGE_GROUPS = [
	{
		title: __( 'Styles and animation', 'dx-ui' ),
		rows: [
			'keyframes', 'keyframes-resolved', 'animation-declarations', 'transitions',
			'custom-properties', 'tokens-resolved', 'css-rules', 'media-queries',
			'css-url-assets', 'font-face', 'font-stylesheets',
		],
	},
	{
		title: __( 'Vectors and media', 'dx-ui' ),
		rows: [ 'inline-svg', 'svg-shapes', 'svg-defs', 'svg-files', 'icons', 'icons-wired', 'images', 'videos', 'archive-media' ],
	},
	{
		title: __( 'Behaviour', 'dx-ui' ),
		rows: [
			'behaviour-markers', 'state-classes', 'scroll-state', 'motion-elements',
			'interactive-components', 'handlers', 'behaviour-wired', 'runtime-js',
		],
	},
];

const COVERAGE_LABELS = {
	keyframes: __( 'Keyframes', 'dx-ui' ),
	'keyframes-resolved': __( 'Animations with keyframes', 'dx-ui' ),
	'tokens-resolved': __( 'Tokens resolved', 'dx-ui' ),
	'css-url-assets': __( 'Stylesheet assets', 'dx-ui' ),
	'icons-wired': __( 'Icon runtime', 'dx-ui' ),
	'scroll-state': __( 'Scroll state', 'dx-ui' ),
	'behaviour-wired': __( 'Behaviour wired', 'dx-ui' ),
	'animation-declarations': __( 'Animations', 'dx-ui' ),
	transitions: __( 'Transitions', 'dx-ui' ),
	'custom-properties': __( 'Design tokens', 'dx-ui' ),
	'css-rules': __( 'CSS rules', 'dx-ui' ),
	'media-queries': __( 'Breakpoints', 'dx-ui' ),
	'font-face': __( 'Font faces', 'dx-ui' ),
	'font-stylesheets': __( 'Font stylesheets', 'dx-ui' ),
	'inline-svg': __( 'Inline SVG', 'dx-ui' ),
	'svg-shapes': __( 'SVG shapes', 'dx-ui' ),
	'svg-defs': __( 'Gradients and masks', 'dx-ui' ),
	'svg-files': __( 'SVG files', 'dx-ui' ),
	icons: __( 'Icons', 'dx-ui' ),
	images: __( 'Images', 'dx-ui' ),
	videos: __( 'Videos', 'dx-ui' ),
	'archive-media': __( 'Files in the archive', 'dx-ui' ),
	'behaviour-markers': __( 'Behaviour hooks', 'dx-ui' ),
	'state-classes': __( 'State classes', 'dx-ui' ),
	handlers: __( 'JSX handlers', 'dx-ui' ),
	'runtime-js': __( 'Behaviour script', 'dx-ui' ),
};

export function CoverageTable( { coverage } ) {
	const rows = Array.isArray( coverage?.rows ) ? coverage.rows : [];
	if ( rows.length === 0 ) {
		return null;
	}

	const byName = {};
	rows.forEach( ( row ) => {
		byName[ row.dimension ] = row;
	} );
	const short = rows.filter( ( row ) => row.short );

	/*
	 * Whatever the groups above do not name. The server decides what it
	 * measures, and a dimension added there used to vanish here — including
	 * the only row that reported a loss, while the heading counted it. A
	 * report that hides a finding is worse than no report.
	 */
	const grouped = COVERAGE_GROUPS.reduce( ( all, group ) => all.concat( group.rows ), [] );
	const groups = COVERAGE_GROUPS.concat( [ {
		title: __( 'Also measured', 'dx-ui' ),
		rows: rows.map( ( row ) => row.dimension ).filter( ( name ) => ! grouped.includes( name ) ),
	} ] );

	return (
		<section className={ 'dxui-coverage' + ( short.length === 0 ? ' is-whole' : '' ) }>
			<header className="dxui-coverage-head">
				<div>
					<p className="dxui-section-kicker">{ __( 'Import coverage', 'dx-ui' ) }</p>
					<h4>
						{ short.length === 0
							? __( 'Every measurable dimension came across', 'dx-ui' )
							: sprintf(
								/* translators: %d: number of dimensions that lost something. */
								_n( '%d dimension lost something', '%d dimensions lost something', short.length, 'dx-ui' ),
								short.length
							) }
					</h4>
				</div>
				{ typeof coverage.score === 'number' && (
					<p className="dxui-coverage-score">
						<strong>{ coverage.score }%</strong>
						<span>{ __( 'carried', 'dx-ui' ) }</span>
					</p>
				) }
			</header>

			{ groups.map( ( group ) => {
				const present = group.rows.map( ( name ) => byName[ name ] ).filter( Boolean );
				if ( present.length === 0 ) {
					return null;
				}

				return (
					<div className="dxui-coverage-group" key={ group.title }>
						<p className="dxui-section-kicker">{ group.title }</p>
						<table className="dxui-coverage-table">
							<thead>
								<tr>
									<th scope="col">{ __( 'Dimension', 'dx-ui' ) }</th>
									<th scope="col">{ __( 'In the design', 'dx-ui' ) }</th>
									<th scope="col">{ __( 'On the page', 'dx-ui' ) }</th>
									<th scope="col">{ __( 'Result', 'dx-ui' ) }</th>
								</tr>
							</thead>
							<tbody>
								{ present.map( ( row ) => (
									<tr
										key={ row.dimension }
										className={ row.short ? 'is-short' : ( row.unknown ? 'is-unknown' : ( row.informational ? 'is-info' : 'is-ok' ) ) }
									>
										<th scope="row">{ COVERAGE_LABELS[ row.dimension ] || row.dimension }</th>
										<td>{ row.unknown ? '—' : row.source }</td>
										<td>{ row.page }</td>
										<td>
											{ row.short
												? sprintf(
													/* translators: %d: how many items did not arrive. */
													__( '%d missing', 'dx-ui' ),
													row.source - row.page
												)
												: ( row.unknown
													? __( 'Not measurable', 'dx-ui' )
													: ( row.informational ? __( 'For reference', 'dx-ui' ) : __( 'Carried', 'dx-ui' ) ) ) }
											{ row.short && Array.isArray( row.missing ) && row.missing.length > 0 && (
												<span className="dxui-coverage-missing">{ row.missing.join( ', ' ) }</span>
											) }
										</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>
				);
			} ) }
		</section>
	);
}

/**
 * Whether this conversion is pixel perfect, and what is in the way if it is not.
 *
 * Four numbers decide it, and each one is a thing the compiler measured rather
 * than guessed:
 *
 * - raw-HTML islands, because an island renders exactly right and cannot be
 *   edited — the pixel oracle and block validation are both blind to it, so
 *   this is the one gap no other check can catch;
 * - Tailwind utilities with no compiler rule, because those classes are in the
 *   markup with nothing to paint them;
 * - expressions the JSX evaluator could not evaluate, because each one is a
 *   value on the page that came from somewhere other than the design;
 * - whether the design's own theme travelled, because a v3 project keeps its
 *   whole palette in tailwind.config.ts and a page without it is painted in
 *   Tailwind's default colours.
 *
 * A gap here is not a failed import. It is the difference between "it saved"
 * and "it matches", which is the question someone opening this screen is
 * actually asking.
 */
export default function FidelityReport( { result, harvest, structures, compact, coverage } ) {
	if ( ! result ) {
		return null;
	}

	const report = measureFidelity( result, harvest, structures );
	const worst = worstElements( report.byElement );

	const rows = [
		{
			id: 'editable',
			label: __( 'Editable blocks', 'dx-ui' ),
			value: report.editable === null ? '—' : report.editable + '%',
			state: report.islands === 0 ? 'ok' : ( report.editable >= 90 ? 'warn' : 'bad' ),
			detail: report.islands === 0
				? sprintf(
					/* translators: %d: number of blocks. */
					_n( '%d block, all of it editable in Gutenberg.', '%d blocks, all of them editable in Gutenberg.', report.blocks, 'dx-ui' ),
					report.blocks
				)
				: sprintf(
					/* translators: 1: raw-HTML blocks, 2: total blocks. */
					__( '%1$d of %2$d blocks stayed raw HTML: right on screen, not editable.', 'dx-ui' ),
					report.islands,
					report.blocks
				),
		},
		{
			id: 'styles',
			label: __( 'Tailwind rules', 'dx-ui' ),
			value: report.utilities.length === 0 ? __( 'Complete', 'dx-ui' ) : String( report.utilities.length ),
			state: report.utilities.length === 0 ? 'ok' : 'bad',
			detail: report.utilities.length === 0
				? __( 'Every utility class in this markup compiled to a rule.', 'dx-ui' )
				: sprintf(
					/* translators: 1: number of classes, 2: an example class. */
					__( '%1$d classes have no rule. First: %2$s.', 'dx-ui' ),
					report.utilities.length,
					report.utilities[ 0 ]
				),
		},
		{
			id: 'theme',
			label: __( 'Design theme', 'dx-ui' ),
			value: report.theme.css > 0 || report.theme.staticHtml ? __( 'Carried', 'dx-ui' ) : __( 'Missing', 'dx-ui' ),
			state: report.theme.css > 0 || report.theme.staticHtml ? 'ok' : 'bad',
			detail: report.theme.staticHtml
				? __( 'This design never ran under Tailwind, so it takes no Preflight and no reset.', 'dx-ui' )
				: ( report.theme.css > 0
					? sprintf(
						/* translators: 1: stylesheet size in KB, 2: tailwind.config note. */
						__( 'The design’s own stylesheet travelled (%1$s KB)%2$s.', 'dx-ui' ),
						Math.round( report.theme.css / 1024 ),
						report.theme.config > 0 ? __( ', with its tailwind.config', 'dx-ui' ) : ''
					)
					: __( 'No stylesheet came with this conversion: the page will be painted with Tailwind defaults.', 'dx-ui' ) ),
		},
		{
			id: 'expressions',
			label: __( 'Expressions', 'dx-ui' ),
			value: report.expressions === 0 ? __( 'All read', 'dx-ui' ) : String( report.expressions ),
			state: report.expressions === 0 ? 'ok' : 'warn',
			detail: report.expressions === 0
				? __( 'Every value in the design evaluated to what the design shows.', 'dx-ui' )
				: sprintf(
					/* translators: 1: number of expressions, 2: an example source. */
					__( '%1$d values could not be evaluated, so they may differ from the design. First: %2$s.', 'dx-ui' ),
					report.expressions,
					String( report.unevaluated[ 0 ]?.source || '' ).slice( 0, 60 )
				),
		},
	];

	const verdict = report.perfect
		? __( 'Pixel perfect and fully editable', 'dx-ui' )
		: sprintf(
			/* translators: %d: number of gaps. */
			_n( '%d thing between this and pixel perfect', '%d things between this and pixel perfect', report.gaps.length, 'dx-ui' ),
			report.gaps.length
		);

	return (
		<section className={ 'dxui-fidelity' + ( report.perfect ? ' is-perfect' : '' ) + ( compact ? ' is-compact' : '' ) }>
			<header className="dxui-fidelity-head">
				<div>
					<p className="dxui-section-kicker">{ __( 'Conversion report', 'dx-ui' ) }</p>
					<h4>{ verdict }</h4>
				</div>
				<dl className="dxui-fidelity-tally">
					<div>
						<dt>{ __( 'Sections', 'dx-ui' ) }</dt>
						<dd>{ report.sections }</dd>
					</div>
					<div>
						<dt>{ __( 'Blocks', 'dx-ui' ) }</dt>
						<dd>{ report.blocks }</dd>
					</div>
					<div>
						<dt>{ __( 'Assets', 'dx-ui' ) }</dt>
						<dd>{ report.assets.images + report.assets.videos + report.assets.fonts }</dd>
					</div>
					{ report.score !== null && (
						<div>
							<dt>{ __( 'Score', 'dx-ui' ) }</dt>
							<dd>{ report.score }</dd>
						</div>
					) }
				</dl>
			</header>

			<ul className="dxui-fidelity-rows">
				{ rows.map( ( row ) => (
					<li key={ row.id } className={ 'is-' + row.state }>
						<span className="dxui-fidelity-dot" aria-hidden="true" />
						<span className="dxui-fidelity-label">{ row.label }</span>
						<strong className="dxui-fidelity-value">{ row.value }</strong>
						<span className="dxui-fidelity-detail">{ row.detail }</span>
					</li>
				) ) }
			</ul>

			{ worst.length > 0 && (
				<div className="dxui-fidelity-more">
					<p className="dxui-section-kicker">{ __( 'Elements that stayed raw HTML', 'dx-ui' ) }</p>
					<ul className="dxui-fidelity-tags">
						{ worst.map( ( item ) => (
							<li key={ item.tag }>
								<code>{ item.tag }</code>
								<span>{ item.count }</span>
							</li>
						) ) }
					</ul>
					<p className="dxui-muted">
						{ __( 'These render exactly as designed. They are the parts an editor cannot take apart yet — each one needs a block of its own.', 'dx-ui' ) }
					</p>
				</div>
			) }

			{ coverage && <CoverageTable coverage={ coverage } /> }

			{ report.assets.images + report.assets.videos + report.assets.fonts + report.assets.links > 0 && (
				<p className="dxui-fidelity-assets dxui-muted">
					{ sprintf(
						/* translators: 1: images, 2: videos, 3: fonts, 4: links. */
						__( '%1$d images · %2$d videos · %3$d fonts · %4$d links came across with the design.', 'dx-ui' ),
						report.assets.images,
						report.assets.videos,
						report.assets.fonts,
						report.assets.links
					) }
				</p>
			) }
		</section>
	);
}
