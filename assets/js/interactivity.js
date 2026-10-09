/**
 * The store that drives a converted page's states through the Interactivity API (Blocks\Interactivity), in place of the page
 * runtime's state machine (Compiler\Motion_Runtime), which it mirrors mark for mark:
 *
 *   trigger   class dxai-toggle-<state>[--<value>], data-dxai-step (a relative move), data-dxai-clamp, data-dxai-set (a literal),
 *             data-dxai-mode="toggle" (off again when already on), data-dxai-init (the authored start), data-dxai-hover
 *   target    class dxai-cls-<state>[--<value>] with data-dxai-on / data-dxai-off class lists (per state and value, per state, shared),
 *             class dxai-on-<state>[--<value>] / dxai-off-… panels (hidden, grid rows, max-height), data-dxai-text-<state> word maps,
 *             data-dxai-disabled="state:v1,v2", data-dxai-any="derived:a,b", data-dxai-scroll="state:px", data-dxai-outside="state"
 *
 * The region's context holds the machine (m), the order of each state's values (o), the derived states (d), the scroll specs (sc) and
 * the states a click elsewhere resets (out); callbacks.init seeds it from the markup, so nothing moves on load. The getters read the
 * element's live classes, so a class another script adds (the runtime's reveal `is-in`) is kept.
 */
import { store, getContext, getElement, withScope } from '@wordpress/interactivity';

const NS = 'dxai-ui';

const tokens = ( value ) => String( value || '' ).split( /\s+/ ).filter( ( c ) => c !== '' );

// State names are JavaScript identifiers and never hold "-"; a value can, so the split is at the FIRST "--".
const marks = ( classText, kind ) => {
	const prefix = 'dxai-' + kind + '-';
	const out = [];
	tokens( classText ).forEach( ( c ) => {
		if ( c.indexOf( prefix ) !== 0 ) {
			return;
		}
		const flag = c.slice( prefix.length );
		const cut = flag.indexOf( '--' );
		out.push( cut > 0 ? { state: flag.slice( 0, cut ), value: flag.slice( cut + 2 ) } : { state: flag, value: null } );
	} );
	return out;
};

// The class list a projection adds: per state and value, then per state, then the shared one. Attribute names are looked up in
// lower case: the compiler writes a state's case into them (`data-dxai-on-help-Waterdamage`), the HTML parser lowers it.
const attrFor = ( attrs, kind, mark ) => {
	let v = mark.value === null ? undefined : attrs[ ( 'data-dxai-' + kind + '-' + mark.state + '-' + mark.value ).toLowerCase() ];
	if ( v === undefined ) {
		v = attrs[ ( 'data-dxai-' + kind + '-' + mark.state ).toLowerCase() ];
	}
	if ( v === undefined ) {
		v = attrs[ 'data-dxai-' + kind ];
	}
	return tokens( v );
};

const lowered = ( obj ) => {
	const out = {};
	Object.keys( obj || {} ).forEach( ( k ) => {
		out[ k.toLowerCase() ] = obj[ k ];
	} );
	return out;
};

const attributesOf = ( el ) => {
	const out = {};
	for ( const a of el.attributes ) {
		out[ a.name.toLowerCase() ] = a.value;
	}
	return out;
};

const holds = ( m, state, value ) => ( value === null ? !! m[ state ] : m[ state ] !== null && m[ state ] !== undefined && String( m[ state ] ) === value );

// The element a getter or an action speaks for: its live node when mounted, else what the markup says.
const live = () => {
	const { ref, attributes } = getElement();
	return { el: ref || null, attrs: ref ? attributesOf( ref ) : lowered( attributes ) };
};

// Nothing is projected on load: the compiler rendered the page in its starting state, and seeding reads that state back. A projection
// follows a state only once something has moved it (ctx.t), the way the runtime projects a state when it changes and never before.
const moved = ( ctx, s ) => !! ( ctx.t && ctx.t[ s ] );

