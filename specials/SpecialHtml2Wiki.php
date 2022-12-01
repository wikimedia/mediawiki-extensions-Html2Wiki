<?php

/**
 * Import/Upload SpecialPage for Html2Wiki extension
 *
 * @see https://www.mediawiki.org/wiki/Manual:Special_pages
 * @file
 * @ingroup Extensions
 * @package Html2Wiki
 *
 * @todo implement style guide https://www.mediawiki.org/wiki/Design/Living_style_guide
 */

use MediaWiki\MediaWikiServices;

class SpecialHtml2Wiki extends SpecialPage {

	/** @var array of original file upload attributes
	 * [filename]
	 * [mimetype]
	 * [filesize]
	 * []
	 *
	 */
	private $mOriginal;

	/** @var string The HTML we want to turn into wiki text */
	private $mContent;
	private $mContentRaw;  // the exact contents which were uploaded
	private $mContentTidy; // Raw afer it's passed through tidy
	/** @var string The (original) name of the uploaded file */
	private $mFilename; // supplied
	public $mArticleTitle; // created by mArticleSavePath and mFilename or unWrap

	/** @var int The size, in bytes, of the uploaded file. */
	private $mFilesize;
	private $mIsDryRun; // @var bool false option to save or only preview results
	private $mTidyErrors;  // the error output of tidy
	private $mTidyConfig;  // the configuration we want to use for tidy.
	private $mMimeType;  // the detected or inferred mime-type of the upload
	private $mDataDir; // where we want to temporarily store and retrieve data from
	/** name for the collection we're importing e.g. UNH-1.1d
	 *
	 * @var string a specific identifier for a set of documents imported into
	 * the wiki.  Gathered from the form, if the value has any slashes in it
	 * then Collection name is the first element and the full supplied value is
	 * used as $mArticleSavePath
	 */
	private $mCollectionName;
	protected $mArticleSavePath;
	/**
	 *
	 * @var bool Html2Wiki started purpose built to detect and handle two types
	 * of zipped content.  The challenge now is to make the parsing and handling
	 * of content recipe driven so that a user can interact with the system without
	 * having to re-code anything yet still achieve the powerful results available
	 * with Tidy, QueryPath and Pandoc
	 */
	public $mIsRecognized;

	public $mFilesAreProcessed; // boolean, whether we've (uploaded and) processed content files
	public $mResults; // the HTML-formatted result report of an import
	public $mFileCountExpected; // the number of files we expect to process
	public $mFileCountProcessed; // the number of files we've actually processed

	/** @var array List of common image files extensions and MIME-types
	 * and HTML MIME-type
	 * as well as the compressed archive types we will allow
	 */
	protected static $mimeTypes = [
		'gif' => 'image/gif',
		'jpe' => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'jpg' => 'image/jpeg',
		'png' => 'image/png',
		'tif' => 'image/tiff',
		'tiff' => 'image/tiff',
		'xbm' => 'image/x-xbitmap',
		'svg' => 'image/svg+xml',
		// HTML content
		'htm' => 'text/html',
		'html' => 'text/html',
		// archives
		'gz' => 'application/x-gzip',
		'gzip' => 'application/x-gzip',
		'tar.gz' => 'application/x-gtar',
		'tgz' => 'application/x-gtar',
		'zip' => 'application/zip',
	];

	/** @var array List of common Tidy options that we want to use in order
	 * to clean up incoming HTML
	 */
	protected static $tidyOpts = [
		"drop-empty-paras" => 1,
		"drop-font-tags" => 1,
		"enclose-block-text" => 1,
		"enclose-text" => 1,
		"fix-backslash" => 1,
		"fix-bad-comments" => 1,
		"fix-uri" => 1,
		"hide-comments" => 1,
		"merge-divs" => 1,
		"merge-spans" => 1,
		"repeated-attributes" => "keep-first",
		"show-body-only" => 1,
		"show-errors" => 0,
		"show-warnings" => 0,
		"indent" => 0,
		"wrap" => 120,
		"tidy-mark" => 0,
		"write-back" => 0
	];

	/** @var WebRequest|FauxRequest The request this form is supposed to handle */
	public $mRequest;

	public $mComment;

	public function doesWrites() {
		return true;
	}

	/**
	 * Shows the page to the user.
	 * @param string $sub The subpage string argument (if any).
	 */
	public function execute( $sub ) {
		$out = $this->getOutput();

		$out->setPageTitle( $this->msg( 'html2wiki-title' ) );

		/**
		 * Restrict access to the importing of content.
		 * Use the same approach as Special:Import since if you're
		 * allowed to import wiki content, we'll also allow you to import
		 * HTML content.
		 */
		$user = $this->getUser();
		// Even without the isAllowsedAny check, the anonymous user sees
		// 'No transwiki import sources have been defined and direct history uploads are disabled.'
		# @todo Allow PermissionManager::getPermissionErrors() to take an array
		# @todo FIXME: PermissionManager::checkSpecialsAndNSPermissions() has a very weird expectation
		# of what getPermissionErrors() might actually be used for, hence the 'ns-specialprotected'
		$title = $this->getPageTitle();
		$ignoreErrors = [ 'ns-specialprotected', 'badaccess-group0', 'badaccess-groups' ];
		if ( class_exists( 'MediaWiki\Permissions\PermissionManager' ) ) {
			// MW 1.33+
			$permManager = MediaWikiServices::getInstance()->getPermissionManager();
			$errors = wfMergeErrorArrays(
				$permManager->getPermissionErrors(
					'import', $user, $title,
					'secure', // Cannot access PermissionManager::RIGOR_SECURE,
					$ignoreErrors
				),
				$permManager->getPermissionErrors(
					'importupload', $user, $title, 'secure', $ignoreErrors
				)
			);
		} else {
			$errors = wfMergeErrorArrays(
				$title->getUserPermissionsErrors(
					'import', $user, true, $ignoreErrors
				),
				$title->getUserPermissionsErrors(
					'importupload', $user, true, $ignoreErrors
				)
			);
		}

		if ( $errors ) {
			throw new PermissionsError( 'import', $errors );
		}
		// from parent, throw an error if the wiki is in read-only mode
		$this->checkReadOnly();
		$request = $this->getRequest();
		if ( $request->wasPosted() && $request->getVal( 'action' ) == 'submit' ) {
			$this->loadRequest();
			$this->doImport();
		} else {
			$this->showForm();
		}
	}

