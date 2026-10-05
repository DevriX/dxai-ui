<?php
/**
 * Scroll-reveal and count-up runtime for converted Lovable pages.
 *
 * @package DXAI_UI
 */

declare(strict_types=1);

namespace DXAI_UI\Compiler;

final class Motion_Runtime {

	/**
	 * The marks around the runtime in a page's script (javascript()), so a
	 * later version can find and replace exactly this part (refresh()).
	 */
	private const BEGIN = '/*! dxai-ui motion runtime ';
	private const END   = '/*! /dxai-ui motion runtime */';

	/**
	 * How the runtime began before it was marked — every version so far: its
	 * IIFE, and the scope lookup as its first statement. Matched at the start
	 * of a line, with either line ending (the heredoc keeps the file's).
	 */
	private const LEGACY_HEAD = '/(?:^|\n)(\(function \(\) \{\r?\n\tvar root = document\.querySelector\("\.dxai-ui"\) \|\| document;)/';

	/** Progress of the stored-script refresh: the runtime version done, and the last file looked at. */
	public const REFRESH_OPTION = 'dxai_ui_motion_runtime';

	/** Stored scripts per admin page load (maybe_refresh_stored()). */
	private const REFRESH_BATCH = 25;

	/**
	 * The runtime as a page's script carries it: marked, so a stored copy can
	 * be brought up to date without a re-import (refresh()).
	 *
	 * A page's script is compiled once, at import — Source_Compiler and
	 * Chunked_Generator put this in `custom_js`, and it is written to
	 * uploads/dxai-ui/pattern-{id}.js — so every page imported before a
	 * runtime change kept the old runtime: measured when keyboard access to
	 * hover menus landed, the stored pattern-93057.js was the previous
	 * runtime byte for byte, and Tab never opened a panel there.
	 */
	public static function javascript(): string {
		$body = self::body();
		$eol  = str_contains( $body, "\r\n" ) ? "\r\n" : "\n";

		return self::BEGIN . self::version() . ' */' . $eol . $body . $eol . self::END;
	}

	/** A short fingerprint of the runtime; changes whenever its code does. */
	public static function version(): string {
		return substr( md5( self::body() ), 0, 12 );
	}

	/**
	 * $js with the runtime inside it replaced by the current one, or $js as
	 * it is when it holds the current runtime already, or none.
	 *
	 * A marked copy is found by its marks. An unmarked one — every page
	 * imported before the marks existed — by its first two lines, which no
	 * version has changed, up to the first line that is `})();` alone: the
	 * IIFE's own close, since everything inside it is indented. The page's
	 * other code (compiled behaviour before or after it) is left byte for
	 * byte.
	 */
	public static function refresh( string $js ): string {
		$current = self::javascript();
		$start   = strpos( $js, self::BEGIN );
		if ( false !== $start ) {
			$end = strpos( $js, self::END, $start );
			if ( false === $end ) {
				return $js;
			}
			$end += strlen( self::END );

			return substr( $js, $start, $end - $start ) === $current ? $js : substr( $js, 0, $start ) . $current . substr( $js, $end );
		}
		if ( 1 !== preg_match( self::LEGACY_HEAD, $js, $head, PREG_OFFSET_CAPTURE ) ) {
			return $js;
		}
		$start = (int) $head[1][1];
		if ( 1 !== preg_match( '/\r?\n\}\)\(\);(?=\r?\n|$)/', $js, $close, PREG_OFFSET_CAPTURE, $start ) ) {
			return $js;
		}
		$end = (int) $close[0][1] + strlen( $close[0][0] );

		return substr( $js, 0, $start ) . $current . substr( $js, $end );
	}

