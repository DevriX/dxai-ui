import { __, _n, sprintf } from '@wordpress/i18n';
import { dateOf, designHash } from '../router';

/*
 * The panel's one glossary of a design's state (Design_Status, docs/PLAN-ADMIN-UX.md section 7): six chips, each saying
 * what it means and where it is changed, the same in the list and on the design's page. A chip's tone is its state —
 * done (green), info (blue), warn (yellow), plain (grey) and off (dashed grey: not done, and can be).
 */

/** The chips of a status. `id` makes each chip a link to the tab where its state is changed. */
export function chipsOf( status, id = 0 ) {
	if ( ! status ) {
		return [];
	}
	const to = ( tab ) => ( id ? designHash( id, tab ) : '' );
	const chips = [];

	chips.push( {
		key: 'imported',
		tone: 'plain',
		text: status.imported_at
			/* translators: %s: a date. */
			? sprintf( __( 'Imported %s', 'dxai-ui' ), dateOf( status.imported_at ) )
			: __( 'Imported', 'dxai-ui' ),
		title: status.built_with
			/* translators: 1: a date, 2: the plugin version. */
			? sprintf( __( 'Imported %1$s with DX %2$s. History shows every import.', 'dxai-ui' ), dateOf( status.imported_at, true ), status.built_with )
			: __( 'When the design was last imported. History shows every import.', 'dxai-ui' ),
		href: to( 'history' ),
	} );

	chips.push( status.native
		? {
			key: 'native',
			tone: 'done',
			text: __( 'Native blocks', 'dxai-ui' ),
			title: __( 'The design’s blocks are the theme’s own blocks, editable with the theme’s settings. Changed in Blocks.', 'dxai-ui' ),
			href: to( 'blocks' ),
		}
		: {
			key: 'native',
			tone: 'off',
			text: __( 'DX blocks', 'dxai-ui' ),
			title: __( 'The pages use the plugin’s blocks. Apply native blocks in Blocks to make them the theme’s own.', 'dxai-ui' ),
			href: to( 'blocks' ),
		} );

	if ( status.colours === 'follow' ) {
		chips.push( {
			key: 'colours',
			tone: 'info',
			text: __( 'Theme colours', 'dxai-ui' ),
			title: __( 'The design’s colours follow the theme’s palette. Changed in Look.', 'dxai-ui' ),
			href: to( 'look' ),
		} );
	} else {
		chips.push( {
			key: 'colours',
			tone: status.palette ? 'off' : 'plain',
			text: __( 'Own colours', 'dxai-ui' ),
			title: status.palette
				? __( 'The design keeps its own colours. In Look it can follow the theme’s palette.', 'dxai-ui' )
				: __( 'The design keeps its own colours; the theme has no palette to follow.', 'dxai-ui' ),
			href: to( 'look' ),
		} );
	}

	if ( status.fonts === 'theme' ) {
		chips.push( {
			key: 'fonts',
			tone: 'info',
			text: __( 'Theme fonts', 'dxai-ui' ),
			title: __( 'The design is drawn in the theme’s fonts and loads none of its own. Changed in Speed.', 'dxai-ui' ),
			href: to( 'speed' ),
		} );
	} else {
		chips.push( {
			key: 'fonts',
			tone: status.fonts === 'own' ? 'off' : 'plain',
			text: __( 'Own fonts', 'dxai-ui' ),
			title: status.fonts === 'own'
				? __( 'The design loads its own fonts. In Speed it can use the theme’s.', 'dxai-ui' )
				: __( 'The design loads its own fonts; the theme does not manage the site’s fonts.', 'dxai-ui' ),
			href: to( 'speed' ),
		} );
	}

	if ( status.states > 0 ) {
		chips.push( {
			key: 'states',
			tone: status.interactivity ? 'done' : 'plain',
			text: status.interactivity
				/* translators: %d: number of states. */
				? sprintf( _n( '%d state · Interactivity API', '%d states · Interactivity API', status.states, 'dxai-ui' ), status.states )
				/* translators: %d: number of states. */
				: sprintf( _n( '%d state · page runtime', '%d states · page runtime', status.states, 'dxai-ui' ), status.states ),
			title: status.interactivity
				? __( 'What the page does when touched (menus, tabs, panels) is driven by WordPress’s Interactivity API. The setting is in Settings › Behaviour.', 'dxai-ui' )
				: __( 'What the page does when touched is driven by the plugin’s page runtime. The Interactivity API is off in Settings › Behaviour.', 'dxai-ui' ),
			href: to( 'blocks' ),
		} );
	} else {
		chips.push( {
			key: 'states',
			tone: 'plain',
			text: __( 'No states', 'dxai-ui' ),
			title: __( 'Nothing on the page changes when it is touched; nothing to drive.', 'dxai-ui' ),
			href: to( 'blocks' ),
		} );
	}

	chips.push( status.exported_at
		? {
			key: 'exported',
			tone: 'info',
			/* translators: %s: a date. */
			text: sprintf( __( 'Exported %s', 'dxai-ui' ), dateOf( status.exported_at ) ),
			title: __( 'A package of the design was downloaded. Transfer makes another.', 'dxai-ui' ),
			href: to( 'transfer' ),
		}
		: {
			key: 'exported',
			tone: 'warn',
			text: __( 'Not exported', 'dxai-ui' ),
			title: __( 'No package of the design has left this site. Transfer makes one for the live site.', 'dxai-ui' ),
			href: to( 'transfer' ),
		} );

	return chips;
}

/** The chips drawn. `small` for a table cell. */
export default function StatusChips( { status, id = 0, small = false } ) {
	const chips = chipsOf( status, id );
	if ( ! chips.length ) {
		return null;
	}
	return (
		<ul className={ 'dxai-chips' + ( small ? ' dxai-chips--small' : '' ) }>
			{ chips.map( ( c ) => (
				<li key={ c.key }>
					{ c.href
						? <a className={ 'dxai-chip dxai-chip--' + c.tone } href={ c.href } title={ c.title }>{ c.text }</a>
						: <span className={ 'dxai-chip dxai-chip--' + c.tone } title={ c.title }>{ c.text }</span> }
				</li>
			) ) }
		</ul>
	);
}

/** The source a design came from, as a person names it. */
export function sourceLabel( kind ) {
	const names = {
		'claude-design': __( 'Claude Design', 'dxai-ui' ),
		dc: __( 'Claude Design', 'dxai-ui' ),
		lovable: __( 'Lovable', 'dxai-ui' ),
		figma: __( 'Figma', 'dxai-ui' ),
		html: __( 'HTML', 'dxai-ui' ),
		zip: __( 'Archive', 'dxai-ui' ),
		jsx: __( 'Component', 'dxai-ui' ),
		live: __( 'Live site', 'dxai-ui' ),
		unknown: '',
	};
	if ( ! kind ) {
		return '';
	}
	return names[ kind ] !== undefined ? names[ kind ] : kind;
}
