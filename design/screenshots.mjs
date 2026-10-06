#!/usr/bin/env node
/**
 * Captures the WordPress.org listing screenshots from the Playground test store.
 *
 * Start a fresh store first (see README.md), then run: node design/screenshots.mjs
 * It seeds the sample shop, works on its order with real mouse and key events, and writes
 * .wordpress-org/screenshot-1.png to screenshot-5.png. The order is changed along the way, so
 * start the store again before another run.
 *
 * Needs Node 22 or later and Google Chrome. Set CHROME_BIN or IOEFW_STORE to override the defaults.
 */
import { spawn } from 'node:child_process';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const STORE = process.env.IOEFW_STORE || 'http://127.0.0.1:9412';
const CHROME =
	process.env.CHROME_BIN ||
	'/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const OUT = join(
	dirname( fileURLToPath( import.meta.url ) ),
	'..',
	'.wordpress-org'
);
const PORT = 9334;
const VIEWPORT = { width: 1280, height: 860 };

const KEYS = {
	Enter: { code: 'Enter', keyCode: 13, text: '\r' },
	Tab: { code: 'Tab', keyCode: 9 },
	Escape: { code: 'Escape', keyCode: 27 },
	ArrowDown: { code: 'ArrowDown', keyCode: 40 },
};

const sleep = ( ms ) => new Promise( ( resolve ) => setTimeout( resolve, ms ) );

const profile = mkdtempSync( join( tmpdir(), 'ioefw-screenshots-' ) );
const chrome = spawn(
	CHROME,
	[
		'--headless=new',
		`--remote-debugging-port=${ PORT }`,
		`--user-data-dir=${ profile }`,
		`--window-size=${ VIEWPORT.width },${ VIEWPORT.height }`,
		'--hide-scrollbars',
		'--no-first-run',
		'--disable-gpu',
		'about:blank',
	],
	{ stdio: 'ignore' }
);

async function connect() {
	for ( let attempt = 0; attempt < 50; attempt++ ) {
		try {
			const targets = await (
				await fetch( `http://127.0.0.1:${ PORT }/json/list` )
			 ).json();
			const page = targets.find( ( target ) => 'page' === target.type );
			if ( page ) {
				return new WebSocket( page.webSocketDebuggerUrl );
			}
		} catch {
			// Chrome is still starting.
		}
		await sleep( 200 );
	}
	throw new Error( 'Chrome did not start.' );
}

const socket = await connect();
await new Promise( ( resolve ) => socket.addEventListener( 'open', resolve ) );

let lastId = 0;
const pending = new Map();
const waiting = new Map();

socket.addEventListener( 'message', ( event ) => {
	const message = JSON.parse( event.data );

	if ( message.id && pending.has( message.id ) ) {
		const { resolve, reject } = pending.get( message.id );
		pending.delete( message.id );
		if ( message.error ) {
			reject( new Error( message.error.message ) );
		} else {
			resolve( message.result );
		}
	} else if ( message.method && waiting.has( message.method ) ) {
		const resolvers = waiting.get( message.method );
		waiting.delete( message.method );
		resolvers.forEach( ( resolve ) => resolve( message.params ) );
	}
} );

const send = ( method, params = {} ) =>
	new Promise( ( resolve, reject ) => {
		const id = ++lastId;
		pending.set( id, { resolve, reject } );
		socket.send( JSON.stringify( { id, method, params } ) );
	} );

const once = ( method ) =>
	new Promise( ( resolve ) => {
		waiting.set( method, [ ...( waiting.get( method ) || [] ), resolve ] );
	} );

async function goTo( path ) {
	const loaded = once( 'Page.loadEventFired' );
	await send( 'Page.navigate', { url: STORE + path } );
	await loaded;
	await sleep( 1500 );
}

// Runs a function in the page. It must be self-contained: only its arguments cross over.
async function inPage( fn, ...args ) {
	const { result, exceptionDetails } = await send( 'Runtime.evaluate', {
		expression: `(${ fn })(...${ JSON.stringify( args ) })`,
		awaitPromise: true,
		returnByValue: true,
	} );

	if ( exceptionDetails ) {
		throw new Error(
			exceptionDetails.exception?.description || exceptionDetails.text
		);
	}

	return result.value;
}

async function waitFor( what, fn, ...args ) {
	for ( let attempt = 0; attempt < 120; attempt++ ) {
		if ( await inPage( fn, ...args ) ) {
			return;
		}
		await sleep( 250 );
	}
	throw new Error( `Timed out waiting for ${ what }.` );
}

async function press( key ) {
	const { code, keyCode, text } = KEYS[ key ];
	const event = { key, code, windowsVirtualKeyCode: keyCode, nativeVirtualKeyCode: keyCode };

	await send( 'Input.dispatchKeyEvent', { type: text ? 'keyDown' : 'rawKeyDown', text, ...event } );
	await send( 'Input.dispatchKeyEvent', { type: 'keyUp', ...event } );
	await sleep( 150 );
}

async function type( text ) {
	for ( const character of text ) {
		await send( 'Input.insertText', { text: character } );
		await sleep( 25 );
	}
}

// The middle of an element, in viewport coordinates, after scrolling it into view.
const middleOf = ( selector ) =>
	inPage( ( query ) => {
		const element = document.querySelector( query );
		element.scrollIntoView( { block: 'center' } );
		const box = element.getBoundingClientRect();
		return { x: box.left + box.width / 2, y: box.top + box.height / 2 };
	}, selector );

