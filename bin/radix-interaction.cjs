#!/usr/bin/env node
/**
 * Does a click on a converted Radix component actually do something?
 *
 * Geometry cannot see this at all: a tab set with dead triggers has exactly
 * the same boxes as a working one, and the accordion on every converted page
 * measured 0px while no panel would ever open. The state attributes landing in
 * the markup is not the same as the page responding to a click, so the click
 * is performed.
 *
 * Asserted against the component's own contract rather than against a
 * recording, so the check stays true if the design changes:
 *
 *   accordion  a closed trigger opens, its panel becomes visible, and for
 *              `type="single"` the previously open one closes
 *   tabs       clicking an inactive tab makes it the only active one and its
 *              panel the only visible one; ArrowRight moves on
 *   select     the list opens, choosing an option puts that option's text on
 *              the trigger and closes the list
 *   menu       the trigger opens a menu; choosing an item closes it
 *   dialog     the trigger opens a centred panel over a backdrop; Escape and
 *              its own close button close it
 *   popover    the trigger opens a panel anchored to itself and focus moves
 *              in; a click elsewhere closes it, and so does the trigger again
 *   tooltip    hovering opens it after Radix's delay, focus opens it at once;
 *              leaving, Escape and a click on the trigger all close it
 *
 * Two shapes of component reach this probe. A shadcn/Radix one carries
 * `data-state` at rest and is held to Radix's whole contract. A design's own
 * component — a `useState` tab strip with `role="tab"` and `aria-selected`, a
 * disclosure button with `aria-expanded` and nothing else — has only the ARIA
 * its author wrote, and is held to that: what it publishes has to change and
 * what it controls has to appear. Asserting `data-state` on those failed
 * three real designs whose components work perfectly well.
 *
 * Real mouse events, not element.click(): Radix listens on mousedown and
 * focus, so a synthetic click leaves the design side inert and would make a
 * broken conversion look identical to a working one.
 *
 *   node bin/radix-interaction.cjs <url>… [--chrome <path>]
 *
 * Exits 1 when a component is present and inert. A page with none of them is
 * reported as having nothing to drive, never as a pass.
 */

'use strict';

const path = require('path');

const puppeteer = require(path.join(__dirname, '..', 'node_modules', 'puppeteer-core'));

function arg(name, fallback) {
	const i = process.argv.indexOf('--' + name);
	return i > -1 && process.argv[i + 1] ? process.argv[i + 1] : fallback;
}

/*
 * Disclosure triggers: a Radix accordion's, or a design's own. Not a tab (a
 * design's tab strip may carry aria-expanded as well), not a combobox, not
 * anything that opens a popup — each of those is driven by its own section.
 */
const ACCORDION = 'button[aria-expanded]:not([role]):not([aria-haspopup])';

/*
 * Tooltip triggers. The only mark Radix leaves on one at rest is
 * `data-state="closed"` — no role, no aria-expanded, no popup — so that is
 * what identifies it. A tooltip on a button that also opens a dialog is not
 * reached this way; it is driven as the dialog's trigger instead.
 */
const TOOLTIP = 'button[data-state="closed"]:not([aria-expanded]):not([aria-haspopup]):not([role])';

