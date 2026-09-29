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
 * Server-side measurement (DXAI_UI\Verification\Design_Coverage) — this only
 * arranges it. A row marked informational cannot fail: the purger removes rules
 * a page does not use, and demanding the same count on both sides would make
 * the table lie in the other direction.
 */
const COVERAGE_GROUPS = [
	{
		title: __( 'Styles and animation', 'dxai-ui' ),
		rows: [
			'keyframes', 'keyframes-resolved', 'animation-declarations', 'transitions',
			'custom-properties', 'tokens-resolved', 'css-rules', 'media-queries',
			'css-url-assets', 'font-face', 'font-stylesheets',
		],
	},
	{
		title: __( 'Vectors and media', 'dxai-ui' ),
		rows: [ 'inline-svg', 'svg-shapes', 'svg-defs', 'svg-files', 'icons', 'icons-wired', 'images', 'videos', 'archive-media' ],
	},
	{
		title: __( 'Behaviour', 'dxai-ui' ),
		rows: [
			'behaviour-markers', 'state-classes', 'scroll-state', 'motion-elements',
			'interactive-components', 'handlers', 'behaviour-wired', 'runtime-js',
		],
	},
];

const COVERAGE_LABELS = {
	keyframes: __( 'Keyframes', 'dxai-ui' ),
	'keyframes-resolved': __( 'Animations with keyframes', 'dxai-ui' ),
	'tokens-resolved': __( 'Tokens resolved', 'dxai-ui' ),
	'css-url-assets': __( 'Stylesheet assets', 'dxai-ui' ),
	'icons-wired': __( 'Icon runtime', 'dxai-ui' ),
	'scroll-state': __( 'Scroll state', 'dxai-ui' ),
	'behaviour-wired': __( 'Behaviour wired', 'dxai-ui' ),
	'animation-declarations': __( 'Animations', 'dxai-ui' ),
	transitions: __( 'Transitions', 'dxai-ui' ),
	'custom-properties': __( 'Design tokens', 'dxai-ui' ),
	'css-rules': __( 'CSS rules', 'dxai-ui' ),
	'media-queries': __( 'Breakpoints', 'dxai-ui' ),
	'font-face': __( 'Font faces', 'dxai-ui' ),
	'font-stylesheets': __( 'Font stylesheets', 'dxai-ui' ),
	'inline-svg': __( 'Inline SVG', 'dxai-ui' ),
	'svg-shapes': __( 'SVG shapes', 'dxai-ui' ),
	'svg-defs': __( 'Gradients and masks', 'dxai-ui' ),
	'svg-files': __( 'SVG files', 'dxai-ui' ),
	icons: __( 'Icons', 'dxai-ui' ),
	images: __( 'Images', 'dxai-ui' ),
	videos: __( 'Videos', 'dxai-ui' ),
	'archive-media': __( 'Files in the archive', 'dxai-ui' ),
	'behaviour-markers': __( 'Behaviour hooks', 'dxai-ui' ),
	'state-classes': __( 'State classes', 'dxai-ui' ),
	handlers: __( 'JSX handlers', 'dxai-ui' ),
	'runtime-js': __( 'Behaviour script', 'dxai-ui' ),
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
		title: __( 'Also measured', 'dxai-ui' ),
		rows: rows.map( ( row ) => row.dimension ).filter( ( name ) => ! grouped.includes( name ) ),
	} ] );

	return (
		<section className={ 'dxai-coverage' + ( short.length === 0 ? ' is-whole' : '' ) }>
			<header className="dxai-coverage-head">
				<div>
					<p className="dxai-section-kicker">{ __( 'Import coverage', 'dxai-ui' ) }</p>
					<h4>
						{ short.length === 0
							? __( 'Every measurable dimension came across', 'dxai-ui' )
							: sprintf(
								/* translators: %d: number of dimensions that lost something. */
								_n( '%d dimension lost something', '%d dimensions lost something', short.length, 'dxai-ui' ),
								short.length
							) }
					</h4>
				</div>
				{ typeof coverage.score === 'number' && (
					<p className="dxai-coverage-score">
						<strong>{ coverage.score }%</strong>
						<span>{ __( 'carried', 'dxai-ui' ) }</span>
					</p>
				) }
			</header>

			{ groups.map( ( group ) => {
				const present = group.rows.map( ( name ) => byName[ name ] ).filter( Boolean );
				if ( present.length === 0 ) {
					return null;
				}

				return (
					<div className="dxai-coverage-group" key={ group.title }>
						<p className="dxai-section-kicker">{ group.title }</p>
						<table className="dxai-coverage-table">
							<thead>
								<tr>
									<th scope="col">{ __( 'Dimension', 'dxai-ui' ) }</th>
									<th scope="col">{ __( 'In the design', 'dxai-ui' ) }</th>
									<th scope="col">{ __( 'On the page', 'dxai-ui' ) }</th>
									<th scope="col">{ __( 'Result', 'dxai-ui' ) }</th>
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
													__( '%d missing', 'dxai-ui' ),
													row.source - row.page
												)
												: ( row.unknown
													? __( 'Not measurable', 'dxai-ui' )
													: ( row.informational ? __( 'For reference', 'dxai-ui' ) : __( 'Carried', 'dxai-ui' ) ) ) }
											{ row.short && Array.isArray( row.missing ) && row.missing.length > 0 && (
												<span className="dxai-coverage-missing">{ row.missing.join( ', ' ) }</span>
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
			label: __( 'Editable blocks', 'dxai-ui' ),
			value: report.editable === null ? '—' : report.editable + '%',
			state: report.islands === 0 ? 'ok' : ( report.editable >= 90 ? 'warn' : 'bad' ),
			detail: report.islands === 0
				? sprintf(
					/* translators: %d: number of blocks. */
					_n( '%d block, all of it editable in Gutenberg.', '%d blocks, all of them editable in Gutenberg.', report.blocks, 'dxai-ui' ),
					report.blocks
				)
				: sprintf(
					/* translators: 1: raw-HTML blocks, 2: total blocks. */
					__( '%1$d of %2$d blocks stayed raw HTML: right on screen, not editable.', 'dxai-ui' ),
					report.islands,
					report.blocks
				),
		},
		{
			id: 'styles',
			label: __( 'Tailwind rules', 'dxai-ui' ),
			value: report.utilities.length === 0 ? __( 'Complete', 'dxai-ui' ) : String( report.utilities.length ),
			state: report.utilities.length === 0 ? 'ok' : 'bad',
			detail: report.utilities.length === 0
				? __( 'Every utility class in this markup compiled to a rule.', 'dxai-ui' )
				: sprintf(
					/* translators: 1: number of classes, 2: an example class. */
					__( '%1$d classes have no rule. First: %2$s.', 'dxai-ui' ),
					report.utilities.length,
					report.utilities[ 0 ]
				),
		},
		{
			id: 'theme',
			label: __( 'Design theme', 'dxai-ui' ),
			value: report.theme.css > 0 || report.theme.staticHtml ? __( 'Carried', 'dxai-ui' ) : __( 'Missing', 'dxai-ui' ),
			state: report.theme.css > 0 || report.theme.staticHtml ? 'ok' : 'bad',
			detail: report.theme.staticHtml
				? __( 'This design never ran under Tailwind, so it takes no Preflight and no reset.', 'dxai-ui' )
				: ( report.theme.css > 0
					? sprintf(
						/* translators: 1: stylesheet size in KB, 2: tailwind.config note. */
						__( 'The design’s own stylesheet travelled (%1$s KB)%2$s.', 'dxai-ui' ),
						Math.round( report.theme.css / 1024 ),
						report.theme.config > 0 ? __( ', with its tailwind.config', 'dxai-ui' ) : ''
					)
					: __( 'No stylesheet came with this conversion: the page will be painted with Tailwind defaults.', 'dxai-ui' ) ),
		},
		{
			id: 'expressions',
			label: __( 'Expressions', 'dxai-ui' ),
			value: report.expressions === 0 ? __( 'All read', 'dxai-ui' ) : String( report.expressions ),
			state: report.expressions === 0 ? 'ok' : 'warn',
			detail: report.expressions === 0
				? __( 'Every value in the design evaluated to what the design shows.', 'dxai-ui' )
				: sprintf(
					/* translators: 1: number of expressions, 2: an example source. */
					__( '%1$d values could not be evaluated, so they may differ from the design. First: %2$s.', 'dxai-ui' ),
					report.expressions,
					String( report.unevaluated[ 0 ]?.source || '' ).slice( 0, 60 )
				),
		},
	];

	const verdict = report.perfect
		? __( 'Pixel perfect and fully editable', 'dxai-ui' )
		: sprintf(
			/* translators: %d: number of gaps. */
			_n( '%d thing between this and pixel perfect', '%d things between this and pixel perfect', report.gaps.length, 'dxai-ui' ),
			report.gaps.length
		);

	return (
		<section className={ 'dxai-fidelity' + ( report.perfect ? ' is-perfect' : '' ) + ( compact ? ' is-compact' : '' ) }>
			<header className="dxai-fidelity-head">
				<div>
					<p className="dxai-section-kicker">{ __( 'Conversion report', 'dxai-ui' ) }</p>
					<h4>{ verdict }</h4>
				</div>
				<dl className="dxai-fidelity-tally">
					<div>
						<dt>{ __( 'Sections', 'dxai-ui' ) }</dt>
						<dd>{ report.sections }</dd>
					</div>
					<div>
						<dt>{ __( 'Blocks', 'dxai-ui' ) }</dt>
						<dd>{ report.blocks }</dd>
					</div>
					<div>
						<dt>{ __( 'Assets', 'dxai-ui' ) }</dt>
						<dd>{ report.assets.images + report.assets.videos + report.assets.fonts }</dd>
					</div>
					{ report.score !== null && (
						<div>
							<dt>{ __( 'Score', 'dxai-ui' ) }</dt>
							<dd>{ report.score }</dd>
						</div>
					) }
				</dl>
			</header>

			<ul className="dxai-fidelity-rows">
				{ rows.map( ( row ) => (
					<li key={ row.id } className={ 'is-' + row.state }>
						<span className="dxai-fidelity-dot" aria-hidden="true" />
						<span className="dxai-fidelity-label">{ row.label }</span>
						<strong className="dxai-fidelity-value">{ row.value }</strong>
						<span className="dxai-fidelity-detail">{ row.detail }</span>
					</li>
				) ) }
			</ul>

			{ worst.length > 0 && (
				<div className="dxai-fidelity-more">
					<p className="dxai-section-kicker">{ __( 'Elements that stayed raw HTML', 'dxai-ui' ) }</p>
					<ul className="dxai-fidelity-tags">
						{ worst.map( ( item ) => (
							<li key={ item.tag }>
								<code>{ item.tag }</code>
								<span>{ item.count }</span>
							</li>
						) ) }
					</ul>
					<p className="dxai-muted">
						{ __( 'These render exactly as designed. They are the parts an editor cannot take apart yet — each one needs a block of its own.', 'dxai-ui' ) }
					</p>
				</div>
			) }

			{ coverage && <CoverageTable coverage={ coverage } /> }

			{ report.assets.images + report.assets.videos + report.assets.fonts + report.assets.links > 0 && (
				<p className="dxai-fidelity-assets dxai-muted">
					{ sprintf(
						/* translators: 1: images, 2: videos, 3: fonts, 4: links. */
						__( '%1$d images · %2$d videos · %3$d fonts · %4$d links came across with the design.', 'dxai-ui' ),
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