	protected function loadRequest() {
		$this->mRequest = $request = $this->getRequest();
		// get the value from the form, or use the default defined in the language messages
		// $commentDefault = wfMessage( 'html2wiki-comment' )->inContentLanguage()->plain();
		$commentDefault = wfMessage( 'html2wiki-comment' )->inContentLanguage()->parse();
		$this->mComment = $request->getText( 'log-comment', $commentDefault );
		$this->mIsDryRun = $request->getCheck( 'dry-run' );

		// use the mCollectionName for tagging content.
		// mArticleSavePath does not contain the CollectionName; equals the value any intermediate path elements NOT including the file name
		// mArticleTitle will be the full value of mCollectionName (if any) plus mArticleSavePath plus the file name MINUS any extension
		$this->mCollectionName = (string)$request->getText( 'collection-name' );
		$upload = $request->getUpload( 'userfile' );
		if ( $this->mCollectionName ) {
			// remove a leading slash because Collection Names must not start with a slash
			// and also 'save path' must be relative
			$this->mCollectionName = ltrim( $this->mCollectionName, '/' );
			// make sure that any collection name is at least two characters
			if ( strlen( $this->mCollectionName ) < 2 ) {
				$this->showForm( "Invalid Collection Name; must be greater than a single character" );
			}
			// if it's not naked, split it into a name and a path (and fix up
			// the path to ensure there is a trailing slash)
			// We do this here to handle single files, but also later in Upload
			// to handle zips
			if ( stristr( $this->mCollectionName, '/' ) ) {
				$parts = explode( '/', $this->mCollectionName );
				$this->mCollectionName = array_shift( $parts );
				if ( count( $parts ) ) {
					$this->mArticleSavePath = implode( '/', $parts ); // back to a string
					// ensure a trailing slash
					$this->mArticleSavePath = ( substr( $this->mArticleSavePath, -1 ) == '/' ) ? $this->mArticleSavePath : "{$this->mArticleSavePath}/";
				}
			}
			// later if we detect a zip, we'll build a different filename
			// for now, assume a single HTML upload to replace an existing collection title
			$this->mArticleTitle = $this->mFilename = $this->mCollectionName . '/' . $this->mArticleSavePath . $upload->getName();
		} else {
			$this->mArticleTitle = $this->mFilename = $upload->getName();
		}
		// We'll make the title without the extension
		$this->mArticleTitle = self::removeExtensionFromPath( $this->mArticleTitle );
	}

	/**
	 * Takes a filepath and removes the 'extension' component
	 *
	 * It's awkward to have titles in a wiki like example.html, so we can use
	 * this function to make that title 'example'.
	 * readme.txt will still be readme.txt unless $htmlonly is false
	 * E.g. /foo/bar/file.tar.gz
	 * becomes /foo/bar/file.tar
	 * @param string $path
	 * @param bool $htmlonly Only strip extensions from files named .htm(l)
	 * @return string
	 */
	public static function removeExtensionFromPath( $path, $htmlonly = true ) {
		$path_parts = pathinfo( $path );
		// nothing to do?
		if ( !isset( $path_parts['extension'] ) || empty( $path_parts['extension'] ) ) {
			return $path;
		}
		$ishtml = substr( $path_parts['extension'], 0, 3 ) == 'htm';
		if ( $htmlonly && !$ishtml ) {
			return $path;
		}

		$str = '';
		if ( stristr( $path, '/' ) ) {
			$str .= $path_parts['dirname'] . '/';
		}
		$str .= $path_parts['filename'];
		$ext = $path_parts['extension'];
		if ( stristr( $ext, '#' ) ) {
			$hash = substr( $ext, strrpos( $ext, '#' ) );
			$str .= $hash;
		}
		return $str;
	}

	/**
	 * Get data POSTed through the form and assign them to the object
	 * @param WebRequest|null $request Data posted.
	 * We'll use the parent's constructor to instantiate the name but not perms
	 */
	public function __construct( $request = null ) {
		parent::__construct( 'Html2Wiki' );
	}

	/**
	 * Override the parent to set where the special page appears on Special:SpecialPages
	 * 'other' is the default, so you do not need to override if that's what you want.
	 * Specify 'media' to use the specialpages-group-media system interface
	 * message, which translates to 'Media reports and uploads' in English.
	 *
	 * @return string
	 */
	protected function getGroupName() {
		return 'media';
	}

	/**
	 * You can get the mimetype of an arbitrary file in bash with
	 * file --mime-type $file
	 *
	 * @param string $file
	 * @return bool|string
	 */
	public static function getMimeType( $file ) {
		$realpath = realpath( $file );
		if (
				$realpath && function_exists( 'finfo_file' ) && function_exists( 'finfo_open' ) && defined( 'FILEINFO_MIME_TYPE' )
		) {
			$mimeType = finfo_file( finfo_open( FILEINFO_MIME_TYPE ), $realpath );
			if ( array_search( $mimeType, self::$mimeTypes ) ) {
				return $mimeType;
			} else {
				return false; // not allowed type
			}
		} else {
			// Infer the MIME-type from the file extension
			$ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
			if ( isset( self::$mimeTypes[$ext] ) ) {
				return self::$mimeTypes[$ext];
			}
		}
		// neither approach worked, or mimeType not allowed
		return false;
	}

	public static function getTidyOpts() {
		return self::$tidyOpts;
	}

	/**
	 * Do the import which consists of three phases:
	 * 1) Select and/or Upload user nominated files
	 * 2) pre-process, filter, and convert them to wikitext
	 * 3) Create the articles, and images
	 */
	private function doImport() {
		global $wgOut;
		$wgOut->addModules( 'ext.Html2Wiki' );

		if ( $this->doUpload() ) {
			$this->mFilesAreProcessed = ( $this->mFileCountExpected == $this->mFileCountProcessed ) ? true : false;
			if ( $this->mFilesAreProcessed ) {
				$this->showResults();
			} else {
				$this->showForm( 'Some files were not processed' );
			}
		} else {
			// @todo This should be an error report, not a form.
			$this->showForm();
		}
		return true;
	}

	/**
	 * Upload user nominated file.
	 *
	 * The user may nominate either a single HTML file, or a zip/tar archive of
	 * HTML and images etc.
	 *
	 * Since we don't know which, we'll check the mimetype, and then either
	 * process a single file, or else unwrap the archive and process each entry
	 * Populate
	 * $mFilename
	 * $mMimeType
	 * $mFilesize
	 */
	private function doUpload() {
		$this->mFilesAreProcessed = false;
		$out = $this->getOutput();
		global $wgMaxUploadSize;
		$upload = $this->getRequest()->getUpload( 'userfile' );
		try {
			// Check error value.
			switch ( $upload->getError() ) {
				case UPLOAD_ERR_OK: // Value: 0; There is no error, the file uploaded with success.
					break;
				case UPLOAD_ERR_INI_SIZE:
					// Value: 1; The uploaded file exceeds the upload_max_filesize directive in php.ini.
					throw new RuntimeException( 'Exceeded filesize limit. Check upload_max_filesize in php.ini.' );
				case UPLOAD_ERR_FORM_SIZE:
					// Value: 2; The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form.
					throw new RuntimeException( 'Exceeded filesize limit in form.' );
				case UPLOAD_ERR_PARTIAL:
					throw new RuntimeException( 'The uploaded file was only partially uploaded.' );
				case UPLOAD_ERR_NO_FILE: // Value: 4; No file was uploaded.
					throw new RuntimeException( 'No file sent.' );
				default:
					throw new RuntimeException( 'Unhandled error: ' . $upload->getError() . ' in ' . __METHOD__ );
			}
			// Check filesize.  Right now, we're just using one value.  @todo add URL uploading
			$max_size = is_array( $wgMaxUploadSize ) ? $wgMaxUploadSize['*'] : $wgMaxUploadSize;
			if ( $upload->getSize() > $max_size ) {
				throw new RuntimeException( 'Exceeded filesize limit, check $wgMaxUploadSize.' );
			}

			// we do not trust the type
			$this->mMimeType = $this->mOriginal['mimetype'] = self::getMimeType( $upload->getTempName() );
			if ( $this->mMimeType === false ) {
				throw new RuntimeException( 'Invalid file format.' );
			}

			// You should name it uniquely.
			// DO NOT USE upload name WITHOUT ANY VALIDATION !!
			// On this example, obtain safe unique name from its binary data.
			/* We don't even need to move the file.  We WANT the upload to be discarded
			 * and it will be after upload if we don't move it from the tmp_name
			 * So, we can process it, and only use the result, while PHP destroys
			 * the original source
			 */
			if ( !is_uploaded_file( $upload->getTempName() ) ) {
				throw new RuntimeException( 'There was a failure in the file upload' );
			}
		} catch ( RuntimeException $e ) {
			$out->wrapWikiMsg(
					"<p class=\"error\">\n$1\n</p>", [ 'html2wiki_uploaderror', $e->getMessage() ]
			);
			return false;
		}
		$this->mFilename = $this->mOriginal['filename'] = $upload->getName();
		$this->mFilesize = $this->mOriginal['filesize'] = $upload->getSize();

		switch ( $this->mOriginal['mimetype'] ) {
			case 'application/x-gzip':
			case 'application/x-gtar':
			case 'application/zip':
				// @todo Do we disallow uploading a zip file without a collection name?

				// unwrap the file
				$this->unwrapZipFile();
				break;

			case 'text/html':
				// process single file
				$this->processFile();
				$this->mFilesAreProcessed = true;
				break;

			default:
				return false;
		}
		// we know about all the images that are referenced, make sure they are
		// in the wiki
		return $this->mFilesAreProcessed;
	}

