/**
 * Inline Order Editor for WooCommerce: the order screen.
 *
 * Adds an always-open row for a new line, lets a line be changed in place, and keeps the totals
 * current. Every change is saved on the server and the Items box is then redrawn from what was
 * saved, so the fields WooCommerce posts with Update always match the order.
 */
( function ( $, wp, settings ) {
	'use strict';

	if ( ! settings || ! wp || ! wp.i18n ) {
		return;
	}

	const __ = wp.i18n.__;
	const sprintf = wp.i18n.sprintf;
	const speak = wp.a11y && wp.a11y.speak ? wp.a11y.speak : function () {};

	// Every WooCommerce selector, event and request name this script relies on, in one place.
	const CORE = {
		box: '#woocommerce-order-items',
		inside: '#woocommerce-order-items .inside',
		table: 'table.woocommerce_order_items',
		lineItems: '#order_line_items',
		feeItems: '#order_fee_line_items',
		shippingItems: '#order_shipping_line_items',
		itemName: '.wc-order-item-name',
		notes: 'ul.order_notes',
		status: '#order_status',
		form: 'form#order, form#post',
		toasts: '.woocommerce-transient-notices',
		reloaded: 'wc_order_items_reloaded',
		reload: 'wc_order_items_reload',
		draftStatus: 'wc-checkout-draft',
		addProduct: 'woocommerce_add_order_item',
	};

	const FIELDS = [ 'name', 'price', 'quantity', 'total' ];

	let state = null; // The order as the server last drew it.
	let busy = false; // One request at a time.
	let editing = null; // The cell being changed.
	let pendingFocus = null; // Where focus goes once the box is redrawn.
	let toastTimer = null;
	let submitting = false; // The order form itself is being saved.
	let pendingSubmit = null; // The order form, held back until a save in flight has answered.
	let lastPointer = 'mouse';
	let searchTimer = null;
	let searchCount = 0;
	let options = [];
	let activeOption = -1;

	// What has been typed in the new row. Kept here so it survives a redraw of the box.
	const draft = {
		name: '',
		price: '',
		quantity: '1',
		note: '',
		taxClass: '',
		kind: 'custom',
		product: null,
	};

	/* ---------- Helpers ---------- */

	function box() {
		return document.querySelector( CORE.box );
	}

	function readState() {
		const element = document.querySelector( CORE.box + ' .ioefw-state' );

		if ( ! element ) {
			return null;
		}

		try {
			return JSON.parse( element.getAttribute( 'data-state' ) );
		} catch ( error ) {
			return null;
		}
	}

	function hasItems() {
		return !! state && Object.keys( state.items || {} ).length > 0;
	}

	// A typed number, read with the store's decimal separator. NaN when it is not a number.
	function parseNumber( text ) {
		// As wc_format_decimal() reads it: the store's separator becomes a dot, and the last dot is the decimal point.
		let value = String( text )
			.split( settings.decimalPoint )
			.join( '.' )
			.replace( /[^0-9.\-]/g, '' );
		const point = value.lastIndexOf( '.' );

		if ( -1 !== point ) {
			value = value.slice( 0, point ).split( '.' ).join( '' ) + value.slice( point );
		}

		return '' === value ? NaN : Number( value );
	}

	function formatNumber( number ) {
		return number.toFixed( settings.decimals ).replace( '.', settings.decimalPoint );
	}

	// The address tax is calculated for, read from the form as WooCommerce reads it for Recalculate.
	function taxAddress() {
		const basedOn = ( window.woocommerce_admin_meta_boxes || {} ).tax_based_on;
		const read = function ( prefix ) {
			const value = function ( key ) {
				const field = document.getElementById( prefix + key );
				return field ? field.value || '' : '';
			};

			return {
				country: value( '_country' ),
				state: value( '_state' ),
				postcode: value( '_postcode' ),
				city: value( '_city' ),
			};
		};
		let address = 'shipping' === basedOn ? read( '_shipping' ) : { country: '' };

		if ( 'billing' === basedOn || ! address.country ) {
			address = read( '_billing' );
		}

		return address;
	}

	function failure( message ) {
		return { success: false, data: { message: message } };
	}

	function request( body ) {
		const failed = failure(
			__( 'That did not save. Check your connection and try again.', 'inline-order-editor-for-woocommerce' )
		);
		// WordPress answers 403 when the page has been open so long that its security token ran out.
		const expired = failure(
			__( 'This page has been open for a while. Reload it, then try again.', 'inline-order-editor-for-woocommerce' )
		);

		return window
			.fetch( settings.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: body,
			} )
			.then( function ( response ) {
				return response.text().then( function ( text ) {
					try {
						const json = JSON.parse( text );

						if ( json && 'object' === typeof json && 'success' in json ) {
							return json;
						}
					} catch ( error ) {
						// Not JSON: handled below.
					}

					return 403 === response.status ? expired : failed;
				} );
			} )
			.catch( function () {
				return failed;
			} );
	}

	function post( action, data ) {
		const body = new URLSearchParams();

		body.append( 'action', 'ioefw_' + action );
		body.append( 'security', settings.nonce );
		body.append( 'order_id', settings.orderId );

		Object.keys( data || {} ).forEach( function ( key ) {
			body.append( key, null === data[ key ] || undefined === data[ key ] ? '' : data[ key ] );
		} );

		return request( body );
	}

	function emit( name, detail ) {
		document.dispatchEvent( new window.CustomEvent( 'ioefw:' + name, { detail: detail || {} } ) );
	}

	/* ---------- Messages ---------- */

	// A message is a toast. WooCommerce's admin screens already show WordPress notices of the
	// snackbar kind, bottom left, and read them out. Where that is not on the page, a small toast
	// of our own stands in. A new message takes the place of the last one.
	function showNotice( text, type ) {
		const isError = 'error' === type;
		const notices = wp.data && wp.data.dispatch ? wp.data.dispatch( 'core/notices' ) : null;

		if ( notices && notices.createNotice && document.querySelector( CORE.toasts ) ) {
			notices.createNotice( isError ? 'error' : 'success', text, {
				type: 'snackbar',
				id: 'ioefw-toast',
				// Something went wrong: the message stays until it is closed or replaced.
				explicitDismiss: isError,
			} );
			return;
		}

		let toast = document.querySelector( '.ioefw-toast' );

		if ( ! toast ) {
			toast = element( 'div', 'ioefw-toast', { role: 'status' } );
			toast.addEventListener( 'click', function () {
				toast.hidden = true;
			} );
			document.body.appendChild( toast );
		}

		window.clearTimeout( toastTimer );
		toast.textContent = text;
		toast.classList.toggle( 'ioefw-toast--error', isError );
		toast.hidden = false;

		if ( ! isError ) {
			toastTimer = window.setTimeout( function () {
				toast.hidden = true;
			}, 8000 );
		}

		speak( text, isError ? 'assertive' : 'polite' );
	}

	function savedText( data ) {
		if ( data && data.total && data.totalBefore && data.total !== data.totalBefore ) {
			return sprintf(
				/* translators: 1: new order total, 2: order total before the change. */
				__( 'Saved. The order total is now %1$s. It was %2$s.', 'inline-order-editor-for-woocommerce' ),
				data.total,
				data.totalBefore
			);
		}

		return __( 'Saved.', 'inline-order-editor-for-woocommerce' );
	}

	// On an order that does not exist yet, a saved line is only kept once the order is created.
	function savedNotice( data ) {
		const text = savedText( data );

		return state && state.isNew
			? text + ' ' + __( 'Click Create to keep this order.', 'inline-order-editor-for-woocommerce' )
			: text;
	}

	/* ---------- Drawing ---------- */

	// Puts the server's Items box and notes on the screen, as WooCommerce does after its own saves.
	function draw( data ) {
		if ( ! data || ! data.html ) {
			// Without the box, WooCommerce reloads it itself and mount() runs when it lands.
			$( CORE.box ).trigger( CORE.reload );
			return;
		}

		$( CORE.inside ).empty().append( data.html );

		if ( data.notes_html ) {
			$( CORE.notes ).empty().append( $( data.notes_html ).find( 'li' ) );
		}

		$( CORE.box ).trigger( CORE.reloaded );
		mount();
	}

	function fieldTargets( row ) {
		if ( row.classList.contains( 'item' ) ) {
			return {
				name: row.querySelector( 'td.name ' + CORE.itemName ),
				price: row.querySelector( 'td.item_cost > .view' ),
				quantity: row.querySelector( 'td.quantity > .view' ),
				total: row.querySelector( 'td.line_cost > .view' ),
			};
		}

		return {
			name: row.querySelector( 'td.name > .view' ),
			total: row.querySelector( 'td.line_cost > .view' ),
		};
	}

	function cellLabel( field, item ) {
		switch ( field ) {
			case 'name':
				/* translators: %s: line name. */
				return sprintf( __( 'Change the name: %s', 'inline-order-editor-for-woocommerce' ), item.name );
			case 'price':
				return sprintf(
					/* translators: 1: line name, 2: price each. */
					__( 'Change the price of %1$s, now %2$s', 'inline-order-editor-for-woocommerce' ),
					item.name,
					item.price
				);
			case 'quantity':
				return sprintf(
					/* translators: 1: line name, 2: quantity. */
					__( 'Change the quantity of %1$s, now %2$s', 'inline-order-editor-for-woocommerce' ),
					item.name,
					item.quantity
				);
		}

		return sprintf(
			/* translators: 1: line name, 2: line total. */
			__( 'Change the total of %1$s, now %2$s', 'inline-order-editor-for-woocommerce' ),
			item.name,
			item.total
		);
	}

	function markCells( table ) {
		table.querySelectorAll( 'tr[data-order_item_id]' ).forEach( function ( row ) {
			const item = state.items[ row.getAttribute( 'data-order_item_id' ) ];

			if ( ! item ) {
				return;
			}

			const targets = fieldTargets( row );

			( item.fields || [] ).forEach( function ( field ) {
				const cell = targets[ field ];

				if ( ! cell || -1 === FIELDS.indexOf( field ) ) {
					return;
				}

				cell.classList.add( 'ioefw-cell' );
				cell.setAttribute( 'data-ioefw-field', field );
				cell.setAttribute( 'tabindex', '0' );
				cell.setAttribute( 'role', 'button' );
				cell.setAttribute( 'aria-label', cellLabel( field, item ) );
				cell.setAttribute(
					'title',
					state.inclusive && ( 'price' === field || 'total' === field )
						? __( 'Double-click to change. Includes tax.', 'inline-order-editor-for-woocommerce' )
						: __( 'Double-click to change', 'inline-order-editor-for-woocommerce' )
				);
				cell.closest( 'td' ).classList.add( 'ioefw-host' );
			} );
		} );
	}

	function mount() {
		const container = box();
		const table = container ? container.querySelector( CORE.table ) : null;

		if ( ! table ) {
			return;
		}

		state = readState();
		table.setAttribute( 'data-ioefw-mounted', '1' );
		editing = null;

		if ( ! state || ! state.editable ) {
			return;
		}

		markCells( table );
		buildAddRow( table );
		restoreFocus();
		emit( 'mounted', { state: state } );
	}

	function findCell( itemId, field ) {
		return document.querySelector(
			CORE.box + ' tr[data-order_item_id="' + itemId + '"] .ioefw-cell[data-ioefw-field="' + field + '"]'
		);
	}

	function restoreFocus() {
		const target = pendingFocus;

		pendingFocus = null;

		if ( ! target ) {
			return;
		}

		if ( 'add' === target.type ) {
			const field = addField( target.part || 'name' ) || addField( 'name' );

			if ( field ) {
				field.focus();
			}

			return;
		}

		const cell = findCell( target.itemId, target.field );

		if ( cell && target.edit ) {
			startEdit( cell );
		} else if ( cell ) {
			cell.focus();
		}
	}

	/* ---------- Changing a line in place ---------- */

	// WooCommerce's own edit mode, opened with the pencil, shows a row's .edit fields. A redraw of the
	// box would throw away what was typed there, so nothing of ours runs while it is open.
	function coreEditOpen() {
		const container = box();

		return (
			!! container &&
			Array.prototype.some.call( container.querySelectorAll( CORE.table + ' tr .edit' ), function ( edit ) {
				return null !== edit.offsetParent;
			} )
		);
	}

	function refuseWhileCoreEdits() {
		if ( ! coreEditOpen() ) {
			return false;
		}

		showNotice(
			__(
				'Save or cancel the changes you started with the pencil first.',
				'inline-order-editor-for-woocommerce'
			),
			'error'
		);

		return true;
	}

	// Where focus is, in words that survive a redraw of the box.
	function focusPlace() {
		const active = document.activeElement;

		if ( ! active || ! active.closest || ! active.closest( CORE.box ) ) {
			return null;
		}

		if ( active.classList.contains( 'ioefw-cell' ) ) {
			return {
				itemId: active.closest( 'tr' ).getAttribute( 'data-order_item_id' ),
				field: active.getAttribute( 'data-ioefw-field' ),
				edit: false,
			};
		}

		if ( active.closest( '.ioefw-add__row' ) ) {
			const part = ( active.className.match( /ioefw-add__([a-z]+)/ ) || [] )[ 1 ];

			return { type: 'add', part: part || 'name' };
		}

		return null;
	}

	function neighbour( cell, step ) {
		const cells = Array.prototype.slice.call( document.querySelectorAll( CORE.box + ' .ioefw-cell' ) );
		const next = cells[ cells.indexOf( cell ) + step ];

		if ( ! next ) {
			return null;
		}

		return {
			itemId: next.closest( 'tr' ).getAttribute( 'data-order_item_id' ),
			field: next.getAttribute( 'data-ioefw-field' ),
			edit: true,
		};
	}

	function startEdit( cell ) {
		const row = cell.closest( 'tr' );
		const host = cell.closest( 'td' );

		if ( busy || editing || ! state || ! state.editable || ! row || ! host || refuseWhileCoreEdits() ) {
			return;
		}

		const itemId = row.getAttribute( 'data-order_item_id' );
		const item = state.items[ itemId ];
		const field = cell.getAttribute( 'data-ioefw-field' );

		if ( ! item ) {
			return;
		}

		// The input sits exactly over the text it replaces. The text stays in the page, hidden, so
		// nothing around it moves.
		const cellBox = cell.getBoundingClientRect();
		const hostBox = host.getBoundingClientRect();
		const cellStyle = window.getComputedStyle( cell );
		const hostStyle = window.getComputedStyle( host );
		const input = document.createElement( 'input' );
		const isName = 'name' === field;

		input.type = 'text';
		input.className = 'ioefw-input ioefw-input--' + field;
		input.value = String( item[ field ] );
		input.autocomplete = 'off';
		input.setAttribute( 'aria-label', cell.getAttribute( 'aria-label' ) );

		if ( isName ) {
			input.maxLength = 200;
			input.style.left = cellBox.left - hostBox.left - host.clientLeft + 'px';
			input.style.width = cellBox.width + 'px';
		} else {
			input.inputMode = 'decimal';
			input.style.left = '0';
			input.style.width = '100%';
			input.style.paddingLeft = hostStyle.paddingLeft;
			input.style.paddingRight = hostStyle.paddingRight;
		}

		input.style.top = cellBox.top - hostBox.top - host.clientTop + 'px';
		input.style.height = cellBox.height + 'px';
		input.style.fontFamily = cellStyle.fontFamily;
		input.style.fontSize = cellStyle.fontSize;
		input.style.fontWeight = cellStyle.fontWeight;
		input.style.lineHeight = cellStyle.lineHeight;
		input.style.textAlign = cellStyle.textAlign;
		input.style.color = cellStyle.color;

		editing = {
			cell: cell,
			input: input,
			itemId: itemId,
			field: field,
			original: String( item[ field ] ),
			closing: false,
			failedValue: null,
		};

		if ( window.getSelection ) {
			window.getSelection().removeAllRanges();
		}

		cell.classList.add( 'ioefw-cell--editing' );
		host.appendChild( input );
		input.focus();
		input.select();

		input.addEventListener( 'keydown', function ( event ) {
			if ( event.isComposing || 229 === event.keyCode ) {
				return;
			}

			if ( 'Enter' === event.key ) {
				// Never let Enter reach the order form: it would save the whole order.
				event.preventDefault();
				event.stopPropagation();
				commit( { refocus: true } );
			} else if ( 'Escape' === event.key ) {
				event.preventDefault();
				event.stopPropagation();
				closeEdit( true );
			} else if ( 'Tab' === event.key ) {
				const next = neighbour( cell, event.shiftKey ? -1 : 1 );

				if ( next ) {
					event.preventDefault();
					commit( { next: next } );
				}
			}
		} );

		input.addEventListener( 'blur', function () {
			if ( ! editing || editing.input !== input || editing.closing ) {
				return;
			}

			// A value the server just refused is dropped when focus leaves, so focus is never trapped.
			if ( null !== editing.failedValue && input.value.trim() === editing.failedValue ) {
				closeEdit( false );
			} else {
				commit( {} );
			}
		} );
	}

	function closeEdit( refocus ) {
		const edit = editing;

		if ( ! edit ) {
			return;
		}

		edit.closing = true;
		editing = null;
		edit.cell.classList.remove( 'ioefw-cell--editing' );

		if ( edit.input.parentNode ) {
			edit.input.parentNode.removeChild( edit.input );
		}

		if ( refocus ) {
			edit.cell.focus();
		}
	}

	function commit( how ) {
		const edit = editing;

		if ( ! edit || edit.closing ) {
			return;
		}

		const value = edit.input.value.trim();

		if ( value === edit.original ) {
			closeEdit( !! how.refocus );

			if ( how.next ) {
				const cell = findCell( how.next.itemId, how.next.field );

				if ( cell ) {
					startEdit( cell );
				}
			}

			return;
		}

		if ( 'name' !== edit.field && Number.isNaN( parseNumber( value ) ) ) {
			edit.failedValue = value;
			showNotice( __( 'Type a number.', 'inline-order-editor-for-woocommerce' ), 'error' );
			edit.input.focus();
			edit.input.select();
			return;
		}

		edit.closing = true;
		busy = true;
		edit.input.readOnly = true;
		edit.input.classList.add( 'ioefw-input--saving' );
		edit.input.setAttribute( 'aria-busy', 'true' );

		const after = how.next || ( how.refocus ? { itemId: edit.itemId, field: edit.field, edit: false } : null );
		const data = taxAddress();

		data.item_id = edit.itemId;
		data.field = edit.field;
		data.value = value;
		data.hash = state.hash;

		post( 'update_item', data ).then( function ( response ) {
			busy = false;

			if ( response.success ) {
				// Focus goes where it was asked to go, or stays where the user has moved to since.
				pendingFocus = after || focusPlace();
				showNotice( savedNotice( response.data ) );
				draw( response.data );
				emit( 'saved', { action: 'update_item', itemId: edit.itemId, field: edit.field, response: response.data } );
				releaseSubmit( true );
				return;
			}

			const error = response.data || {};

			releaseSubmit( false );
			showNotice(
				error.message || __( 'That did not save. Try again.', 'inline-order-editor-for-woocommerce' ),
				'error'
			);

			if ( error.html ) {
				// The order moved on. Show it as it is now.
				pendingFocus = { itemId: edit.itemId, field: edit.field, edit: false };
				draw( error );
				return;
			}

			// Leave the value in place so it can be corrected.
			edit.closing = false;
			edit.failedValue = value;
			edit.input.readOnly = false;
			edit.input.classList.remove( 'ioefw-input--saving' );
			edit.input.removeAttribute( 'aria-busy' );
			edit.input.focus();
			edit.input.select();
		} );
	}

	// The order form was submitted while a change was still being saved. It goes ahead once the change
	// is in, so the form posts what was saved. If the change failed, the form stays put.
	function releaseSubmit( saved ) {
		const held = pendingSubmit;

		pendingSubmit = null;

		if ( ! held || ! saved ) {
			return;
		}

		if ( held.form.requestSubmit ) {
			held.form.requestSubmit( held.submitter && held.form.contains( held.submitter ) ? held.submitter : undefined );
		} else {
			held.form.submit();
		}
	}

	/* ---------- The new row ---------- */

	function element( tag, className, attributes ) {
		const node = document.createElement( tag );

		if ( className ) {
			node.className = className;
		}

		Object.keys( attributes || {} ).forEach( function ( key ) {
			node.setAttribute( key, attributes[ key ] );
		} );

		return node;
	}

	function cellWith( className, child ) {
		const cell = element( 'td', className );

		if ( child ) {
			cell.appendChild( child );
		}

		return cell;
	}

	function nameCell() {
		const cell = element( 'td', 'name ioefw-add__name-cell' );
		const combo = element( 'div', 'ioefw-add__combo' );
		const more = element( 'div', 'ioefw-add__more' );
		const clear = element( 'button', 'ioefw-add__clear', {
			type: 'button',
			'aria-label': __( 'Type a different item', 'inline-order-editor-for-woocommerce' ),
			hidden: 'hidden',
		} );

		clear.textContent = '×';

		combo.appendChild(
			element( 'input', 'ioefw-add__name', {
				type: 'text',
				role: 'combobox',
				'aria-autocomplete': 'list',
				'aria-expanded': 'false',
				'aria-controls': 'ioefw-suggestions',
				'aria-label': __( 'New item name', 'inline-order-editor-for-woocommerce' ),
				placeholder: __( 'Add an item: type its name', 'inline-order-editor-for-woocommerce' ),
				autocomplete: 'off',
				maxlength: '200',
			} )
		);
		combo.appendChild( element( 'span', 'ioefw-add__kind', { hidden: 'hidden' } ) );
		combo.appendChild( clear );

		more.appendChild(
			element( 'input', 'ioefw-add__note', {
				type: 'text',
				'aria-label': __( 'Note for this item', 'inline-order-editor-for-woocommerce' ),
				placeholder: __( 'Note, such as colour or size (optional)', 'inline-order-editor-for-woocommerce' ),
				autocomplete: 'off',
				maxlength: '200',
			} )
		);

		if ( ( settings.taxClasses || [] ).length ) {
			const select = element( 'select', 'ioefw-add__tax', {
				'aria-label': __( 'Tax for this item', 'inline-order-editor-for-woocommerce' ),
			} );

			settings.taxClasses.forEach( function ( taxClass ) {
				const option = element( 'option', '', { value: taxClass.slug } );
				option.textContent = taxClass.label;
				select.appendChild( option );
			} );

			more.appendChild( select );
		}

		cell.appendChild( combo );
		cell.appendChild( more );

		return cell;
	}

	function buildAddRow( table ) {
		// The empty row is the last row of the table: after the items, and after any fees and shipping.
		const anchor =
			table.querySelector( CORE.shippingItems ) ||
			table.querySelector( CORE.feeItems ) ||
			table.querySelector( CORE.lineItems );

		if ( ! anchor ) {
			return;
		}

		const body = element( 'tbody', 'ioefw-add' );
		const row = element( 'tr', 'ioefw-add__row', { 'data-sort-ignore': 'true' } );
		const plus = element( 'span', 'ioefw-add__plus', { 'aria-hidden': 'true' } );
		const button = element( 'button', 'button ioefw-add__button', { type: 'button' } );

		const priceLabel = state.inclusive
			? __( 'Price each, including tax', 'inline-order-editor-for-woocommerce' )
			: __( 'Price each', 'inline-order-editor-for-woocommerce' );

		plus.textContent = '+';
		button.textContent = __( 'Add', 'inline-order-editor-for-woocommerce' );

		// One cell under each heading, whatever columns other extensions have added.
		table.querySelectorAll( 'thead > tr > th' ).forEach( function ( heading ) {
			const list = heading.classList;

			if ( list.contains( 'item' ) ) {
				row.appendChild( cellWith( 'thumb', plus ) );
				row.appendChild( nameCell() );
			} else if ( list.contains( 'item_cost' ) ) {
				row.appendChild(
					cellWith(
						'item_cost',
						element( 'input', 'ioefw-add__price', {
							type: 'text',
							inputmode: 'decimal',
							autocomplete: 'off',
							'aria-label': priceLabel,
							title: priceLabel,
							placeholder: formatNumber( 0 ),
						} )
					)
				);
			} else if ( list.contains( 'quantity' ) ) {
				row.appendChild(
					cellWith(
						'quantity',
						element( 'input', 'ioefw-add__quantity', {
							type: 'text',
							inputmode: 'decimal',
							autocomplete: 'off',
							'aria-label': __( 'Quantity', 'inline-order-editor-for-woocommerce' ),
						} )
					)
				);
			} else if ( list.contains( 'line_cost' ) ) {
				row.appendChild(
					cellWith(
						'line_cost',
						element( 'output', 'ioefw-add__total', {
							'aria-label': __( 'Line total', 'inline-order-editor-for-woocommerce' ),
						} )
					)
				);
			} else if ( list.contains( 'wc-order-edit-line-item' ) ) {
				row.appendChild( cellWith( 'wc-order-edit-line-item', button ) );
			} else {
				row.appendChild( element( 'td', '' ) );
			}
		} );

		body.appendChild( row );
		anchor.insertAdjacentElement( 'afterend', body );
		fillAddRow();
	}

	function addRow() {
		return document.querySelector( CORE.box + ' .ioefw-add__row' );
	}

	function addField( name ) {
		const row = addRow();

		return row ? row.querySelector( '.ioefw-add__' + name ) : null;
	}

	// Puts the draft back into the row, and shows what kind of line it will be.
	function fillAddRow() {
		const row = addRow();

		if ( ! row ) {
			return;
		}

		const kind = addField( 'kind' );
		const clear = addField( 'clear' );
		const more = addField( 'more' );
		const price = addField( 'price' );
		const quantity = addField( 'quantity' );
		const tax = addField( 'tax' );
		const isDelivery = 'delivery' === draft.kind;
		const special = isDelivery || !! draft.product;

		addField( 'name' ).value = draft.name;
		addField( 'note' ).value = draft.note;
		price.value = draft.product ? draft.product.price : draft.price;
		price.readOnly = !! draft.product;
		quantity.value = isDelivery ? '1' : draft.quantity;
		quantity.disabled = isDelivery;

		if ( tax ) {
			tax.value = draft.taxClass;
		}

		kind.hidden = ! special;
		clear.hidden = ! special;
		more.hidden = special;
		kind.textContent = isDelivery
			? __( 'Delivery charge', 'inline-order-editor-for-woocommerce' )
			: __( 'From your catalogue', 'inline-order-editor-for-woocommerce' );
		row.classList.toggle( 'ioefw-add__row--delivery', isDelivery );
		row.classList.toggle( 'ioefw-add__row--product', !! draft.product );
		drawAddTotal();
	}

	function drawAddTotal() {
		const total = addField( 'total' );

		if ( ! total ) {
			return;
		}

		const price = draft.product ? Number( draft.product.amount ) : parseNumber( draft.price );
		const quantity = 'delivery' === draft.kind ? 1 : parseNumber( draft.quantity );

		total.textContent = Number.isNaN( price ) || Number.isNaN( quantity ) ? '' : formatNumber( price * quantity );
	}

	function resetDraft() {
		draft.name = '';
		draft.price = '';
		draft.quantity = '1';
		draft.note = '';
		draft.kind = 'custom';
		draft.product = null;
	}

	/* ---------- Suggestions under the new row's name ---------- */

	// The list hangs off the Items box itself. Inside the table's wrapper it would be cut off.
	function suggestionList() {
		const container = box();
		let list = document.getElementById( 'ioefw-suggestions' );

		if ( ! list && container ) {
			list = element( 'ul', 'ioefw-suggestions', {
				id: 'ioefw-suggestions',
				role: 'listbox',
				'aria-label': __( 'Suggestions for the new item', 'inline-order-editor-for-woocommerce' ),
				hidden: 'hidden',
			} );
			container.appendChild( list );
		}

		return list;
	}

	function closeSuggestions() {
		const list = document.getElementById( 'ioefw-suggestions' );
		const name = addField( 'name' );

		window.clearTimeout( searchTimer );
		searchCount++;
		options = [];
		activeOption = -1;

		if ( list ) {
			list.hidden = true;
			list.textContent = '';
		}

		if ( name ) {
			name.setAttribute( 'aria-expanded', 'false' );
			name.removeAttribute( 'aria-activedescendant' );
		}
	}

	function highlight( index ) {
		const list = document.getElementById( 'ioefw-suggestions' );
		const name = addField( 'name' );

		activeOption = index;

		if ( ! list || ! name ) {
			return;
		}

		Array.prototype.forEach.call( list.children, function ( option, position ) {
			option.setAttribute( 'aria-selected', position === index ? 'true' : 'false' );
		} );

		if ( index >= 0 && list.children[ index ] ) {
			name.setAttribute( 'aria-activedescendant', list.children[ index ].id );
			list.children[ index ].scrollIntoView( { block: 'nearest' } );
		} else {
			name.removeAttribute( 'aria-activedescendant' );
		}
	}

	function drawSuggestions( term, products ) {
		const list = suggestionList();
		const name = addField( 'name' );

		if ( ! list || ! name ) {
			return;
		}

		const container = box();
		const nameBox = name.getBoundingClientRect();
		const containerBox = container.getBoundingClientRect();

		list.style.top = nameBox.bottom - containerBox.top - container.clientTop + 2 + 'px';
		list.style.left = nameBox.left - containerBox.left - container.clientLeft + 'px';
		list.style.width = Math.max( nameBox.width, 280 ) + 'px';

		options = products.map( function ( product ) {
			return {
				type: 'product',
				product: product,
				label: product.name,
				detail: [ product.price, product.stock, product.sku ].filter( Boolean ).join( ' \u00b7 ' ),
			};
		} );
		options.push( {
			type: 'custom',
			/* translators: %s: what was typed. */
			label: sprintf( __( 'Add "%s" as a new item', 'inline-order-editor-for-woocommerce' ), term ),
		} );
		options.push( {
			type: 'delivery',
			/* translators: %s: what was typed. */
			label: sprintf( __( 'Add "%s" as a delivery charge', 'inline-order-editor-for-woocommerce' ), term ),
		} );

		list.textContent = '';

		options.forEach( function ( option, index ) {
			const item = element( 'li', 'ioefw-suggestions__option ioefw-suggestions__option--' + option.type, {
				id: 'ioefw-suggestion-' + index,
				role: 'option',
				'aria-selected': 'false',
				'data-index': String( index ),
			} );
			const label = element( 'span', 'ioefw-suggestions__label' );

			label.textContent = option.label;
			item.appendChild( label );

			if ( option.detail ) {
				const detail = element( 'span', 'ioefw-suggestions__detail' );
				detail.textContent = option.detail;
				item.appendChild( detail );
			}

			list.appendChild( item );
		} );

		list.hidden = false;
		name.setAttribute( 'aria-expanded', 'true' );
		highlight( -1 );
	}

	function suggest( term ) {
		window.clearTimeout( searchTimer );

		if ( term.length < 2 || 'delivery' === draft.kind ) {
			closeSuggestions();
			return;
		}

		// The two typed choices show at once. Catalogue matches join them when the search answers.
		drawSuggestions( term, [] );

		const count = ++searchCount;

		searchTimer = window.setTimeout( function () {
			post( 'search_products', { term: term } ).then( function ( response ) {
				const name = addField( 'name' );

				if ( count !== searchCount || ! name || name.value.trim() !== term || document.activeElement !== name ) {
					return;
				}

				if ( response.success && response.data.products.length ) {
					drawSuggestions( term, response.data.products );
				}
			} );
		}, 250 );
	}

	function choose( option ) {
		if ( ! option ) {
			return;
		}

		if ( 'product' === option.type ) {
			draft.product = option.product;
			draft.kind = 'custom';
			draft.name = option.product.name;
		} else {
			draft.product = null;
			draft.kind = option.type;
		}

		closeSuggestions();
		fillAddRow();

		const next = addField( draft.product ? 'quantity' : 'price' );

		if ( next ) {
			next.focus();
			next.select();
		}
	}

	/* ---------- Adding the new row to the order ---------- */

	function setAddBusy( on ) {
		const row = addRow();

		busy = on;

		if ( row ) {
			row.classList.toggle( 'ioefw-add__row--saving', on );
			row.setAttribute( 'aria-busy', on ? 'true' : 'false' );
		}
	}

	function addFailed( error, fallback ) {
		setAddBusy( false );
		releaseSubmit( false );
		showNotice( ( error && ( error.message || error.error ) ) || fallback, 'error' );

		if ( error && error.html ) {
			pendingFocus = { type: 'add' };
			draw( error );
		}
	}

	function added( response, action ) {
		setAddBusy( false );
		resetDraft();
		pendingFocus = { type: 'add' };
		showNotice( savedNotice( response.data ) );
		draw( response.data );
		emit( 'saved', { action: action, response: response.data } );
		releaseSubmit( true );
	}

	// A catalogue product is added by WooCommerce's own request, so stock and other extensions behave
	// as they do with its Add product(s) button. The totals are then brought up to date.
	function addProduct() {
		const meta = window.woocommerce_admin_meta_boxes || {};
		const quantity = parseNumber( draft.quantity );
		const body = new URLSearchParams();
		const fallback = __( 'That product could not be added. Try again.', 'inline-order-editor-for-woocommerce' );

		body.append( 'action', CORE.addProduct );
		body.append( 'security', meta.order_item_nonce || '' );
		body.append( 'order_id', settings.orderId );
		body.append( 'data[0][id]', draft.product.id );
		body.append( 'data[0][qty]', Number.isNaN( quantity ) || quantity <= 0 ? 1 : quantity );

		setAddBusy( true );

		request( body ).then( function ( response ) {
			if ( ! response.success ) {
				addFailed( response.data, fallback );
				return;
			}

			post( 'recalculate', taxAddress() ).then( function ( settled ) {
				if ( settled.success ) {
					added( settled, 'add_product' );
					return;
				}

				// The product is on the order. Show it, and say the totals still need a recalculation.
				setAddBusy( false );
				releaseSubmit( false );
				resetDraft();
				showNotice( ( settled.data && settled.data.message ) || fallback, 'error' );
				draw( response.data );
			} );
		} );
	}

	function submitAdd() {
		if ( busy || ! state || ! state.editable || refuseWhileCoreEdits() ) {
			return;
		}

		closeSuggestions();

		if ( draft.product ) {
			addProduct();
			return;
		}

		if ( '' === draft.name.trim() ) {
			showNotice( __( 'Type a name for the item first.', 'inline-order-editor-for-woocommerce' ), 'error' );

			if ( addField( 'name' ) ) {
				addField( 'name' ).focus();
			}

			return;
		}

		const data = taxAddress();

		data.kind = draft.kind;
		data.name = draft.name.trim();
		data.quantity = draft.quantity;
		data.price = draft.price;
		data.note = draft.note;
		data.tax_class = draft.taxClass;
		data.hash = state.hash;

		setAddBusy( true );

		post( 'add_item', data ).then( function ( response ) {
			if ( response.success ) {
				added( response, 'add_item' );
			} else {
				addFailed( response.data, __( 'That did not save. Try again.', 'inline-order-editor-for-woocommerce' ) );
			}
		} );
	}

	/* ---------- Events ---------- */

	document.addEventListener(
		'pointerdown',
		function ( event ) {
			lastPointer = event.pointerType || 'mouse';
		},
		true
	);

	$( document )
		// Changing a line: double-click, or Enter, Space or F2 on a focused value. One tap on a touch screen.
		.on( 'dblclick', CORE.box + ' .ioefw-cell', function ( event ) {
			event.preventDefault();
			startEdit( this );
		} )
		.on( 'click', CORE.box + ' .ioefw-cell', function ( event ) {
			// A tap, or a click made for the user by a screen reader or voice control, which has no pointer.
			if ( 'touch' === lastPointer || 'pen' === lastPointer || 0 === event.detail ) {
				startEdit( this );
			}
		} )
		.on( 'keydown', CORE.box + ' .ioefw-cell', function ( event ) {
			if ( 'Enter' === event.key || 'F2' === event.key || ' ' === event.key ) {
				event.preventDefault();
				startEdit( this );
			}
		} )

		// The new row.
		.on( 'input', CORE.box + ' .ioefw-add__row input, ' + CORE.box + ' .ioefw-add__row select', function () {
			if ( this.classList.contains( 'ioefw-add__name' ) ) {
				draft.name = this.value;

				if ( draft.product ) {
					// Typing over a picked product makes it a typed item again.
					draft.product = null;
					draft.price = '';
					fillAddRow();
				}

				suggest( this.value.trim() );
			} else if ( this.classList.contains( 'ioefw-add__price' ) ) {
				draft.price = this.value;
			} else if ( this.classList.contains( 'ioefw-add__quantity' ) ) {
				draft.quantity = this.value;
			} else if ( this.classList.contains( 'ioefw-add__note' ) ) {
				draft.note = this.value;
			} else if ( this.classList.contains( 'ioefw-add__tax' ) ) {
				draft.taxClass = this.value;
			}

			drawAddTotal();
		} )
		.on( 'change', CORE.box + ' .ioefw-add__tax', function () {
			draft.taxClass = this.value;
		} )
		.on( 'keydown', CORE.box + ' .ioefw-add__row input, ' + CORE.box + ' .ioefw-add__row select', function ( event ) {
			const isName = this.classList.contains( 'ioefw-add__name' );
			const open = isName && options.length > 0;

			if ( event.isComposing || 229 === event.keyCode ) {
				return;
			}

			if ( 'Enter' === event.key ) {
				// Never let Enter reach the order form: it would save the whole order.
				event.preventDefault();
				event.stopPropagation();

				if ( open && activeOption >= 0 ) {
					choose( options[ activeOption ] );
				} else if ( isName && '' === draft.price && ! draft.product && '' !== draft.name.trim() ) {
					// A name alone is not a line yet. Move on to its price.
					closeSuggestions();
					addField( 'price' ).focus();
				} else {
					submitAdd();
				}
			} else if ( open && 'ArrowDown' === event.key ) {
				event.preventDefault();
				highlight( Math.min( activeOption + 1, options.length - 1 ) );
			} else if ( open && 'ArrowUp' === event.key ) {
				event.preventDefault();
				highlight( Math.max( activeOption - 1, -1 ) );
			} else if ( open && 'Escape' === event.key ) {
				event.preventDefault();
				event.stopPropagation();
				closeSuggestions();
			}
		} )
		.on( 'keydown', CORE.box + ' .ioefw-add__row input, ' + CORE.box + ' .ioefw-add__row select, ' + CORE.box + ' .ioefw-add__row button', function ( event ) {
			if ( 'Tab' !== event.key ) {
				return;
			}

			// Name, price, quantity come first, as they are said on the phone. The optional note and tax follow.
			const order = [ 'name', 'clear', 'price', 'quantity', 'note', 'tax', 'button' ].map( addField ).filter( function ( field ) {
				return field && ! field.disabled && null !== field.offsetParent;
			} );
			const next = order[ order.indexOf( this ) + ( event.shiftKey ? -1 : 1 ) ];

			if ( -1 !== order.indexOf( this ) && next ) {
				event.preventDefault();
				next.focus();

				if ( next.select ) {
					next.select();
				}
			}
		} )
		.on( 'blur', CORE.box + ' .ioefw-add__name', function () {
			closeSuggestions();
		} )
		.on( 'mousedown', CORE.box + ' .ioefw-suggestions__option', function ( event ) {
			// Keep focus in the name field, so the list is still there when the click lands.
			event.preventDefault();
		} )
		.on( 'click', CORE.box + ' .ioefw-suggestions__option', function () {
			choose( options[ Number( this.getAttribute( 'data-index' ) ) ] );
		} )
		.on( 'click', CORE.box + ' .ioefw-add__clear', function () {
			draft.product = null;
			draft.kind = 'custom';
			draft.price = '';
			fillAddRow();
			addField( 'name' ).focus();
		} )
		.on( 'click', CORE.box + ' .ioefw-add__button', function () {
			submitAdd();
		} )

		// Order terms: put the default text back.
		.on( 'click', '.ioefw-use-default-terms', function () {
			const terms = document.getElementById( 'ioefw-order-terms-text' );

			if ( terms ) {
				terms.value = this.getAttribute( 'data-terms' ) || '';
				terms.focus();
			}
		} )

		// Draft is a checkout status. WooCommerce clears Draft orders away, so say so when it is picked.
		.on( 'change', CORE.status, draftWarning )

		.on( 'submit', CORE.form, function ( event ) {
			// A value still open, or a save still in flight: finish that first, then let the form go.
			// Otherwise the form would post the old value over the new one.
			if ( editing && ! editing.closing ) {
				commit( {} );
			}

			if ( busy ) {
				event.preventDefault();
				pendingSubmit = {
					form: this,
					submitter: event.originalEvent ? event.originalEvent.submitter : null,
				};
				return;
			}

			if (
				'' !== draft.name.trim() &&
				! window.confirm(
					__(
						'You typed an item that has not been added to the order yet. Save the order without it?',
						'inline-order-editor-for-woocommerce'
					)
				)
			) {
				event.preventDefault();
				return;
			}

			submitting = true;
		} );

	function draftWarning() {
		const select = document.querySelector( CORE.status );
		let warning = document.querySelector( '.ioefw-draft-warning' );

		if ( ! select ) {
			return;
		}

		if ( CORE.draftStatus !== select.value ) {
			if ( warning ) {
				warning.parentNode.removeChild( warning );
			}

			return;
		}

		if ( ! warning ) {
			warning = element( 'p', 'ioefw-draft-warning', { role: 'alert' } );
			warning.textContent = __(
				'WooCommerce deletes Draft orders by itself, about a day after their last change. To keep this order, choose Pending payment.',
				'inline-order-editor-for-woocommerce'
			);
			select.parentNode.appendChild( warning );
		}
	}

	// Leaving with something typed but not saved, or with a new order that was never created.
	window.addEventListener( 'beforeunload', function ( event ) {
		const typed = '' !== draft.name.trim() || ( !! editing && editing.input.value.trim() !== editing.original );
		const neverCreated = !! state && state.editable && state.isNew && hasItems();

		if ( ! submitting && ( typed || neverCreated ) ) {
			event.preventDefault();
			event.returnValue = '';
		}
	} );

	/* ---------- Start ---------- */

	$( function () {
		const container = box();

		draftWarning();

		if ( ! container ) {
			return;
		}

		// WooCommerce replaces the Items box after its own saves. Set it up again each time.
		new window.MutationObserver( function () {
			const table = container.querySelector( CORE.table );

			if ( table && ! table.hasAttribute( 'data-ioefw-mounted' ) ) {
				mount();
			}
		} ).observe( container, { childList: true, subtree: true } );

		mount();
	} );

	// For add-ons: read the order as last drawn, draw the answer to their own request, say what happened,
	// or ask WooCommerce to reload the box.
	window.ioefw = {
		getState: function () {
			return state;
		},
		draw: draw,
		notice: showNotice,
		taxAddress: taxAddress,
		refresh: function () {
			$( CORE.box ).trigger( CORE.reload );
		},
	};
} )( window.jQuery, window.wp, window.ioefwSettings );