const classesOf = ( ctx, el, attrs ) => {
	const current = attrs.class || '';
	const all = ctx.m || {};
	const m = {};
	Object.keys( all ).forEach( ( s ) => {
		if ( moved( ctx, s ) ) {
			m[ s ] = all[ s ];
		}
	} );
	const list = tokens( current );
	const has = ( c ) => list.indexOf( c ) >= 0;
	const set = ( c, on ) => {
		const at = list.indexOf( c );
		if ( on && at < 0 ) {
			list.push( c );
		} else if ( ! on && at >= 0 ) {
			list.splice( at, 1 );
		}
	};
	let touched = false;
	marks( current, 'cls' ).forEach( ( mark ) => {
		if ( ! ( mark.state in m ) ) {
			return;
		}
		const hit = holds( m, mark.state, mark.value );
		attrFor( attrs, 'on', mark ).forEach( ( c ) => set( c, hit ) );
		attrFor( attrs, 'off', mark ).forEach( ( c ) => set( c, ! hit ) );
		touched = true;
	} );
	// A panel can be shown for several values of one state (a card in a window of a list): any of them shows it.
	const on = marks( current, 'on' ).filter( ( mark ) => mark.state in m );
	const off = marks( current, 'off' ).filter( ( mark ) => mark.state in m );
	if ( on.length ) {
		set( 'hidden', ! on.some( ( mark ) => holds( m, mark.state, mark.value ) ) );
		touched = true;
	}
	if ( off.length ) {
		set( 'hidden', off.some( ( mark ) => holds( m, mark.state, mark.value ) ) );
		touched = true;
	}
	return touched ? list.join( ' ' ) : current;
};

const panelOpen = ( ctx, attrs ) => {
	const m = ctx.m || {};
	const on = marks( attrs.class, 'on' ).filter( ( mark ) => mark.state in m && moved( ctx, mark.state ) );
	const off = marks( attrs.class, 'off' ).filter( ( mark ) => mark.state in m && moved( ctx, mark.state ) );
	if ( ! on.length && ! off.length ) {
		return tokens( attrs.class ).indexOf( 'hidden' ) < 0;
	}
	if ( on.length ) {
		return on.some( ( mark ) => holds( m, mark.state, mark.value ) );
	}
	return ! off.some( ( mark ) => holds( m, mark.state, mark.value ) );
};

// A property of the element's inline style as the markup has it ('' when it is not there).
const styleProp = ( attrs, prop ) => {
	const m = new RegExp( '(?:^|;)\\s*' + prop + '\\s*:\\s*([^;]+)' ).exec( attrs.style || '' );
	return m ? m[ 1 ].trim() : '';
};

// Whether the trigger's state is held: 'true'/'false' for its aria, its own attribute until the state has moved.
const held = ( attribute ) => {
	const ctx = getContext();
	const { attrs } = live();
	const m = ctx.m || {};
	const own = marks( attrs.class, 'toggle' ).filter( ( mark ) => mark.state in m && moved( ctx, mark.state ) );
	if ( ! own.length ) {
		// As the markup has it — and not at all where it has none (the bind writes its value once at init).
		return attrs[ attribute ] !== undefined ? attrs[ attribute ] : null;
	}
	return own.some( ( mark ) => holds( m, mark.state, mark.value ) ) ? 'true' : 'false';
};

// Whether any state a panel answers to has moved: until then its styles are what the markup says.
const panelMoved = ( ctx, attrs ) => marks( attrs.class, 'on' ).concat( marks( attrs.class, 'off' ) ).some( ( mark ) => moved( ctx, mark.state ) );