	/**
	 * During the upload process, we detect the mimetype, and if it's an archive
	 * we work with the contents of the archive, processing each entry.
	 * We have to loop through the archive twice...
	 * On the first pass we detect the "brand" of archive.
	 * The brand is currently one of 'REI' or 'UNH' which are collections we
	 * are familiar with.
	 * Based on the brand, we build up a list of HTML files and image files that
	 * match our expectations.
	 * On the second pass, we process each entry (filtered by the first pass).
	 */
	private function unwrapZipFile() {
		// wfDebug( __METHOD__ . ": unzipping stuff" );
		// grab the original extension so we can use it for PHAR handling
		$pos = strrpos( $this->mFilename, '.' );
		$ext = $pos === false ? '' : substr( $this->mFilename, $pos + 1 );
		// blank out our per-file data
		$this->mMimeType = $this->mFilename = $this->mFilesize = '';
		$this->mIsRecognized = false;

		$out = $this->getOutput();
		$upload = $this->getRequest()->getUpload( 'userfile' );
		$zipfile = $upload->getTempName();
		// this little shim will try to convert a gzip file
		if ( ( $this->mOriginal['mimetype'] == 'application/x-gzip' ) ||
		   ( $this->mOriginal['mimetype'] == 'application/x-gtar' ) ) {
			try {
				// PharData will refuse to read without a proper extension
				$moved = rename( $zipfile, "$zipfile.$ext" );
				$tarphar = new PharData( "$zipfile.$ext" );
				// convert it to zip (does not unlink the original, which we'll do at the end of this function)
				$tarphar->convertToData( Phar::ZIP, Phar::NONE );
				unlink( "$zipfile.$ext" );
				$zipfile = "$zipfile.zip";
			} catch ( Exception $e ) {
				$out->wrapWikiMsg(
					"<p class=\"error\">\n$1\n</p>", [ 'html2wiki_uploaderror', $e->getMessage() ]
				);
				return false;
			}
		}
		$availableFiles = [];
		$availableImages = [];

		// we need to loop through twice, once to determine if this zip matches expectations (and build a bill of laden)
		$zipHandle = zip_open( $zipfile );
		if ( is_resource( $zipHandle ) ) {
			while ( $zip_entry = zip_read( $zipHandle ) ) {
				$entry = zip_entry_name( $zip_entry );
				switch ( $entry ) {
					case ( preg_match( '#htmldocs/.*\.html?$#i', $entry ) ? $entry : '' ): # 1,112  // This matches REI content
					case ( preg_match( '#docs/html/files/.*\.html?$#i', $entry ) ? $entry : '' ):  // This is for UNH content
					case ( preg_match( '#\.html?$#i', $entry ) ? $entry : '' ):
							$availableFiles[] = $entry;
						break;

					case ( preg_match( '#htmldocs/.*/images/.*\.(?:jpe?g|png|gif)$#i', $entry ) ? $entry : '' ):  # 511
					case ( preg_match( '#docs/html/images/.*\.(?:jpe?g|png|gif)$#i', $entry ) ? $entry : '' ):  // This is for UNH content
					case ( preg_match( '#\.(?:jpe?g|png|gif)$#i', $entry ) ? $entry : '' ):  // all png, jpg, gif images
							$availableImages[] = $entry;
						break;

					case 'docs/htmldocs/':
					case 'docs/html/files/':
						$this->mIsRecognized = true;
						break;

					default:
						// if a simple print doesn't work, then your switch has bad syntax
						break;
				}
			}
			zip_close( $zipHandle );
		}
		if ( count( $availableFiles ) || count( $availableImages ) ) {
			$imageCount = 0;
			$zipHandle = zip_open( $zipfile );
			if ( is_resource( $zipHandle ) ) {
				while ( $zip_entry = zip_read( $zipHandle ) ) {
					$entry = zip_entry_name( $zip_entry );

					// Process HTML files
					if ( in_array( $entry, $availableFiles ) ) {
						$this->mFileCountExpected += 1;
						$this->mFilename = zip_entry_name( $zip_entry );
						$this->mFilesize = zip_entry_filesize( $zip_entry );
						$this->mMimeType = 'text/html'; // we'll spoof it for now.
						if ( zip_entry_open( $zipHandle, $zip_entry, "r" ) ) {
							$this->mContent = $this->mContentRaw =
									zip_entry_read( $zip_entry, zip_entry_filesize( $zip_entry ) );
							zip_entry_close( $zip_entry );
						}
						// mArticleSavePath any intermediate path elements NOT including the file name nor the CollectionName
						$this->mArticleSavePath = pathinfo( $this->mFilename, PATHINFO_DIRNAME );
						// mArticleTitle will be the full value of mCollectionName (if any) plus mArticleSavePath plus the file name MINUS any extension for .html files
						$this->mArticleTitle = ( $this->mCollectionName ) ? "{$this->mCollectionName}/{$this->mFilename}" : $this->mFilename;
						$this->mArticleTitle = self::removeExtensionFromPath( $this->mArticleTitle );
						$this->processFile();
					}

					// Process Images
					if ( in_array( $entry, $availableImages ) ) {
						global $wgH2WEliminateDuplicateImages;
						if ( $wgH2WEliminateDuplicateImages ) {
							$this->mFilename = basename( zip_entry_name( $zip_entry ) );
						} else {
							$this->mFilename = zip_entry_name( $zip_entry );
						}
						$this->mFilesize = zip_entry_filesize( $zip_entry );
						$this->mComment = "Html2Wiki imported {$this->mFilename} (" . $this->formatValue( $this->mFilesize ) . ")";
						$this->mArticleTitle = ( $this->mCollectionName ) ? "{$this->mCollectionName}/{$this->mFilename}" : $this->mFilename;
						if ( zip_entry_open( $zipHandle, $zip_entry, "r" ) ) {
							$buf = zip_entry_read( $zip_entry, zip_entry_filesize( $zip_entry ) );
							zip_entry_close( $zip_entry );
						}
						$tmpFile = $this->makeTempFile( $buf );
						unset( $buf );
						$this->saveImage( $tmpFile );
						$imageCount++;
					}
				}
				// check if mFileCountExpected = mFileCountProcessed and close the handle
				zip_close( $zipHandle );
			}
		unlink( $zipfile );
		} else {
			$out->addHTML( '<div><pre>' );
			$out->addHTML( 'This zip archive does not contain any HTML or image files to process' );
			$out->addHTML( print_r( $availableFiles, true ) );
			$out->addHTML( '</pre></div>' );
		}
	}

