( function () {
	var all = document.getElementById( 'pcm-all' );
	if ( ! all ) {
		return;
	}
	all.addEventListener( 'change', function () {
		document.querySelectorAll( '.pcm-metric' ).forEach( function ( c ) {
			c.checked = all.checked;
		} );
	} );
} )();