const { state, actions, callbacks } = store( NS, {
	state: {
		get cls() {
			const ctx = getContext();
			const { el, attrs } = live();
			return classesOf( ctx, el, attrs );
		},
		get rows() {
			const ctx = getContext();
			const { attrs } = live();
			if ( ! panelMoved( ctx, attrs ) ) {
				return styleProp( attrs, 'grid-template-rows' );
			}
			return panelOpen( ctx, attrs ) ? attrs[ 'data-open-rows' ] || '1fr' : attrs[ 'data-closed-rows' ] || '0fr';
		},
		get maxHeight() {
			const ctx = getContext();
			const { attrs } = live();
			if ( ! panelMoved( ctx, attrs ) ) {
				return styleProp( attrs, 'max-height' );
			}
			return panelOpen( ctx, attrs ) ? attrs[ 'data-open-max' ] || '400px' : '0px';
		},
		get opacity() {
			const ctx = getContext();
			const { attrs } = live();
			if ( ! panelMoved( ctx, attrs ) ) {
				return styleProp( attrs, 'opacity' );
			}
			return panelOpen( ctx, attrs ) ? '1' : '0';
		},
		get expanded() {
			return held( 'aria-expanded' );
		},
		// A tab's aria-selected: its own attribute until its state moves (the bind writes the getter's value once at init).
		get selected() {
			return held( 'aria-selected' );
		},
		get off() {
			const ctx = getContext();
			const { attrs } = live();
			const spec = String( attrs[ 'data-dxai-disabled' ] || '' );
			const cut = spec.indexOf( ':' );
			const m = ctx.m || {};
			if ( cut < 1 || ! ( spec.slice( 0, cut ) in m ) || ! moved( ctx, spec.slice( 0, cut ) ) ) {
				return attrs.disabled !== undefined;
			}
			const current = m[ spec.slice( 0, cut ) ];
			return current !== null && current !== undefined && spec.slice( cut + 1 ).split( ',' ).indexOf( String( current ) ) > -1;
		},
	},
	actions: {
		fire( event ) {
			const ctx = getContext();
			const { el } = live();
			if ( ! el ) {
				return;
			}
			// The nearest trigger to the click is the one that fires, as the runtime's one listener at the root picks it: a click inside a
			// menu's wrapper (itself a trigger that closes the menu, data-dxai-set="null") must not reach the wrapper after the item.
			const target = event && event.target && event.target.closest ? event.target.closest( "[class*='dxai-toggle-']" ) : null;
			if ( target && target !== el ) {
				return;
			}
			const acted = fire( ctx, el );
			// A real link keeps its navigation; the state change still happens, because that is what the design does.
			const href = el.tagName === 'A' ? el.getAttribute( 'href' ) : '';
			if ( acted && event && ! ( href && href !== '#' ) ) {
				event.preventDefault();
			}
		},
		hoverOn() {
			const ctx = getContext();
			const { el } = live();
			if ( ! el ) {
				return;
			}
			hoverOn( ctx, el );
			leaveOnce( ctx, el, 'mouseleave' );
		},
		hoverOff() {
			const ctx = getContext();
			const { el } = live();
			if ( el ) {
				hoverOff( ctx, el );
			}
		},
		focusOn( event ) {
			const ctx = getContext();
			const { el } = live();
			const target = event && event.target;
			if ( ! el || ! target || ! keyboardFocus( target ) ) {
				return;
			}
			hoverOn( ctx, el );
			leaveOnce( ctx, el, 'focusout' );
		},
		focusOut( event ) {
			const ctx = getContext();
			const { el } = live();
			if ( ! el ) {
				return;
			}
			const next = event && event.relatedTarget;
			if ( ( next && el.contains( next ) ) || pointerOver( el ) ) {
				return;
			}
			hoverOff( ctx, el );
		},
		outside( event ) {
			const ctx = getContext();
			const target = event && event.target;
			const m = ctx.m || {};
			( ctx.out || [] ).forEach( ( s ) => {
				const current = m[ s ];
				if ( current === null || current === undefined || current === false ) {
					return;
				}
				if ( target && target.closest && target.closest( "[class*='dxai-toggle-" + s + "'], [class*='dxai-on-" + s + "'], [class*='dxai-off-" + s + "']" ) ) {
					return;
				}
				setState( ctx, s, ( ctx.o[ s ] || [] ).length ? null : false, true );
			} );
		},
		scroll() {
			scroll( getContext() );
		},
		escape( event ) {
			if ( ! event || ( event.key !== 'Escape' && event.key !== 'Esc' ) ) {
				return;
			}
			const ctx = getContext();
			const root = getElement().ref;
			if ( ! root ) {
				return;
			}
			// The innermost open hover menu that holds the focus or the pointer closes; focus goes back to its trigger.
			const active = document.activeElement;
			const open = [].slice.call( root.querySelectorAll( "[data-dxai-hover='enter']" ) ).filter( ( el ) => {
				const box = wrapOf( el );
				return hoverHeld( ctx, el ) && ( ( active && box.contains( active ) ) || pointerOver( box ) );
			} );
			open.filter( ( el ) => ! open.some( ( o ) => o !== el && wrapOf( el ) !== wrapOf( o ) && wrapOf( el ).contains( wrapOf( o ) ) ) ).forEach( ( el ) => {
				const trigger = active && wrapOf( el ).contains( active ) ? focusable( el ) : null;
				if ( trigger && trigger !== active && trigger.focus ) {
					trigger.focus();
				}
				hoverOff( ctx, el );
			} );
		},
	},
	callbacks: {
		init() {
			const ctx = getContext();
			const root = getElement().ref;
			if ( ! root ) {
				return;
			}
			seed( ctx, root );
			scroll( ctx );
		},
	},
} );

