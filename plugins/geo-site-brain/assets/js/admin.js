/* global GSB, jQuery */
( function ( $ ) {
	'use strict';

	function post( action, data ) {
		return $.post( GSB.ajaxurl, $.extend( { action: action, nonce: GSB.nonce }, data || {} ) );
	}

	function setBar( pct, label ) {
		$( '#gsb-progress' ).show();
		$( '#gsb-bar-fill' ).css( 'width', Math.max( 0, Math.min( 100, pct ) ) + '%' );
		if ( label ) {
			$( '#gsb-progress-label' ).text( label );
		}
	}

	function refreshStats() {
		post( 'gsb_progress' ).done( function ( res ) {
			if ( ! res || ! res.success ) { return; }
			var p = res.data;
			$( '#gsb-stat-posts' ).text( p.posts );
			$( '#gsb-stat-chunks' ).text( p.chunks );
			$( '#gsb-stat-embedded' ).text( p.embedded );
			$( '#gsb-stat-unembedded' ).text( p.unembedded );
		} );
	}

	/* --------------------------------------------------- full scan + embed loop */

	function runScan() {
		var $btn = $( '#gsb-run-scan' ).prop( 'disabled', true );
		post( 'gsb_start_scan' ).done( function ( res ) {
			if ( ! res || ! res.success ) { return done(); }
			var total = res.data.total || 0;
			if ( ! total ) { setBar( 100, GSB.strings.done ); return done(); }
			scanLoop( total );
		} ).fail( done );

		function scanLoop( total ) {
			post( 'gsb_scan_step', { per: 3 } ).done( function ( res ) {
				if ( ! res || ! res.success ) { return done(); }
				var p = res.data;
				var pct = total ? Math.round( ( p.done / total ) * 100 ) : 100;
				setBar( pct, GSB.strings.scanning + ' ' + p.done + ' / ' + total );
				refreshStats();
				if ( p.complete ) {
					// Embed, then build the business understanding (entities,
					// knowledge graph, AI visibility, fix queue) from real vectors.
					setBar( pct, GSB.strings.embedding );
					embedLoop( function () {
						setBar( 100, GSB.strings.understanding || 'Building knowledge…' );
						post( 'gsb_finalize' ).always( function () {
							setBar( 100, GSB.strings.done );
						} );
					} );
				} else {
					scanLoop( total );
				}
			} ).fail( done );
		}

		function done() {
			$btn.prop( 'disabled', false );
		}
	}

	function embedLoop( onFinish ) {
		post( 'gsb_embed_step' ).done( function ( res ) {
			if ( ! res || ! res.success ) { return finish(); }
			var p = res.data;
			refreshStats();
			if ( ! p.has_openai ) {
				setBar( 100, GSB.strings.done + ' (OpenAI not configured — embeddings skipped)' );
				return finish();
			}
			if ( p.embedded_now > 0 && p.unembedded > 0 ) {
				var totalChunks = p.chunks || 1;
				var pct = Math.round( ( p.embedded / totalChunks ) * 100 );
				setBar( pct, GSB.strings.embedding + ' ' + p.embedded + ' / ' + p.chunks );
				embedLoop( onFinish );
			} else {
				setBar( 100, GSB.strings.done );
				finish();
			}
		} ).fail( finish );

		function finish() {
			$( '#gsb-run-scan, #gsb-embed-only' ).prop( 'disabled', false );
			if ( typeof onFinish === 'function' ) { onFinish(); }
		}
	}

	$( '#gsb-run-scan' ).on( 'click', function ( e ) { e.preventDefault(); runScan(); } );
	$( '#gsb-embed-only' ).on( 'click', function ( e ) {
		e.preventDefault();
		$( this ).prop( 'disabled', true );
		setBar( 0, GSB.strings.embedding );
		embedLoop();
	} );

	/* ------------------------------------------------------ rebuild recommendations */

	$( '#gsb-rebuild-recs' ).on( 'click', function ( e ) {
		e.preventDefault();
		var $b = $( this ).prop( 'disabled', true ).text( '…' );
		post( 'gsb_rebuild_recs' ).always( function () {
			location.reload();
		} );
	} );

	/* ------------------------------------------------------------- reindex one post */

	$( document ).on( 'click', '.gsb-reindex-one', function ( e ) {
		e.preventDefault();
		var $b = $( this ).prop( 'disabled', true ).text( '…' );
		post( 'gsb_reindex_post', { post_id: $b.data( 'post' ) } ).always( function () {
			$b.prop( 'disabled', false ).text( 'Reindex' );
		} );
	} );

	/* ----------------------------------------------------------- score breakdown */

	$( document ).on( 'click', '.gsb-toggle-detail', function ( e ) {
		e.preventDefault();
		var id = $( this ).closest( '.gsb-score-row' ).data( 'row' );
		$( '#gsb-detail-' + id ).toggle();
	} );

	/* ------------------------------------------------------- recommendation actions */

	$( document ).on( 'click', '.gsb-rec-act', function ( e ) {
		e.preventDefault();
		var $li = $( this ).closest( '.gsb-rec' );
		post( 'gsb_rec_status', { id: $li.data( 'id' ), status: $( this ).data( 'status' ) } ).always( function () {
			$li.slideUp( 150, function () { $( this ).remove(); } );
		} );
	} );

	/* --------------------------------------------------------- apply a fix */

	$( document ).on( 'click', '.gsb-apply-fix', function ( e ) {
		e.preventDefault();
		var $btn = $( this );
		var $li = $btn.closest( '.gsb-rec' );
		var $result = $li.find( '.gsb-fix-result' );
		$btn.prop( 'disabled', true ).text( '…' );
		post( 'gsb_apply_fix', { id: $li.data( 'id' ) } ).done( function ( res ) {
			if ( res && res.success ) {
				var html = $( '<span></span>' ).text( res.data.message );
				$result.empty().addClass( 'gsb-ok' ).append( html );
				if ( res.data.edit_url ) {
					$result.append( ' ' ).append( $( '<a class="button button-small"></a>' ).attr( 'href', res.data.edit_url ).text( 'Open editor' ) );
				}
				if ( ! res.data.manual ) {
					$btn.text( 'Applied ✓' );
					$li.addClass( 'gsb-applied' );
				} else {
					$btn.prop( 'disabled', false ).text( 'Apply Fix' );
				}
			} else {
				$result.addClass( 'gsb-bad' ).text( ( res && res.data && res.data.message ) || GSB.strings.error );
				$btn.prop( 'disabled', false ).text( 'Apply Fix' );
			}
		} ).fail( function () {
			$result.addClass( 'gsb-bad' ).text( GSB.strings.error );
			$btn.prop( 'disabled', false ).text( 'Apply Fix' );
		} );
	} );

	/* ------------------------------------------------- live per-engine probe */

	$( document ).on( 'click', '.gsb-probe', function ( e ) {
		e.preventDefault();
		var $btn = $( this );
		var engine = $btn.data( 'engine' );
		var $status = $( '.gsb-probe-status[data-engine="' + engine + '"]' ).text( GSB.strings.thinking ).removeClass( 'gsb-bad' );
		$btn.prop( 'disabled', true );
		post( 'gsb_probe', { engine: engine } ).done( function ( res ) {
			if ( res && res.success ) {
				$status.text( GSB.strings.done );
				location.reload();
			} else {
				$status.addClass( 'gsb-bad' ).text( ( res && res.data && res.data.message ) || GSB.strings.error );
				$btn.prop( 'disabled', false );
			}
		} ).fail( function () {
			$status.addClass( 'gsb-bad' ).text( GSB.strings.error );
			$btn.prop( 'disabled', false );
		} );
	} );

	$( '#gsb-probe-all' ).on( 'click', function ( e ) {
		e.preventDefault();
		var $btn = $( this ).prop( 'disabled', true ).text( GSB.strings.thinking );
		post( 'gsb_probe', { engine: 'all' } ).done( function ( res ) {
			if ( res && res.success ) {
				location.reload();
			} else {
				$btn.prop( 'disabled', false ).text( 'Run live probe on all engines' );
				alert( ( res && res.data && res.data.message ) || GSB.strings.error );
			}
		} ).fail( function () {
			$btn.prop( 'disabled', false ).text( 'Run live probe on all engines' );
		} );
	} );

	/* ------------------------------------------------------- competitors */

	$( '#gsb-run-competitors' ).on( 'click', function ( e ) {
		e.preventDefault();
		var $btn = $( this ).prop( 'disabled', true );
		var $st = $( '#gsb-comp-status' ).text( GSB.strings.thinking ).removeClass( 'gsb-bad' );
		post( 'gsb_run_competitors' ).done( function ( res ) {
			if ( res && res.success ) {
				$st.text( GSB.strings.done );
				location.reload();
			} else {
				$st.addClass( 'gsb-bad' ).text( ( res && res.data && res.data.message ) || GSB.strings.error );
				$btn.prop( 'disabled', false );
			}
		} ).fail( function () {
			$st.addClass( 'gsb-bad' ).text( GSB.strings.error );
			$btn.prop( 'disabled', false );
		} );
	} );

	/* ----------------------------------------------------- send test digest */

	$( '#gsb-send-digest' ).on( 'click', function ( e ) {
		e.preventDefault();
		var $btn = $( this ).prop( 'disabled', true );
		var $out = $( '.gsb-test-result[data-for="digest"]' ).text( '…' ).removeClass( 'gsb-ok gsb-bad' );
		post( 'gsb_send_digest' ).done( function ( res ) {
			if ( res && res.success ) {
				$out.addClass( 'gsb-ok' ).text( res.data.message );
			} else {
				$out.addClass( 'gsb-bad' ).text( ( res && res.data && res.data.message ) || GSB.strings.error );
			}
		} ).fail( function () {
			$out.addClass( 'gsb-bad' ).text( GSB.strings.error );
		} ).always( function () {
			$btn.prop( 'disabled', false );
		} );
	} );

	/* ----------------------------------------------- AI visibility narrative */

	$( document ).on( 'click', '.gsb-narrative', function ( e ) {
		e.preventDefault();
		var $btn = $( this );
		var engine = $btn.data( 'engine' );
		var $out = $( '.gsb-narrative-out[data-engine="' + engine + '"]' );
		$out.text( GSB.strings.thinking );
		$btn.prop( 'disabled', true );
		post( 'gsb_narrative', { engine: engine } ).done( function ( res ) {
			if ( res && res.success ) {
				$out.text( res.data.narrative );
			} else {
				$out.text( ( res && res.data && res.data.message ) || GSB.strings.error );
			}
		} ).fail( function () {
			$out.text( GSB.strings.error );
		} ).always( function () {
			$btn.prop( 'disabled', false );
		} );
	} );

	/* ---------------------------------------------------------------- test buttons */

	$( document ).on( 'click', '.gsb-test', function ( e ) {
		e.preventDefault();
		var which = $( this ).data( 'test' );
		var $out = $( '.gsb-test-result[data-for="' + which + '"]' ).text( '…' ).removeClass( 'gsb-ok gsb-bad' );
		post( 'gsb_test_' + which ).done( function ( res ) {
			if ( res && res.success ) {
				$out.addClass( 'gsb-ok' ).text( res.data.message );
			} else {
				$out.addClass( 'gsb-bad' ).text( ( res && res.data && res.data.message ) || GSB.strings.error );
			}
		} ).fail( function () {
			$out.addClass( 'gsb-bad' ).text( GSB.strings.error );
		} );
	} );

	/* ------------------------------------------------------------------------ chat */

	function appendMsg( cls, text ) {
		var $m = $( '<div class="gsb-msg ' + cls + '"></div>' ).text( text );
		$( '#gsb-chat-log' ).append( $m );
		$( '#gsb-chat-log' ).scrollTop( $( '#gsb-chat-log' )[ 0 ].scrollHeight );
		return $m;
	}

	function ask( question ) {
		if ( ! question ) { return; }
		appendMsg( 'gsb-msg-user', question );
		var $thinking = appendMsg( 'gsb-msg-agent', GSB.strings.thinking );
		post( 'gsb_chat', { question: question } ).done( function ( res ) {
			if ( ! res || ! res.success ) {
				$thinking.text( GSB.strings.error );
				return;
			}
			var d = res.data;
			$thinking.text( d.answer );
			if ( d.sources && d.sources.length ) {
				var $s = $( '<div class="gsb-sources"></div>' ).append( document.createTextNode( 'Sources: ' ) );
				d.sources.forEach( function ( src, i ) {
					if ( i ) { $s.append( ' · ' ); }
					if ( src.url ) {
						$s.append( $( '<a target="_blank" rel="noopener"></a>' ).attr( 'href', src.url ).text( src.title ) );
					} else {
						$s.append( document.createTextNode( src.title ) );
					}
				} );
				$thinking.append( $s );
			}
			$thinking.append( $( '<span class="gsb-backend-tag"></span>' ).text(
				( d.used_ai ? 'AI answer' : 'Retrieval only' ) + ' · ' + d.backend
			) );
		} ).fail( function () {
			$thinking.text( GSB.strings.error );
		} );
	}

	$( '#gsb-chat-form' ).on( 'submit', function ( e ) {
		e.preventDefault();
		var q = $.trim( $( '#gsb-chat-input' ).val() );
		$( '#gsb-chat-input' ).val( '' );
		ask( q );
	} );

	$( document ).on( 'click', '.gsb-sample', function ( e ) {
		e.preventDefault();
		ask( $( this ).text() );
	} );

} )( jQuery );
