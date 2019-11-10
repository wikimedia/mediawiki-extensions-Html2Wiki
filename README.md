Please see <https://www.mediawiki.org/wiki/Extension:Html2Wiki>

Options:

$wgH2WEliminateDuplicateImages boolean false
      - when set to true, imported image (and their references)
        will be flattened to
        [CollectionName/]image.jpg
        instead of
        [CollectionName/]nested/folder/image.jpg
        thus eliminating duplicates based on repeated nested folders

$wgH2WProcessImages boolean true
      - when set to false, image processing will be skipped, saving time in the
        re-upload of content when no images have changed.
        With a simple preference option, we avoid doing a binary diff on every image.
        @todo create preference element in form