	private function formatValue( $value ) {
		return htmlspecialchars( $this->getLanguage()->formatSize( $value ) );
	}

	/**
	 * displays $this->mContent (in a <source> block)
	 * optionally passed through htmlentities() and nl2br()
	 */
	private function showContent( $showEscaped = false ) {
		$out = $this->getOutput();
		$out->addModules( [ 'ext.Html2Wiki' ] ); // add our javascript and css

		if ( $showEscaped ) {
			$escapedContent = $this->escapeContent();
			$out->addHTML( '<div id="h2wLabel">Escaped File Contents:</div>' );
			$out->addHTML( '<div id="h2wContent">' . $escapedContent . '</div>' );
		} else {
			$out->addHTML( '<div id="h2wLabel">' . $this->msg( 'html2wiki-dry-run-heading' )->text() . '</div>' );
			// putting the original source into GeSHi makes it "safe" from the parser
			// but destroys any ability to act on the 'source' by manipulating this
			// elements innerHTML
			$out->addWikiTextAsInterface( '<source id="h2wContent" lang="html4strict">' . $this->mContent . '</source>' );
		}
	}

	private function addFileToResults() {
		$this->mFileCountProcessed += 1;
		$size = $this->formatValue( $this->mFilesize );
		$this->mResults .= "<li>{$this->mFilename} ({$size}) {$this->mMimeType}</li>\n";
	}

	private function showResults() {
		$out = $this->getOutput();
		$out->addHTML( '<div id="h2wContent"><ul class="mw-ext-Html2Wiki">' . $this->mResults . '</ul></div>' );
	}

	private function escapeContent() {
		return nl2br( htmlentities( $this->mContent, ENT_QUOTES | ENT_IGNORE, "UTF-8" ) );
	}

	/**
	 * Use Pandoc to convert our content to mediawiki markup
	 */
	public function panDoc2Wiki() {
		$tempfilename = $this->makeTempFile( $this->mContent );
		$this->mContent = shell_exec( "pandoc -f html -t mediawiki $tempfilename" );
		unlink( $tempfilename );
	}

	/**
	 * Processing a file is done in five phases:
	 * 1) Tidy tries to normalize the file
	 * 2) filter / alter content based on what we know about it's peculiarities
	 * 3) convert it to wikitext using Pandoc
	 * 4) post-process wikitext
	 * 5) save it
	 *
	 * $this->mArticleSavePath, $this->mArticleTitle already set
	 * @return bool
	 */
	private function processFile() {
		// when only a single file is uploaded, we can populate content from tmp_name
		if ( $this->mOriginal['mimetype'] == 'text/html' ) {
			$upload = $this->getRequest()->getUpload( 'userfile' );
			$this->mContent = $this->mContentRaw = file_get_contents( $upload->getTempName() );
			$this->mFileCountExpected = 1;
		}

		// Tidy now works on mContent even when in fallback mode for MediaWiki-Vagrant
		$this->tidyup( self::getTidyOpts() );

		// QueryPath is finicky because HTML is wild and untamed
		try {
			$this->mContent = self::qpClean( $this->mContent );
			// Remove mouseovers and onclick handlers in links
			$this->qpRemoveMouseOvers();
			// turn <a name="foo"></a> to <span name="foo"></span> for intradocument links
			$this->mContent = self::qpLinkToSpan( $this->mContent );
			// fix up relative links
			$this->qpNormalizeLinks( 'a:link' );
			$this->qpNormalizeLinks( 'img' );

			// fix up the image links
			$this->qpAlterImageLinks();
			// cleanFile is actually good for both sets, and gets rid of scripts etc.
			// but it's old and duplicates stuff that is already done by Tidy etc.
			$this->cleanFile();

			// example of using a static method that would be available without
			// an Html2Wiki object (outside the class)
			$this->mContent = self::qpItalics( $this->mContent, 'span.cItalic' );
		} catch ( Exception $e ) {
			$out = $this->getOutput();
			$out->wrapWikiMsg(
				"<p class=\"error\">\n$1\n</p>", [ 'html2wiki_uploaderror', $e->getMessage() ]
			);
			return false;
		}
		// panDoc apparently lets double single quotes ''italics'' pass through
		// so we can qpItalics before running panDoc2Wiki()
		$this->panDoc2Wiki();
		$this->substituteTemplates();
		$this->autoCategorize();
		// @todo turn this into a function, and ensure that the CollectionName
		// is safe for a Category Name
		if ( $this->mCollectionName ) {
			$this->mContent .= "\n[[Category:{$this->mCollectionName}]]";
		}

		if ( $this->mIsDryRun ) {
			$this->showContent();
		} else {
			$this->saveArticle();
		}
		$this->addFileToResults();

		return true;
	}

	/**
	 * Save an image in the File namespace
	 */
	public function saveImage( $tmpFile ) {
		global $wgH2WProcessImages;
		if ( $wgH2WProcessImages === false ) {
			return true;
		}
		$out = $this->getOutput();
		$user = $this->getUser();
		// @todo, modify this so we can enter dynamic $comment and $pageText
		$comment = $this->mComment;
		$pageText = "[[Category:Html2Wiki]]";

		$title = $this->makeTitle( NS_FILE );
		if ( method_exists( MediaWikiServices::class, 'getRepoGroup' ) ) {
			// MediaWiki 1.34+
			$image = MediaWikiServices::getInstance()->getRepoGroup()->getLocalRepo()->newFile( $title );
		} else {
			$image = wfLocalFile( $title );
		}
		$result = $image->upload( $tmpFile, $comment, $pageText );
		if ( $result !== false ) {
			$out->addWikiTextAsInterface( '<div class="success">' . $title . ' was uploaded.  See [[:' . $title . ']] [[' . $title . '|thumb]]</div>' );
			$logEntry = new ManualLogEntry( 'html2wiki', 'import' ); // Log action 'import' in the Special:Log for 'html2wiki'
			$logEntry->setPerformer( $user ); // User object, the user who performed this action
			$logEntry->setTarget( $title ); // The page that this log entry affects, a Title object
			$logEntry->setComment( $this->mComment );
			$logid = $logEntry->insert();
			// optionally publish the log item to recent changes
			$logEntry->publish( $logid );
		} else {
			die( "failed to upload $tmpFile" );
		}
		return unlink( $tmpFile );
		// image saved
	}

	private function makeTitle( $namespace = NS_MAIN ) {
		$desiredTitleObj = Title::makeTitleSafe( $namespace, $this->mArticleTitle );
		if ( $desiredTitleObj !== null ) {
			return $desiredTitleObj;
		} else {
			die( $this->mArticleTitle . " is an invalid filename" );
		}
	}

