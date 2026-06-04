/* GEO Site Brain — dependency-free force-directed knowledge graph.
 * Reads JSON from #gsb-graph-data and renders an interactive node/edge map into
 * the #gsb-graph SVG. No external library (no D3/CDN) so it works on any host.
 */
( function () {
	'use strict';

	var host = document.getElementById( 'gsb-graph' );
	var dataEl = document.getElementById( 'gsb-graph-data' );
	if ( ! host || ! dataEl ) {
		return;
	}

	var graph;
	try {
		graph = JSON.parse( dataEl.textContent );
	} catch ( e ) {
		return;
	}
	if ( ! graph.nodes || ! graph.nodes.length ) {
		return;
	}

	var SVGNS = 'http://www.w3.org/2000/svg';
	var W = host.clientWidth || 800;
	var H = 460;
	var colors = {
		business: '#1d2327',
		service: '#2271b1',
		location: '#00794b',
		faq: '#b26200',
		testimonial: '#8c5cd6',
		author: '#557',
		other: '#888'
	};

	var svg = document.createElementNS( SVGNS, 'svg' );
	svg.setAttribute( 'width', '100%' );
	svg.setAttribute( 'height', H );
	svg.setAttribute( 'viewBox', '0 0 ' + W + ' ' + H );
	svg.classList.add( 'gsb-graph-svg' );
	host.innerHTML = '';
	host.appendChild( svg );

	var gLinks = document.createElementNS( SVGNS, 'g' );
	var gNodes = document.createElementNS( SVGNS, 'g' );
	svg.appendChild( gLinks );
	svg.appendChild( gNodes );

	// Index nodes + seed positions in a ring around the centre.
	var nodes = graph.nodes;
	var index = {};
	nodes.forEach( function ( n, i ) {
		index[ n.id ] = n;
		var angle = ( i / nodes.length ) * Math.PI * 2;
		var r = ( n.type === 'business' ) ? 0 : 150 + ( i % 5 ) * 18;
		n.x = W / 2 + Math.cos( angle ) * r;
		n.y = H / 2 + Math.sin( angle ) * r;
		n.vx = 0; n.vy = 0;
		n.deg = 0;
	} );
	var links = ( graph.links || [] ).filter( function ( l ) {
		return index[ l.source ] && index[ l.target ];
	} );
	links.forEach( function ( l ) { index[ l.source ].deg++; index[ l.target ].deg++; } );

	// Build SVG elements.
	var linkEls = links.map( function ( l ) {
		var line = document.createElementNS( SVGNS, 'line' );
		line.setAttribute( 'class', 'gsb-edge gsb-edge-' + ( l.status || 'found' ) );
		gLinks.appendChild( line );
		return line;
	} );

	var nodeEls = nodes.map( function ( n ) {
		var g = document.createElementNS( SVGNS, 'g' );
		g.setAttribute( 'class', 'gsb-node' );
		var radius = ( n.type === 'business' ) ? 16 : Math.max( 6, Math.min( 16, 6 + n.deg * 2 ) );
		n.r = radius;
		var c = document.createElementNS( SVGNS, 'circle' );
		c.setAttribute( 'r', radius );
		c.setAttribute( 'fill', colors[ n.type ] || colors.other );
		c.setAttribute( 'fill-opacity', n.status === 'recommended' ? 0.35 : 1 );
		c.setAttribute( 'stroke', '#fff' );
		c.setAttribute( 'stroke-width', 2 );
		g.appendChild( c );
		var t = document.createElementNS( SVGNS, 'text' );
		t.setAttribute( 'class', 'gsb-node-label' );
		t.setAttribute( 'dy', radius + 12 );
		t.setAttribute( 'text-anchor', 'middle' );
		t.textContent = n.label.length > 26 ? n.label.slice( 0, 24 ) + '…' : n.label;
		g.appendChild( t );
		var title = document.createElementNS( SVGNS, 'title' );
		title.textContent = n.label + ' (' + n.type + ( n.status ? ', ' + n.status : '' ) + ')';
		g.appendChild( title );
		gNodes.appendChild( g );
		enableDrag( g, n );
		return g;
	} );

	// Simple force simulation (Verlet-ish): repulsion + link springs + centering.
	var alpha = 1;
	function tick() {
		var i, j, n, m, dx, dy, dist, force;
		// Repulsion
		for ( i = 0; i < nodes.length; i++ ) {
			n = nodes[ i ];
			for ( j = i + 1; j < nodes.length; j++ ) {
				m = nodes[ j ];
				dx = n.x - m.x; dy = n.y - m.y;
				dist = Math.sqrt( dx * dx + dy * dy ) || 1;
				force = ( 2600 / ( dist * dist ) ) * alpha;
				var fx = ( dx / dist ) * force, fy = ( dy / dist ) * force;
				n.vx += fx; n.vy += fy; m.vx -= fx; m.vy -= fy;
			}
		}
		// Link springs
		links.forEach( function ( l ) {
			n = index[ l.source ]; m = index[ l.target ];
			dx = m.x - n.x; dy = m.y - n.y;
			dist = Math.sqrt( dx * dx + dy * dy ) || 1;
			var target = 90;
			force = ( ( dist - target ) / dist ) * 0.05 * alpha;
			var fx = dx * force, fy = dy * force;
			n.vx += fx; n.vy += fy; m.vx -= fx; m.vy -= fy;
		} );
		// Centering + integrate
		nodes.forEach( function ( nn ) {
			if ( nn.fixed ) { return; }
			nn.vx += ( W / 2 - nn.x ) * 0.002 * alpha;
			nn.vy += ( H / 2 - nn.y ) * 0.002 * alpha;
			nn.vx *= 0.85; nn.vy *= 0.85;
			nn.x += nn.vx; nn.y += nn.vy;
			nn.x = Math.max( 20, Math.min( W - 20, nn.x ) );
			nn.y = Math.max( 20, Math.min( H - 20, nn.y ) );
		} );
		render();
		alpha *= 0.985;
		if ( alpha > 0.02 ) {
			requestAnimationFrame( tick );
		}
	}

	function render() {
		links.forEach( function ( l, i ) {
			var a = index[ l.source ], b = index[ l.target ];
			linkEls[ i ].setAttribute( 'x1', a.x ); linkEls[ i ].setAttribute( 'y1', a.y );
			linkEls[ i ].setAttribute( 'x2', b.x ); linkEls[ i ].setAttribute( 'y2', b.y );
		} );
		nodes.forEach( function ( n, i ) {
			nodeEls[ i ].setAttribute( 'transform', 'translate(' + n.x + ',' + n.y + ')' );
		} );
	}

	function enableDrag( g, n ) {
		var dragging = false;
		g.style.cursor = 'grab';
		g.addEventListener( 'pointerdown', function ( ev ) {
			dragging = true; n.fixed = true; g.setPointerCapture( ev.pointerId ); alpha = Math.max( alpha, 0.3 );
		} );
		g.addEventListener( 'pointermove', function ( ev ) {
			if ( ! dragging ) { return; }
			var pt = svg.createSVGPoint(); pt.x = ev.clientX; pt.y = ev.clientY;
			var loc = pt.matrixTransform( svg.getScreenCTM().inverse() );
			n.x = loc.x; n.y = loc.y; n.vx = 0; n.vy = 0; render();
		} );
		g.addEventListener( 'pointerup', function () { dragging = false; n.fixed = false; alpha = Math.max( alpha, 0.2 ); requestAnimationFrame( tick ); } );
	}

	tick();
} )();