	/**
	 * Keep stored page scripts on the current runtime: a batch per admin page
	 * load (by someone who can run imports) until every stored script has been
	 * looked at once for this runtime version, and on demand with
	 * `wp dxai-ui refresh-runtime`. No cron, so it also runs where wp-cron is
	 * off, and a batch is small enough for any host's time limit. On
	 * multisite each site keeps its own progress, as it keeps its own uploads.
	 *
	 * Not while the plugin is deactivated or deleted (the must-use runtime
	 * boots it render-only, Support\Runtime): like the other repair batches,
	 * it is the active plugin's job, and the copy the runtime boots could be
	 * older than the scripts it would rewrite.
	 */
	public static function register_refresh(): void {
		if ( \DXAI_UI\Support\Runtime::render_only() ) {
			return;
		}
		add_action( 'admin_init', array( self::class, 'maybe_refresh_stored' ) );
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'dxai-ui refresh-runtime', array( self::class, 'cli' ) );
		}
	}

	/** One batch of the refresh, when this runtime version has not been through every stored script yet. */
	public static function maybe_refresh_stored(): void {
		if ( wp_doing_ajax() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$state = get_option( self::REFRESH_OPTION );
		if ( is_array( $state ) && ( $state['version'] ?? '' ) === self::version() && ! empty( $state['done'] ) ) {
			return;
		}
		$after = is_array( $state ) && ( $state['version'] ?? '' ) === self::version() ? (string) ( $state['after'] ?? '' ) : '';
		$batch = self::refresh_stored( self::REFRESH_BATCH, $after );
		update_option(
			self::REFRESH_OPTION,
			array(
				'version' => self::version(),
				'after'   => $batch['last'],
				'done'    => $batch['done'],
			),
			false
		);
	}

	/** `wp dxai-ui refresh-runtime`: every stored script, now. */
	public static function cli(): void {
		$after = '';
		$total = array(
			'looked'    => 0,
			'refreshed' => 0,
			'failed'    => 0,
		);
		do {
			$batch = self::refresh_stored( 200, $after );
			$after = $batch['last'];
			foreach ( array_keys( $total ) as $key ) {
				$total[ $key ] += $batch[ $key ];
			}
		} while ( ! $batch['done'] );
		update_option(
			self::REFRESH_OPTION,
			array(
				'version' => self::version(),
				'after'   => '',
				'done'    => true,
			),
			false
		);
		\WP_CLI::success( sprintf( 'runtime %s: %d script(s) looked at, %d refreshed, %d could not be written.', self::version(), $total['looked'], $total['refreshed'], $total['failed'] ) );
	}

	/**
	 * Refresh up to $limit stored scripts (uploads/dxai-ui/pattern-*.js) whose
	 * name sorts after $after. A script is rewritten only when refresh()
	 * changes it; the page enqueues it versioned by the file's mtime
	 * (Upload_Paths::version()), so visitors get the new copy on their next
	 * load.
	 *
	 * @param string $dir Where the scripts are; the uploads' dxai-ui folder when ''.
	 * @return array{looked:int, refreshed:int, failed:int, last:string, done:bool}
	 */
	public static function refresh_stored( int $limit, string $after = '', string $dir = '' ): array {
		$out = array(
			'looked'    => 0,
			'refreshed' => 0,
			'failed'    => 0,
			'last'      => $after,
			'done'      => true,
		);
		if ( '' === $dir ) {
			$upload = wp_upload_dir( null, false );
			if ( ! empty( $upload['error'] ) ) {
				return $out;
			}
			$dir = trailingslashit( (string) $upload['basedir'] ) . \DXAI_UI\Support\Upload_Paths::DIR;
		}
		$files = glob( trailingslashit( $dir ) . 'pattern-*.js' );
		if ( ! is_array( $files ) ) {
			return $out;
		}
		$names = array_map( 'basename', $files );
		sort( $names, SORT_STRING );
		foreach ( $names as $name ) {
			if ( '' !== $after && strcmp( $name, $after ) <= 0 ) {
				continue;
			}
			if ( $limit > 0 && $out['looked'] >= $limit ) {
				$out['done'] = false;
				break;
			}
			++$out['looked'];
			$out['last'] = $name;
			$path        = trailingslashit( $dir ) . $name;
			$js          = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( ! is_string( $js ) ) {
				continue;
			}
			$fresh = self::refresh( $js );
			if ( $fresh === $js ) {
				continue;
			}
			// As Tailwind_Purger::persist_js() writes it.
			$fresh = preg_replace( '#</script#i', '<\\/script', $fresh ) ?? $fresh;
			if ( false === file_put_contents( $path, $fresh ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				++$out['failed'];
				continue;
			}
			++$out['refreshed'];
		}

		return $out;
	}

	/** The runtime itself (javascript() marks it). */
	private static function body(): string {
		return <<<'JS'
(function () {
	/*
	 * One runtime per design scope on the page. A converted page has one: the element around the whole design. A page of the theme that
	 * holds sections copied from a design has one around each run of them — a block of the page's own between two runs makes two — and
	 * the first is not the page: a slider or an accordion in the second never got its script. A scope inside another is part of it.
	 */
	var scopes = [].slice.call(document.querySelectorAll(".dxai-ui")).filter(function (el) {
		return !(el.parentElement && el.parentElement.closest(".dxai-ui"));
	});
	(scopes.length ? scopes : [document]).forEach(function (root) {
	var reduce = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
	if (root && root.classList) { root.classList.add("dxai-motion"); }
	function reveal(el) {
		el.classList.add("is-in");
		el.querySelectorAll(".rv-mask-in").forEach(function (mask) {
			mask.style.transform = "translateY(0)";
		});
	}
	/*
	 * Everything that fades in on approach — minus anything whose `is-in` is
	 * owned by the state machine.
	 *
	 * The reveal sweep adds `is-in` to whatever is on screen and never takes it
	 * off, which is right for a section that fades in once. It is wrong for an
	 * element that a state projects `is-in` onto: GTM Strategy Hub's sticky CTA
	 * is `data-dxai-scroll="show:700"` with `data-dxai-on="is-in"` AND
	 * `position: fixed`, so it is on screen from the first frame, the sweep
	 * stamped `is-in` on it at load and re-stamped it on every scroll event,
	 * and the floating bar the design only shows past 700px sat over the page
	 * from the top. Nine of the corpus's thirteen scroll states were pinned on
	 * this way.
	 *
	 * The machine seeds itself from the markup and projects from there, so the
	 * rule is simply that one owner per class wins: if `data-dxai-on` names a
	 * class the sweep would add, the sweep leaves that element alone.
	 */
	var targets = [].slice.call(
		root.querySelectorAll("section, header, footer, .rv-close, .rv-leadmagnet, [data-reveal], .dxai-reveal")
	).filter(function (el) {
		var owned = el.getAttribute && el.getAttribute("data-dxai-on");
		return !(owned && /(^|[\s,])is-in([\s,:]|$)/.test(owned));
	});
	// A section is only hidden once it is individually armed, so anything the
	// observer never picks up stays visible instead of disappearing.
	function arm(el) { el.classList.add("is-armed"); }
	// Belt and braces: whatever the observer misses, reveal on scroll once it
	// is on screen. Content must never be left permanently invisible.
	function sweep() {
		var vh = window.innerHeight || document.documentElement.clientHeight;
		var pending = 0;
		targets.forEach(function (el) {
			if (el.classList.contains("is-in")) { return; }
			var box = el.getBoundingClientRect();
			if (box.top < vh * 0.95 && box.bottom > 0) { reveal(el); return; }
			pending++;
		});
		if (!pending) {
			window.removeEventListener("scroll", onSweep);
			window.removeEventListener("resize", onSweep);
		}
	}
	var sweepQueued = false;
	function onSweep() {
		if (sweepQueued) { return; }
		sweepQueued = true;
		// Throttled with a timer rather than requestAnimationFrame, which is
		// paused in a background tab and would strand the fallback.
		window.setTimeout(function () { sweepQueued = false; sweep(); }, 100);
	}
	// Absolute backstop: never let a section stay hidden because both the
	// observer and the scroll fallback failed. Un-arming removes the hiding
	// rule without pretending the entrance played.
	function disarmAll() {
		targets.forEach(function (el) { el.classList.remove("is-armed"); });
	}
	if ("IntersectionObserver" in window && !reduce) {
		var io = new IntersectionObserver(function (entries) {
			entries.forEach(function (entry) {
				if (!entry.isIntersecting) {
					return;
				}
				reveal(entry.target);
				io.unobserve(entry.target);
			});
		}, { threshold: 0, rootMargin: "0px 0px -5% 0px" });
		targets.forEach(function (el) { arm(el); io.observe(el); });
		window.addEventListener("scroll", onSweep, { passive: true });
		window.addEventListener("resize", onSweep);
		onSweep();
		window.setTimeout(disarmAll, 10000);
	} else {
		targets.forEach(reveal);
	}
	/*
	 * One design's sticky CTA used to be handled right here, by name:
	 * `root.querySelector(".rv-stickycta")` with a literal 700 threshold. That
	 * is hand-written JavaScript for one corpus design — the thing this
	 * converter exists not to need — and it only ever worked for elements
	 * spelled that way. The generic path does it for every design now: the
	 * compiler emits `data-dxai-scroll="show:700"` with `data-dxai-on="is-in"`
	 * and the state machine below projects it, so this block was both redundant
	 * and a second writer racing the first.
	 */
	/*
	 * The number, without disturbing anything else inside the element.
	 *
	 * This used to be `el.textContent = …`, which replaces every child. A
	 * design pairs the animated figure with a static copy for assistive
	 * technology — `62%<span class="sr-only">62%</span>` — because the visible
	 * text counts up from 0 and a screen reader would otherwise read whatever
	 * frame it happened to catch. Writing textContent deleted that copy on the
	 * first tick, so the markup carried it and the rendered page did not.
	 *
	 * Only the element's own leading text node is rewritten. The copy is a
	 * sibling of it, so it survives; and it is not re-animated, which is the
	 * point of it.
	 */
	var setCount = function (el, text) {
		var node = null;
		for (var i = 0; i < el.childNodes.length; i++) {
			if (el.childNodes[i].nodeType === 3) {
				node = el.childNodes[i];
				break;
			}
		}
		if (node) {
			node.nodeValue = text;
			return;
		}
		el.insertBefore(document.createTextNode(text), el.firstChild || null);
	};
	root.querySelectorAll("[data-countup], .rv-num[data-to]").forEach(function (el) {
		var value = parseFloat(el.getAttribute("data-countup") || el.getAttribute("data-to") || "0");
		var suffix = el.getAttribute("data-suffix") || "";
		var duration = parseInt(el.getAttribute("data-duration") || "900", 10);
		if (reduce || !("IntersectionObserver" in window)) {
			setCount(el, Math.round(value).toLocaleString() + suffix);
			return;
		}
		var ran = false;
		var cio = new IntersectionObserver(function (entries) {
			entries.forEach(function (entry) {
				if (!entry.isIntersecting || ran) {
					return;
				}
				ran = true;
				cio.unobserve(el);
				var start = performance.now();
				var tick = function (t) {
					var p = Math.min(1, (t - start) / duration);
					var eased = 1 - Math.pow(1 - p, 3);
					setCount(el, Math.round(value * eased).toLocaleString() + suffix);
					if (p < 1) {
						requestAnimationFrame(tick);
					}
				};
				requestAnimationFrame(tick);
			});
		}, { threshold: 0.4 });
		cio.observe(el);
	});
	root.querySelectorAll(".rv-hero").forEach(function (hero) {
		var tabs = hero.querySelectorAll(".rv-hero-tab");
		var leak = hero.querySelector(".rv-hero-split-cell--leak p:not(.rv-hero-split-label)");
		var fix = hero.querySelector(".rv-hero-split-cell--fix p:not(.rv-hero-split-label)");
		tabs.forEach(function (tab) {
			tab.addEventListener("click", function () {
				tabs.forEach(function (item) {
					item.classList.remove("is-on");
					item.setAttribute("aria-selected", "false");
				});
				tab.classList.add("is-on");
				tab.setAttribute("aria-selected", "true");
				if (leak && tab.getAttribute("data-leak")) {
					leak.textContent = tab.getAttribute("data-leak");
				}
				if (fix && tab.getAttribute("data-fix")) {
					fix.textContent = tab.getAttribute("data-fix");
				}
			});
		});
	});
	/*
	 * One authority per control.
	 *
	 * Set by the state machine at the bottom of this file to its own `machine`
	 * object, and read here at CLICK time — `owned` is still null while these
	 * listeners are being registered, so the test cannot be hoisted to bind
	 * time. Both blocks listen for click on `root` in the bubble phase, and the
	 * handler below derives "will open" from the trigger's own aria-expanded,
	 * which cannot express `setOpen(null)`. The input that defeated running
	 * both: DevriX Elevate's 36 mega-menu links, `<a href="#services"
	 * onClick={() => setOpen(null)}>`. The machine sets `open` to null and
	 * closes the panel; this handler then reads aria-expanded="false" off the
	 * link, concludes the state is opening, and re-opens what the click was
	 * written to close. Whichever ran second won, so the net DOM change was
	 * nothing.
	 */
	var owned = null;
	// `marks` and `classText` are function declarations further down and so are
	// hoisted; only the `owned` ASSIGNMENT is late, and it lands before any
	// event can fire.
	//
	// Narrow deliberately, and measured: a data-dxai-step trigger is EXEMPT.
	// Revenue Operations' `rv-cs-arrow` buttons carry `dxai-toggle-i` with no
	// value, no group and no panel, so the only DOM the handler below touches
	// for them is aria-expanded on the button itself — nothing the machine also
	// writes, so the two cannot disagree. Gating them off as well removed that
	// aria flip and cost a control on the behaviour probe (which reads
	// innerHTML length, and so sees an aria flip) while changing no rendering.
	function machineOwns(el) {
		if (owned === null || !el || !el.hasAttribute || el.hasAttribute("data-dxai-step")) {
			return false;
		}
		return marks(el, "toggle").some(function (mark) {
			return mark.state in owned;
		});
	}
	root.addEventListener("click", function (event) {
		var btn = event.target && event.target.closest ? event.target.closest("[class*='dxai-toggle-']") : null;
		if (!btn || !root.contains(btn)) {
			return;
		}
		if (machineOwns(btn)) {
			return;
		}
		if (btn.getAttribute("data-dxai-hover") === "enter" && event.type === "click") {
			// Allow click toggle even on hover menus (touch / keyboard).
		}
		if (btn.tagName === "A" && btn.getAttribute("href") && btn.getAttribute("href") !== "#") {
			return;
		}
		event.preventDefault();
		var names = [];
		String(btn.className || "").split(/\s+/).forEach(function (c) {
			if (c.indexOf("dxai-toggle-") === 0) {
				names.push(c.slice(12));
			}
		});
		names.forEach(function (name) {
			applyToggle(btn, name, btn.getAttribute("aria-expanded") !== "true");
		});
	});
	function applyPanel(el, open) {
		el.classList.toggle("hidden", !open);
		var style = el.getAttribute("style") || "";
		if (el.hasAttribute("data-open-rows") || /grid-template-rows/i.test(style)) {
			el.style.gridTemplateRows = open
				? (el.getAttribute("data-open-rows") || "1fr")
				: (el.getAttribute("data-closed-rows") || "0fr");
		}
		if (el.hasAttribute("data-open-max") || /max-height/i.test(style)) {
			el.style.maxHeight = open ? (el.getAttribute("data-open-max") || "400px") : "0px";
			el.style.opacity = open ? "1" : "0";
		}
	}
	function applyToggle(btn, name, willOpen) {
		var group = name.indexOf("--") > 0 ? name.split("--")[0] : "";
		if (group) {
			root.querySelectorAll("[class*='dxai-on-" + group + "--']").forEach(function (el) {
				applyPanel(el, false);
			});
			root.querySelectorAll("[class*='dxai-toggle-" + group + "--']").forEach(function (el) {
				el.setAttribute("aria-expanded", "false");
				el.querySelectorAll(".rotate-45").forEach(function (icon) {
					icon.classList.remove("rotate-45");
					icon.classList.add("rotate-0");
				});
			});
		}
		root.querySelectorAll(".dxai-on-" + name).forEach(function (el) {
			applyPanel(el, willOpen);
		});
		root.querySelectorAll(".dxai-off-" + name).forEach(function (el) {
			applyPanel(el, !willOpen);
		});
		btn.setAttribute("aria-expanded", willOpen ? "true" : "false");
		var spin = btn.querySelector(".rotate-45, .rotate-0, [class*='rotate-']");
		if (spin) {
			spin.classList.toggle("rotate-45", willOpen);
			spin.classList.toggle("rotate-0", !willOpen);
		}
	}
	function setHoverState(btn, open) {
		String(btn.className || "").split(/\s+/).forEach(function (c) {
			if (c.indexOf("dxai-toggle-") !== 0) {
				return;
			}
			applyToggle(btn, c.slice(12), open);
		});
	}
	/*
	 * Keyboard access to a hover menu. Both hover bindings use it: the one
	 * just below and the state machine's.
	 *
	 * The `data-dxai-hover="enter"` element is often a plain <div> around the
	 * trigger link and its panel (a design's `onMouseEnter` on the menu item,
	 * Claude Design's header). A <div> never takes focus, so the `focus`
	 * listener these bindings used never fired: Tab reached the trigger, the
	 * panel stayed shut, and its links could not be reached at all. Now:
	 *   - focus arriving anywhere inside the element opens it (`focusin`
	 *     bubbles from the trigger), when it is keyboard focus
	 *     (:focus-visible). A mouse click on the trigger has already opened it
	 *     through mouseenter, and a window that regains focus must not reopen
	 *     a menu the pointer left;
	 *   - focus leaving the wrap closes it. The wrap is the same box whose
	 *     mouseleave closes it. A focusout while the pointer is still over that
	 *     box does nothing, because mouseleave closes it later, as before;
	 *   - Escape closes the innermost open menu that holds the focus or the
	 *     pointer. If the focus was inside it, focus goes back to the trigger,
	 *     without reopening it.
	 * None of this listens to the mouse, so hover behaves exactly as it did.
	 */
	var hoverBindings = [];
	var hoverRefocus = null;
	function pointerOver(el) {
		try { return el.matches(":hover"); } catch (e) { return false; }
	}
	function keyboardFocus(el) {
		// A browser without :focus-visible throws; every focus counts there.
		try { return el.matches(":focus-visible"); } catch (e) { return true; }
	}
	var FOCUSABLE = "a[href], button:not([disabled]), input:not([disabled]):not([type='hidden']), select:not([disabled]), textarea:not([disabled]), summary, [tabindex]:not([tabindex='-1'])";
	function hoverTrigger(el) {
		return el.matches && el.matches(FOCUSABLE) ? el : el.querySelector(FOCUSABLE);
	}
	function onHoverEscape(event) {
		if (event.key !== "Escape" && event.key !== "Esc") { return; }
		var active = document.activeElement;
		var hits = hoverBindings.filter(function (b) {
			return b.isOpen() && ((active && b.box.contains(active)) || pointerOver(b.box));
		});
		// Innermost first: a flyout inside a dropdown closes before the dropdown.
		hits.filter(function (b) {
			return !hits.some(function (o) { return o !== b && b.box !== o.box && b.box.contains(o.box); });
		}).forEach(function (b) {
			var trigger = active && b.box.contains(active) ? hoverTrigger(b.el) : null;
			if (trigger && trigger !== active && trigger.focus) {
				hoverRefocus = b.el;
				try { trigger.focus(); } finally { hoverRefocus = null; }
			}
			b.close();
		});
	}
	function hoverKeys(el, wrap, open, close, isOpen) {
		var box = wrap || el;
		el.addEventListener("focusin", function (event) {
			if (hoverRefocus === el || !keyboardFocus(event.target)) { return; }
			open();
		});
		box.addEventListener("focusout", function (event) {
			var next = event.relatedTarget;
			if ((next && box.contains(next)) || pointerOver(box)) { return; }
			close();
		});
		if (!hoverBindings.length) { document.addEventListener("keydown", onHoverEscape); }
		hoverBindings.push({ el: el, box: box, close: close, isOpen: isOpen });
	}
	// A `leave` element closes on focus leaving it, as on the pointer leaving it.
	function leaveKeys(el, close) {
		el.addEventListener("focusout", function (event) {
			var next = event.relatedTarget;
			if ((next && el.contains(next)) || pointerOver(el)) { return; }
			close();
		});
	}
	/*
	 * Hover, gated the same way and for the same reason — and the gate has to
	 * live INSIDE each callback: these bindings run while `owned` is still
	 * null, so testing at bind time would let every one of them through.
	 */
	root.querySelectorAll("[data-dxai-hover='enter'], [class*='dxai-toggle-open--']").forEach(function (el) {
		var wrap = el.closest("li, .relative, [class*='relative']") || el.parentElement;
		el.addEventListener("mouseenter", function () { if (!machineOwns(el)) { setHoverState(el, true); } });
		if (el.getAttribute("data-dxai-hover") === "enter") {
			hoverKeys(el, wrap,
				function () { if (!machineOwns(el)) { setHoverState(el, true); } },
				function () { if (!machineOwns(el)) { setHoverState(el, false); } },
				function () { return !machineOwns(el) && el.getAttribute("aria-expanded") === "true"; });
		} else {
			el.addEventListener("focus", function () { if (!machineOwns(el)) { setHoverState(el, true); } });
		}
		if (wrap) {
			wrap.addEventListener("mouseleave", function () { if (!machineOwns(el)) { setHoverState(el, false); } });
		}
	});
	root.querySelectorAll("[data-dxai-hover='leave']").forEach(function (el) {
		el.addEventListener("mouseleave", function () { if (!machineOwns(el)) { setHoverState(el, false); } });
		el.addEventListener("blur", function () { if (!machineOwns(el)) { setHoverState(el, false); } });
		leaveKeys(el, function () { if (!machineOwns(el)) { setHoverState(el, false); } });
	});
	if (root.querySelector("[data-lucide]")) {
		var bootIcons = function () {
			if (window.lucide && typeof window.lucide.createIcons === "function") {
				window.lucide.createIcons({ attrs: { "stroke-width": 2 } });
			}
		};
		/*
		 * The runtime is the copy bundled with the plugin, whose URL Assets
		 * sets ahead of every page script. There is no CDN fallback any more:
		 * a remote script is what plugin review flags and a strict CSP or a
		 * firewalled host blocks, so it bought nothing where it was needed.
		 */
		if (window.lucide) {
			bootIcons();
		} else if (window.dxaiLucideUrl) {
			var script = document.createElement("script");
			script.src = window.dxaiLucideUrl;
			script.onload = bootIcons;
			document.head.appendChild(script);
		}
	}
	/*
	 * Generic state machine, driven by what the compiler emitted rather than by
	 * conventions written into this file.
	 *
	 * Everything above knows specific class names by heart — `is-on` on a hero
	 * tab, `is-active`/`is-done` on a method row, `hidden` on a panel,
	 * `rotate-45` on an accordion chevron. That works for the patterns somebody
	 * hand-coded and does nothing for any other design, which is why two of the
	 * seven projected nothing at all.
	 *
	 * The contract instead:
	 *   trigger  class dxai-toggle-<state>[--<value>], plus data-dxai-step for
	 *            a relative move, data-dxai-set for a literal assignment
	 *            (`setOpen(null)`), data-dxai-mode="toggle" to collapse when
	 *            already on, data-dxai-init for the authored starting value
	 *   target   class dxai-cls-<state>[--<value>], with data-dxai-on and
	 *            data-dxai-off naming the classes the DESIGN's own stylesheet
	 *            selects on — read out of its conditional class expressions,
	 *            never guessed here. Measured: not one of the seven
	 *            stylesheets contains an [aria-expanded] selector, so aria
	 *            alone changes nothing on screen; it is still written, for
	 *            assistive technology, and still is not the mechanism.
	 *
	 * Nothing is projected on load: the compiler already rendered the initial
	 * state into the markup, so touching it here could only move the page.
	 */
	function classText(el) {
		return el.getAttribute ? (el.getAttribute("class") || "") : "";
	}
	function tokens(value) {
		return String(value || "").split(/\s+/).filter(function (c) { return c !== ""; });
	}
	// State names are JavaScript identifiers and so never contain "-"; a value
	// can (`open--how-we-help`), so the split is at the FIRST "--".
	function marks(el, kind) {
		var prefix = "dxai-" + kind + "-";
		var out = [];
		tokens(classText(el)).forEach(function (c) {
			if (c.indexOf(prefix) !== 0) { return; }
			var flag = c.slice(prefix.length);
			var cut = flag.indexOf("--");
			out.push(cut > 0
				? { state: flag.slice(0, cut), value: flag.slice(cut + 2) }
				: { state: flag, value: null });
		});
		return out;
	}

	/*
	 * The classes a projection adds. One element can carry projections for
	 * several states — a panel whose style depends on the open FAQ index AND
	 * on the viewport — so the class list is looked up per state and value
	 * (`data-dxai-on-openFaq-2`), then per state (`data-dxai-on-openFaq`),
	 * and only then in the shared `data-dxai-on` the earlier compiler wrote.
	 */
	function attrFor(el, kind, mark) {
		var v = mark.value === null ? null : el.getAttribute("data-dxai-" + kind + "-" + mark.state + "-" + mark.value);
		if (v === null) { v = el.getAttribute("data-dxai-" + kind + "-" + mark.state); }
		if (v === null) { v = el.getAttribute("data-dxai-" + kind); }
		return v || "";
	}

	var projections = [];
	var order = {};
	root.querySelectorAll("[class*='dxai-cls-']").forEach(function (el) {
		marks(el, "cls").forEach(function (mark) {
			projections.push({
				el: el,
				state: mark.state,
				value: mark.value,
				on: tokens(attrFor(el, "on", mark)),
				off: tokens(attrFor(el, "off", mark))
			});
			if (mark.value === null) { return; }
			order[mark.state] = order[mark.state] || [];
			if (order[mark.state].indexOf(mark.value) < 0) { order[mark.state].push(mark.value); }
		});
	});

	var panels = root.querySelectorAll("[class*='dxai-on-'], [class*='dxai-off-']");
	panels.forEach(function (el) {
		marks(el, "on").forEach(function (mark) {
			if (mark.value === null) { return; }
			order[mark.state] = order[mark.state] || [];
			if (order[mark.state].indexOf(mark.value) < 0) { order[mark.state].push(mark.value); }
		});
	});
	/*
	 * The order a step moves through is the VALUES' order, not the order the
	 * markers happen to sit in the DOM. Arcus's form has class projections for
	 * steps 2 and 3 and panels for 1, 2 and 3, so `order.formStep` came out
	 * ["2", "3", "1"]: from 1 (index 2) a clamped +1 stayed at index 2, and
	 * "Continue →" did nothing on the page while the design moved on. Numeric
	 * values sort as numbers; anything else keeps the trigger order, which is
	 * how a carousel's rail or a tab strip is written.
	 */
	Object.keys(order).forEach(function (state) {
		var values = order[state];
		if (values.length > 1 && values.every(function (v) { return /^-?\d+(\.\d+)?$/.test(v); })) {
			values.sort(function (a, b) { return parseFloat(a) - parseFloat(b); });
		} else if (values.length > 1) {
			var byTrigger = [];
			root.querySelectorAll("[class*='dxai-toggle-" + state + "--']").forEach(function (el) {
				marks(el, "toggle").forEach(function (mark) {
					if (mark.state === state && mark.value !== null && byTrigger.indexOf(mark.value) < 0) { byTrigger.push(mark.value); }
				});
			});
			values.forEach(function (v) { if (byTrigger.indexOf(v) < 0) { byTrigger.push(v); } });
			order[state] = byTrigger;
		}
	});
	if (projections.length || panels.length) {
		var machine = {};
		// Seed from the markup, never the other way round: the compiler already
		// rendered the initial state, so nothing is projected on load and the
		// page cannot move before anyone touches it.
		//
		// A projection whose on-classes are all present is the current one.
		projections.forEach(function (p) {
			if (!p.on.length || p.state in machine) { return; }
			var live = p.on.every(function (c) { return p.el.classList.contains(c); });
			if (live) { machine[p.state] = p.value === null ? true : p.value; }
		});
		// A panel with no class projection reports through its own visibility.
		panels.forEach(function (el) {
			var shown = !el.classList.contains("hidden");
			marks(el, "on").forEach(function (mark) {
				if (mark.state in machine || !shown) { return; }
				machine[mark.state] = mark.value === null ? true : mark.value;
			});
			marks(el, "off").forEach(function (mark) {
				if (mark.state in machine || !shown) { return; }
				machine[mark.state] = mark.value === null ? false : null;
			});
		});
		projections.forEach(function (p) {
			if (!(p.state in machine)) { machine[p.state] = p.value === null ? false : null; }
		});
		panels.forEach(function (el) {
			marks(el, "on").concat(marks(el, "off")).forEach(function (mark) {
				if (!(mark.state in machine)) { machine[mark.state] = mark.value === null ? false : null; }
			});
		});
		/*
		 * States built from other states: data-dxai-any="dark:solid,open".
		 *
		 * Seeded before anything reads the machine, because a member with no
		 * projection of its own — a scroll flag — would otherwise never be in
		 * it, and every block below skips a state it cannot find. The derived
		 * state's own value comes from its projection above; the members start
		 * false, which is the state the page was compiled in.
		 */
		var derived = [];
		[root].concat([].slice.call(root.querySelectorAll("[data-dxai-any]"))).forEach(function (el) {
			tokens(el.getAttribute && el.getAttribute("data-dxai-any")).forEach(function (spec) {
				var cut = spec.indexOf(":");
				if (cut < 1) { return; }
				var state = spec.slice(0, cut);
				var of = spec.slice(cut + 1).split(",").map(function (m) { return m.trim(); })
					.filter(function (m) { return m !== ""; });
				if (!of.length || !(state in machine)) { return; }
				derived.push({ state: state, of: of });
				of.forEach(function (m) {
					if (!(m in machine)) { machine[m] = false; }
				});
			});
		});

		// An explicit initial wins: a relative move needs the index it starts
		// from, which nothing in the class list states once every arm has been
		// resolved to the one the page was compiled at.
		root.querySelectorAll("[data-dxai-init]").forEach(function (el) {
			marks(el, "toggle").forEach(function (mark) {
				machine[mark.state] = el.getAttribute("data-dxai-init");
			});
		});

		/*
		 * Hand the hand-written handler its stop list. From here on a trigger
		 * whose state is described in `machine` is driven by this block only;
		 * everything else — a design with no projection at all, `.rv-hero-tab`,
		 * `.rv-mbp` — still reaches the handlers above untouched.
		 */
		owned = machine;

		var holds = function (state, value) {
			return value === null
				? !!machine[state]
				: machine[state] !== null && String(machine[state]) === value;
		};

		var project = function (state) {
			projections.forEach(function (p) {
				if (p.state !== state) { return; }
				var hit = holds(state, p.value);
				p.on.forEach(function (c) { p.el.classList.toggle(c, hit); });
				p.off.forEach(function (c) { p.el.classList.toggle(c, !hit); });
			});
			/*
			 * Panels too, reusing applyPanel() above rather than a second
			 * opinion on what "open" means. The hand-written click handler
			 * still runs first and is untouched; it reads the trigger's own
			 * aria-expanded, which cannot express "set this state to null" —
			 * so DevriX Elevate's 38 drawer links and 33 menu dismissals
			 * re-opened what they were written to close.
			 */
			panels.forEach(function (el) {
				// A panel can be shown for several values of one state (a card in a window of a list): any of them shows it.
				var on = marks(el, "on").filter(function (mark) { return mark.state === state; });
				if (on.length) {
					applyPanel(el, on.some(function (mark) { return holds(state, mark.value); }));
				}
				var off = marks(el, "off").filter(function (mark) { return mark.state === state; });
				if (off.length) {
					applyPanel(el, !off.some(function (mark) { return holds(state, mark.value); }));
				}
			});
			/*
			 * A button that is off for some values of the state — the arrow that goes back, with the list at its first window:
			 * data-dxai-disabled="start:0", the values listed. The page is compiled at the starting one.
			 */
			root.querySelectorAll("[data-dxai-disabled]").forEach(function (el) {
				var spec = String(el.getAttribute("data-dxai-disabled") || "");
				var cut = spec.indexOf(":");
				if (cut < 1 || spec.slice(0, cut) !== state) { return; }
				var current = machine[state];
				el.disabled = current !== null && current !== undefined && spec.slice(cut + 1).split(",").indexOf(String(current)) > -1;
			});
			root.querySelectorAll("[class*='dxai-toggle-']").forEach(function (el) {
				if (el.hasAttribute("data-dxai-step") || el.hasAttribute("data-dxai-set")) { return; }
				marks(el, "toggle").forEach(function (mark) {
					if (mark.state !== state) { return; }
					var hit = holds(state, mark.value);
					el.setAttribute("aria-expanded", hit ? "true" : "false");
					if (el.getAttribute("role") === "tab") {
						el.setAttribute("aria-selected", hit ? "true" : "false");
					}
				});
			});
			/*
			 * Words that change with the state — "+ 8 more cities" / "− Show
			 * fewer", "Step 2 of 3", the +/− of an accordion sign. The compiler
			 * writes every reading it evaluated as a map keyed by state value;
			 * the machine's own spelling of the value picks the reading.
			 */
			root.querySelectorAll("[data-dxai-text-" + state + "]").forEach(function (el) {
				var map;
				try { map = JSON.parse(el.getAttribute("data-dxai-text-" + state) || "{}"); } catch (e) { return; }
				var current = machine[state];
				var key = current === null || current === undefined ? "null" : String(current);
				if (Object.prototype.hasOwnProperty.call(map, key)) { el.textContent = map[key]; }
			});
			/*
			 * And anything derived from this state. One level: a derivation's
			 * members are plain states, so projecting the derived name here
			 * cannot come back round to this one.
			 */
			derived.forEach(function (d) {
				if (d.state === state || d.of.indexOf(state) < 0) { return; }
				var next = d.of.some(function (m) { return !!machine[m]; });
				if (machine[d.state] === next) { return; }
				machine[d.state] = next;
				project(d.state);
			});
		};

		var fire = function (el) {
			var acted = false;
			marks(el, "toggle").forEach(function (mark) {
				if (!(mark.state in machine)) { return; }
				var step = parseInt(el.getAttribute("data-dxai-step") || "0", 10);
				var set = el.getAttribute("data-dxai-set");
				var mode = el.getAttribute("data-dxai-mode");
				var current = machine[mark.state];
				if (step) {
					// `go(i - 1)` on a carousel rail: the value is only right
					// for the index the page was compiled at, so the compiler
					// emits the step and the wrap-around happens here.
					var values = order[mark.state] || [];
					if (!values.length) { return; }
					var at = values.indexOf(String(current));
					if (at < 0) { at = 0; }
					var next = at + step;
					// `Math.min(3, step + 1)` stops at the end; a carousel wraps.
					if (el.hasAttribute("data-dxai-clamp")) {
						next = Math.max(0, Math.min(values.length - 1, next));
					} else {
						next = ((next % values.length) + values.length) % values.length;
					}
					machine[mark.state] = values[next];
				} else if (set === "null") {
					machine[mark.state] = null;
				} else if (set === "true" || set === "false") {
					machine[mark.state] = set === "true";
				} else if (mark.value === null) {
					// See wanted() below: on a state whose projections are all
					// keyed by value, flipping a boolean produces a value no
					// projection can ever match, so "off" is the only reachable
					// reading of a valueless trigger.
					machine[mark.state] = (order[mark.state] || []).length ? null : !current;
				} else if (mode === "toggle" && current !== null && String(current) === mark.value) {
					machine[mark.state] = null;
				} else {
					machine[mark.state] = mark.value;
				}
				project(mark.state);
				acted = true;
			});
			return acted;
		};

		root.addEventListener("click", function (event) {
			var el = event.target && event.target.closest
				? event.target.closest("[class*='dxai-toggle-']")
				: null;
			if (!el || !root.contains(el)) { return; }
			/*
			 * A real link keeps its navigation — a blanket preventDefault()
			 * suppresses behaviour a live control may depend on — but the state
			 * change still happens, because that is what the design does. All
			 * 38 of DevriX Elevate's drawer links are
			 * `<a href="#services" onClick={() => setMobile(false)}>`: skipping
			 * them outright, as the handler above must, is why closing the
			 * drawer by choosing something from it never worked.
			 */
			var href = el.tagName === "A" ? el.getAttribute("href") : "";
			var navigates = !!href && href !== "#";
			if (fire(el) && !navigates) { event.preventDefault(); }
		});
		/*
		 * Hover is a direction, not a toggle.
		 *
		 * `fire()` is right for a click and wrong for mouseenter: on a trigger
		 * carrying data-dxai-mode="toggle" a second entry into the same item —
		 * which happens whenever the pointer crosses back out of a child and
		 * in again — would read as "already on" and CLOSE the menu under the
		 * cursor. These two set the state, idempotently, which is what
		 * setHoverState() did before the state machine existed.
		 */
		/*
		 * What a VALUELESS trigger means on a state whose projections are all
		 * keyed by value. `true` could never satisfy `holds("open", "RevOps")`,
		 * so a bare `dxai-toggle-open` on such a state cannot mean "on" — the
		 * only reachable thing it can mean is "none of them". DevriX Elevate's
		 * fifth nav item is the case: the same
		 * `onMouseEnter={() => setOpen(item.children ? item.label : null)}` as
		 * the four beside it, on the one item with no children, so it compiles
		 * to a valueless flag while its siblings carry `open--<Label>`.
		 *
		 * Read off `order`, which is built from the emitted markers, so this is
		 * a fact about the page rather than another convention in this file.
		 */
		var wanted = function (el, mark) {
			var set = el.getAttribute("data-dxai-set");
			if (set === "null") { return null; }
			if (set === "true" || set === "false") { return set === "true"; }
			if (mark.value !== null) { return mark.value; }
			return (order[mark.state] || []).length ? null : true;
		};
		var hoverOn = function (el) {
			marks(el, "toggle").forEach(function (mark) {
				if (!(mark.state in machine)) { return; }
				var next = wanted(el, mark);
				if (machine[mark.state] === next) { return; }
				machine[mark.state] = next;
				project(mark.state);
			});
		};
		// Only if THIS element's value is the one currently held: leaving a
		// closed nav item must not shut the menu opened from another.
		var hoverOff = function (el) {
			marks(el, "toggle").forEach(function (mark) {
				if (!(mark.state in machine)) { return; }
				if (mark.value === null) {
					if (machine[mark.state] === false) { return; }
					machine[mark.state] = false;
				} else {
					if (machine[mark.state] === null || String(machine[mark.state]) !== mark.value) { return; }
					machine[mark.state] = null;
				}
				project(mark.state);
			});
		};
		/*
		 * The wrap, not the trigger, owns mouseleave — a mega-menu panel is a
		 * sibling of the button that opens it, so leaving the button to move
		 * INTO the panel would otherwise close it before it could be clicked.
		 * Same ancestor the pre-machine binding used.
		 */
		// Open: the state this element's hoverOn() sets is the one held.
		var hoverHeld = function (el) {
			return marks(el, "toggle").some(function (mark) {
				if (!(mark.state in machine)) { return false; }
				var want = wanted(el, mark);
				var now = machine[mark.state];
				return want !== null && want !== false && now !== null && now !== undefined && String(now) === String(want);
			});
		};
		root.querySelectorAll("[data-dxai-hover='enter']").forEach(function (el) {
			// A wrapper that carries its own mouseleave handler closes when the
			// pointer leaves IT, not its parent: `data-dxai-hover-self`.
			var wrap = el.hasAttribute("data-dxai-hover-self") ? el : (el.closest("li, .relative, [class*='relative']") || el.parentElement);
			el.addEventListener("mouseenter", function () { hoverOn(el); });
			// Focus, focus leaving and Escape: hoverKeys() above.
			hoverKeys(el, wrap,
				function () { hoverOn(el); },
				function () { hoverOff(el); },
				function () { return hoverHeld(el); });
			if (wrap) {
				wrap.addEventListener("mouseleave", function () { hoverOff(el); });
			}
		});
		root.querySelectorAll("[data-dxai-hover='leave']").forEach(function (el) {
			el.addEventListener("mouseleave", function () { hoverOff(el); });
			el.addEventListener("blur", function () { hoverOff(el); });
			leaveKeys(el, function () { hoverOff(el); });
		});

		/*
		 * State the page sets from scrolling: `data-dxai-scroll="scrolled:20"`
		 * means `scrolled` is true once the page is scrolled past 20px — the
		 * design's `window.scrollY > 20` in a scroll listener, which turns a
		 * translucent header solid.
		 */
		var scrollSpecs = [];
		[root].concat([].slice.call(root.querySelectorAll("[data-dxai-scroll]"))).forEach(function (el) {
			tokens(el.getAttribute && el.getAttribute("data-dxai-scroll")).forEach(function (spec) {
				var cut = spec.indexOf(":");
				var state = cut > 0 ? spec.slice(0, cut) : spec;
				if (!(state in machine)) { return; }
				scrollSpecs.push({ state: state, at: cut > 0 ? parseFloat(spec.slice(cut + 1)) || 0 : 0 });
			});
		});
		if (scrollSpecs.length) {
			var onScrollState = function () {
				scrollSpecs.forEach(function (spec) {
					var next = (window.scrollY || window.pageYOffset || 0) > spec.at;
					if (machine[spec.state] === next) { return; }
					machine[spec.state] = next;
					project(spec.state);
				});
			};
			window.addEventListener("scroll", onScrollState, { passive: true });
			onScrollState();
		}

		/*
		 * State a click anywhere else resets: `data-dxai-outside="dropdown"`
		 * is the design's document click listener that closes an open menu.
		 * A click on one of that state's own triggers or panels is not
		 * "elsewhere" — the trigger already handled it.
		 */
		var outsideStates = [];
		[root].concat([].slice.call(root.querySelectorAll("[data-dxai-outside]"))).forEach(function (el) {
			tokens(el.getAttribute && el.getAttribute("data-dxai-outside")).forEach(function (state) {
				if (state in machine && outsideStates.indexOf(state) < 0) { outsideStates.push(state); }
			});
		});
		if (outsideStates.length) {
			document.addEventListener("click", function (event) {
				var target = event.target;
				outsideStates.forEach(function (state) {
					var current = machine[state];
					if (current === null || current === false) { return; }
					if (target && target.closest && target.closest("[class*='dxai-toggle-" + state + "'], [class*='dxai-on-" + state + "'], [class*='dxai-off-" + state + "']")) { return; }
					machine[state] = (order[state] || []).length ? null : false;
					project(state);
				});
			});
		}
	}
	root.querySelectorAll(".rv-mbp, [data-dxai-method], .dxai-method").forEach(function (wrap) {
		var rows = wrap.querySelectorAll(".rv-mbp-row");
		var nums = wrap.querySelectorAll(".rv-mbp-numline");
		var bar = wrap.querySelector(".rv-mbp-meta-bar > span");
		var compute = function () {
			var box = wrap.getBoundingClientRect();
			var vh = window.innerHeight || 1;
			var start = vh * 0.85;
			var end = vh * 0.25;
			var total = box.height + (start - end);
			var scrolled = start - box.top;
			var p = Math.max(0, Math.min(1, scrolled / total));
			wrap.style.setProperty("--rv-mbp-p", String(p));
			var active = Math.min(Math.max(rows.length - 1, 0), Math.floor(p * rows.length + 0.15));
			rows.forEach(function (row, i) {
				row.classList.toggle("is-active", i === active);
				row.classList.toggle("is-done", i < active);
				row.classList.toggle("is-next", i > active);
			});
			nums.forEach(function (n, i) {
				n.classList.toggle("is-on", i === active);
				n.classList.toggle("is-done", i < active);
			});
			if (bar) {
				bar.style.width = (((active + 1) / Math.max(rows.length, 1)) * 100) + "%";
			}
		};
		window.addEventListener("scroll", compute, { passive: true });
		window.addEventListener("resize", compute);
		compute();
	});

	/* ------------------------------------------------ Radix interaction ---
	 *
	 * Accordion, Tabs and Select, driven entirely by the ARIA the converter
	 * already emits. Nothing bespoke is needed: the relationships are in the
	 * markup because Radix puts them there, and reproducing them was the whole
	 * point of emitting them.
	 *
	 *   accordion  trigger [aria-expanded][id]  ->  panel [role=region][aria-labelledby=id]
	 *   tabs       trigger [role=tab][id]       ->  panel [role=tabpanel][aria-labelledby=id]
	 *   select     trigger [role=combobox]      ->  list  [role=listbox] via aria-controls
	 *   dialog     trigger [aria-haspopup=dialog] -> panel [role=dialog] via aria-controls, centred
	 *   popover    trigger [aria-haspopup=dialog] -> panel [role=dialog][data-side] via aria-controls
	 *   menu       trigger [aria-haspopup=menu]   -> panel [role=menu] via aria-controls
	 *   tooltip    trigger [aria-describedby]     -> panel [role=tooltip], on hover and focus
	 *
	 * Grouping comes from the id the converter mints — `dxai-rx-<n>-trigger-…`
	 * — so one accordion on a page cannot close another's panels.
	 *
	 * Deliberately NOT keyed on the `dxai-toggle-` classes above: that
	 * mechanism models a design's own useState, and a Radix component has no
	 * such state in the source to read. The two never match the same element.
	 */
	function rxGroup(el) {
		var id = el.getAttribute("id") || "";
		var cut = id.indexOf("-trigger-");
		return cut > 0 ? id.slice(0, cut) : "";
	}
	function rxPanel(trigger, role) {
		var id = trigger.getAttribute("id");
		if (!id) { return null; }
		var all = root.querySelectorAll('[role="' + role + '"][aria-labelledby]');
		for (var i = 0; i < all.length; i++) {
			if (all[i].getAttribute("aria-labelledby") === id) { return all[i]; }
		}
		return null;
	}
	/*
	 * Two hiding mechanisms have to be undone, because the converter uses both:
	 * `hidden` for a closed disclosure (what Radix does) and an inline
	 * `display:none` for a portalled overlay (which `hidden` cannot hold —
	 * a `grid` or `flex` class would override it).
	 *
	 * On a page whose styles Style_Hoister moved out of the markup, that
	 * `display:none` is no longer inline: it is in the element's dxs- rule,
	 * which clearing el.style cannot undo, so the part never opened. There
	 * the part is shown with an inline display of the runtime's own — the one
	 * its other classes give it, read with its dxs- classes set aside — and
	 * `data-dxai-shown` marks that value, so closing takes it off again and
	 * the rule hides the part as before.
	 */
	function rxOwnDisplay(el) {
		var own = [].filter.call(el.classList, function (name) { return name.indexOf("dxs-") === 0; });
		if (!own.length) { return ""; }
		own.forEach(function (name) { el.classList.remove(name); });
		var display = getComputedStyle(el).display;
		own.forEach(function (name) { el.classList.add(name); });
		return display;
	}
	function rxShow(el, show) {
		if (show) {
			el.removeAttribute("hidden");
			if (/display\s*:\s*none/i.test(el.getAttribute("style") || "")) {
				el.style.display = "";
			}
			if (getComputedStyle(el).display === "none") {
				var display = rxOwnDisplay(el);
				if (display && display !== "none") {
					el.style.display = display;
					el.setAttribute("data-dxai-shown", "");
				}
			}
		} else {
			el.setAttribute("hidden", "");
			if (el.hasAttribute("data-dxai-shown")) {
				el.removeAttribute("data-dxai-shown");
				el.style.display = "";
			}
		}
	}
	function rxState(el, value) {
		if (el) { el.setAttribute("data-state", value); }
	}

	function rxAccordion(trigger) {
		var panel = rxPanel(trigger, "region");
		if (!panel) { return false; }
		var open = trigger.getAttribute("aria-expanded") !== "true";
		var group = rxGroup(trigger);

		/*
		 * `single` closes its siblings, `multiple` lets them stack, and the
		 * converter publishes which — Radix keeps `type` in the root's props
		 * and puts nothing on the DOM, so without that attribute this used to
		 * follow the majority and close the siblings of a multiple accordion.
		 */
		var host = trigger.closest("[data-dxai-accordion]");
		var multiple = host && host.getAttribute("data-dxai-accordion") === "multiple";

		/*
		 * And a `single` accordion that is not `collapsible` cannot be closed
		 * by clicking its open trigger — the same refusal Radix marks with
		 * aria-disabled.
		 */
		if (!open && !multiple && host && host.getAttribute("data-dxai-collapsible") !== "true") {
			return true;
		}

		if (open && group && !multiple) {
			root.querySelectorAll('[aria-expanded][id^="' + group + '-trigger-"]').forEach(function (other) {
				if (other === trigger) { return; }
				var sib = rxPanel(other, "region");
				if (!sib) { return; }
				other.setAttribute("aria-expanded", "false");
				other.removeAttribute("aria-controls");
				rxState(other, "closed");
				rxState(other.parentElement, "closed");
				rxState(other.closest("[data-state]"), "closed");
				rxState(sib, "closed");
				rxShow(sib, false);
			});
		}

		/*
		 * The design's keyframes animate to `var(--radix-accordion-content-height)`,
		 * which Radix measures at open time. Without it the panel animates to
		 * zero and never appears to open, so it is measured here the same way.
		 */
		if (open) {
			rxShow(panel, true);
			panel.style.setProperty("--radix-accordion-content-height", panel.scrollHeight + "px");
			panel.style.setProperty("--radix-collapsible-content-height", panel.scrollHeight + "px");
		}
		trigger.setAttribute("aria-expanded", open ? "true" : "false");
		if (open) {
			trigger.setAttribute("aria-controls", panel.id || "");
		} else {
			trigger.removeAttribute("aria-controls");
		}
		rxState(trigger, open ? "open" : "closed");
		rxState(trigger.parentElement, open ? "open" : "closed");
		var item = trigger.parentElement && trigger.parentElement.parentElement;
		if (item && item.hasAttribute("data-state")) { rxState(item, open ? "open" : "closed"); }
		rxState(panel, open ? "open" : "closed");
		if (!open) { rxShow(panel, false); }
		return true;
	}

	function rxTabs(trigger) {
		var panel = rxPanel(trigger, "tabpanel");
		if (!panel) { return false; }
		var group = rxGroup(trigger);
		root.querySelectorAll('[role="tab"][id^="' + group + '-trigger-"]').forEach(function (other) {
			var own = rxPanel(other, "tabpanel");
			var on = other === trigger;
			other.setAttribute("aria-selected", on ? "true" : "false");
			rxState(other, on ? "active" : "inactive");
			if (own) {
				rxState(own, on ? "active" : "inactive");
				rxShow(own, on);
			}
		});
		return true;
	}

	function rxSelectClose(trigger, list) {
		trigger.setAttribute("aria-expanded", "false");
		rxState(trigger, "closed");
		rxState(list, "closed");
		rxShow(list, false);
		list.style.display = "none";
	}

	function rxSelect(trigger) {
		var id = trigger.getAttribute("aria-controls");
		var list = id ? root.querySelector('[id="' + id + '"][role="listbox"]') : null;
		if (!list) { return false; }
		if (trigger.getAttribute("aria-expanded") === "true") {
			rxSelectClose(trigger, list);
			return true;
		}

		/*
		 * Radix portals the list to the body and positions it with floating-ui.
		 * A converted page keeps it where it was written, so it is positioned
		 * against the trigger here instead — otherwise it would sit in the
		 * flow and push the rest of the page down when it opened.
		 */
		var host = list.offsetParent || trigger.parentElement;
		if (host && getComputedStyle(host).position === "static") { host.style.position = "relative"; }
		// Cleared before rxShow(), which may give the list a display of its own.
		list.style.display = "";
		rxShow(list, true);
		list.style.position = "absolute";
		list.style.zIndex = "50";
		list.style.left = trigger.offsetLeft + "px";
		list.style.top = (trigger.offsetTop + trigger.offsetHeight + 4) + "px";
		list.style.minWidth = trigger.offsetWidth + "px";
		trigger.setAttribute("aria-expanded", "true");
		rxState(trigger, "open");
		rxState(list, "open");
		return true;
	}

	function rxChoose(option) {
		var list = option.closest('[role="listbox"]');
		if (!list) { return false; }
		var trigger = root.querySelector('[role="combobox"][aria-controls="' + (list.getAttribute("id") || "") + '"]');
		list.querySelectorAll('[role="option"]').forEach(function (other) {
			var on = other === option;
			other.setAttribute("aria-selected", on ? "true" : "false");
			rxState(other, on ? "checked" : "unchecked");
		});
		if (trigger) {
			// The trigger shows the chosen item's text, in the span the
			// converter filled with the initially selected one.
			var slot = trigger.querySelector("span");
			if (slot) { slot.textContent = (option.textContent || "").trim(); }
			rxSelectClose(trigger, list);
		}
		return true;
	}

	/*
	 * A portal of our own, for what Radix portals.
	 *
	 * A dialog's overlay and a menu's panel are `position: fixed`, and fixed
	 * positioning is measured against the nearest ancestor with a transform —
	 * which a revealed section has (`translateY(0)` is still a transform).
	 * Left where the converter wrote them, they would open inside the section
	 * instead of over the page. Radix avoids this by rendering into <body>;
	 * so does this, into a host that carries the wrapper's own classes, since
	 * every stylesheet on the page is scoped to them and an element moved
	 * outside the wrapper would lose all of its styling.
	 */
	var rxHostEl = null;
	function rxHost() {
		if (rxHostEl) { return rxHostEl; }
		rxHostEl = document.createElement("div");
		rxHostEl.className = ((root.className || "") + " dxai-portal").trim();
		rxHostEl.setAttribute("data-dxai-portal-host", "");
		document.body.appendChild(rxHostEl);
		rxHostEl.addEventListener("click", rxClick);
		// Leaving a tooltip that was moved here closes it, as leaving its
		// trigger does.
		rxHostEl.addEventListener("pointerout", rxTipOut);
		return rxHostEl;
	}
	function rxOwns(el) {
		return root.contains(el) || (rxHostEl !== null && rxHostEl.contains(el));
	}
	function rxFind(id) {
		return id ? document.getElementById(id) : null;
	}
	function rxPortal(el) {
		if (el && el.parentElement !== rxHost()) { rxHost().appendChild(el); }
	}

	function rxOverlayFor(content) {
		// Paired by id: the converter names them `…-content-N` / `…-overlay-N`.
		var id = content.getAttribute("id") || "";
		return id.indexOf("-content-") > 0 ? rxFind(id.replace("-content-", "-overlay-")) : null;
	}

	function rxCloseOverlay(trigger) {
		var content = rxFind(trigger.getAttribute("aria-controls"));
		if (!content) { return; }
		var overlay = rxOverlayFor(content);
		trigger.setAttribute("aria-expanded", "false");
		rxState(trigger, "closed");
		trigger.removeAttribute("data-radix-popper-side");
		trigger.removeAttribute("data-radix-popper-align");
		rxState(content, "closed");
		content.style.display = "none";
		if (overlay) { rxState(overlay, "closed"); overlay.style.display = "none"; }
		if (trigger.focus) { trigger.focus(); }
	}

	/* A dialog: overlay and panel are fixed and centred by their own classes,
	 * so opening is moving them out from under any transform and showing them. */
	function rxDialog(trigger) {
		var content = rxFind(trigger.getAttribute("aria-controls"));
		if (!content || trigger.getAttribute("aria-haspopup") !== "dialog") { return false; }
		if (trigger.getAttribute("aria-expanded") === "true") { rxCloseOverlay(trigger); return true; }
		var overlay = rxOverlayFor(content);
		// Inline display cleared before rxShow(), which may set one of its own.
		if (overlay) { rxPortal(overlay); overlay.style.display = ""; rxShow(overlay, true); rxState(overlay, "open"); }
		rxPortal(content);
		content.style.display = "";
		rxShow(content, true);
		rxState(content, "open");
		trigger.setAttribute("aria-expanded", "true");
		rxState(trigger, "open");
		if (content.focus) { content.focus(); }
		return true;
	}

	/*
	 * Radix's popper, reduced to what the kit needs. The panel sits on the
	 * side of its trigger the content names (`data-side`), aligned along it
	 * (`data-align`), `data-dxai-offset` pixels away. It flips to the opposite
	 * side when it would leave the viewport, and slides along the trigger to
	 * stay inside it: measured on the real build, a 341px tooltip centred on
	 * a button near the right edge of a 390px viewport ends flush with that
	 * edge rather than clipped. Fixed, in the portal host, which is where
	 * Radix's own wrapper lives.
	 */
	function rxPopper(trigger, content) {
		var side = content.getAttribute("data-side") || "bottom";
		var align = content.getAttribute("data-align") || "center";
		var offset = parseFloat(content.getAttribute("data-dxai-offset") || "0") || 0;
		var box = trigger.getBoundingClientRect();
		rxPortal(content);
		content.style.display = "";
		rxShow(content, true);
		content.style.position = "fixed";
		content.style.left = "0px";
		content.style.top = "0px";
		content.style.zIndex = "50";
		/*
		 * Measured at the origin, where the whole viewport is available, and
		 * then held at that width: a fixed box shrinks to what is left of the
		 * viewport beyond its `left`, so a tooltip placed near the right edge
		 * would otherwise wrap. Radix's wrapper has `min-width: max-content`
		 * for the same reason.
		 */
		content.style.width = "";
		var w = content.offsetWidth;
		var h = content.offsetHeight;
		content.style.width = w + "px";
		var vw = window.innerWidth;
		var vh = window.innerHeight;
		if (side === "bottom" && box.bottom + offset + h > vh && box.top - offset - h >= 0) { side = "top"; }
		else if (side === "top" && box.top - offset - h < 0 && box.bottom + offset + h <= vh) { side = "bottom"; }
		else if (side === "right" && box.right + offset + w > vw && box.left - offset - w >= 0) { side = "left"; }
		else if (side === "left" && box.left - offset - w < 0 && box.right + offset + w <= vw) { side = "right"; }
		var x;
		var y;
		if (side === "top" || side === "bottom") {
			y = side === "top" ? box.top - offset - h : box.bottom + offset;
			x = align === "start" ? box.left : (align === "end" ? box.right - w : box.left + (box.width - w) / 2);
		} else {
			x = side === "left" ? box.left - offset - w : box.right + offset;
			y = align === "start" ? box.top : (align === "end" ? box.bottom - h : box.top + (box.height - h) / 2);
		}
		x = Math.max(0, Math.min(x, vw - w));
		y = Math.max(0, Math.min(y, vh - h));
		content.style.left = Math.round(x) + "px";
		content.style.top = Math.round(y) + "px";
		content.setAttribute("data-side", side);
		content.setAttribute("data-align", align);
		trigger.setAttribute("data-radix-popper-side", side);
		trigger.setAttribute("data-radix-popper-align", align);
		// The variables Radix publishes for the kit's own styles to read.
		content.style.setProperty("--radix-popper-anchor-width", box.width + "px");
		content.style.setProperty("--radix-popper-anchor-height", box.height + "px");
		content.style.setProperty("--radix-popper-available-width", vw + "px");
		content.style.setProperty("--radix-popper-available-height", vh + "px");
	}

	/* A dialog and a popover both announce `aria-haspopup="dialog"`; the panel
	 * tells them apart — a popover's names the side it opens on. */
	function rxIsPopper(content) {
		return content !== null && content.hasAttribute("data-side");
	}

	/* A popover: anchored to its trigger like a menu, and focus moves to the
	 * first control inside it — measured on the real build, the input. */
	function rxPopover(trigger) {
		var content = rxFind(trigger.getAttribute("aria-controls"));
		if (!content || trigger.getAttribute("aria-haspopup") !== "dialog") { return false; }
		if (trigger.getAttribute("aria-expanded") === "true") { rxCloseOverlay(trigger); return true; }
		rxPopper(trigger, content);
		rxState(content, "open");
		trigger.setAttribute("aria-expanded", "true");
		rxState(trigger, "open");
		var first = content.querySelector('input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), button:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])');
		var target = first || content;
		if (target.focus) { target.focus(); }
		return true;
	}

	/* A menu: portalled like the dialog, then anchored under its trigger the
	 * way Radix's popper does. */
	function rxMenu(trigger) {
		var content = rxFind(trigger.getAttribute("aria-controls"));
		if (!content || trigger.getAttribute("aria-haspopup") !== "menu") { return false; }
		if (trigger.getAttribute("aria-expanded") === "true") { rxCloseOverlay(trigger); return true; }
		rxPopper(trigger, content);
		rxState(content, "open");
		trigger.setAttribute("aria-expanded", "true");
		rxState(trigger, "open");
		return true;
	}

	/*
	 * Tooltips. Measured on the real build: hovering opens one after
	 * `delayDuration` (700ms unless the tooltip says otherwise), focus opens
	 * it at once, and it closes on Escape, on blur, on a click of its trigger,
	 * and when the pointer leaves for anywhere but the tooltip itself. That
	 * last is Radix's "hoverable content": leaving the trigger towards the
	 * tooltip keeps it open, and the first move elsewhere closes it —
	 * approximated here with the box that holds both trigger and tooltip.
	 * The tooltip is the element the trigger's aria-describedby names, which
	 * is Radix's own link between the two.
	 */
	var rxTipTimer = null;
	var rxTipClosedAt = 0;
	var rxTipPointerDown = false;
	function rxTipContent(trigger) {
		var content = rxFind(trigger.getAttribute("aria-describedby"));
		return content && content.getAttribute("role") === "tooltip" ? content : null;
	}
	function rxTipTrigger(el) {
		var trigger = el && el.closest ? el.closest("[aria-describedby][data-state]") : null;
		return trigger && rxOwns(trigger) && rxTipContent(trigger) ? trigger : null;
	}
	function rxTipOwner(tip) {
		var all = root.querySelectorAll('[aria-describedby="' + (tip.getAttribute("id") || "") + '"]');
		return all.length ? all[0] : null;
	}
	function rxTipDelay(trigger) {
		var own = parseFloat(trigger.getAttribute("data-dxai-delay") || "");
		// Radix skips the delay when a tooltip closed less than 300ms ago, so
		// moving along a row of them does not wait at each one.
		return Date.now() - rxTipClosedAt < 300 ? 0 : (isNaN(own) ? 700 : own);
	}
	function rxTipOpen(trigger, instant) {
		clearTimeout(rxTipTimer);
		var content = rxTipContent(trigger);
		if (!content || trigger.getAttribute("data-state") !== "closed") { return; }
		rxPopper(trigger, content);
		rxState(content, instant ? "instant-open" : "delayed-open");
		rxState(trigger, instant ? "instant-open" : "delayed-open");
	}
	function rxTipClose(trigger) {
		clearTimeout(rxTipTimer);
		var content = rxTipContent(trigger);
		if (!content || trigger.getAttribute("data-state") === "closed") { return; }
		content.style.display = "none";
		rxState(content, "closed");
		rxState(trigger, "closed");
		trigger.removeAttribute("data-radix-popper-side");
		trigger.removeAttribute("data-radix-popper-align");
		rxTipClosedAt = Date.now();
	}
	function rxOpenTips() {
		return [].slice.call(root.querySelectorAll('[aria-describedby][data-state]:not([data-state="closed"])')).filter(rxTipContent);
	}
	function rxTipLeave(trigger) {
		var content = rxTipContent(trigger);
		function track(event) {
			if (trigger.getAttribute("data-state") === "closed") { document.removeEventListener("pointermove", track); return; }
			var over = event.target;
			if (trigger.contains(over) || (content && content.contains(over))) { return; }
			var a = trigger.getBoundingClientRect();
			var b = content ? content.getBoundingClientRect() : a;
			var inside = event.clientX >= Math.min(a.left, b.left) && event.clientX <= Math.max(a.right, b.right)
				&& event.clientY >= Math.min(a.top, b.top) && event.clientY <= Math.max(a.bottom, b.bottom);
			if (inside) { return; }
			document.removeEventListener("pointermove", track);
			rxTipClose(trigger);
		}
		document.addEventListener("pointermove", track);
	}
	function rxTipOver(event) {
		var trigger = rxTipTrigger(event.target);
		if (!trigger || event.pointerType === "touch") { return; }
		if (event.relatedTarget && trigger.contains(event.relatedTarget)) { return; }
		if (trigger.getAttribute("data-state") !== "closed") { return; }
		clearTimeout(rxTipTimer);
		var delay = rxTipDelay(trigger);
		rxTipTimer = setTimeout(function () { rxTipOpen(trigger, delay === 0); }, delay);
	}
	function rxTipOut(event) {
		var trigger = rxTipTrigger(event.target);
		if (!trigger) {
			// Leaving the tooltip itself, which lives in the portal host.
			var tip = event.target && event.target.closest ? event.target.closest('[role="tooltip"]') : null;
			var owner = tip ? rxTipOwner(tip) : null;
			if (owner && !(event.relatedTarget && tip.contains(event.relatedTarget))) { rxTipLeave(owner); }
			return;
		}
		if (event.relatedTarget && trigger.contains(event.relatedTarget)) { return; }
		clearTimeout(rxTipTimer);
		if (trigger.getAttribute("data-state") !== "closed") { rxTipLeave(trigger); }
	}
	root.addEventListener("pointerover", rxTipOver);
	root.addEventListener("pointerout", rxTipOut);
	root.addEventListener("pointerdown", function (event) {
		if (rxTipTrigger(event.target)) {
			rxTipPointerDown = true;
			document.addEventListener("pointerup", function () { rxTipPointerDown = false; }, { once: true });
		}
	});
	root.addEventListener("focusin", function (event) {
		var trigger = rxTipTrigger(event.target);
		// Focus that arrives with a click does not open it: the click closes it.
		if (trigger && !rxTipPointerDown) { rxTipOpen(trigger, true); }
	});
	root.addEventListener("focusout", function (event) {
		var trigger = rxTipTrigger(event.target);
		if (trigger) { rxTipClose(trigger); }
	});

	function rxTriggerOf(content) {
		var id = content.getAttribute("id") || "";
		var all = document.querySelectorAll('[aria-haspopup][aria-controls="' + id + '"]');
		return all.length ? all[0] : null;
	}

	function rxClick(event) {
		var target = event.target;
		if (!target || !target.closest) { return; }

		// A click on a tooltip's trigger closes the tooltip, whatever else it does.
		var tip = rxTipTrigger(target);
		if (tip) { rxTipClose(tip); }

		// A dialog's own close button, or a menu item — both close what holds them.
		var dismiss = target.closest("[data-dxai-dismiss]");
		if (dismiss && rxOwns(dismiss)) {
			var dialog = dismiss.closest('[role="dialog"], [role="alertdialog"]');
			var owner = dialog ? rxTriggerOf(dialog) : null;
			if (owner) { event.preventDefault(); rxCloseOverlay(owner); return; }
		}
		var item = target.closest('[role="menuitem"], [role="menuitemcheckbox"], [role="menuitemradio"]');
		if (item && rxOwns(item)) {
			var menu = item.closest('[role="menu"]');
			var menuTrigger = menu ? rxTriggerOf(menu) : null;
			if (menuTrigger) { rxCloseOverlay(menuTrigger); }
			return;
		}

		var option = target.closest('[role="option"]');
		if (option && rxOwns(option)) { event.preventDefault(); rxChoose(option); return; }

		var popup = target.closest("[aria-haspopup]");
		if (popup && rxOwns(popup)) {
			var kind = popup.getAttribute("aria-haspopup");
			var panel = rxFind(popup.getAttribute("aria-controls"));
			if (kind === "dialog" && (rxIsPopper(panel) ? rxPopover(popup) : rxDialog(popup))) { event.preventDefault(); return; }
			if (kind === "menu" && rxMenu(popup)) { event.preventDefault(); return; }
		}

		var combo = target.closest('[role="combobox"]');
		if (combo && rxOwns(combo) && rxSelect(combo)) { event.preventDefault(); return; }

		var tab = target.closest('[role="tab"]');
		if (tab && rxOwns(tab) && rxTabs(tab)) { event.preventDefault(); return; }

		var acc = target.closest("[aria-expanded][id]");
		if (acc && rxOwns(acc) && !acc.hasAttribute("aria-haspopup") && rxAccordion(acc)) { event.preventDefault(); }
	}
	root.addEventListener("click", rxClick);

	function rxOpenOverlays() {
		return [].slice.call(root.querySelectorAll('[aria-haspopup][aria-expanded="true"]'));
	}
	function rxOpenSelects() {
		return [].slice.call(root.querySelectorAll('[role="combobox"][aria-expanded="true"]'));
	}

	// Escape closes whatever is open, and a click elsewhere does too — both are
	// Radix behaviours a half-open panel would otherwise keep on the page.
	document.addEventListener("keydown", function (event) {
		if (event.key !== "Escape") { return; }
		rxOpenSelects().forEach(function (trigger) {
			var list = rxFind(trigger.getAttribute("aria-controls"));
			if (list) { rxSelectClose(trigger, list); }
		});
		rxOpenOverlays().forEach(rxCloseOverlay);
		rxOpenTips().forEach(rxTipClose);
	});
	document.addEventListener("click", function (event) {
		rxOpenSelects().forEach(function (trigger) {
			var list = rxFind(trigger.getAttribute("aria-controls"));
			if (!list) { return; }
			if (trigger.contains(event.target) || list.contains(event.target)) { return; }
			rxSelectClose(trigger, list);
		});
		rxOpenOverlays().forEach(function (trigger) {
			var content = rxFind(trigger.getAttribute("aria-controls"));
			if (!content) { return; }
			if (trigger.contains(event.target) || content.contains(event.target)) { return; }
			// Clicking the overlay closes a dialog; clicking anywhere outside
			// closes a menu. Both are "outside the content".
			rxCloseOverlay(trigger);
		});
	}, true);

	// Arrow keys move between tabs, which is Radix's automatic activation.
	root.addEventListener("keydown", function (event) {
		var tab = event.target && event.target.closest ? event.target.closest('[role="tab"]') : null;
		if (!tab || !root.contains(tab)) { return; }
		var step = event.key === "ArrowRight" ? 1 : (event.key === "ArrowLeft" ? -1 : 0);
		if (!step && event.key !== "Home" && event.key !== "End") { return; }
		var group = rxGroup(tab);
		var tabs = [].slice.call(root.querySelectorAll('[role="tab"][id^="' + group + '-trigger-"]'));
		if (!tabs.length) { return; }
		var at = tabs.indexOf(tab);
		var next = event.key === "Home" ? 0
			: (event.key === "End" ? tabs.length - 1 : (at + step + tabs.length) % tabs.length);
		event.preventDefault();
		rxTabs(tabs[next]);
		if (tabs[next].focus) { tabs[next].focus(); }
	});
	});
})();
JS;
	}

	/**
	 * Entrance state for scroll-reveal sections.
	 *
	 * Gated on `.is-armed`, which the runtime sets on each element as it starts
	 * observing it — never on an ancestor. A section is therefore hidden only
	 * while something is actually watching to reveal it: no JavaScript, a dead
	 * observer, or an element the runtime never reached all leave it visible.
	 * The design's own transition/duration/easing utilities supply the timing.
	 */
	public static function css( string $scope ): string {
		$scope = trim( $scope );
		if ( $scope === '' ) {
			return '';
		}

		return "\n/* scroll reveal */\n"
			. $scope . ' .dxai-reveal.is-armed:not(.is-in)'
			. " { opacity: 0; transform: translateY(1rem); }\n"
			. '@media (prefers-reduced-motion: reduce) { ' . $scope . ' .dxai-reveal.is-armed:not(.is-in)'
			. " { opacity: 1; transform: none; } }\n"
			// Hide Motion_Runtime toggle panels only. Do NOT blanket `.hidden` —
			// Tailwind responsive pairs like `hidden md:block` must still win.
			. $scope . " [class*='dxai-on-'].hidden, " . $scope . " [class*='dxai-off-'].hidden { display: none !important; }\n";
	}

	public static function should_attach( string $markup, string $css ): string {
		$hay = $markup . ' ' . $css;
		if (
			str_contains( $hay, 'rv-' )
			|| str_contains( $hay, 'data-countup' )
			|| str_contains( $hay, 'is-in' )
			|| str_contains( $hay, 'dxai-reveal' )
			|| str_contains( $hay, 'dxai-toggle-' )
			|| str_contains( $hay, 'dxai-on-' )
			|| str_contains( $hay, 'dxai-cls-' )
			|| str_contains( $hay, 'data-lucide' )
			/*
			 * The Radix markers. Without them a page whose only interactive
			 * content is an accordion or a tab set got no runtime at all —
			 * it attached only because that page happened to carry icons.
			 */
			|| str_contains( $hay, 'aria-expanded' )
			|| str_contains( $hay, 'role="tab"' )
			|| str_contains( $hay, 'role="combobox"' )
			|| str_contains( $hay, 'data-state' )
		) {
			return self::javascript();
		}

		return '';
	}
}