	private function saveArticle() {
		$out = $this->getOutput();
		$user = $this->getUser();
		$token = $user->getEditToken();
		$title = $this->makeTitle( NS_MAIN );
		$existing = $title->exists();
		$actionverb = $existing ? 'edited' : 'created';
		$action = 'edit';
		$api = new ApiMain(
				new DerivativeRequest(
				$this->getRequest(), // Fallback upon $wgRequest if you can't access context
				[
			'action' => $action,
			'title' => $title,
			'text' => $this->mContent, // can only use one of 'text' or 'appendtext'
			'summary' => $this->mComment,
			'notminor' => true,
			'token' => $token
				], true // was posted?
				), true // enable write?
		);
		$api->execute(); // actually save the article.
		// @todo convert this to a message with parameters to go in en.json
		$out->addWikiTextAsInterface( '<div class="success">' . $title . ' was ' . $actionverb . '. See [[' . $title . ']]</div>' );
		$logEntry = new ManualLogEntry( 'html2wiki', 'import' ); // Log action 'import' in the Special:Log for 'html2wiki'
		$logEntry->setPerformer( $user ); // User object, the user who performed this action
		$logEntry->setTarget( $title ); // The page that this log entry affects, a Title object
		$logEntry->setComment( $this->mComment );
		$logid = $logEntry->insert();
	}

	/**
	 * We don't have to worry about access restrictions here, because the whole
	 * SpecialPage is restricted to users with "import" privs.
	 * @param string|null $message is an optional error message that gets displayed
	 * on form re-display
	 */
	private function showForm( $message = null ) {
		$action = $this->getPageTitle()->getLocalURL( [ 'action' => 'submit' ] );
		$user = $this->getUser();
		$out = $this->getOutput();
		$out->addModules( [ 'ext.Html2Wiki' ] ); // add our javascript and css
		// display an error message if any
		if ( $message ) {
			$out->addHTML( '<div class="error">' . $message . "</div>\n" );
		}
		if ( $user->isAllowed( 'importupload' ) ) {
			$out->addWikiMsg( 'html2wiki-intro' );
			$out->addHTML(
					Xml::fieldset( $this->msg( 'html2wiki-fieldset-legend' )->text() ) . // ->plain() or ->escaped()
					Xml::openElement(
						'form', [
							'enctype' => 'multipart/form-data',
							'method' => 'post',
							'action' => $action,
							'id' => 'html2wiki-form'           // new id
						]
					) .
					$this->msg( 'html2wiki-text' )->parseAsBlock() .
					Html::hidden( 'action', 'submit' ) .
					Html::hidden( 'source', 'upload' ) .
					Xml::openElement( 'table', [ 'id' => 'html2wiki-table' ] ) . // new id
					"<tr>
					<td class='mw-label'>" .
					Xml::label( $this->msg( 'html2wiki-filename' )->text(), 'userfile' ) .
					"</td>
					<td class='mw-input'>" .
					Html::input( 'userfile', '', 'file', [ 'id' => 'userfile' ] ) . ' ' .
					"</td>
				</tr>
				<tr>
					<td class='mw-label'>" .
					Xml::label( $this->msg( 'html2wiki-collection-name' )->text(), 'mw-collection-name' ) .
					"</td>
					<td class='mw-input'>" .
					Xml::input( 'collection-name', 50, ( $this->mRequest ? $this->mCollectionName : '' ), // value
							[ 'id' => 'mw-collection-name', 'type' => 'text' ] ) . ' ' . // attribs
					"</td>
				</tr>
				<tr>
					<td class='mw-label'>" .
					Xml::label( $this->msg( 'import-comment' )->text(), 'mw-import-comment' ) .
					"</td>
					<td class='mw-input'>" .
					Xml::input( 'log-comment', 50, ( $this->mRequest ? $this->mComment : '' ), // value
							[ 'id' => 'mw-import-comment', 'type' => 'text' ] ) . ' ' . // attribs
					"</td>
				</tr>
				<tr>
					<td class='mw-label'>" .
					Xml::label( $this->msg( 'html2wiki-dry-run' )->text(), 'mw-import-dry-run' ) .
					"</td>
					<td class='mw-input'>" .
					Xml::check( 'dry-run', ( $this->mRequest ? $this->mIsDryRun : false ),
							[ 'id' => 'mw-import-dry-run', 'title' => 'check here to PREVIEW results without saving' ] ) . ' ' . // attribs
					"</td>
				</tr>

				<tr>
					<td></td>
					<td class='mw-submit'>" .
					Xml::submitButton( $this->msg( 'html2wiki-importbtn' )->text(), [ 'id' => 'html2wiki-submit' ] ) .
					"</td>
				</tr>" .
					Xml::closeElement( 'table' ) .
					Html::hidden( 'editToken', $user->getEditToken() ) .
					Xml::closeElement( 'form' ) .
					Xml::closeElement( 'fieldset' )
			);

			$out->addHTML( '<div class="mw-ext-Html2Wiki-loading"><img src="http://upload.wikimedia.org/wikipedia/commons/d/de/Ajax-loader.gif" alt="loading... please wait" style="margin:9px" /></div>' );

		} else {
			$out->addWikiMsg( 'html2wiki-not-allowed' );
		}
	}

	/**
	 * The Tidy class can easily use an array.
	 * To REUSE that same array in a command line environment, we reformat it
	 * @param array $tidyOpts
	 * @return string
	 */
	private function makeConfigStringForTidy( $tidyOpts ) {
		$tidyOptsString = '';
		foreach ( $tidyOpts as $k => $v ) {
			$tidyOptsString .= " --$k $v";
		}
		return $tidyOptsString;
	}

	public static function tidyErrorsToArray( $errors ) {
		preg_match_all(
				'/^(?:line (\d+) column (\d+) - )?(\S+): (?:\[((?:\d+\.?){4})]:) ?(.*?)$/m',
				$errors, $matches, PREG_SET_ORDER );
		return $matches;
	}

