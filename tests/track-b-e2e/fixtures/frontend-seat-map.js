/**
 * Credoq Visual Seats Pro — frontend seat map.
 *
 * The Engine's React widget (FormField.jsx / SeatMapField) fetches
 * server-rendered HTML from `credoq_seats_load_map` and injects it via
 * dangerouslySetInnerHTML, then calls window.cvspReinitMaps(). Because
 * React never executes <script> tags injected that way, this file is a
 * normal, separately-enqueued script that scans the DOM for any
 * `.cvsp-map-wrap` markup it hasn't wired up yet and attaches real click
 * handling, holds, and repaint logic to it. FormField.jsx then reaches
 * into window.CVSPMaps[planId] to sync selections back into the form.
 */
( function () {
	'use strict';

	window.CVSPMaps = window.CVSPMaps || {};

	function cfg() {
		return window.credoqSeatsCfg || { ajaxUrl: '/wp-admin/admin-ajax.php', nonce: '' };
	}

	function post( action, params ) {
		var fd = new FormData();
		fd.append( 'action', action );
		fd.append( 'nonce', cfg().nonce );
		Object.keys( params || {} ).forEach( function ( k ) { fd.append( k, params[ k ] ); } );
		return fetch( cfg().ajaxUrl, { method: 'POST', body: fd } ).then( function ( r ) { return r.json(); } );
	}

	function buildMap( wrap ) {
		var planId = parseInt( wrap.getAttribute( 'data-plan-id' ), 10 );
		if ( ! planId ) return;

		var map = {
			wrap: wrap,
			planId: planId,
			selectedIds: [],
			bookedIds: [],
			currentDate: wrap.getAttribute( 'data-credoq-date' ) || '',
			currentSlot: wrap.getAttribute( 'data-credoq-slot' ) || '',
			currentStaffId: parseInt( wrap.getAttribute( 'data-credoq-staff-id' ) || '0', 10 ),
			currentEventId: parseInt( wrap.getAttribute( 'data-credoq-event-id' ) || '0', 10 ),
			seatEls: {},
			pending: {},
		};

		wrap.querySelectorAll( '.cvsp-seat' ).forEach( function ( el ) {
			var id = parseInt( el.getAttribute( 'data-seat-id' ), 10 );
			map.seatEls[ id ] = el;
			el.addEventListener( 'click', function () {
				if ( map.pending[ id ] ) return;
				map.handleClick( id );
			} );
		} );

		wrap.querySelectorAll( '.cvsp-floor-tab-btn' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				wrap.querySelectorAll( '.cvsp-floor-tab-btn' ).forEach( function ( b ) { b.classList.remove( 'is-active' ); } );
				btn.classList.add( 'is-active' );
				var fid = btn.getAttribute( 'data-floor' );
				wrap.querySelectorAll( '.cvsp-floor-canvas' ).forEach( function ( c ) {
					c.style.display = ( c.getAttribute( 'data-floor-id' ) === fid ) ? '' : 'none';
				} );
			} );
		} );

		map.calcPrice = function () {
			var total = 0;
			map.selectedIds.forEach( function ( id ) {
				var el = map.seatEls[ id ];
				if ( el ) total += parseFloat( el.getAttribute( 'data-price' ) ) || 0;
			} );
			return total;
		};

		map.repaint = function () {
			Object.keys( map.seatEls ).forEach( function ( idStr ) {
				var id = parseInt( idStr, 10 );
				var el = map.seatEls[ id ];
				var isSelected = map.selectedIds.indexOf( id ) !== -1;
				el.classList.toggle( 'is-selected', isSelected );
				el.classList.toggle( 'is-booked', ! isSelected && map.bookedIds.indexOf( id ) !== -1 );
			} );
			var countEl = wrap.querySelector( '.cvsp-sel-count' );
			var totalEl = wrap.querySelector( '.cvsp-sel-total' );
			if ( countEl ) countEl.textContent = String( map.selectedIds.length );
			if ( totalEl ) totalEl.textContent = map.calcPrice().toFixed( 2 );
			// Fires exactly when selectedIds has actually changed (hold/release
			// AJAX resolved, or loadBooked() ran) — not when a click merely
			// *started*. The Engine's React widget hooks this instead of
			// wrapping handleClick, because handleClick's effect on
			// selectedIds only lands inside its async .then() — syncing
			// right after calling it (synchronously) reads stale state and
			// is exactly what caused "N seats clicked, N-1 counted" (the
			// last click's result wasn't captured yet).
			if ( typeof map.onSelectionChange === 'function' ) map.onSelectionChange();
		};

		map.getSelectedDetails = function () {
			return map.selectedIds.map( function ( id ) {
				var el = map.seatEls[ id ];
				return {
					id: id,
					label: el ? el.getAttribute( 'data-label' ) || String( id ) : String( id ),
					price: el ? ( parseFloat( el.getAttribute( 'data-price' ) ) || 0 ) : 0,
				};
			} );
		};

		map.showMessage = function ( msg ) {
			var el = wrap.querySelector( '.cvsp-hold-msg' );
			if ( ! el ) return;
			el.textContent = msg || '';
			if ( msg ) setTimeout( function () { if ( el.textContent === msg ) el.textContent = ''; }, 4000 );
		};

		map.handleClick = function ( seatId ) {
			var el = map.seatEls[ seatId ];
			if ( ! el || el.classList.contains( 'is-booked' ) ) return;

			var idx = map.selectedIds.indexOf( seatId );
			var releasing = idx !== -1;
			map.pending[ seatId ] = true;
			el.classList.add( 'is-pending' );

			post( releasing ? 'credoq_seats_release' : 'credoq_seats_hold', {
				plan_id: map.planId, seat_id: seatId, date: map.currentDate, slot: map.currentSlot, event_id: map.currentEventId,
			} ).then( function ( res ) {
				delete map.pending[ seatId ];
				el.classList.remove( 'is-pending' );
				if ( ! res.success ) {
					map.showMessage( ( res.data && res.data.message ) || 'Could not update that seat.' );
					if ( ! releasing && map.bookedIds.indexOf( seatId ) === -1 ) map.bookedIds.push( seatId );
					map.repaint();
					return;
				}
				if ( releasing ) map.selectedIds.splice( idx, 1 ); else map.selectedIds.push( seatId );
				map.repaint();
			} ).catch( function () {
				delete map.pending[ seatId ];
				el.classList.remove( 'is-pending' );
				map.showMessage( 'Network error — try again.' );
			} );
		};

		// Default implementation; the Engine's React widget overrides this
		// after it mounts (to also pass the picked staff/date/slot). Kept
		// here so the map is still correct if that override never attaches.
		map.loadBooked = function () {
			post( 'credoq_seats_get_booked', { plan_id: map.planId, date: map.currentDate, slot: map.currentSlot, staff_id: map.currentStaffId, event_id: map.currentEventId } )
				.then( function ( res ) {
					if ( res.success ) { map.bookedIds = ( res.data.booked_seat_ids || [] ).map( Number ); map.repaint(); }
				} ).catch( function () {} );
		};

		window.CVSPMaps[ planId ] = map;
		map.loadBooked();
		map.repaint();
	}

	window.cvspReinitMaps = function () {
		document.querySelectorAll( '.cvsp-map-wrap' ).forEach( function ( wrap ) {
			if ( wrap.getAttribute( 'data-cvsp-ready' ) ) return;
			wrap.setAttribute( 'data-cvsp-ready', '1' );
			buildMap( wrap );
		} );
	};

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', window.cvspReinitMaps );
	} else {
		window.cvspReinitMaps();
	}

	// React injects seat-map HTML asynchronously after the booking widget has
	// mounted. A MutationObserver makes the separately-enqueued Seats runtime
	// resilient even when a host page or test harness does not call the manual
	// reinitialization hook at exactly the right time.
	if ( typeof MutationObserver !== 'undefined' ) {
		var observer = new MutationObserver( function () { window.cvspReinitMaps(); } );
		observer.observe( document.documentElement, { childList: true, subtree: true } );
	}
} )();
