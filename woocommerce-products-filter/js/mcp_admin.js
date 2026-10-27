/* HUSKY settings, "MCP Server" tab. */
( function ( $ ) {
	'use strict';

	var cfg = window.woof_mcp_admin || {};

	function cleanPath( v ) {
		v = String( v || '' ).trim();
		if ( /^https?:\/\//i.test( v ) && cfg.home && v.toLowerCase().indexOf( cfg.home.toLowerCase().replace( /\/$/, '' ) ) === 0 ) {
			v = v.substr( cfg.home.replace( /\/$/, '' ).length );
		}
		v = v.split( '?' )[ 0 ].split( '#' )[ 0 ].toLowerCase();
		return v.split( '/' ).map( function ( s ) {
			return s.replace( /[^a-z0-9._-]/g, '' );
		} ).filter( function ( s ) {
			return s !== '' && s !== '.' && s !== '..';
		} ).join( '/' );
	}

	// The address everywhere on the tab follows the pretty path as it is typed
	function applyAddress() {
		var path = cleanPath( $( '#woof_mcp_pretty_path' ).val() );
		var url = path ? cfg.home + path : cfg.rest;
		$( '#woof_mcp_pretty_full' ).text( path ? cfg.home + path : '-' );
		$( '#woof_mcp_public_url' ).val( url );
		var card = path ? url.replace( /\/$/, '' ) + '/server-card' : $( '#woof_mcp_card_link' ).data( 'default' );
		if ( card ) {
			$( '#woof_mcp_card_link' ).attr( 'href', card ).text( card );
		}
		$( '#woof_mcp_cc_cmd, #woof_mcp_cc_json, #woof_mcp_cursor_json, #woof_mcp_vscode_json, #woof_mcp_inspector' ).each( function () {
			var el = $( this );
			if ( ! el.data( 'tpl' ) ) {
				el.data( 'tpl', el.text().split( cfg.publicAtLoad ).join( '{{URL}}' ) );
			}
			el.text( el.data( 'tpl' ).split( '{{URL}}' ).join( url ) );
		} );
	}

	var timer = null;
	function checkPath() {
		clearTimeout( timer );
		timer = setTimeout( function () {
			var path = cleanPath( $( '#woof_mcp_pretty_path' ).val() );
			var box = $( '#woof_mcp_conflicts' );
			if ( ! path ) {
				box.hide().empty();
				return;
			}
			$.post( cfg.ajax, { action: 'woof_mcp_check_path', nonce: cfg.nonce, path: path }, function ( r ) {
				if ( ! r || ! r.success ) {
					return;
				}
				box.empty().show();
				if ( r.data.conflicts.length ) {
					box.append( $( '<span class="woof-mcp-warn"/>' ).text( cfg.used ) ).append( '<br/>' );
					r.data.conflicts.forEach( function ( c ) {
						box.append( $( '<span class="woof-mcp-warn"/>' ).text( '- ' + c ) ).append( '<br/>' );
					} );
				} else {
					box.append( $( '<span class="woof-mcp-ok"/>' ).text( cfg.free ) );
				}
			} );
		}, 350 );
	}

	$( function () {
		if ( ! $( '#tabs-mcp' ).length ) {
			return;
		}

		cfg.publicAtLoad = $( '#woof_mcp_public_url' ).val();
		$( '#woof_mcp_card_link' ).data( 'default', $( '#woof_mcp_card_link' ).attr( 'href' ) );

		// switching on shows the address and the how-to at once, no reload
		$( '#woof_mcp_enabled' ).on( 'change', function () {
			$( '.woof-mcp-on' ).toggle( $( this ).val() === '1' );
		} );

		$( '#woof_mcp_pretty_path' ).on( 'input change', function () {
			applyAddress();
			checkPath();
		} );

		$( '#woof_mcp_reset_ranges' ).on( 'click', function () {
			var ta = $( '#woof_mcp_ai_ranges' );
			ta.val( ta.data( 'default' ) );
		} );

		$( '#woof_mcp_reset_instructions' ).on( 'click', function () {
			var ta = $( '#woof_mcp_instructions' );
			ta.val( ta.data( 'default' ) );
		} );

		$( '#tabs-mcp' ).on( 'click', '.woof-mcp-copy', function () {
			var btn = $( this );
			var el = document.getElementById( btn.data( 'target' ) );
			if ( ! el ) {
				return;
			}
			var text = ( el.value !== undefined && el.tagName !== 'PRE' ) ? el.value : el.textContent;
			var done = function () {
				var t = btn.text();
				btn.text( cfg.copied );
				setTimeout( function () {
					btn.text( t );
				}, 1500 );
			};
			if ( navigator.clipboard && window.isSecureContext ) {
				navigator.clipboard.writeText( text ).then( done );
			} else {
				var tmp = $( '<textarea/>' ).val( text ).css( { position: 'fixed', left: '-9999px' } ).appendTo( 'body' );
				tmp[ 0 ].select();
				document.execCommand( 'copy' );
				tmp.remove();
				done();
			}
		} );
	} );
} )( jQuery );