const READ = () => {
	/*
	 * A page can carry more than one tab strip — a case-study switcher and a
	 * stage stepper on the same design — and each keeps its own active tab,
	 * so "exactly one active" only holds within a strip. A strip is the
	 * `role="tablist"` a tab sits in, or its parent when a design's own
	 * markup has no tablist.
	 */
	const tabs = [...document.querySelectorAll('[role="tab"]')];
	const strips = [];
	const stripOf = (t) => {
		const key = t.closest('[role="tablist"]') || t.parentElement;
		if (!strips.includes(key)) { strips.push(key); }
		return strips.indexOf(key);
	};
	const tabRows = tabs.map((t) => ({
		id: t.id,
		label: t.textContent.trim().slice(0, 20),
		state: t.getAttribute('data-state'),
		selected: t.getAttribute('aria-selected'),
		radix: t.hasAttribute('data-state'),
		group: stripOf(t),
	}));
	return {
	tabs: tabRows,
	panels: [...document.querySelectorAll('[role="tabpanel"]')].map((p) => {
		const by = p.getAttribute('aria-labelledby');
		const owner = by ? tabRows.find((t) => t.id === by) : null;
		return {
			state: p.getAttribute('data-state'),
			visible: p.getBoundingClientRect().height > 10,
			by,
			group: owner ? owner.group : -1,
		};
	}),
	// The same selector as ACCORDION, which this page-side function cannot see.
	accordions: [...document.querySelectorAll('button[aria-expanded]:not([role]):not([aria-haspopup])')].map((t) => {
		const host = t.closest('[data-dxai-accordion]');
		return {
			id: t.id,
			expanded: t.getAttribute('aria-expanded'),
			state: t.getAttribute('data-state'),
			radix: t.hasAttribute('data-state'),
			// Which accordion it belongs to, and whether that one stacks.
			group: host ? (host.getAttribute('data-dxai-accordion') || 'single') : '',
			root: host ? [...document.querySelectorAll('[data-dxai-accordion]')].indexOf(host) : -1,
		};
	}),
	combos: [...document.querySelectorAll('[role="combobox"]')].map((c) => ({
		text: (c.innerText || '').trim().split('\n')[0],
		expanded: c.getAttribute('aria-expanded'),
	})),
	options: [...document.querySelectorAll('[role="option"]')]
		.filter((o) => o.getBoundingClientRect().height > 4).length,
	};
};

