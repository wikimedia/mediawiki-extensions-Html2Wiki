/**
 * JavaScript for Html2Wiki.
 */
// Apparently these are not necessary in the newest mw, or at least not in my module
// var $ = jQuery;
// var mw = mediaWiki;

// Access multiple config values for use throughout our code base
// https://www.mediawiki.org/wiki/Manual:Interface/JavaScript#mw.config
var conf = mw.config.get([
	'wgServer',
	'wgPageName',
	'wgCanonicalSpecialPageName',
	'wgUserLanguage',
	'wgVersion' // The only one we're using at the moment
]);

var edittoken = mw.user.tokens.get( 'csrfToken' );
// alert( 'According to the api, you have an edit token of ' + edittoken );


/**
 * getToken();
 * A lot harder way of getting an edit token
 * @returns {undefined}
 */
function getToken () {
	var mwVersion = parseInt( conf.wgVersion.substr( 2, 2 ) );
	// if we're using mw 1.24 or greater, use action=query&meta=tokens to get a token
	if ( mwVersion > 24 ) {
		$.ajax({ url: mw.util.wikiScript( 'api' ), dataType: 'json', type: 'POST',
		data: {
			format: 'json',
			action: 'query',
			meta: 'tokens'
		}, success: function ( data ) {
			if ( data && data.query && data.query.tokens ) {
				var tokens = data.query.tokens;
				var token = tokens.csrftoken;
				alert (token);
			}
		}, error: function () {
			alert('no token');
		}});
	} else {
		alert('old version of MediaWiki ' + mwVersion);
	}
}

var api = new mw.Api();
/*
var summary = "This is a summary";
var content = "Hello World";
addNewArticle(summary, content);

function addNewArticle( summary, content ) {
	api.postWithToken( "edit", {
		action: "edit",
		title: "NewPage",
		summary: summary,
		text: content,
		token: edittoken
	} ).done( function( result, jqXHR ) {
		mw.log("Saved new Article at NewPage");
		location.reload();
	} ).fail( function( code, result ) {
		if ( code === "http" ) {
			mw.log( "HTTP error: " + result.textStatus ); // result.xhr contains the jqXHR object
		} else if ( code === "ok-but-empty" ) {
			mw.log( "Got an empty response from the server" );
		} else {
			mw.log( "API error: " + code );
		}
	} );
}
*/

function addNewSection( summary, content ) {
	api.postWithToken( "edit", {
		action: "edit",
		title: mw.config.get( "wgPageName" ),
		section: "new",
		summary: summary,
		text: content
	} ).done( function( result, jqXHR ) {
		mw.log( "Saved successfully" );
		location.reload();
	} ).fail( function( code, result ) {
		if ( code === "http" ) {
			mw.log( "HTTP error: " + result.textStatus ); // result.xhr contains the jqXHR object
		} else if ( code === "ok-but-empty" ) {
			mw.log( "Got an empty response from the server" );
		} else {
			mw.log( "API error: " + code );
		}
	} );
}

/**
 *
 * practical example to get working
 */

// Execute this after the site is loaded.
jQuery(document).ready(function ($) {
    // Find list items representing folders and
    // style them accordingly.  Also, turn them
    // into links that can expand/collapse the
    // tree leaf.
    $('li > ul').each(function (i) {
        // Find this list's parent list item.
        var parent_li = $(this).parent('li');

        // Style the list item as folder.
        parent_li.addClass('folder');

        // Temporarily remove the list from the
        // parent list item, wrap the remaining
        // text in an anchor, then reattach it.
        var sub_ul = $(this).remove();
        parent_li.wrapInner('<a>').find('a').click(function () {
            // Make the anchor toggle the leaf display.
            sub_ul.toggle();
        });
        parent_li.append(sub_ul);
    });

    // Hide all lists except the outermost.
    $('ul ul').hide();

	// #h2wWand isn't available on the form screen, only on the result.
	// @todo wrap this in a function that is only called on that view
	var h2wButton = $( "#h2wWand" );
	var h2wContent = $( "#h2wContent" ).html();
	console.log( h2wContent );
	h2wButton.click(function() {
		h2wContent = h2wContent.replace(/<(style|script|object|applet|embed)\b[^>]*>.*?<\/\1>/g, '');
	});

	// click the label, and show all the elements of the source
	$( "#h2wLabel" ).click(function() {
	   // alert('hello world');

		var $content = $( "#h2wContent" ),
		str = $content.html(),
		html = $.parseHTML( str ),
		nodeNames = [];

		// Append the parsed HTML
		// $content.append( html );
		// Gather the parsed HTML's node names
		$.each( html, function( i, el ) {
		  nodeNames[ i ] = "<li>" + el.nodeName + "</li>";
		});

		// Insert the node names
		$content.append( "<h3>Node Names:</h3>" );
		$( "<ol></ol>" )
		  .append( nodeNames.join( "" ) )
		  .appendTo( $content );

	});
    
    /**
     * a simple UI enhancement to add a throbber to the submit button
     * since some uploads will take a long time.
     */
    $("#html2wiki-submit").click(function() {
		$(".mw-ext-Html2Wiki-loading").show();
	});

});