/* ---------------------------------------------------------------- the machine, as the runtime seeds and moves it --- */

function seed( ctx, root ) {
	const m = {};
	const order = {};
	const note = ( s, v ) => {
		if ( v === null ) {
			return;
		}
		order[ s ] = order[ s ] || [];
		if ( order[ s ].indexOf( v ) < 0 ) {
			order[ s ].push( v );
		}
	};
	// A projection whose on-classes are all present is the current one; a panel with none reports through its own visibility.
	root.querySelectorAll( "[class*='dxai-cls-']" ).forEach( ( el ) => {
		const attrs = attributesOf( el );
		marks( attrs.class, 'cls' ).forEach( ( mark ) => {
			note( mark.state, mark.value );
			const on = attrFor( attrs, 'on', mark );
			if ( ! on.length || mark.state in m ) {
				return;
			}
			if ( on.every( ( c ) => el.classList.contains( c ) ) ) {
				m[ mark.state ] = mark.value === null ? true : mark.value;
			}
		} );
	} );
	const panels = root.querySelectorAll( "[class*='dxai-on-'], [class*='dxai-off-']" );
	// A region that projects nothing has no machine at all (the runtime's older handler answers every trigger there).
	if ( ! root.querySelector( "[class*='dxai-cls-']" ) && ! panels.length ) {
		ctx.o = {};
		ctx.d = [];
		ctx.sc = [];
		ctx.out = [];
		ctx.t = {};
		ctx.m = {};
		return;
	}
	panels.forEach( ( el ) => {
		const shown = ! el.classList.contains( 'hidden' );
		marks( el.className, 'on' ).forEach( ( mark ) => {
			note( mark.state, mark.value );
			if ( ! ( mark.state in m ) && shown ) {
				m[ mark.state ] = mark.value === null ? true : mark.value;
			}
		} );
		marks( el.className, 'off' ).forEach( ( mark ) => {
			if ( ! ( mark.state in m ) && shown ) {
				m[ mark.state ] = mark.value === null ? false : null;
			}
		} );
	} );
	root.querySelectorAll( "[class*='dxai-cls-'], [class*='dxai-on-'], [class*='dxai-off-']" ).forEach( ( el ) => {
		[ 'cls', 'on', 'off' ].forEach( ( kind ) => {
			marks( el.className, kind ).forEach( ( mark ) => {
				if ( ! ( mark.state in m ) ) {
					m[ mark.state ] = mark.value === null ? false : null;
				}
			} );
		} );
	} );
	// The order a step moves through is the values' order: numbers as numbers, anything else as the triggers come.
	Object.keys( order ).forEach( ( s ) => {
		const values = order[ s ];
		if ( values.length > 1 && values.every( ( v ) => /^-?\d+(\.\d+)?$/.test( v ) ) ) {
			values.sort( ( a, b ) => parseFloat( a ) - parseFloat( b ) );
		} else if ( values.length > 1 ) {
			const byTrigger = [];
			root.querySelectorAll( "[class*='dxai-toggle-" + s + "--']" ).forEach( ( el ) => {
				marks( el.className, 'toggle' ).forEach( ( mark ) => {
					if ( mark.state === s && mark.value !== null && byTrigger.indexOf( mark.value ) < 0 ) {
						byTrigger.push( mark.value );
					}
				} );
			} );
			values.forEach( ( v ) => {
				if ( byTrigger.indexOf( v ) < 0 ) {
					byTrigger.push( v );
				}
			} );
			order[ s ] = byTrigger;
		}
	} );
	// States built from other states, their members seeded false (the state the page was compiled in).
	const derived = [];
	[ root ].concat( [].slice.call( root.querySelectorAll( '[data-dxai-any]' ) ) ).forEach( ( el ) => {
		tokens( el.getAttribute( 'data-dxai-any' ) ).forEach( ( spec ) => {
			const cut = spec.indexOf( ':' );
			if ( cut < 1 ) {
				return;
			}
			const s = spec.slice( 0, cut );
			const of = spec.slice( cut + 1 ).split( ',' ).map( ( x ) => x.trim() ).filter( ( x ) => x !== '' );
			if ( ! of.length || ! ( s in m ) ) {
				return;
			}
			derived.push( { state: s, of } );
			of.forEach( ( member ) => {
				if ( ! ( member in m ) ) {
					m[ member ] = false;
				}
			} );
		} );
	} );
	// An explicit initial wins: a relative move needs the index it starts from.
	root.querySelectorAll( '[data-dxai-init]' ).forEach( ( el ) => {
		marks( el.className, 'toggle' ).forEach( ( mark ) => {
			m[ mark.state ] = el.getAttribute( 'data-dxai-init' );
		} );
	} );
	const sc = [];
	[ root ].concat( [].slice.call( root.querySelectorAll( '[data-dxai-scroll]' ) ) ).forEach( ( el ) => {
		tokens( el.getAttribute( 'data-dxai-scroll' ) ).forEach( ( spec ) => {
			const cut = spec.indexOf( ':' );
			const s = cut > 0 ? spec.slice( 0, cut ) : spec;
			if ( s in m ) {
				sc.push( { state: s, at: cut > 0 ? parseFloat( spec.slice( cut + 1 ) ) || 0 : 0 } );
			}
		} );
	} );
	const out = [];
	[ root ].concat( [].slice.call( root.querySelectorAll( '[data-dxai-outside]' ) ) ).forEach( ( el ) => {
		tokens( el.getAttribute( 'data-dxai-outside' ) ).forEach( ( s ) => {
			if ( s in m && out.indexOf( s ) < 0 ) {
				out.push( s );
			}
		} );
	} );
	ctx.o = order;
	ctx.d = derived;
	ctx.sc = sc;
	ctx.out = out;
	ctx.t = {};
	ctx.m = m;
}

