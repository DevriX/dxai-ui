<?php
/**
 * How an SVG attribute name is spelled in saved block markup.
 *
 * The editor writes a block's attributes through @wordpress/element's
 * renderAttributes(), which normalises every name with
 * getNormalAttributeName(): it lower-cases the name, then looks it up in three
 * tables and restores the SVG spelling — `viewbox` becomes `viewBox`,
 * `strokewidth` becomes `stroke-width`, `xlinkhref` becomes `xlink:href`.
 * Gutenberg then compares its save() output with the stored markup byte for
 * byte, so the PHP emitter has to spell them the same way or every SVG block
 * opens invalid.
 *
 * DOMDocument, which reads the design, lower-cases attribute names on the way
 * in, so `viewBox` arrives here as `viewbox` and this map is what puts it
 * back.
 *
 * Generated from wp-includes/js/dist/element.js (148 names) rather
 * than typed out, because a table that drifts from the editor's is worse than
 * no table: it would be wrong only for the attributes nobody checked.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Compiler;

final class Svg_Attrs {

	/**
	 * Lower-cased name => the spelling the editor writes.
	 *
	 * @var array<string, string>
	 */
	private const NAMES = array(
		'accentheight'                 => 'accent-height',
		'alignmentbaseline'            => 'alignment-baseline',
		'allowreorder'                 => 'allowReorder',
		'arabicform'                   => 'arabic-form',
		'attributename'                => 'attributeName',
		'attributetype'                => 'attributeType',
		'autoreverse'                  => 'autoReverse',
		'basefrequency'                => 'baseFrequency',
		'baselineshift'                => 'baseline-shift',
		'baseprofile'                  => 'baseProfile',
		'calcmode'                     => 'calcMode',
		'capheight'                    => 'cap-height',
		'clippath'                     => 'clip-path',
		'clippathunits'                => 'clipPathUnits',
		'cliprule'                     => 'clip-rule',
		'colorinterpolation'           => 'color-interpolation',
		'colorinterpolationfilters'    => 'color-interpolation-filters',
		'colorprofile'                 => 'color-profile',
		'colorrendering'               => 'color-rendering',
		'contentscripttype'            => 'contentScriptType',
		'contentstyletype'             => 'contentStyleType',
		'diffuseconstant'              => 'diffuseConstant',
		'dominantbaseline'             => 'dominant-baseline',
		'edgemode'                     => 'edgeMode',
		'enablebackground'             => 'enable-background',
		'externalresourcesrequired'    => 'externalResourcesRequired',
		'fillopacity'                  => 'fill-opacity',
		'fillrule'                     => 'fill-rule',
		'filterres'                    => 'filterRes',
		'filterunits'                  => 'filterUnits',
		'floodcolor'                   => 'flood-color',
		'floodopacity'                 => 'flood-opacity',
		'fontfamily'                   => 'font-family',
		'fontsize'                     => 'font-size',
		'fontsizeadjust'               => 'font-size-adjust',
		'fontstretch'                  => 'font-stretch',
		'fontstyle'                    => 'font-style',
		'fontvariant'                  => 'font-variant',
		'fontweight'                   => 'font-weight',
		'glyphname'                    => 'glyph-name',
		'glyphorientationhorizontal'   => 'glyph-orientation-horizontal',
		'glyphorientationvertical'     => 'glyph-orientation-vertical',
		'glyphref'                     => 'glyphRef',
		'gradienttransform'            => 'gradientTransform',
		'gradientunits'                => 'gradientUnits',
		'horizadvx'                    => 'horiz-adv-x',
		'horizoriginx'                 => 'horiz-origin-x',
		'imagerendering'               => 'image-rendering',
		'kernelmatrix'                 => 'kernelMatrix',
		'kernelunitlength'             => 'kernelUnitLength',
		'keypoints'                    => 'keyPoints',
		'keysplines'                   => 'keySplines',
		'keytimes'                     => 'keyTimes',
		'lengthadjust'                 => 'lengthAdjust',
		'letterspacing'                => 'letter-spacing',
		'lightingcolor'                => 'lighting-color',
		'limitingconeangle'            => 'limitingConeAngle',
		'markerend'                    => 'marker-end',
		'markerheight'                 => 'markerHeight',
		'markermid'                    => 'marker-mid',
		'markerstart'                  => 'marker-start',
		'markerunits'                  => 'markerUnits',
		'markerwidth'                  => 'markerWidth',
		'maskcontentunits'             => 'maskContentUnits',
		'maskunits'                    => 'maskUnits',
		'numoctaves'                   => 'numOctaves',
		'overlineposition'             => 'overline-position',
		'overlinethickness'            => 'overline-thickness',
		'paintorder'                   => 'paint-order',
		'panose1'                      => 'panose1',
		'pathlength'                   => 'pathLength',
		'patterncontentunits'          => 'patternContentUnits',
		'patterntransform'             => 'patternTransform',
		'patternunits'                 => 'patternUnits',
		'pointerevents'                => 'pointer-events',
		'pointsatx'                    => 'pointsAtX',
		'pointsaty'                    => 'pointsAtY',
		'pointsatz'                    => 'pointsAtZ',
		'preservealpha'                => 'preserveAlpha',
		'preserveaspectratio'          => 'preserveAspectRatio',
		'primitiveunits'               => 'primitiveUnits',
		'refx'                         => 'refX',
		'refy'                         => 'refY',
		'renderingintent'              => 'rendering-intent',
		'repeatcount'                  => 'repeatCount',
		'repeatdur'                    => 'repeatDur',
		'requiredextensions'           => 'requiredExtensions',
		'requiredfeatures'             => 'requiredFeatures',
		'shaperendering'               => 'shape-rendering',
		'specularconstant'             => 'specularConstant',
		'specularexponent'             => 'specularExponent',
		'spreadmethod'                 => 'spreadMethod',
		'startoffset'                  => 'startOffset',
		'stddeviation'                 => 'stdDeviation',
		'stitchtiles'                  => 'stitchTiles',
		'stopcolor'                    => 'stop-color',
		'stopopacity'                  => 'stop-opacity',
		'strikethroughposition'        => 'strikethrough-position',
		'strikethroughthickness'       => 'strikethrough-thickness',
		'strokedasharray'              => 'stroke-dasharray',
		'strokedashoffset'             => 'stroke-dashoffset',
		'strokelinecap'                => 'stroke-linecap',
		'strokelinejoin'               => 'stroke-linejoin',
		'strokemiterlimit'             => 'stroke-miterlimit',
		'strokeopacity'                => 'stroke-opacity',
		'strokewidth'                  => 'stroke-width',
		'suppresscontenteditablewarning'  => 'suppressContentEditableWarning',
		'suppresshydrationwarning'     => 'suppressHydrationWarning',
		'surfacescale'                 => 'surfaceScale',
		'systemlanguage'               => 'systemLanguage',
		'tablevalues'                  => 'tableValues',
		'targetx'                      => 'targetX',
		'targety'                      => 'targetY',
		'textanchor'                   => 'text-anchor',
		'textdecoration'               => 'text-decoration',
		'textlength'                   => 'textLength',
		'textrendering'                => 'text-rendering',
		'underlineposition'            => 'underline-position',
		'underlinethickness'           => 'underline-thickness',
		'unicodebidi'                  => 'unicode-bidi',
		'unicoderange'                 => 'unicode-range',
		'unitsperem'                   => 'units-per-em',
		'valphabetic'                  => 'v-alphabetic',
		'vectoreffect'                 => 'vector-effect',
		'vertadvy'                     => 'vert-adv-y',
		'vertoriginx'                  => 'vert-origin-x',
		'vertoriginy'                  => 'vert-origin-y',
		'vhanging'                     => 'v-hanging',
		'videographic'                 => 'v-ideographic',
		'viewbox'                      => 'viewBox',
		'viewtarget'                   => 'viewTarget',
		'vmathematical'                => 'v-mathematical',
		'wordspacing'                  => 'word-spacing',
		'writingmode'                  => 'writing-mode',
		'xchannelselector'             => 'xChannelSelector',
		'xheight'                      => 'x-height',
		'xlinkactuate'                 => 'xlink:actuate',
		'xlinkarcrole'                 => 'xlink:arcrole',
		'xlinkhref'                    => 'xlink:href',
		'xlinkrole'                    => 'xlink:role',
		'xlinkshow'                    => 'xlink:show',
		'xlinktitle'                   => 'xlink:title',
		'xlinktype'                    => 'xlink:type',
		'xmlbase'                      => 'xml:base',
		'xmllang'                      => 'xml:lang',
		'xmlnsxlink'                   => 'xmlns-xlink',
		'xmlspace'                     => 'xml:space',
		'ychannelselector'             => 'yChannelSelector',
	);

	/**
	 * The name to write for an attribute read off the design.
	 *
	 * Anything not in the table keeps the name it arrived with: a plain HTML
	 * attribute (`class`, `id`, `data-*`) is already lower-case, and
	 * getNormalAttributeName() returns the lower-cased name for those too.
	 */
	public static function name( string $name ): string {
		$lower = strtolower( $name );

		return self::NAMES[ $lower ] ?? $lower;
	}

	/**
	 * Put the SVG spelling back into a whole document of markup.
	 *
	 * `name()` above repairs one attribute for one block. This repairs the
	 * markup, because the damage is not limited to the blocks that get one:
	 * Jsx_Compiler::split_rendered() round-trips every section through libxml,
	 * which lower-cases attribute names, so `viewBox` reaches the page as
	 * `viewbox` and `strokeWidth` as `strokewidth`. SVG is case-sensitive and
	 * those two spellings mean nothing at all — the artwork loses its
	 * coordinate system and its line weights. Measured on GTM Strategy Hub:
	 * five `viewBox` and four `strokeWidth` in the design's own TSX, every one
	 * of them dead on the page, and the same in `source_html`, so the pixel
	 * oracle compared one broken SVG against another and reported 0px.
	 *
	 * Only inside an `<svg>` element, and only names the table knows, so an
	 * HTML attribute that happens to share a spelling is never touched.
	 * Already-correct names do not match the table's lower-case keys, which
	 * makes a second pass over repaired markup a no-op.
	 */
	public static function restore( string $html ): string {
		if ( $html === '' || stripos( $html, '<svg' ) === false ) {
			return $html;
		}

		return (string) preg_replace_callback(
			'#<svg[\s>][\s\S]*?</svg\s*>#i',
			static function ( array $svg ): string {
				return (string) preg_replace_callback(
					'/(\s)([A-Za-z][A-Za-z0-9_:-]*)(\s*=\s*")/',
					static function ( array $attr ): string {
						$fixed = self::NAMES[ strtolower( $attr[2] ) ] ?? null;

						return $fixed === null ? $attr[0] : $attr[1] . $fixed . $attr[3];
					},
					$svg[0]
				);
			},
			$html
		);
	}
}