	/**
	 * Tidyup will populate both mContent and mContentTidy
	 * and store errors in mTidyErrors
	 *
	 * Since we support ZipArchives, it doesn't make sense to read from a file.
	 * Instead we need to read from a PHP Variable.  This isn't a problem when
	 * the Tidy module is available (like it is with PHP5).  However, in a strange
	 * twist of fate, the HHVM that comes with MediaWiki Vagrant does not yet
	 * have a Tidy extension built in.  So, we have to fall back to using Tidy
	 * on the command line, and pass our PHP variables to STDIN / STDERR
	 * The way to do this with PHP is to use proc_open().
	 *
	 * You can test Tidy online at sites like http://infohound.net/tidy/tidy.pl
	 *
	 * @param string | array|null $tidyConfig
	 * if passed as an array like so
	 * $tidyConfig = array(
	 * 'indent'        => false,
	 * 'output-xhtml'  => true,
	 * 'wrap'          => 80
	 * );
	 * then tidy will use the supplied configuration
	 * Or, if passed as a string, tidy will use the configuration file
	 * Left alone, it will load the config file supplied with Html2Wiki
	 *
	 * @return bool
	 *
	 * In testing tidy from the command line, it would NOT use a configuration file
	 * If you have an existing tidy.conf you want to use, you can convert a config
	 * awk '{ printf " --"$0 }' /vagrant/mediawiki/extensions/Html2Wiki/tidy.conf
	 *
	 * @todo capture Tidy errors for logging or display?
	 * see http://stackoverflow.com/questions/6472102/redirecting-i-o-in-php
	 * @todo figure out if we need to use "force" option in Tidy?
	 *
	 * eg. $this->tidyup();
	 * with no args, it should default to reading the config file
	 */
	public function tidyup( $tidyConfig = null ) {
		global $tidy;
		if ( $this->mMimeType !== 'text/html' ) {
			// @todo wrap this in a wiki message
			echo "wrong type of file for Tidy\n";
			$this->isTidy = false;
			return false;
		}

		$this->mTidyConfig = realpath( __DIR__ . "/../tidy.conf" );
		if ( $tidyConfig === null ) {
			$tidyConfig = $this->mTidyConfig;
			$shellConfig = " -config $tidyConfig";
		} elseif ( is_array( $tidyConfig ) ) {
			$shellConfig = $this->makeConfigStringForTidy( $tidyConfig );
		} elseif ( is_string( $tidyConfig ) ) {
			$shellConfig = " -config $tidyConfig";
		}
		if ( class_exists( 'Tidy', true ) ) {
			$encoding = 'utf8';
			$tidy = new Tidy;
			$tidy->parseString( $this->mContent, $tidyConfig, $encoding );
			$tidy->cleanRepair();
			// @todo need to do something else with this error list?
			// regex converts string error into a two dimensional array
			// foreach line spit out by Tidy
			// It will match Error, Warning, Info and Access error types.
			if ( !empty( $tidy->errorBuffer ) ) {
				$this->mTidyErrors = self::tidyErrorsToArray( $tidy->errorBuffer );
			}
			// just focus on the body of the document
			$this->mContentTidy = $this->mContent = (string)$tidy->body();
		} else {
			// using Tidy on the command line expects input from STDIN
			// We'll use printf which is more consistent than echo which varies
			// We want something like the following
			// $result =  shell_exec('printf "$content" | tidy');
			$html = $this->mContent;
			// don't need this escaping since we're not using shell_exec
			// $html = str_replace(array('\\', '%'), array('\\\\', '%%'), $html);

			// only by passing the options as a long string worked.
			// also, $this->mTidyErrors will never populate unless we explicitly
			// trap STDERR
			// 2>&1 1> /dev/null
			$tidy = "$tidy -quiet -indent -ashtml $shellConfig";
			/*
			$tidy = "$tidy -quiet -indent -ashtml  --drop-empty-paras 1 --drop-font-tags 1 --enclose-block-text 1 --enclose-text 1 --fix-backslash 1 --fix-bad-comments 1 --fix-uri 1 --hide-comments 1 --merge-divs 1 --merge-spans 1 --repeated-attributes keep-first --show-body-only 1 --show-errors 0 --show-warnings 0 --indent 0 --wrap 120 --tidy-mark 0 --write-back 0";
			 *
			 */
			$descriptorspec = [
				0 => [ "pipe", "r" ], // stdin is a pipe that the child will read from
				1 => [ "pipe", "w" ], // stdout is a pipe that the child will write to
				2 => [ "pipe", "w" ]  // stderr is a pipe that the child will write to
				// 2 => array("file", "/tmp/error-output.txt", "a") // stderr is a file to write to
			];
			$process = proc_open( $tidy, $descriptorspec, $pipes );
			if ( is_resource( $process ) ) {
				// $pipes now looks like this:
				// 0 => writeable handle connected to child stdin
				// 1 => readable handle connected to child stdout
				// 2 => readable handle connected to child stderr
				// Any error output will be appended to /tmp/error-output.txt

				fwrite( $pipes[0], $html );
				fclose( $pipes[0] );

				$this->mContentTidy = $this->mContent = stream_get_contents( $pipes[1] );
				fclose( $pipes[1] );

				$this->mTidyErrors = self::tidyErrorsToArray( stream_get_contents( $pipes[2] ) );
				fclose( $pipes[2] );

				// It is important that you close any pipes before calling
				// proc_close in order to avoid a deadlock
				$return_value = proc_close( $process );
			} else {
				die( 'could not get process open' );
			}

		}
		$this->isTidy = true;
		return true;
	}

	/**
	 * Function to convert relative links to their wikified equivalents to preserve
	 * the imported collection's relative hierarchy in the wiki for imported Collections.
	 *
	 * Multi-variant, works on images and anchors
	 *
	 * @param string $selector is a CSS3 selector, e.g. "a:link" or "img"
	 * @return bool
	 */
	public function qpNormalizeLinks( $selector ) {
		global $wgH2WEliminateDuplicateImages;
		try {
			$qp = htmlqp( $this->mContent, $selector );
		} catch ( Exception $e ) {
			$this->getOutput()->wrapWikiMsg(
				"<p class=\"error\">\n$1\n</p>", [ 'html2wiki_parse-error', $e->getMessage() . "\n " . $e->getTraceAsString() ]
			);
			return false;
		}
		if ( $qp->length == 0 ) {
			return false;
		}
		switch ( $selector ) {
			case 'img':
				$attribute = 'src';
				break;
			case 'a':
			case 'a:link':
			default:
				$attribute = 'href';
				break;
		}
		// take out the final trailing slash, which will just create an empty value
		// MWDebug::log('Save Path is ' . $this->mArticleSavePath);
		$arrPath = explode( '/', rtrim( $this->mArticleSavePath, '/' ) );
		foreach ( $qp as $item ) {
			$isAbsolute = false;
			$ { $attribute } = $item->attr( $attribute );
			// skip over absolute references, unless is a friggin google tracker virus
			if ( substr( $ { $attribute }, 0, 4 ) == 'http' ) {
				$isAbsolute = true;
				if ( strpos( $ { $attribute }, 'google.com/url?q=' ) ) {
					$matches = [];
					if ( preg_match( '#url\?q=([^&]*)#', $ { $attribute }, $matches ) ) {
						$ { $attribute } = urldecode( $matches[1] );
					}
				} else {
					// if it's an absolute link but now Googlfried, then nothing to do
					continue;
				}
			}
			// skip intra-document links
			if ( substr( $ { $attribute }, 0, 1 ) == '#' ) {
				continue;
			}
			// how deep is this relative link
			$levels = substr_count( $ { $attribute }, '../' );
			if ( $levels ) {
				$anchor = array_slice( $arrPath, 0, count( $arrPath ) - $levels );
				$anchor = implode( '/', $anchor );
				$ { $attribute } = str_replace( '../', '', $ { $attribute } );
				$ { $attribute } = "$anchor/{${$attribute}}";
			}
			// only remove the extension on href's because we've similarly modified titles
			if ( $attribute == 'href' && !$isAbsolute ) {
				$ { $attribute } = self::removeExtensionFromPath( $ { $attribute } );
			}
			// flatten images; according to the setting of $wgH2WEliminateDuplicateImages
			if ( $attribute == 'src' ) {
				$ { $attribute } = ( $wgH2WEliminateDuplicateImages ) ? basename( $ { $attribute } ) : $ { $attribute };
			}
			// add in the CollectionName
			if ( $this->mCollectionName ) {
				$ { $attribute } = $this->mCollectionName . '/' . $ { $attribute };
			}
			// MWDebug::log("Setting $attribute to  ${$attribute}");
			$item->attr( $attribute, $ { $attribute } );
		}
		ob_start();
		$qp->writeHTML();
		$this->mContent = ob_get_clean();

		return true;
	}