// One state's new value: the machine, then whatever is derived from it, then the words that change with it. The state is marked as
// moved (ctx.t): from here on its projections follow the machine; until then they are what the compiler rendered.
function setState( ctx, s, value, force ) {
	// A click projects its state even to the value it already holds (the runtime does: two components compiled at different values of
	// one state name line up on the first click); a hover, the scroll and a derived state move only when the value changes.
	if ( ! force && ctx.m[ s ] === value ) {
		return;
	}
	ctx.m[ s ] = value;
	ctx.t = Object.assign( {}, ctx.t || {}, { [ s ]: true } );
	words( ctx, s );
	( ctx.d || [] ).forEach( ( d ) => {
		if ( d.state === s || d.of.indexOf( s ) < 0 ) {
			return;
		}
		const next = d.of.some( ( member ) => !! ctx.m[ member ] );
		if ( ctx.m[ d.state ] !== next ) {
			ctx.m[ d.state ] = next;
			ctx.t = Object.assign( {}, ctx.t || {}, { [ d.state ]: true } );
			words( ctx, d.state );
		}
	} );
}

// Words that change with the state: every reading the compiler evaluated, keyed by the value as the machine spells it.
function words( ctx, s ) {
	const root = getElement().ref;
	const scope = root && root.closest ? root.closest( '[data-wp-interactive]' ) || root : null;
	if ( ! scope ) {
		return;
	}
	scope.querySelectorAll( '[data-dxai-text-' + s + ']' ).forEach( ( el ) => {
		let map;
		try {
			map = JSON.parse( el.getAttribute( 'data-dxai-text-' + s ) || '{}' );
		} catch ( e ) {
			return;
		}
		const current = ctx.m[ s ];
		const key = current === null || current === undefined ? 'null' : String( current );
		if ( Object.prototype.hasOwnProperty.call( map, key ) ) {
			el.textContent = map[ key ];
		}
	} );
}

