/* global GSB, jQuery */
( function ( $ ) {
	'use strict';

	function post( action, data ) {
		return $.post( GSB.ajaxurl, $.extend( { action: action, nonce: GSB.nonce }, data || {} ) );
	}

	function setBar( pct, label, phase ) {
		$( '#gsb-progress' ).show();
		$( '#gsb-bar-fill' ).css( 'width', Math.max( 0, Math.min( 100, pct ) ) + '%' );
		if ( label ) {
			$( '#gsb-progress-label' ).text( label );
		}
		// Show human-readable phase name under the bar if provided
		if ( phase && GSB.strings[ phase ] ) {
			$( '#gsb-progress-phase' ).text( GSB.strings[ phase ] );
		} else if ( phase ) {
			$( '#gsb-progress-phase' ).text( phase );
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
		setBar( 0, GSB.strings.scanning || 'Scanning…', 'phase_scanning' );
		post( 'gsb_start_scan' ).done( function ( res ) {
			if ( ! res || ! res.success ) { return done(); }
			var total = res.data.total || 0;
			if ( ! total ) { setBar( 100, GSB.strings.done, '' ); return done(); }
			scanLoop( total );
		} ).fail( done );

		function scanLoop( total ) {
			setBar( 0, GSB.strings.scanning || 'Scanning…', 'phase_scanning' );
			post( 'gsb_scan_step', { per: 3 } ).done( function ( res ) {
				if ( ! res || ! res.success ) { return done(); }
				var p = res.data;
				var pct = total ? Math.round( ( p.done / total ) * 30 ) : 30; // scan = first 30%
				setBar( pct, ( GSB.strings.phase_scanning || 'Scanning content…' ) + ' ' + p.done + ' / ' + total, 'phase_scanning' );
				refreshStats();
				if ( p.complete ) {
					// Phase: creating chunks (already done inline during scan — jump straight to embed)
					setBar( 35, GSB.strings.phase_chunks || 'Creating chunks…', 'phase_chunks' );
					setTimeout( function () {
						// Phase: generating embeddings
						setBar( 40, GSB.strings.phase_embedding || 'Generating embeddings…', 'phase_embedding' );
						embedLoop( function () {
							// Phase: building knowledge graph
							setBar( 80, GSB.strings.phase_graph || 'Building knowledge graph…', 'phase_graph' );
							post( 'gsb_finalize' ).done( function ( res ) {
								// Fix 10: surface errors instead of always showing Done.
								if ( res && res.success ) {
									setBar( 95, GSB.strings.phase_visibility || 'Generating visibility data…', 'phase_visibility' );
									setTimeout( function () {
										setBar( 100, GSB.strings.phase_fixes || 'Generating fix queue…', 'phase_fixes' );
										setTimeout( function () {
											setBar( 100, GSB.strings.done, '' );
											refreshStats();
											done();
										}, 800 );
									}, 600 );
								} else {
									var errMsg = ( res && res.data && res.data.message ) || GSB.strings.error;
									$( '#gsb-progress-phase' ).addClass( 'gsb-bad' ).text( errMsg );
									setBar( 80, errMsg, '' );
									refreshStats();
									done();
								}
							} ).fail( function () {
								$( '#gsb-progress-phase' ).addClass( 'gsb-bad' ).text( GSB.strings.error );
								setBar( 80, GSB.strings.error, '' );
								refreshStats();
								done();
							} );
						} );
					}, 400 );
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
				// Indexed but not embedded — show clear message
				setBar( 75,
					( GSB.strings.no_openai || 'Embeddings skipped — add OpenAI key in Settings' ),
					''
				);
				$( '#gsb-progress-phase' ).addClass( 'gsb-bad' );
				return finish();
			}
			if ( p.embedded_now > 0 && p.unembedded > 0 ) {
				var totalChunks = p.chunks || 1;
				var pct = 40 + Math.round( ( p.embedded / totalChunks ) * 35 ); // embed = 40–75%
				setBar( pct,
					( GSB.strings.phase_embedding || 'Generating embeddings…' ) + ' ' + p.embedded + ' / ' + p.chunks,
					'phase_embedding'
				);
				embedLoop( onFinish );
			} else {
				setBar( 75, GSB.strings.phase_graph || 'Building knowledge graph…', 'phase_graph' );
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
		setBar( 0, GSB.strings.phase_embedding || 'Generating embeddings…', 'phase_embedding' );
		embedLoop();
	} );

	/* ------------------------------------------------------ rebuild recommendations */

	$( '#gsb-rebuild-recs' ).on( 'click', function ( e ) {
		e.preventDefault();
		var $b = $( this ).prop( 'disabled', true ).text( '…' );
		post( 'gsb_rebuild_recs' ).done( function ( res ) {
			if ( res && res.success ) {
				location.reload();
			} else {
				var msg = ( res && res.data && res.data.message ) || GSB.strings.error;
				alert( msg );
				$b.prop( 'disabled', false ).text( 'Rebuild' );
			}
		} ).fail( function () {
			alert( GSB.strings.error );
			$b.prop( 'disabled', false ).text( 'Rebuild' );
		} );
	} );

	/* ------------------------------------------------------------- reindex one post */

	$( document ).on( 'click', '.gsb-reindex-one', function ( e ) {
		e.preventDefault();
		var $b = $( this ).prop( 'disabled', true ).text( '…' );
		post( 'gsb_reindex_post', { post_id: $b.data( 'post' ) } ).done( function ( res ) {
			if ( res && res.success ) {
				$b.prop( 'disabled', false ).text( 'Reindex' );
			} else {
				$b.prop( 'disabled', false ).text( 'Error — retry' ).css( 'color', '#b32d2e' );
			}
		} ).fail( function () {
			$b.prop( 'disabled', false ).text( 'Error — retry' ).css( 'color', '#b32d2e' );
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
		var $li     = $( this ).closest( '.gsb-rec' );
		var $btn    = $( this );
		var status  = $btn.data( 'status' );
		// Disable button immediately so double-clicks don't fire twice.
		$btn.prop( 'disabled', true );
		post( 'gsb_rec_status', { id: $li.data( 'id' ), status: status } ).done( function ( res ) {
			// Bug 4 fix: only mutate UI when the server confirms success.
			// Previously .always() hid the row even when the server returned an error.
			if ( ! res || ! res.success ) {
				$btn.prop( 'disabled', false );
				return;
			}
			if ( 'in_progress' === status ) {
				$li.addClass( 'gsb-in-progress' );
				$btn.text( 'In Progress' );
			} else {
				$li.slideUp( 150, function () { $( this ).remove(); } );
			}
		} ).fail( function () {
			$btn.prop( 'disabled', false );
		} );
	} );

	/* --------------------------------------------------------- apply a fix */

	$( document ).on( 'click', '.gsb-apply-fix', function ( e ) {
		e.preventDefault();
		var $btn = $( this );
		var $li  = $btn.closest( '.gsb-rec' );
		var $result = $li.find( '.gsb-fix-result' );
		$btn.prop( 'disabled', true ).text( '…' );
		post( 'gsb_apply_fix', { id: $li.data( 'id' ) } ).done( function ( res ) {
			if ( res && res.success ) {
				var html = $( '<span></span>' ).text( res.data.message );
				$result.empty().addClass( 'gsb-ok' ).append( html );
				if ( res.data.edit_url ) {
					$result.append( ' ' ).append(
						$( '<a class="button button-small"></a>' ).attr( 'href', res.data.edit_url ).text( 'Open editor' )
					);
				}
				if ( ! res.data.manual ) {
					$btn.text( 'Applied ✓' );
					$li.addClass( 'gsb-applied' );
				} else {
					$btn.prop( 'disabled', false ).text( 'Apply Fix' );
					$li.addClass( 'gsb-manual' );
				}
			} else {
				// Fix 9: move the failed item into the failed section immediately
				// so it's visible without a page reload, instead of just adding a CSS class.
				var errMsg = ( res && res.data && res.data.message ) || GSB.strings.error;
				var $failed = $( '.gsb-fixqueue-failed' );
				if ( ! $failed.length ) {
					// No server-rendered failed section — create header + list dynamically.
					// Insert before the open queue (or the page sub heading if no open queue).
					$failed = $( '<ul class="gsb-recs gsb-fixqueue gsb-fixqueue-failed"></ul>' );
					var $header = $( '<h2 class="gsb-failed-header" style="color:#b32d2e;"></h2>' ).text( GSB.strings.fixes_failed || 'Fix failed' );
					var $anchor = $( '.gsb-fixqueue:not(.gsb-fixqueue-failed)' ).first();
					if ( $anchor.length ) {
						$anchor.before( $header ).before( $failed );
					} else {
						$li.closest( '.gsb-wrap' ).append( $header ).append( $failed );
					}
				} else if ( ! $( '.gsb-failed-header' ).length ) {
					// List exists (server-rendered) but header was removed or never added — prepend header.
					var $hdr = $( '<h2 class="gsb-failed-header" style="color:#b32d2e;"></h2>' ).text( GSB.strings.fixes_failed || 'Fix failed' );
					$failed.before( $hdr );
				}
				// Clone the item, strip action buttons, add retry + dismiss, prepend to failed list.
				var $clone = $li.clone().removeClass( 'gsb-in-progress gsb-applied' ).addClass( 'gsb-failed' );
				$clone.find( '.gsb-fix-result' ).addClass( 'gsb-bad' ).text( errMsg );
				$clone.find( '.gsb-rec-actions' ).html(
					'<button class="button button-primary gsb-apply-fix">' + ( GSB.strings.retry || 'Retry Fix' ) + '</button>' +
					'<button class="button button-small gsb-rec-act" data-status="dismissed">' + ( GSB.strings.dismiss || 'Dismiss' ) + '</button>'
				);
				$failed.prepend( $clone );
				$li.slideUp( 150, function () { $( this ).remove(); } );
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

	/* ------------------------------------------- regenerate API key / secret */

	$( document ).on( 'click', '.gsb-regen-key', function ( e ) {
		e.preventDefault();
		if ( ! window.confirm( 'Regenerate this value? Any tool using the old one will stop working.' ) ) {
			return;
		}
		var $btn = $( this ).prop( 'disabled', true );
		var target = $btn.data( 'target' );
		post( 'gsb_regen_key', { which: $btn.data( 'which' ) } ).done( function ( res ) {
			if ( res && res.success ) {
				$( '#' + target ).val( res.data.value );
			}
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
		// Send the value typed into the field so the user can test BEFORE saving.
		// Blank falls back to the saved key/DSN on the server.
		var typed = $( this ).closest( 'td' ).find( 'input' ).first().val() || '';
		var $out = $( '.gsb-test-result[data-for="' + which + '"]' ).text( '…' ).removeClass( 'gsb-ok gsb-bad' );
		post( 'gsb_test_' + which, { key: typed } ).done( function ( res ) {
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