async function check(browser, url, chrome) {
	const page = await browser.newPage();
	await page.setViewport({ width: 1280, height: 1000 });
	const errors = [];
	page.on('pageerror', (e) => errors.push(String(e).slice(0, 80)));
	await page.goto(url, { waitUntil: 'load', timeout: 60000 });
	await new Promise((r) => setTimeout(r, 1200));

	const slug = url.replace(/\/$/, '').split('/').pop();
	const out = { slug, drove: 0, failures: [], errors: errors.length, first: '' };
	const settle = (ms) => new Promise((r) => setTimeout(r, ms || 450));

	/*
	 * Whether a handle is something a person could click: it has to have a box.
	 * `display:none`, a zero-size wrapper and a detached node all answer no,
	 * and so does a handle that has gone stale, which is why the read is
	 * guarded rather than trusted.
	 */
	const boxed = async (handle) => {
		if (!handle) {
			return false;
		}
		try {
			const box = await handle.boundingBox();
			return Boolean(box) && box.width >= 1 && box.height >= 1;
		} catch ( e ) {
			return false;
		}
	};

	/*
	 * The first candidate that both matches and can be clicked. `rows` and
	 * `handles` come from the same selector, so their indices line up; this
	 * returns the index into both, or -1 when the page offers none at this
	 * width.
	 */
	const firstShown = async ( rows, handles, want ) => {
		for ( let i = 0; i < rows.length; i++ ) {
			if ( want( rows[ i ], i ) && await boxed( handles[ i ] ) ) {
				return i;
			}
		}
		return -1;
	};

	const before = await page.evaluate(READ);

	/* ------------------------------------------------------- accordion --- */
	if (before.accordions.length) {
		const accordionHandles = await page.$$(ACCORDION);
		const closed = await firstShown(
			before.accordions,
			accordionHandles,
			(a) => a.expanded === 'false'
		);
		if (closed > -1) {
			const handles = accordionHandles;
			await handles[closed].click();
			await settle();
			const after = await page.evaluate(READ);
			const trigger = after.accordions[closed];
			out.drove++;
			if (trigger.expanded !== 'true' || (trigger.radix && trigger.state !== 'open')) {
				out.failures.push('accordion trigger did not open: aria-expanded=' + trigger.expanded
					+ (trigger.radix ? ' data-state=' + trigger.state : ''));
			} else {
				/*
				 * The panel: what aria-controls names, or — for a design's own
				 * disclosure, which names nothing — the element that follows
				 * the trigger.
				 */
				const shown = await page.evaluate((el) => {
					const id = el.getAttribute('aria-controls');
					const named = id ? document.getElementById(id) : null;
					const panel = named || el.nextElementSibling
						|| (el.parentElement && el.parentElement.nextElementSibling);
					return panel
						? { h: panel.getBoundingClientRect().height, state: panel.getAttribute('data-state'), named: Boolean(named) }
						: null;
				}, handles[closed]);
				if (!shown) {
					out.failures.push('accordion trigger opened but no panel follows it');
				} else if (shown.h <= 10) {
					out.failures.push('accordion panel stayed collapsed (' + Math.round(shown.h) + 'px)');
				} else if (trigger.radix && !shown.named) {
					out.failures.push('accordion trigger opened but names no panel');
				} else if (trigger.radix && shown.state !== 'open') {
					out.failures.push('accordion panel data-state=' + shown.state);
				}
			}

			/*
			 * A SECOND item of the same accordion, which is where `single` and
			 * `multiple` part company: single closes the first, multiple keeps
			 * both open. Radix holds `type` in the root's props, so a
			 * converted page can only get this right if the converter
			 * published it — this is the assertion that it did. A design's
			 * own disclosures have no such rule to be held to.
			 */
			const opened = await page.evaluate(READ);
			const first = opened.accordions[closed];
			const next = first.radix ? opened.accordions.findIndex(
				(a, i) => i !== closed && a.root === first.root && a.expanded === 'false'
			) : -1;
			if (next > -1 && await boxed(( await page.$$(ACCORDION) )[ next ])) {
				const handles = await page.$$(ACCORDION);
				await handles[next].click();
				await settle();
				const after = await page.evaluate(READ);
				out.drove++;
				if (after.accordions[next].expanded !== 'true') {
					out.failures.push('a second accordion item would not open');
				}
				const stillOpen = after.accordions[closed].expanded === 'true';
				if (first.group === 'multiple' && !stillOpen) {
					out.failures.push('type="multiple" closed the first panel when the second opened');
				}
				if (first.group === 'single' && stillOpen) {
					out.failures.push('type="single" left two panels open');
				}
			}
		}
	}

	/* ------------------------------------------------------------ tabs --- */
	if (before.tabs.length > 1) {
		// Nothing from the previous section may still be covering a tab.
		await page.keyboard.press('Escape');
		await settle();
		// Radix marks the active tab in data-state; a design's own strip only
		// in aria-selected, which is the one thing every tab strip must have.
		// Judged within the clicked tab's own strip: another strip on the same
		// page keeps its own active tab, and counting that failed a design
		// with a case-study switcher and a stage stepper side by side.
		const tabHandles = await page.$$('[role="tab"]');
		const target = await firstShown(
			before.tabs,
			tabHandles,
			(t) => (t.radix ? t.state !== 'active' : t.selected !== 'true')
		);
		if (target > -1) {
			const group = before.tabs[target].group;
			const strip = (rows) => rows.filter((t) => t.group === group);
			const radix = strip(before.tabs).every((t) => t.radix);
			const isOn = (t) => (radix ? t.state === 'active' : t.selected === 'true');
			const handles = tabHandles;
			await handles[target].click();
			await settle();
			let after = await page.evaluate(READ);
			out.drove++;
			const active = strip(after.tabs).filter(isOn);
			if (active.length !== 1 || !isOn(after.tabs[target])) {
				out.failures.push('clicking a tab left ' + active.length + ' active in its strip: '
					+ strip(after.tabs).map((t) => t.label + '=' + (radix ? t.state : t.selected)).join(' '));
			}
			const panels = strip(after.panels);
			if (panels.length) {
				const visible = panels.filter((p) => p.visible);
				if (visible.length !== 1) {
					out.failures.push(visible.length + ' tab panels visible after a click, expected 1');
				} else if (radix && visible[0].state !== 'active') {
					out.failures.push('the visible panel is not the clicked tab\'s');
				}
			}

			// The keyboard path, which is how Radix is operated without a
			// mouse. A design's own strip promises no such thing.
			if (radix) {
				await handles[target].focus();
				await page.keyboard.press('ArrowRight');
				await settle();
				after = await page.evaluate(READ);
				const moved = after.tabs.findIndex((t) => t.group === group && t.state === 'active');
				if (moved === target) {
					out.failures.push('ArrowRight did not move the active tab');
				}
			}
		}
	}

	/* ---------------------------------------------------------- select --- */
	const comboHandles = before.combos.length ? await page.$$('[role="combobox"]') : [];
	if (before.combos.length && await boxed(comboHandles[0])) {
		const handles = comboHandles;
		const label = before.combos[0].text;
		await handles[0].click();
		await settle();
		const open = await page.evaluate(READ);
		out.drove++;
		if (open.combos[0].expanded !== 'true') {
			out.failures.push('the select did not report itself open');
		}
		if (open.options < 1) {
			out.failures.push('the select opened with no visible options');
		} else {
			const options = await page.$$('[role="option"]');
			// The second one, so the text is guaranteed to change.
			const pick = options[1] || options[0];
			const picked = (await page.evaluate((el) => el.textContent.trim(), pick));
			await pick.click();
			await settle();
			const after = await page.evaluate(READ);
			if (after.combos[0].expanded !== 'false') {
				out.failures.push('the select stayed open after choosing');
			}
			if (after.combos[0].text !== picked) {
				out.failures.push('the trigger reads "' + after.combos[0].text
					+ '" after choosing "' + picked + '" (was "' + label + '")');
			}
		}
	}

	/* -------------------------------------------------------- dropdown --- */
	const readMenu = () => page.evaluate(() => {
		const t = document.querySelector('[aria-haspopup="menu"]');
		const m = document.querySelector('[role="menu"]');
		return {
			trigger: t ? { expanded: t.getAttribute('aria-expanded'), state: t.getAttribute('data-state') } : null,
			menuVisible: m ? m.getBoundingClientRect().height > 10 : null,
			menuState: m ? m.getAttribute('data-state') : null,
			items: [...document.querySelectorAll('[role="menuitem"]')].filter((i) => i.getBoundingClientRect().height > 4).length,
		};
	});
	const menuAtRest = await readMenu();
	const menuTrigger = menuAtRest.trigger ? await page.$('[aria-haspopup="menu"]') : null;
	if (menuAtRest.trigger && await boxed(menuTrigger)) {
		await page.keyboard.press('Escape');
		await settle();
		const trig = menuTrigger;
		await trig.click();
		await settle();
		const open = await readMenu();
		out.drove++;
		if (open.trigger.expanded !== 'true' || open.trigger.state !== 'open') {
			out.failures.push('the menu trigger did not report itself open');
		}
		if (!open.menuVisible) {
			out.failures.push('the menu did not become visible');
		}
		if (open.items < 1) {
			out.failures.push('the menu opened with no visible items');
		} else {
			const items = await page.$$('[role="menuitem"]');
			await items[0].click();
			await settle();
			const after = await readMenu();
			if (after.menuVisible) {
				out.failures.push('choosing a menu item left the menu open');
			}
			if (after.trigger.expanded !== 'false') {
				out.failures.push('the menu trigger still reports open after choosing');
			}
		}
	}

	/* ---------------------------------------------- dialog and popover --- */
	/*
	 * Both announce `aria-haspopup="dialog"`, and only the open panel says
	 * which it is: a popover's is anchored to its trigger and names the side
	 * it opened on (`data-side`); a dialog's is centred over a backdrop. So
	 * every such trigger is opened first and judged by what appeared.
	 */
	const readPanel = (handle) => page.evaluate((t) => {
		const id = t.getAttribute('aria-controls');
		const d = id ? document.getElementById(id) : null;
		const shown = d && d.getBoundingClientRect().height > 20 && getComputedStyle(d).display !== 'none';
		const base = { expanded: t.getAttribute('aria-expanded'), state: t.getAttribute('data-state'), shown: Boolean(shown) };
		if (!shown) { return base; }
		const tb = t.getBoundingClientRect();
		const db = d.getBoundingClientRect();
		const side = d.getAttribute('data-side');
		const align = d.getAttribute('data-align');
		// The backdrop: ours is paired by id, Radix's is the sibling before the panel.
		const ours = d.id ? document.getElementById(d.id.replace('-content-', '-overlay-')) : null;
		const prev = d.previousElementSibling;
		const overlay = ours || (prev && prev.hasAttribute('data-state') && !prev.hasAttribute('role') ? prev : null);
		const gap = side === 'bottom' ? db.top - tb.bottom
			: side === 'top' ? tb.top - db.bottom
				: side === 'right' ? db.left - tb.right
					: side === 'left' ? tb.left - db.right : null;
		const slip = align === 'start' ? Math.abs(db.left - tb.left)
			: align === 'end' ? Math.abs(db.right - tb.right)
				: Math.abs((db.left + db.width / 2) - (tb.left + tb.width / 2));
		return Object.assign(base, {
			role: d.getAttribute('role'),
			side,
			align,
			// The panel must sit over the page, not inside a section: a fixed
			// element under a transformed ancestor is positioned against it.
			centred: Math.abs((db.left + db.width / 2) - window.innerWidth / 2) < 8,
			overlayShown: overlay ? overlay.getBoundingClientRect().height > 50 : null,
			gap,
			slip,
			// Slid along the trigger to stay on screen, which Radix does too.
			atEdge: db.left <= 1 || db.right >= window.innerWidth - 1,
			focusInside: d.contains(document.activeElement),
		});
	}, handle);

	const popupTriggers = await page.$$('[aria-haspopup="dialog"]');
	for (const trig of popupTriggers) {
		if (!await boxed(trig)) {
			continue;
		}
		await page.keyboard.press('Escape');
		await settle();
		const name = await page.evaluate((el) => (el.textContent || '').trim().slice(0, 24), trig);
		await trig.click();
		await settle();
		const open = await readPanel(trig);
		out.drove++;
		if (open.expanded !== 'true' || !open.shown) {
			out.failures.push('"' + name + '" did not open its panel (aria-expanded=' + open.expanded + ')');
			continue;
		}

		if (open.side) {
			/* popover */
			if (open.role !== 'dialog') { out.failures.push('the popover panel has role=' + open.role); }
			if (open.gap === null || open.gap < 0 || open.gap > 12) {
				out.failures.push('the popover sits ' + Math.round(open.gap) + 'px from its trigger on side ' + open.side);
			}
			if (!open.atEdge && open.slip > 2) {
				out.failures.push('the popover is ' + Math.round(open.slip) + 'px off its ' + open.align + ' alignment');
			}
			if (!open.focusInside) { out.failures.push('opening the popover did not move focus into it'); }

			// A click elsewhere closes it — which is what makes it a popover
			// rather than a dialog — and so does its own trigger.
			await page.click('h1');
			await settle();
			const outside = await readPanel(trig);
			if (outside.shown) { out.failures.push('a click outside did not close the popover'); }
			await trig.click();
			await settle();
			await trig.click();
			await settle();
			const toggled = await readPanel(trig);
			if (toggled.shown || toggled.expanded !== 'false') {
				out.failures.push('clicking the trigger again did not close the popover');
			}
			continue;
		}

		/* dialog */
		if (open.role !== 'dialog' && open.role !== 'alertdialog') { out.failures.push('the dialog panel has role=' + open.role); }
		if (open.overlayShown === false) { out.failures.push('the dialog overlay stayed hidden'); }
		if (!open.centred) { out.failures.push('the dialog is not centred on the viewport'); }

		await page.keyboard.press('Escape');
		await settle();
		const closed = await readPanel(trig);
		if (closed.shown) { out.failures.push('Escape did not close the dialog'); }

		// Open again and use its own close button.
		await trig.click();
		await settle();
		/*
		 * Found the way a person finds it, not by our own marker: the button
		 * inside the dialog that says "Close". `data-dxai-dismiss` is what the
		 * converted page carries and Radix does not, so requiring it made the
		 * REAL app fail this assertion — the one thing a contract check must
		 * never do.
		 */
		const close = await page.evaluateHandle((t) => {
			const dialog = document.getElementById(t.getAttribute('aria-controls') || '');
			if (!dialog) { return null; }
			return dialog.querySelector('[data-dxai-dismiss]')
				|| [...dialog.querySelectorAll('button')].find((b) => /\bclose\b/i.test(b.textContent || ''))
				|| null;
		}, trig);
		const hasClose = await page.evaluate((el) => el !== null, close);
		if (!hasClose) {
			out.failures.push('the dialog has no close button');
		} else {
			await close.click();
			await settle();
			const after = await readPanel(trig);
			if (after.shown) { out.failures.push('the close button did not close the dialog'); }
		}
	}

	/* --------------------------------------------------------- tooltip --- */
	const readTip = (handle) => page.evaluate((t) => {
		const id = t.getAttribute('aria-describedby');
		const tip = id ? document.getElementById(id) : null;
		const shown = tip && tip.getBoundingClientRect().height > 10 && getComputedStyle(tip).display !== 'none';
		const tb = t.getBoundingClientRect();
		const cb = shown ? tip.getBoundingClientRect() : null;
		const side = tip ? tip.getAttribute('data-side') : null;
		return {
			state: t.getAttribute('data-state'),
			role: tip ? tip.getAttribute('role') : null,
			text: tip ? (tip.textContent || '').trim() : '',
			shown: Boolean(shown),
			side,
			gap: cb ? (side === 'bottom' ? cb.top - tb.bottom : side === 'top' ? tb.top - cb.bottom : null) : null,
			onScreen: cb ? cb.left >= -1 && cb.right <= window.innerWidth + 1 : null,
		};
	}, handle);

	const tipTriggers = await page.$$(TOOLTIP);
	for (const trig of tipTriggers) {
		if (!await boxed(trig)) {
			continue;
		}
		await page.keyboard.press('Escape');
		await page.mouse.move(5, 5, { steps: 3 });
		await settle();
		const name = await page.evaluate((el) => (el.getAttribute('aria-label') || el.textContent || '').trim().slice(0, 24), trig);

		// Hover, and wait out Radix's default 700ms delay.
		await trig.hover();
		await settle(1000);
		const hovered = await readTip(trig);
		out.drove++;
		if (hovered.state !== 'delayed-open' && hovered.state !== 'instant-open') {
			out.failures.push('hovering "' + name + '" left its tooltip data-state=' + hovered.state);
			continue;
		}
		if (hovered.role !== 'tooltip') { out.failures.push('"' + name + '" is described by a ' + hovered.role + ', not a tooltip'); }
		if (!hovered.text) { out.failures.push('the tooltip for "' + name + '" is empty'); }
		if (!hovered.shown) { out.failures.push('the tooltip for "' + name + '" did not become visible'); }
		if (hovered.shown && (hovered.gap === null || hovered.gap < 0 || hovered.gap > 12)) {
			out.failures.push('the tooltip sits ' + Math.round(hovered.gap) + 'px from its trigger on side ' + hovered.side);
		}
		if (hovered.onScreen === false) { out.failures.push('the tooltip runs off the viewport'); }

		/*
		 * Leaving. In steps, as a hand moves: Radix keeps a tooltip open
		 * while the pointer crosses towards it and closes on the first move
		 * elsewhere, so a single jump — no pointermove in between — leaves
		 * the REAL one open and would fail the design side.
		 */
		await page.mouse.move(5, 5, { steps: 12 });
		await settle(500);
		const left = await readTip(trig);
		if (left.state !== 'closed' || left.shown) { out.failures.push('the tooltip stayed open after the pointer left'); }

		// Focus opens it at once; Escape closes it.
		await trig.focus();
		await settle(300);
		const focused = await readTip(trig);
		if (focused.state !== 'instant-open' || !focused.shown) {
			out.failures.push('focusing "' + name + '" did not open its tooltip at once (data-state=' + focused.state + ')');
		}
		await page.keyboard.press('Escape');
		await settle(300);
		const escaped = await readTip(trig);
		if (escaped.state !== 'closed' || escaped.shown) { out.failures.push('Escape did not close the tooltip'); }

		// And a click on the trigger closes an open one.
		await trig.hover();
		await settle(1000);
		await trig.click();
		await settle(300);
		const clicked = await readTip(trig);
		if (clicked.state !== 'closed' || clicked.shown) { out.failures.push('clicking the trigger did not close its tooltip'); }
	}

	out.errors = errors.length;
	out.first = errors[0] || '';
	await page.close();
	return out;
}

(async () => {
	const chrome = arg('chrome', 'C:/Program Files/Google/Chrome/Application/chrome.exe');
	const urls = process.argv.slice(2).filter((a) => a.startsWith('http'));
	if (!urls.length) {
		console.error('usage: node bin/radix-interaction.cjs <url>… [--chrome <path>]');
		process.exit(2);
	}

	const browser = await puppeteer.launch({ executablePath: chrome, headless: 'shell', args: ['--no-sandbox'] });
	let bad = 0;
	let drove = 0;
	for (const url of urls) {
		let row;
		try {
			row = await check(browser, url, chrome);
		} catch (e) {
			row = { slug: url, drove: 0, failures: ['probe threw: ' + String(e).slice(0, 80)], errors: 1, first: '' };
		}
		drove += row.drove;
		const ok = row.failures.length === 0 && row.errors === 0;
		if (!ok) { bad++; }
		console.log(JSON.stringify(row));
	}
	await browser.close();

	console.log('radix-interaction: ' + drove + ' component(s) driven, ' + bad + ' page(s) with a failure');
	process.exit(bad ? 1 : 0);
})();