function fire( ctx, el ) {
	let acted = false;
	marks( el.className, 'toggle' ).forEach( ( mark ) => {
		if ( ! ( mark.state in ctx.m ) ) {
			// A state nothing on the page projects (ICP Segmentation's book pages): the runtime's older handler answers for it, flipping
			// the trigger's own aria and its chevron, and the aria of the other triggers of its group. Done the same here.
			legacy( ctx, el, mark );
			acted = true;
			return;
		}
		const step = parseInt( el.getAttribute( 'data-dxai-step' ) || '0', 10 );
		const set = el.getAttribute( 'data-dxai-set' );
		const mode = el.getAttribute( 'data-dxai-mode' );
		const current = ctx.m[ mark.state ];
		let next;
		if ( step ) {
			// A step trigger is exempt from the runtime's machine, so its older handler flips the arrow's own aria as well.
			legacy( ctx, el, mark );
			const values = ctx.o[ mark.state ] || [];
			if ( ! values.length ) {
				return;
			}
			let at = values.indexOf( String( current ) );
			if ( at < 0 ) {
				at = 0;
			}
			let to = at + step;
			to = el.hasAttribute( 'data-dxai-clamp' ) ? Math.max( 0, Math.min( values.length - 1, to ) ) : ( ( to % values.length ) + values.length ) % values.length;
			next = values[ to ];
		} else if ( set === 'null' ) {
			next = null;
		} else if ( set === 'true' || set === 'false' ) {
			next = set === 'true';
		} else if ( mark.value === null ) {
			next = ( ctx.o[ mark.state ] || [] ).length ? null : ! current;
		} else if ( mode === 'toggle' && current !== null && String( current ) === mark.value ) {
			next = null;
		} else {
			next = mark.value;
		}
		setState( ctx, mark.state, next, true );
		acted = true;
	} );
	return acted;
}

// The runtime's handler from before the state machine, for a trigger whose state the machine does not hold (Motion_Runtime's
// applyToggle): aria-expanded flipped on the trigger, the chevron turned, the other triggers of a `state--value` group closed.
function legacy( ctx, el, mark ) {
	const root = getElement().ref;
	const scope = root && root.closest ? root.closest( '[data-wp-interactive]' ) || root : document;
	const willOpen = el.getAttribute( 'aria-expanded' ) !== 'true';
	if ( mark.value !== null ) {
		scope.querySelectorAll( "[class*='dxai-toggle-" + mark.state + "--']" ).forEach( ( other ) => {
			other.setAttribute( 'aria-expanded', 'false' );
			other.querySelectorAll( '.rotate-45' ).forEach( ( icon ) => {
				icon.classList.remove( 'rotate-45' );
				icon.classList.add( 'rotate-0' );
			} );
		} );
	}
	el.setAttribute( 'aria-expanded', willOpen ? 'true' : 'false' );
	const spin = el.querySelector( ".rotate-45, .rotate-0, [class*='rotate-']" );
	if ( spin ) {
		spin.classList.toggle( 'rotate-45', willOpen );
		spin.classList.toggle( 'rotate-0', ! willOpen );
	}
}