async function click( selector, clicks = 1 ) {
	const { x, y } = await middleOf( selector );

	for ( let clickCount = 1; clickCount <= clicks; clickCount++ ) {
		await send( 'Input.dispatchMouseEvent', { type: 'mousePressed', x, y, button: 'left', clickCount } );
		await send( 'Input.dispatchMouseEvent', { type: 'mouseReleased', x, y, button: 'left', clickCount } );
	}

	await sleep( 200 );
}

// Scrolls so the element sits a little below the admin bar, and parks the pointer out of the way.
async function frame( selector, offset = 70 ) {
	await inPage(
		( query, gap ) => {
			const top = document.querySelector( query ).getBoundingClientRect().top + window.scrollY;
			window.scrollTo( 0, Math.max( 0, top - gap ) );
		},
		selector,
		offset
	);
	await send( 'Input.dispatchMouseEvent', { type: 'mouseMoved', x: VIEWPORT.width - 4, y: VIEWPORT.height - 4 } );
	await sleep( 300 );
}

async function capture( number ) {
	const { data } = await send( 'Page.captureScreenshot', { format: 'png' } );
	writeFileSync(
		join( OUT, `screenshot-${ number }.png` ),
		Buffer.from( data, 'base64' )
	);
	console.log( `screenshot-${ number }.png` );
}

// The plugin fires ioefw:saved on the document after each save. Count them, to know when one is in.
const countSaves = () =>
	inPage( () => {
		if ( undefined === window.ioefwSaves ) {
			window.ioefwSaves = 0;
			document.addEventListener( 'ioefw:saved', () => window.ioefwSaves++ );
		}
		return window.ioefwSaves;
	} );

const saved = ( before ) =>
	waitFor(
		'the change to be saved',
		( count ) => window.ioefwSaves > count && ! document.querySelector( '.ioefw-input, .ioefw-add__row--saving' ),
		before
	);

const ITEMS = '#woocommerce-order-items';
const NAME = `${ ITEMS } .ioefw-add__name`;

try {
	await send( 'Page.enable' );
	await send( 'Runtime.enable' );
	await send( 'Emulation.setDeviceMetricsOverride', {
		...VIEWPORT,
		deviceScaleFactor: 1,
		mobile: false,
	} );

	// The store logs the first visitor in.
	await goTo( '/' );
	await goTo( '/wp-admin/admin-post.php?action=ioefw_dev&script=seed' );

	const order = await inPage( () => ( document.body.innerText.match( /SEED_ORDER=(\d+)/ ) || [] )[ 1 ] );

	if ( ! order ) {
		throw new Error( 'The seed script did not report its order.' );
	}

	await goTo( `/wp-admin/admin.php?page=wc-orders&action=edit&id=${ order }` );
	await waitFor( 'the new row', ( query ) => !! document.querySelector( query ), NAME );

	// 1. The empty row, with a product suggested from the catalogue.
	await click( NAME );
	await type( 'tul' );
	await waitFor( 'a catalogue suggestion', () => !! document.querySelector( '.ioefw-suggestions__option--product' ) );
	await press( 'ArrowDown' );
	await frame( ITEMS );
	await capture( 1 );

	await press( 'Escape' );
	await inPage( ( query ) => {
		const name = document.querySelector( query );
		name.value = '';
		name.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	}, NAME );

	// 2. A quantity being changed where it stands.
	await click( `${ ITEMS } #order_line_items tr.item .ioefw-cell[data-ioefw-field="quantity"]`, 2 );
	await waitFor( 'the value to open', () => !! document.querySelector( '.ioefw-input' ) );
	await type( '3' );
	await frame( ITEMS );
	await capture( 2 );
	let saves = await countSaves();
	await press( 'Enter' );
	await saved( saves );

	// 3. A typed item and a delivery charge, added from the empty row.
	await click( NAME );
	await type( 'Glass vase rental' );
	await press( 'Tab' );
	await type( '12.50' );
	await press( 'Tab' );
	await type( '2' );
	saves = await countSaves();
	await press( 'Enter' );
	await saved( saves );
	await waitFor( 'the typed item', () => /Glass vase rental/.test( document.querySelector( '#order_line_items' ).innerText ) );

	await click( NAME );
	await type( 'Courier' );
	await waitFor( 'the suggestions', () => document.querySelectorAll( '.ioefw-suggestions__option' ).length >= 2 );
	await inPage( () => document.querySelector( '.ioefw-suggestions__option--delivery' ).click() );
	await type( '8' );
	saves = await countSaves();
	await press( 'Enter' );
	await saved( saves );
	await waitFor( 'the delivery charge', () => !! document.querySelector( '#order_shipping_line_items tr.shipping' ) );
	await inPage( () => document.activeElement.blur() );
	await frame( ITEMS );
	// The toast that says what the change did to the total, once it has slid in.
	await waitFor( 'the saved message', () => /Saved\./.test( ( document.querySelector( '.components-snackbar, .ioefw-toast' ) || {} ).textContent || '' ) );
	await sleep( 700 );
	await capture( 3 );

	// 4. The Order terms box.
	await frame( '#ioefw-order-terms', 120 );
	await capture( 4 );

	// 5. The terms in the email the customer gets.
	await goTo( `/wp-admin/admin-post.php?action=ioefw_dev&script=email&order=${ order }` );
	await frame( '.ioefw-order-terms', 520 );
	await capture( 5 );
} finally {
	socket.close();
	chrome.kill();
	await sleep( 300 );
	rmSync( profile, { recursive: true, force: true } );
}
