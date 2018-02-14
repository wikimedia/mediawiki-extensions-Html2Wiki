/*
 * THIS COMMENT CAN BE REMOVED WHEN COPYING THIS CONTENT TO YOUR WIKI
 * THIS COMMENT DESCRIBES HOW TO INSTALL THIS JAVASCRIPT
 *
 * Copy and past the contents of this file to your wiki by editing the
 * "interface" page MediaWiki:Common.js
 * e.g. http://localhost/wiki/MediaWiki:Common.js
 *
 * If there is already content in that page, PRESERVE the existing content.
 * i.e. Append the source below.
 *
 * This JavaScript adds a link (Import HTML) to the 'tools' section of your wiki
 * making it easy to access the Special:Html2Wiki page.
 */

/* Any JavaScript here will be loaded for all users on every page load. */

/**
 * Cusomize the sidebar for the Html2Wiki extension
 * @see https://www.mediawiki.org/wiki/Manual:Interface/Sidebar
 * added by Greg Rundlett <info@eQuality-Tech.com>
 */
function isObject( obj ) {
	return typeof obj == 'object' && obj !== null;
}

function isArray( obj ) {
	return isObject( obj ) && obj.constructor.toString().indexOf( 'Array' ) != -1;
}

Array.prototype.Contains = function( element, strict ) {
	for( var i in this ) {
		if( this[i] == element && !strict || this[i] === element ) {
			return true;
		}
	}
	return false;
};

function ModifySidebar( action, section, name, link ) {
	try {
		var target;
		switch ( section ) {
			case 'languages':
				target = 'p-lang';
				break;
			case 'toolbox':
				target = 'p-tb';
				break;
			case 'navigation':
				target = 'p-navigation';
				break;
			default:
				target = 'p-' + section;
				break;
		}

		if ( action == 'add' ) {
			var node = document.getElementById( target )
							   .getElementsByTagName( 'div' )[0]
							   .getElementsByTagName( 'ul' )[0];

			var aNode = document.createElement( 'a' );
			var liNode = document.createElement( 'li' );

			aNode.appendChild( document.createTextNode( name ) );
			aNode.setAttribute( 'href', link );
			liNode.appendChild( aNode );
			liNode.className = 'plainlinks';
			node.appendChild( liNode );
		}

		if ( action == 'remove' ) {
			var list = document.getElementById( target )
							   .getElementsByTagName( 'div' )[0]
							   .getElementsByTagName( 'ul' )[0];

			var listelements = list.getElementsByTagName( 'li' );

			for ( var i = 0; i < listelements.length; i++ ) {
				if (
					listelements[i].getElementsByTagName( 'a' )[0].innerHTML == name ||
					listelements[i].getElementsByTagName( 'a' )[0].href == link
				)
				{
					list.removeChild( listelements[i] );
				}
			}
		}

	} catch( e ) {
		// let's just ignore what's happened
		return;
	}
}

jQuery( document ).ready( function( $ ) {
	// adds [[Special:Html2Wiki]] to toolbox
	ModifySidebar( 'add', 'toolbox', 'Import HTML', '/wiki/Special:Html2Wiki' );
});
