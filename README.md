# MathJax extension for MediaWiki
Version 3.1.

Alexander Mashin, based on work by Mithgol the Webmaster.

## Overview
*MathJax* is a *MediaWiki* extension introducing a parser tag `<math>`
that allows to embed mathematical formulae in _TeX_ or _MML_ into wiki pages.

Formulae are rendered by [MathJax](https://docs.mathjax.org/en/latest/index.html) 3.0 or above,
server-side (converted to MathML), client-side (re-rendered and menu added), or both.

Formulae can be wikified:
- manually, e.g.: `x' = \frac {x-vt} {\sqrt {1 - {v^2} / [[Speed of light|c]] ^ 2}}`,
- or automatically, if `$wgmjAddWikilinks = true`, by defining _MediaWiki_ messages linking wiki pages to _TeX_ commands
(starting with a backslash):
  - e.g., to add wikilinks to the _Exponent_ page in your wiki to all `\exp` commands, add `exp` to the
comma-separated list in the message _MediaWiki:mathjax-pages_
and define a new message _MediaWiki:mathjax-page-exp_ as `Exponent`.

New macros (e.g. `\newmacro`) are defined by adding `newmacro` to the comma-separated list stored
in the message _MediaWiki:mathjax-macros_ and creating the message _MediaWiki:mathjax-macro-newmacro_
containing the definition of the macro.

## Credits
The extension was initially written by [Mithgol the Webmaster](https://traditio.wiki/Mithgol_the_Webmaster) in 2010
and then rewritten completely several times by [Alexander Mashin](https://traditio.wiki/Alex_Mashin)
for [Traditio wiki](https://traditio.wiki). In 2021, it is published at GitHub.

## Requirements
The extension requires:
- _PHP_ 7.4 or higher,
- _MediaWiki_ 1.33 or higher,
- _MathJax_ 3.0 or higher.

## Installation
To install the extension, copy or the entire `MathJax` folder or clone the repository into `(path to mediawiki)/extensions` folder.
To enable the extension, add `wfLoadExtension( 'MathJax' );` to `LocalSettings.php`.

If fourmulæ are to be rendered server-side, or MathJax is to be served from your server
rather that from MathJax CDN, also run `npm install` (requires `node.js`) in `MathJax` folder.
Make it accessible in the web server configuration. Otherwise, _MathJax_ will be loaded from CDN.

If, however, formulæ are to be rendered server-side in a _MathJax_ container,
do not run `npm install`, but
copy the file `src/engines/Dockerfile` to an appropriate location,
unless you already have the identical file `includes/presets/cgi/Dockerfile`
from the extension _External Data_ installed,
build the image, set up the _MathJax_ container and have it connected to
your _MediaWiki_ installation as described in the `src/engines/docker-compose.yml` file.
Set `$wgmjServiceUrl` to the URL of the container, as connectable from
_MediaWiki_. You might change the default values of `$wgmjServiceExternalUrl`
and `$wgnjServiceVersionUrl`, too.

## Further information
For further information, see https://traditio.wiki/MathJax_for_MediaWiki.