	/**
	 * Creates a title attribute for an image tag using the file name.
	 *
	 * Optionally can strip a path element from the src attribute
	 *
	 * @param string|null $removePathElement is a string that will be stripped from the resultant image
	 * tag src attribute
	 * @return bool true on completion
	 *
	 * @see also: $wgH2WEliminateDuplicateImages
	 *
	 * For some collections of html content, all the images are in a
	 * single folder such as 'images', or 'img', or 'assets'. Thus the
	 * src attributes for all imported images will be the same, aka 'static', up
	 * to but not including the final filename.
	 *
	 * This is opposed to other 'organic' collections of html where image files may
	 * be interspersed throughout the collection of html, and thus have varying
	 * image paths.
	 *
	 * In the former case, we don't need long static paths.  It serves no purpose
	 * to have an image reside at
	 *   UNH2.2/docs/html/images/illustration.png v.
	 *   UNH2.2/illustration.png
	 *
	 * Thus we should import them into the wiki with just
	 * the Collection name as a distinguishing identifier.
	 *
	 * The Collection name being used as an identifier means that images can differ
	 * between collections.
	 *   UNH2.2/illustration.png v.
	 *   UNH2.3/illustration.png
	 *
	 *
	 * A function to change the image tags in imported documents
	 * to reflect the path that those images will be found at in the wiki.
	 *
	 * Previously we tried to make things more elaborate for REI, but then found
	 * that QueryPath fails miserably to find the 'parent' if it doesn't exist.
	 * In UNH collections, there won't be a .pFigureTitle container for the img
	 *
	 * So, now we're just removing the 'images/' portion of the src attribute
	 *
	 * This other documentation is for further mulling/refinement of original goals
	 *
	 * We also want to dress up the tag so that it can be useful to Pandoc
	 *
	 * Our source content looks like this (blank lines removed):
	 * @source
	 * <a name="wp80923"></a><p class="pFigureTitle" id="MGC80923">
	 * Figure 4-1. <a name="CRefID61031"></a>
	 * Message Sequence Chart for a Normal mvc_sequence<a name='Graphic80921'></a><img src="images/message_sequence_chart__normal_mvc_sequence.jpg"  style='width:6.36667in;'  class="Aligncenter" id='wp80921' border='0' hspace='0' vspace='0'/>
	 * </p>
	 * @source
	 *
	 * Pandoc will recognize and condense "title" and "alt" attributes, producing
	 * [[Image: source txt ]]
	 *
	 * Also, Pandoc has special handling if the Title attribute starts with
	 * the word 'Figure', producing something like
	 * [[Image: src |frame|none txt ]]
	 *
	 * Our conversion currently looks like
	 * @source
	 * <span id="wp80923"></span>
	 * Figure 4-1. <span id="CRefID61031"></span>
	 * Message Sequence Chart for a Normal mvc_sequence<span id="Graphic80921"></span>[[Image:images/message_sequence_chart__normal_mvc_sequence.jpg]]
	 * @source
	 *
	 * This is because we already preserve "named anchors" by converting them to spans
	 *
	 * What we'll do in this function is grab the "text" portion of the parent
	 * paragraph element and create a title attribute for our image.
	 */
	public function qpAlterImageLinks( $removePathElement = null ) {
		try {
			$qp = htmlqp( $this->mContent, 'img' );
		} catch ( Exception $e ) {
			// FIXME: There is no $out!
			$out->wrapWikiMsg(
				"<p class=\"error\">\n$1\n</p>", [ 'html2wiki_parse-error', $e->getMessage() . "\n " . $e->getTraceAsString() ]
			);
			return false;
		}
		if ( $qp->length == 0 ) {
			return false;
		}
		foreach ( $qp as $img ) {
			# preserve the collection name as part of the image path
			$src = ( $this->mCollectionName ) ? "{$this->mCollectionName}/{$img->attr('src')}" : $img->attr( 'src' );
			# which could also be explicitly removed if you want to 'flatten' your images
			if ( $removePathElement !== null ) {
				$src = str_replace( "$removePathElement/", '', $src );
			}
			$img->attr( 'src', $src );
			// add in title attributes where they don't exist
			if ( !$img->attr( 'title' ) ) {
				$title = str_replace( '_', ' ', basename( $src ) );
				$img->attr( 'title', $title );
			}
		}
		ob_start();
		$qp->writeHTML();
		$this->mContent = ob_get_clean();
		return true;
	}

	/**
	 * Provide a selector to transform HTML to wiki text markup for italics
	 * e.g. <div class="foo">something</div>  ----->  ''something''
	 *
	 * Html2Wiki::qpItalics("div.foo");
	 */
	public static function qpItalics( $content, $selector = null, $options = [ 'ignore_parser_warnings' => true ] ) {
		try {
			$qp = htmlqp( $content, $selector, $options );
		} catch ( Exception $e ) {
			// FIXME: There is no $out!
			$out->wrapWikiMsg(
				"<p class=\"error\">\n$1\n</p>", [ 'html2wiki_parse-error', $e->getMessage() . "\n " . $e->getTraceAsString() ]
			);
			return false;
		}
		$items = $qp->find( $selector );
		if ( $items->length == 0 ) {
			return $content;
		}
		foreach ( $items as $item ) {
			$text = $item->text();
			$newtag = "''$text''";
			$item->replaceWith( $newtag );
		}
		$qp->top();
		ob_start();
		$qp->writeHTML();
		$return = ob_get_clean();
		return $return;
	}

	/**
	 * A function that will strip the head, and remove certain document elements
	 * which are found in REI collections and are not desired for conversion to
	 * wikitext and so they should be filtered out.
	 * @param string $content
	 * @return string the cleaned content
	 *
	 * Here is what a QueryPath object looks like.
	 * There is a count() method, and a length property.
	 * Note that because we remove the 'head' with Tidy, there is no 'head' to
	 * remove with QP
	 *
	 * object(QueryPath\DOMQuery)[476]
	 *   private 'errTypes' => int 257
	 *   protected 'document' =>
	 *     object(DOMDocument)[214]
	 *   private 'options' =>
	 *     array (size=9)
	 *       'ignore_parser_warnings' => boolean true
	 *       'convert_to_encoding' => string 'ISO-8859-1' (length=10)
	 *       'convert_from_encoding' => string 'auto' (length=4)
	 *       'use_parser' => string 'html' (length=4)
	 *       'parser_flags' => null
	 *       'omit_xml_declaration' => boolean false
	 *       'replace_entities' => boolean false
	 *       'exception_level' => int 771
	 *       'escape_xhtml_js_css_sections' => string '' (length=8)
	 *   protected 'matches' =>
	 *     object(SplObjectStorage)[479]
	 *   protected 'last' =>
	 *     object(SplObjectStorage)[482]
	 *   private 'ext' =>
	 *     array (size=0)
	 *       empty
	 *   public 'length' => int 0
	 */
	public static function qpClean( $content ) {
		try {
			$qp = htmlqp( $content );
		} catch ( Exception $e ) {
			// FIXME: There is no $out!
			$out->wrapWikiMsg(
				"<p class=\"error\">\n$1\n</p>", [ 'html2wiki_parse-error', $e->getMessage() . "\n " . $e->getTraceAsString() ]
			);
			return false;
		}
		$ea = $qp->top()->find( 'head' );
		if ( $ea->length ) {
			foreach ( $ea as $head ) {
				$head->remove();
			}

		}
		$qp->find( '#BodyPopup' )->remove();
		$qp->find( '#HideBody' )->remove();
		$qp->find( '#BodyContent' )->unwrap();
		// $qp->top()->find('#BodyContent')->html();
		ob_start();
		$qp->writeHTML();
		$return = ob_get_clean();
		return $return;
	}

