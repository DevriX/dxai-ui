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
			? sprintf( __( 'Imported %s', 'dx-ui' ), dateOf( status.imported_at ) )
			: __( 'Imported', 'dx-ui' ),
		title: status.built_with
			/* translators: 1: a date, 2: the plugin version. */
			? sprintf( __( 'Imported %1$s with DX %2$s. History shows every import.', 'dx-ui' ), dateOf( status.imported_at, true ), status.built_with )
			: __( 'When the design was last imported. History shows every import.', 'dx-ui' ),
		href: to( 'history' ),
	} );

	chips.push( status.native
		? {
			key: 'native',
			tone: 'done',
			text: __( 'Native blocks', 'dx-ui' ),
			title: __( 'The design’s blocks are the theme’s own blocks, editable with the theme’s settings. Changed in Blocks.', 'dx-ui' ),
			href: to( 'blocks' ),
		}
		: {
			key: 'native',
			tone: 'off',
			text: __( 'DX blocks', 'dx-ui' ),
			title: __( 'The pages use the plugin’s blocks. Apply native blocks in Blocks to make them the theme’s own.', 'dx-ui' ),
			href: to( 'blocks' ),
		} );

	if ( status.colours === 'follow' ) {
		chips.push( {
			key: 'colours',
			tone: 'info',
			text: __( 'Theme colours', 'dx-ui' ),
			title: __( 'The design’s colours follow the theme’s palette. Changed in Look.', 'dx-ui' ),
			href: to( 'look' ),
		} );
	} else {
		chips.push( {
			key: 'colours',
			tone: status.palette ? 'off' : 'plain',
			text: __( 'Own colours', 'dx-ui' ),
			title: status.palette
				? __( 'The design keeps its own colours. In Look it can follow the theme’s palette.', 'dx-ui' )
				: __( 'The design keeps its own colours; the theme has no palette to follow.', 'dx-ui' ),
			href: to( 'look' ),
		} );
	}

	if ( status.fonts === 'theme' ) {
		chips.push( {
			key: 'fonts',
			tone: 'info',
			text: __( 'Theme fonts', 'dx-ui' ),
			title: __( 'The design is drawn in the theme’s fonts and loads none of its own. Changed in Speed.', 'dx-ui' ),
			href: to( 'speed' ),
		} );
	} else {
		chips.push( {
			key: 'fonts',
			tone: status.fonts === 'own' ? 'off' : 'plain',
			text: __( 'Own fonts', 'dx-ui' ),
			title: status.fonts === 'own'
				? __( 'The design loads its own fonts. In Speed it can use the theme’s.', 'dx-ui' )
				: __( 'The design loads its own fonts; the theme does not manage the site’s fonts.', 'dx-ui' ),
			href: to( 'speed' ),
		} );
	}

	if ( status.states > 0 ) {
		chips.push( {
			key: 'states',
			tone: status.interactivity ? 'done' : 'plain',
			text: status.interactivity
				/* translators: %d: number of states. */
				? sprintf( _n( '%d state · Interactivity API', '%d states · Interactivity API', status.states, 'dx-ui' ), status.states )
				/* translators: %d: number of states. */
				: sprintf( _n( '%d state · page runtime', '%d states · page runtime', status.states, 'dx-ui' ), status.states ),
			title: status.interactivity
				? __( 'What the page does when touched (menus, tabs, panels) is driven by WordPress’s Interactivity API. The setting is in Settings › Behaviour.', 'dx-ui' )
				: __( 'What the page does when touched is driven by the plugin’s page runtime. The Interactivity API is off in Settings › Behaviour.', 'dx-ui' ),
			href: to( 'blocks' ),
		} );
	} else {
		chips.push( {
			key: 'states',
			tone: 'plain',
			text: __( 'No states', 'dx-ui' ),
			title: __( 'Nothing on the page changes when it is touched; nothing to drive.', 'dx-ui' ),
			href: to( 'blocks' ),
		} );
	}

	chips.push( status.exported_at
		? {
			key: 'exported',
			tone: 'info',
			/* translators: %s: a date. */
			text: sprintf( __( 'Exported %s', 'dx-ui' ), dateOf( status.exported_at ) ),
			title: __( 'A package of the design was downloaded. Transfer makes another.', 'dx-ui' ),
			href: to( 'transfer' ),
		}
		: {
			key: 'exported',
			tone: 'warn',
			text: __( 'Not exported', 'dx-ui' ),
			title: __( 'No package of the design has left this site. Transfer makes one for the live site.', 'dx-ui' ),
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
		<ul className={ 'dxui-chips' + ( small ? ' dxui-chips--small' : '' ) }>
			{ chips.map( ( c ) => (
				<li key={ c.key }>
					{ c.href
						? <a className={ 'dxui-chip dxui-chip--' + c.tone } href={ c.href } title={ c.title }>{ c.text }</a>
						: <span className={ 'dxui-chip dxui-chip--' + c.tone } title={ c.title }>{ c.text }</span> }
				</li>
			) ) }
		</ul>
	);
}

/** The source a design came from, as a person names it. */
export function sourceLabel( kind ) {
	const names = {
		'claude-design': __( 'Claude Design', 'dx-ui' ),
		dc: __( 'Claude Design', 'dx-ui' ),
		lovable: __( 'Lovable', 'dx-ui' ),
		figma: __( 'Figma', 'dx-ui' ),
		html: __( 'HTML', 'dx-ui' ),
		zip: __( 'Archive', 'dx-ui' ),
		jsx: __( 'Component', 'dx-ui' ),
		live: __( 'Live site', 'dx-ui' ),
		unknown: '',
	};
	if ( ! kind ) {
		return '';
	}
	return names[ kind ] !== undefined ? names[ kind ] : kind;
}
