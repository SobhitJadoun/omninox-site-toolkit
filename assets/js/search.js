/**
 * Omninox Site Toolkit - Frontend Live Search Autocomplete
 */
( function () {
	'use strict';

	var config = window.OmninoxSearch;

	if ( ! config || ! config.ajaxUrl ) {
		return;
	}

	var minChars = config.minChars || 2;
	var debounceTimer = null;
	var activePopup = null;
	var selectedIndex = -1;

	function init() {
		var inputs = document.querySelectorAll( 'input[name="s"], input[type="search"], .search-field' );
		for ( var i = 0; i < inputs.length; i++ ) {
			bindSearchInput( inputs[ i ] );
		}
	}

	function bindSearchInput( input ) {
		if ( input.dataset.omninoxBound ) {
			return;
		}
		input.dataset.omninoxBound = 'true';
		input.setAttribute( 'autocomplete', 'off' );

		input.addEventListener( 'input', function () {
			var val = input.value.trim();
			clearTimeout( debounceTimer );

			if ( val.length < minChars ) {
				closePopup();
				return;
			}

			debounceTimer = setTimeout( function () {
				fetchResults( val, input );
			}, 280 );
		} );

		input.addEventListener( 'keydown', function ( e ) {
			if ( ! activePopup ) {
				return;
			}

			var items = activePopup.querySelectorAll( '.wpmt-search-result-item' );
			if ( ! items.length ) {
				return;
			}

			if ( e.key === 'ArrowDown' ) {
				e.preventDefault();
				selectedIndex = ( selectedIndex + 1 ) % items.length;
				updateSelection( items );
			} else if ( e.key === 'ArrowUp' ) {
				e.preventDefault();
				selectedIndex = ( selectedIndex - 1 + items.length ) % items.length;
				updateSelection( items );
			} else if ( e.key === 'Enter' && selectedIndex >= 0 ) {
				var link = items[ selectedIndex ].querySelector( 'a' );
				if ( link ) {
					e.preventDefault();
					window.location.href = link.href;
				}
			} else if ( e.key === 'Escape' ) {
				closePopup();
			}
		} );

		input.addEventListener( 'focus', function () {
			var val = input.value.trim();
			if ( val.length >= minChars ) {
				fetchResults( val, input );
			}
		} );
	}

	function updateSelection( items ) {
		for ( var i = 0; i < items.length; i++ ) {
			if ( i === selectedIndex ) {
				items[ i ].classList.add( 'is-selected' );
				items[ i ].scrollIntoView( { block: 'nearest' } );
			} else {
				items[ i ].classList.remove( 'is-selected' );
			}
		}
	}

	function fetchResults( term, input ) {
		var url = config.ajaxUrl + '?action=omninox_live_search&term=' + encodeURIComponent( term ) + '&nonce=' + encodeURIComponent( config.nonce );

		fetch( url )
			.then( function ( res ) { return res.json(); } )
			.then( function ( data ) {
				if ( data && data.success && data.data && Array.isArray( data.data.results ) ) {
					renderPopup( data.data.results, input, term );
				}
			} )
			.catch( function () {} );
	}

	function renderPopup( results, input, term ) {
		closePopup();

		var popup = document.createElement( 'div' );
		popup.className = 'wpmt-search-results-popup';

		if ( ! results.length ) {
			var noRes = document.createElement( 'div' );
			noRes.className = 'wpmt-search-no-results';
			noRes.textContent = ( config && config.noResults ) ? config.noResults : 'No results found';
			popup.appendChild( noRes );
		} else {
			var list = document.createElement( 'ul' );
			list.className = 'wpmt-search-results-list';

			results.forEach( function ( item ) {
				var li = document.createElement( 'li' );
				li.className = 'wpmt-search-result-item';

				var a = document.createElement( 'a' );
				a.className = 'wpmt-search-result-link';
				a.href = item.url;

				var head = document.createElement( 'div' );
				head.className = 'wpmt-search-result-header';

				var title = document.createElement( 'span' );
				title.className = 'wpmt-search-result-title';
				title.innerHTML = item.highlighted_title || item.title;

				var type = document.createElement( 'span' );
				type.className = 'wpmt-search-result-type';
				type.textContent = item.type;

				head.appendChild( title );
				head.appendChild( type );
				a.appendChild( head );

				if ( item.highlighted_excerpt || item.excerpt ) {
					var snippet = document.createElement( 'p' );
					snippet.className = 'wpmt-search-result-snippet';
					snippet.innerHTML = item.highlighted_excerpt || item.excerpt;
					a.appendChild( snippet );
				}

				li.appendChild( a );
				list.appendChild( li );
			} );

			popup.appendChild( list );
		}

		// Position popup directly beneath the input
		var rect = input.getBoundingClientRect();
		popup.style.position = 'absolute';
		popup.style.top = ( window.scrollY + rect.bottom + 4 ) + 'px';
		popup.style.left = ( window.scrollX + rect.left ) + 'px';
		popup.style.width = Math.max( rect.width, 320 ) + 'px';

		document.body.appendChild( popup );
		activePopup = popup;
		selectedIndex = -1;
	}

	function closePopup() {
		if ( activePopup ) {
			activePopup.remove();
			activePopup = null;
			selectedIndex = -1;
		}
	}

	document.addEventListener( 'click', function ( e ) {
		if ( activePopup && ! activePopup.contains( e.target ) && ! e.target.dataset.wpmtBound ) {
			closePopup();
		}
	} );

	window.addEventListener( 'resize', closePopup );
	window.addEventListener( 'scroll', closePopup, { passive: true } );

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