	public static function qpLinkToSpan( $content ) {
		try {
			$qp = htmlqp( $content, 'a' );
		} catch ( Exception $e ) {
			// FIXME: There is no $out!
			$out->wrapWikiMsg(
				"<p class=\"error\">\n$1\n</p>", [ 'html2wiki_parse-error', $e->getMessage() . "\n " . $e->getTraceAsString() ]
			);
			return false;
		}
		if ( $qp->length == 0 ) {
			return false;
		}
		foreach ( $qp as $anchor ) {
			if ( $anchor->hasAttr( 'href' ) ) {
				// in REI anchors with href's don't have names and vice versa
				// but we still want to be extra cautious not to turn a link
				// with an href into a span tag
				continue;
			}
			if ( $anchor->hasAttr( 'name' ) ) {
				$name = $anchor->attr( 'name' );
				$linktext = $anchor->text(); // normally no link text, but just in case
				$newtag = "<span id=\"$name\">$linktext</span>";
				$anchor->replaceWith( $newtag );
			}
		}
		ob_start();
		$qp->writeHTML();
		$return = ob_get_clean();
		return $return;
	}

	/**
	 * We have a lot of trouble parsing documents that have multiple anchors
	 * with similar name attributes, because the spec treats a name attribute
	 * the same as an id attribute on an anchor tag.  Either use htmlqp() which
	 * seems to ignore this problem, or else set libxml_use_internal_errors()
	 * function to ignore the warnings. ie. libxml_use_internal_errors(true);
	 * @see http://php.net/manual/en/domdocument.loadhtml.php
	 * @see http://www.w3.org/TR/html401/struct/links.html#h-12.2.1
	 */
	public function qpRemoveMouseOvers() {
		// $qp = htmlqp($this->mContent, 'body')->find("a:link");
		try {
			$qp = htmlqp( $this->mContent, 'a:link' );
		} catch ( Exception $e ) {
			// FIXME: There is no $out!
			$out->wrapWikiMsg(
				"<p class=\"error\">\n$1\n</p>", [ 'html2wiki_parse-error', $e->getMessage() . "\n " . $e->getTraceAsString() ]
			);
			return false;
		}
		if ( $qp->length == 0 ) {
			return false;
		}
		foreach ( $qp as $anchor ) {
			$anchor->removeAttr( 'onclick' ); // rei
			$anchor->removeAttr( 'onmouseover' ); // unh
			$anchor->removeAttr( 'onmouseout' ); // unh
		}
		$qp->top();
		ob_start();
		$qp->writeHTML();
		$this->mContent = ob_get_clean();
		return true;
	}

	public function cleanFile() {
		// delete content tags
		$reHead = "#<head>.*?</head>#is";
		$reBody = "#<body\b[^>]*>(.*?)</body>#is";
		$reScript = "#<script\b[^>]*>(.*?)</script>#is"; // caseless dot-all
		$reNoscript = "#<noscript\b[^>]*>(.*?)</noscript>#is"; // caseless dot-all
		$reComment = "#<!--(.*?)-->#s";
		$reEmptyAnchor = "#<a\b[^>]*></a>#is"; // empty anchor tags
		$reCollapsePre = '#</pre>\s*?<pre class="pCode">#s'; // sibling pre tags
		$reBlankLine = "#^\s?$\n#m";
		// simulate Tidy by ditching the <head> and getting only the <body>
		// but the source HTML could still be in a hurtful mess
		if ( !$this->isTidy ) {
			$this->mContent = preg_replace( $reHead, '', $this->mContent );
			$this->mContent = preg_filter( $reBody, "$1", $this->mContent );
		}
		$this->mContent = preg_replace( $reScript, '', $this->mContent );
		$this->mContent = preg_replace( $reNoscript, '', $this->mContent );
		$this->mContent = preg_replace( $reEmptyAnchor, '', $this->mContent );
		$this->mContent = preg_replace( $reCollapsePre, '', $this->mContent );

		$this->mContent = preg_replace( $reComment, '', $this->mContent );
		// keep this last, because there will be a lot of blank lines generated
		$this->mContent = preg_replace( $reBlankLine, '', $this->mContent );
	}

	/**
	 * A method to find certain HTML fragments that you wish to replace with
	 * Templates that you've designed in your wiki.
	 *
	 * For example, you're importing a large set of pages and every page contains
	 * a common footer.
	 *
	 * You can define those strings in this method, and swap in the wiki
	 * templates.  Corresponding wiki templates must be made by hand.
	 *
	 * For example, we'll replace
	 *     <div class="BlankFooter" id="BlankFooter">&nbsp;</div>
	 *     <div class="Footer" id="Footer">&nbsp;</div>
	 * with
	 *     {{BlankFooter}}
	 *     {{Footer}}
	 *
	 * Note: this function uses string replacement, not regular expressions
	 * Also, we use a case insensitive match because <div and <Div are the same
	 * in HTML.  Although the strings /should/ be consistent throughout your source
	 * we'll assume that you might still be hand editing HTML which introduces
	 * such inconsistencies.
	 */
	public function substituteTemplates() {
		$myReplacements = [
			'<div class="BlankFooter" id="BlankFooter">&nbsp;</div>'
			=> '{{BlankFooter}}',
			'<div class="Footer" id="Footer">&nbsp;</div>'
			=> '{{Footer}}'
		];
		$this->mContent = str_ireplace( array_keys( $myReplacements ), array_values( $myReplacements ), $this->mContent );
	}

	/**
	 * Automatically categorize the content based on what's found in the original
	 * source.
	 */
	private function autoCategorize() {
		$categoryTags = [];
		$categories = [
			'unh' => '[[Category:UNH]]',
			'rei' => '[[Category:Info Hub]]'
		];
		$needles = [
			'unh' => 'Generated by Natural Docs',
			'rei' => 'Quadralay WebWorks AutoMap 2003 for FrameMaker'
		];
		foreach ( $needles as $k => $v ) {
			if ( stristr( $this->mContentRaw, $v ) ) {
				$categoryTags[] = $categories[$k];
			}
		}
		$this->mContent .= "\n" . implode( " ", $categoryTags );
	}

	/**
	 * A convenience function to temporarily store contents so that we can use
	 * other tools or methods that expect to work on a file rather than a stream
	 * @param string $content
	 * @return string
	 */
	public function makeTempFile( $content ) {
		if ( $this->mDataDir ) {
			$stage = realpath( $this->mDataDir );
		} else {
			$stage = sys_get_temp_dir();
		}
		$prefix = 'h2w';
		$tempfilename = tempnam( $stage, $prefix );
		$handle = fopen( $tempfilename, "w" );
		fwrite( $handle, $content );
		fclose( $handle );
		return $tempfilename;
	}
}