// What a valueless trigger means on a state whose projections are all keyed by value: "none of them".
const wanted = ( ctx, el, mark ) => {
	const set = el.getAttribute( 'data-dxai-set' );
	if ( set === 'null' ) {
		return null;
	}
	if ( set === 'true' || set === 'false' ) {
		return set === 'true';
	}
	if ( mark.value !== null ) {
		return mark.value;
	}
	return ( ctx.o[ mark.state ] || [] ).length ? null : true;
};

// Hover is a direction, not a toggle: entering sets the state, idempotently.
function hoverOn( ctx, el ) {
	marks( el.className, 'toggle' ).forEach( ( mark ) => {
		if ( mark.state in ctx.m ) {
			setState( ctx, mark.state, wanted( ctx, el, mark ) );
		}
	} );
}

// Only if THIS element's value is the one currently held: leaving a closed item must not shut a menu opened from another.
function hoverOff( ctx, el ) {
	marks( el.className, 'toggle' ).forEach( ( mark ) => {
		if ( ! ( mark.state in ctx.m ) ) {
			return;
		}
		if ( mark.value === null ) {
			if ( ctx.m[ mark.state ] !== false ) {
				setState( ctx, mark.state, false );
			}
		} else if ( ctx.m[ mark.state ] !== null && String( ctx.m[ mark.state ] ) === mark.value ) {
			setState( ctx, mark.state, null );
		}
	} );
}

const hoverHeld = ( ctx, el ) =>
	marks( el.className, 'toggle' ).some( ( mark ) => {
		if ( ! ( mark.state in ctx.m ) ) {
			return false;
		}
		const want = wanted( ctx, el, mark );
		const now = ctx.m[ mark.state ];
		return want !== null && want !== false && now !== null && now !== undefined && String( now ) === String( want );
	} );

// The wrap, not the trigger, owns the leaving: a mega-menu panel is a sibling of the button that opens it.
const wrapOf = ( el ) => ( el.hasAttribute( 'data-dxai-hover-self' ) ? el : el.closest( "li, .relative, [class*='relative']" ) || el.parentElement || el );

const FOCUSABLE = "a[href], button:not([disabled]), input:not([disabled]):not([type='hidden']), select:not([disabled]), textarea:not([disabled]), summary, [tabindex]:not([tabindex='-1'])";
const focusable = ( el ) => ( el.matches && el.matches( FOCUSABLE ) ? el : el.querySelector( FOCUSABLE ) );
const pointerOver = ( el ) => {
	try {
		return el.matches( ':hover' );
	} catch ( e ) {
		return false;
	}
};
const keyboardFocus = ( el ) => {
	try {
		return el.matches( ':focus-visible' );
	} catch ( e ) {
		return true;
	}
};

// Leaving the wrap (the pointer, or the focus for anywhere outside it) closes what entering opened — bound once per entry.
function leaveOnce( ctx, el, kind ) {
	const box = wrapOf( el );
	const key = '__dxaiLeave_' + kind;
	if ( box[ key ] ) {
		return;
	}
	box[ key ] = true;
	const handler = withScope( ( event ) => {
		if ( kind === 'focusout' ) {
			const next = event.relatedTarget;
			if ( ( next && box.contains( next ) ) || pointerOver( box ) ) {
				return;
			}
		}
		box.removeEventListener( kind, handler );
		box[ key ] = false;
		hoverOff( ctx, el );
	} );
	box.addEventListener( kind, handler );
}

// State the page sets from scrolling: true once the page is scrolled past the threshold.
function scroll( ctx ) {
	( ctx.sc || [] ).forEach( ( spec ) => {
		const next = ( window.scrollY || window.pageYOffset || 0 ) > spec.at;
		if ( ctx.m[ spec.state ] !== next ) {
			setState( ctx, spec.state, next );
		}
	} );
}

export { state, actions, callbacks };
