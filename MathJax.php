<?php
use MediaWiki\MediaWikiServices;
use Wikimedia\AtEase\AtEase;
use function MediaWiki\restoreWarnings;
use function MediaWiki\suppressWarnings;

/**
 * This class encapsulates code needed to render mathematical formulae in wikitext.
 *
 * @author Alexaner Mashin
 * @author Mithgol the Webmaster
 *
 * @todo: semantic analysis of formulæ using MathJax.
 * @todo: integration of that analysis with Lua.
 * @todo: integration with SMW: subobjects for formulæ and wikilinks from properties.
 * @todo: access node.js as a service.
 * @todo: feed to node.js only the needed macro.
 */
class MathJax {

	/** @var bool $mathJaxNeeded Page has formulas. */
	private static $mathJaxNeeded = false;
	/** @var bool $alreadyAttached Prevent multiple attaching. */
	private static $alreadyAttached = false;
	/** @var string $mathRegex A regular expression to check if <math> tags are present. */
	private static $mathRegex;
	/** @var array $blockRegexes Regexps with replacements to search for : <math>...</math> and {{#tag:math|...}}. */
	private static $blockRegexes;
	/** @var string $envRegex A regular expression for searching for regular expressions outside <math>. */
	private static $envRegex;
	/** @var string $noMathInTheseTags A regex to find HTML tags that cannot contain maths and screen them. */
	private static $noMathInTheseTags;
	/** @var string $mathTagRegex A regex to fing <math> or {{#tag:math|}}}. */
	private static $mathTagRegex;
	/** @var array $tagLike TeX commands that are somewhat like HTML tags. */
	private static $tagLike = [ '>[^<>]*>[^<>]*>', '<[^<>]*<[^<>]*<' ];
	/** @var string $texConf Complete (with macros) configuration for MathJax's TeX as JSON. */
	private static $texConf;
	/** @var string $mjConf Complete (with macros) configuration for MathJax as JSON. */
	private static $mjConf;
	/** @var BagOStuff $cache Parser cache to store the results of TeX to MML conversion. */
	private static $cache;
	/** @var int $screenCounter A counter for the screen() method. */
	private static $screenCounter = 0;

	/**
	 * Entry point 1. Hooked by "ParserFirstCallInit": "MathJax::setup" in extension.json.
	 *
	 * Make the parser aware of <math> tag on initialisation, do some other initialisations.
	 *
	 * @param Parser $parser The parser object.
	 * @return bool Return true on success.
	 */
	public static function setup( Parser $parser ): bool {
		// When the parser sees the <math> tag, it executes
		// the self::renderMath function:
		global $wgmjMathTag;
		$parser->setHook( $wgmjMathTag, __CLASS__ . '::renderMath' );

		// Initialise regexes:
		$tag = preg_quote( $wgmjMathTag, '/' );
		self::$blockRegexes = [
			// : <math>...</math> -> <math display="block">...</math>.
			'/(?:^|\n)\s*(?::+\s*)+<' . $tag . '>(.+?)<\/' . $tag . '>[  ]*([.,:;]?)/si'
			=> "\n<" . $wgmjMathTag . ' display="block">$1$2</' . $wgmjMathTag . '>',
			// : {{#tag:math|...}} -> {{#tag:math|display="block"|...}}.
			'/(?:^|\n)\s*(?::+\s*)+{{#tag:' . $tag . '\|(.+?)}}[  ]*([.,:;]?)/si'
			=> "\n{{#tag:" . $wgmjMathTag . '|$1$2|display="block"}}'
		];
		global $wgmjTeX;
		self::$mathRegex = '/(' . preg_quote( $wgmjTeX['inlineMath'][0][0], '/' )
			. '.+?'
			. preg_quote( $wgmjTeX['inlineMath'][0][1], '/' )
			. '|'
			. preg_quote( $wgmjTeX['displayMath'][0][0], '/' )
			. '.+?'
			. preg_quote( $wgmjTeX['displayMath'][0][1], '/' )
			. ')/';
		// Regex to screen tags that cannot contain maths:
		global $wgmjMathJax;
		$any_tag = implode( '|', $wgmjMathJax['options']['skipHtmlTags'] );
		// Should deal with nested tags correctly:
		self::$noMathInTheseTags
			= "%<($any_tag)[^>]*?>(\n" // open tag.
			. '| (' . implode( '|', self::$tagLike ) . ")\n" // >>> in CD.
			. "| (?>[^<>]+)\n" // non-tag.
			. "| <($any_tag)[^>]*/>\n" // self-closing tag.
			. "| (?R)\n" // another tag (recursion).
			. ")* </\\1>%six"; // close tag.
		self::$mathTagRegex = "%<{$wgmjMathTag}[^>]*?>.*?</$wgmjMathTag>|{{#tag:math\\|.*?}}%si";

		// Initialise parser cache.
		global $wgmjServerSide;
		if ( $wgmjServerSide ) {
			global $wgmjCacheExpiration;
			if ( $wgmjCacheExpiration === -1 ) {
				global $wgParserCacheExpireTime;
				$wgmjCacheExpiration = $wgParserCacheExpireTime;
			}
			$services = MediaWikiServices::getInstance();
			self::$cache = $services->getMainObjectStash();
		}

		// Powered by MathJax:
		global $wgFooterIcons, $wgmjPoweredByIconURI;
		$wgFooterIcons['poweredby']['MathJax'] = [
			'src' => $wgmjPoweredByIconURI,
			'url' => 'https://www.mathjax.org/',
			'alt' => 'Formulæ rendered by MathJax'
		];

		return true;
	}

	/**
	 * Entry point 2. Hooked by "ParserBeforeInternalParse": "MathJax::blockDisplay" in extension.json.
	 *
	 * Replace :<math>...</math>  with <math display="block">...</math>,
	 *         :{{#tag:math|...}} with {{#tag:math|...|display="block"}}.
	 * Also, bring a following comma, full stop, etc. into the formula,
	 *
	 * @param Parser $parser The Parser object.
	 * @param string &$text The wikitext to process.
	 * @param StripState $strip_state
	 * @return bool Return true on success.
	 */
	public static function blockDisplay( Parser $parser, string &$text, StripState $strip_state ): bool {
		$title = $parser->getTitle();
		if ( $title ) {
			$namespace = $title->getNamespace();
			if ( $namespace >= 0 && $namespace !== NS_MEDIAWIKI ) {
				// Screen tags, in which we expect no math:
				[ $text, $screened ] = self::screen( $text, self::$noMathInTheseTags );
				foreach ( self::$blockRegexes as $regex => $replace ) {
					$text = preg_replace( $regex, $replace, $text );
				}
				$text = self::unscreen( $text, $screened );
			}
		}
		return true;
	}

	/**
	 * Entry point 3. Hooked by "ParserBeforeInternalParse": "MathJax::freeEnvironments" in extension.json.
	 *
	 * Replace free TeX environments \begin{...}...\end{...} with <math display="block">\begin{...}...\end{...}</math>.
	 *
	 * @param Parser $parser The Parser object.
	 * @param string &$text The wikitext to process.
	 * @param StripState $strip_state
	 * @return bool Return true on success.
	 */
	public static function freeEnvironments( Parser $parser, string &$text, StripState $strip_state ): bool {
		$title = $parser->getTitle();
		if ( $title ) {
			$namespace = $title->getNamespace();
			if ( $namespace >= 0 && $namespace !== NS_MEDIAWIKI ) {
				// Screen tags, in which we expect no math, and <math> itself:
				[ $text, $screened ] = self::screen( $text, self::$noMathInTheseTags, self::$mathTagRegex );
				// Process TeX environments outside <math>:
				$text = preg_replace_callback(
					self::envRegex(),
					function ( array $matches ): string {
						// Set flag: MathJax needed:
						self::$mathJaxNeeded = true;
						// Prepare free math just as math within <math></math> tag:
						$wikified = self::wikifyTeX( $matches[0] );
						global $wgmjServerSide;
						if ( $wgmjServerSide ) {
							$wikified = self::tex2MmlServerSide( $wikified, true );
						} else {
							$wikified = '<math display="block">' . $wikified . '</math>';
						}
						return $wikified;
					},
					$text
				);
				// Unscreen all:
				$text = self::unscreen( $text, $screened );
			}
		}
		return true;
	}

	/**
	 * Entry point 3. Hooked by $parser->setHook( $wgmjMathTag, __CLASS__ . '::renderMath' ); in self::setup().
	 *
	 * Render MML or TEX (<math> or {{#tag:math}}).
	 *
	 * @param string $input The content of the <math> tag.
	 * @param array $args The attributes of the <math> tag.
	 * @param Parser $parser The parser object.
	 * @param PPFrame $frame The frame object.
	 *
	 * @return array The resulting [ '(markup)', 'markerType' => 'nowiki' ] array.
	 */
	public static function renderMath( string $input, array $args, Parser $parser, PPFrame $frame ): array {
		$MML = false;
		$attributes = '';
		$block = false;
		// Check tag attributes to decide if it's TeX or MathML:
		global $wgmjMMLnamespaces;
		foreach ( $args as $name => $value ) {
			if ( $name === 'xmlns' && in_array( $value, $wgmjMMLnamespaces, true ) ) {
				// This seems to be MathML (<math xmlns="http://www.w3.org/1998/Math/MathML">):
				$attributes = ' xmlns="' . $value . '"';
				$MML = true;
			}
			if ( $name === 'display' ) {
				if ( $value === 'block' ) {
					$block = true;
					$attributes .= ' display="block"';
				} elseif ( $value === 'inline' ) {
					$attributes .= ' display="inline"';
				}
			}
			// Any other attribute will be ignored.
		}

		if ( $MML ) {
			// Form sanitised MathML code (<math xmlns="http://www.w3.org/1998/Math/MathML">...</math>) for MathJax:
			global $wgmjMMLTag;
			$return = "<$wgmjMMLTag$attributes>" . self::wikifyMML( $input ) . "</$wgmjMMLTag>";
		} else {
			// TeX.
			$wikified = self::wikifyTeX( $input );
			global $wgmjServerSide;
			if ( $wgmjServerSide ) {
				$return = self::tex2MmlServerSide( $wikified, $block );
			} else {
				// Form inline TeX surrounded by \(...\) or block TeX surrounded with $$...$$ for MathJax:
				global $wgmjTeX;
				$return = $wgmjTeX[$block ? 'displayMath' : 'inlineMath'][0][0]
						. $wikified
						. $wgmjTeX[$block ? 'displayMath' : 'inlineMath'][0][1];
			}
		}

		// Flag: MathJax needed:
		self::$mathJaxNeeded = true;

		// No further processing by MediaWiki:
		return [ $return, 'markerType' => 'nowiki' ];
	}

	/**
	 * Convert TeX to MML server-side with node.js and MathJax.
	 * @param string $tex TeX to convert.
	 * @param bool $block Block display (not inline).
	 * @return ?string Converted MML.
	 */
	private static function tex2MmlServerSide( string $tex, bool $block ): ?string {
		$tex_config = self::texConf();
		// Cache varying on TeX formula, its display mode and current TeX config.
		$tex = trim( $tex );
		$key = self::$cache->makeGlobalKey( 'MathJax', 'TeX2MML', $tex, (int)$block, hash( 'fnv1a64', $tex_config ) );
		$cached = self::$cache->get( $key, BagOStuff::READ_VERIFIED );
		if ( $cached ) {
			return $cached;
		}

		$block_str = $block ? 'true' : 'false';
		$quoted_tex = self::escape4JS( $tex );
		// @todo as service.
		$node_command = <<<NODE
let tex_conf = $tex_config;
tex_conf['packages'] = ['base', 'autoload', 'ams', 'newcommand', 'require', 'configmacros'];
require('mathjax-full').init({
    loader: {
        source: {},
        load: ['input/tex-full', 'adaptors/liteDOM' ]
    },
    tex: tex_conf
}).then((MathJax) => {
    MathJax.tex2mmlPromise('$quoted_tex' || '', {
        display: $block_str,
    }).then(mml => mml.replace (
		/^<math(.+?)>(.+)<\/math>$/su,
		'<math$1>\\n<semantics><mrow>$2</mrow>' +
		'<annotation encoding="application/x-tex">' +
		'$quoted_tex'.trim().replace(/&/g, '&#38;') +
		'</annotation>\\n</semantics>\\n</math>'
	)).then(mml => console.log(mml));
}).catch(err => console.log(err));
NODE;
		$cmd = 'node --stack-size=1024 --stack-trace-limit=1000 -r esm -';
		$descriptorspec = [
			[ 'pipe', 'r' ], // stdin is a pipe that the child will read from.
			[ 'pipe', 'w' ], // stdout is a pipe that the child will write to.
			[ 'pipe', 'w' ] // stderr is a pipe that the child will write to.
		];
		// phpcs:ignore
		$process = proc_open( $cmd, $descriptorspec, $pipes, __DIR__ );
		// phpcs:ignore
		if ( is_resource( $process ) ) {
			self::suppressWarnings();
			fwrite( $pipes[0], $node_command );
			self::restoreWarnings();
			fclose( $pipes[0] );
			$mml = stream_get_contents( $pipes[1] );
			fclose( $pipes[1] );
			$error = stream_get_contents( $pipes[2] );
			fclose( $pipes[2] );
			proc_close( $process );
		}
		if ( !$error ) {
			global $wgmjCacheExpiration;
			self::$cache->set( $key, $mml, $wgmjCacheExpiration );
			return $mml;
		} else {
			return '<span class="error">'
				. wfMessage( 'mathjax-broken-tex', $tex, $error )->inContentLanguage()->text()
				. '</span>';
		}
	}

	/**
	 * Entry point 4. Hooked by "BeforePageDisplay": "MathJax::processPage" in extension.json.
	 *
	 * Attach MathJax, if needed.
	 *
	 * @param OutputPage &$output The OutputPage object.
	 * @param Skin $skin The Skin object.
	 *
	 * @return bool Return true on success.
	 */
	public static function processPage( OutputPage &$output, Skin $skin ): bool {
		$namespace = $output->getTitle()->getNamespace();
		$text = $output->mBodytext;
		if ( !$text || $namespace < 0 || $namespace === NS_MEDIAWIKI ) {
			return true;
		}

		// <math> (already processed). Set flag: MathJax needed:
		self::$mathJaxNeeded = self::$mathJaxNeeded || preg_match( self::$mathRegex, $text );

		// Attach MathJax JavaScript, if necessary:
		global $wgmjClientSide;
		if ( $wgmjClientSide ) {
			$namespace = $output->getTitle()->getNamespace();
			if ( $namespace >= 0 && $namespace !== NS_MEDIAWIKI // not in Special: or Media:
				&& self::$mathJaxNeeded // MathJax needed flag has been raised.
			) {
				self::attachMathJaxIfNotYet( $output, $skin );
			}
		} else {
			global $wgmjServerSide;
			if ( $wgmjServerSide ) {
				$output->addInlineStyle( <<<'STYLE'
math {font-size: 150%;}
math>semantics>annotation {display:none;}
STYLE );
			}
		}
		return true;
	}

	/**
	 * Configure MathJax and attach MathJax scripts:
	 *
	 * @param OutputPage &$output The OutputPage object.
	 * @param Skin $skin The Skin object.
	 *
	 * @return bool Return true on success.
	 */
	private static function attachMathJaxIfNotYet( OutputPage &$output, Skin $skin ): bool {
		// Prevent multiple attaching:
		if ( self::$alreadyAttached ) {
			return true;
		}
		// CDN or local:
		global $wgmjServerSide, $wgmjUseCDN, $wgmjCDNDistribution, $wgExtensionAssetsPath, $wgmjLocalDistribution;
		// Attaching scripts:
		$jsonConfig = self::mjConf();
		$output->addInlineScript( "window.MathJax = $jsonConfig;" );
		$script = $wgmjServerSide ? 'mml-chtml.js' : 'tex-mml-chtml.js';
		$lang = $skin->getLanguage()->getCode();
		$output->addScriptFile(
			( $wgmjUseCDN ? $wgmjCDNDistribution : "$wgExtensionAssetsPath$wgmjLocalDistribution" )
			. "/$script?locale=$lang"
		);
		self::$alreadyAttached = true;
		// MathJax configuring and attachment complete.
		return true;
	}

	/*
	 * Math wikification.
	 */

	/**
	 * Prepare TeX for MathJax ([[]] wikilinks).
	 *
	 * @param string $tex The TeX code to wikify.
	 *
	 * @return string The wikified TeX code.
	 */
	private static function wikifyTeX( string $tex ): string {
		// Replace article titles in \href{...} or [[...]] with their canonical URLs, then strip HTML tags.
		// Commutative diagrams arrows (<<<, >>>) suffer from strip_tags, so, we screen arrows:
		[ $wikified, $screened ] = self::screen( $tex, '/' . implode( '|', self::$tagLike ) . '/' );
		$wikified = strip_tags( preg_replace_callback(
			// \href{...}, [[...]]
			[ '/\\href\s*\{(?!http)(.+?)\}\s*\{(.+?)\}/ui', '/\[\[(.+?)(?:\|(.*?))?\]\]/ui' ],
			function ( $matches ): string {
				return self::texHyperlink( $matches[1], $matches[2] );
			},
			$wikified
		) );
		return self::unscreen( $wikified, $screened );
	}

	/**
	 * Create a TeX hyperlink.
	 *
	 * @param string $page The title of the wiki page.
	 * @param ?string $alias The visible link text.
	 *
	 * @return string The TeX code for the hyperlink.
	 */
	private static function texHyperlink( string $page, ?string $alias = null ): string {
		$title = Title::newFromText( $page );
		if ( $title ) {
			return '\href {' . $title->getFullURL() . '}'
				. '{ \texttip {' . ( $alias ?? $page ) . '}'
				. '{ ' . $page . ' }}';
		}
		return $alias ?? $page;
	}

	/**
	 * Prepare MML for MathJax.
	 *
	 * @param string $mml The <math> MML tag inner contents.
	 *
	 * @return string The wikified MML.
	 */
	private static function wikifyMML( string $mml ): string {
		// Replace article titles in href="" with their canonical URLs:
		$ret = preg_replace_callback(
			[
				'/\<maction\s+actiontype\s*=\s*"tooltip"\s+'
				. 'href\s*=\s*"(?!http)(.+?)"\s*\>\s*(.+?)\<\/maction\>/si' // href="..."
				, '/\[\[(.+?)(?:\|(.*?))?\]\]/ui' // [[...]].
			],
			static function ( array $matches ): string {
				$title = Title::newFromText( $matches[1] );
				if ( $title ) {
					$alias = $matches[2] ?? $matches[1];
					return '<maction actiontype="texttip" href="' // was "tooltip" .
						. $title->getFullURL() . '">'
						. "\n$alias"
						. "\n<mtext>$alias</mtext>\n</maction>";
				}
				return $matches[0]; // code execution should not normally reach this point.
			},
			$mml
		);
		global $wgmjMMLtagsAllowed;
		static $mmlTagsAllowedGlued;
		if ( !$mmlTagsAllowedGlued ) {
			$mmlTagsAllowedGlued = '<' . implode( '><', $wgmjMMLtagsAllowed ) . '>';
		}
		return strip_tags( $ret, $mmlTagsAllowedGlued ); // remove HTML.
	}

	/*
	 * Prepare and serve needed configurations.
	 */

	/**
	 * Get a regular expression for free TeX environments.
	 *
	 * @return string The regular expression.
	 */
	private static function envRegex(): string {
		if ( !self::$envRegex ) {
			// Find out sequences that seem to be out-of-tag TEX markup like \begin{equation}...\end{equation}:
			$environments = trim( preg_replace(
				'/\s*,\s*/', '|',
				preg_quote( wfMessage( 'mathjax-environments' )->inContentLanguage()->text(), '/' )
			) );
			self::$envRegex = "/\\\\begin\\s*\\{($environments)\\}(.+?)\\\\end\\s*\\{\\1\\}/si";
		}
		return self::$envRegex;
	}

	/**
	 * Return configuration for MathJax as JSON.
	 * @return string Configuration as JSON.
	 * @todo Different for server-side rendering.
	 */
	private static function mjConf(): string {
		if ( !self::$mjConf ) {
			global $wgmjMathJax, $wgmjServerSide;
			if ( !$wgmjServerSide ) {
				global $wgmjTeX;
				if ( !isset( $wgmjTeX['macros'] ) || count( $wgmjTeX['macros'] ) === 0 ) {
					$wgmjTeX['macros'] = self::texMacros();
				}
				$wgmjMathJax['tex'] = $wgmjTeX;
			}
			try {
				self::$mjConf = json_encode( $wgmjMathJax, JSON_THROW_ON_ERROR );
			} catch ( Exception $e ) {
				self::$mjConf = '{}';
			}
		}
		return self::$mjConf;
	}

	/**
	 * Prepare a list of TeX macros for user's language in JavaScript format.
	 * Also, convert linked TeX commands to macros.
	 *
	 * @return array An associative array of macros.
	 */
	private static function texMacros(): array {
		$macros = [];
		// Macros per se.
		foreach ( preg_split( '/\s*,\s*/', wfMessage( 'mathjax-macros' )->inContentLanguage()->text() ) as $macro ) {
			$msg = wfMessage( "mathjax-macro-$macro" )->inContentLanguage();
			if ( $msg->exists() ) {
				$macros[$macro] = $msg->text();
			}
		}
		// Linked TeX commands.
		global $wgmjAddWikilinks;
		if ( $wgmjAddWikilinks ) {
			foreach ( preg_split( '/\s*,\s*/', wfMessage( 'mathjax-pages' )->inContentLanguage()->text() ) as $link ) {
				$page = wfMessage( "mathjax-page-$link" )->inContentLanguage();
				if ( $page->exists() ) {
					$macros[$link] =
						self::texHyperlink( $page->text(), $macros[$link] ?? '\\operatorname{' . $link . '}' );
				}
			}
		}
		return $macros;
	}

	/**
	 * Return configuration for MathJax's TeX as JSON.
	 * @return string Configuration as JSON.
	 */
	private static function texConf(): string {
		if ( !self::$texConf ) {
			global $wgmjTeX;
			if ( !isset( $wgmjTeX['macros'] ) || count( $wgmjTeX['macros'] ) === 0 ) {
				$wgmjTeX['macros'] = self::texMacros();
			}
			try {
				self::$texConf = json_encode( $wgmjTeX, JSON_THROW_ON_ERROR );
			} catch ( Exception $e ) {
				self::$texConf = '{}';
			}
		}
		return self::$texConf;
	}

	/**
	 * External Lua library paths for Scribunto
	 *
	 * @param string $engine To be used for the call.
	 * @param array &$extraLibraryPaths Additional libs.
	 * @return bool
	 */
	public static function registerLua( string $engine, array &$extraLibraryPaths ): bool {
		if ( $engine !== 'lua' ) {
			return true;
		}
		// Path containing pure Lua libraries that don't need to interact with PHP:
		$extraLibraryPaths[] = __DIR__ . '/lualib';
		$extraLibraryPaths[] = __DIR__ . '/lualib/vendor';
		$extraLibraryPaths[] = __DIR__ . '/lualib/vendor/symmath';
		return true;
	}

	/**
	 * Utilities.
	 */

	/**
	 * Screen certain substrings matching a regular expression.
	 * Unscreen with self::unscreen().
	 * @param string $text Text to screen.
	 * @param string ...$regexes Regular expression(s) to screen.
	 * @return array Element 0 contains text with screened substrings, 1 is an array with screened substrings.
	 */
	private static function screen( string $text, string ...$regexes ): array {
		$screened = [];
		$counter = self::$screenCounter;
		foreach ( $regexes as $regex ) {
			$text = preg_replace_callback(
				$regex,
				static function ( array $matches ) use ( &$screened, &$counter ): string {
					$screened[$counter /* self is inherited */ ] = $matches[0];
					return "&&&&" . ( $counter++ ) . '&&&&';
				},
				$text
			);
		}
		self::$screenCounter = $counter;
		return [ $text, $screened ];
	}

	/**
	 * Unscreen screened substrings.
	 * @param string $text Text with screened substrings.
	 * @param array $screened Screened substrings.
	 * @return string Text with unscreened substrings.
	 */
	private static function unscreen( string $text, array $screened ): string {
		return preg_replace_callback( '/&&&&(\\d+)&&&&/', static function ( array $matches ) use ( $screened ): string {
			return $screened[(int)$matches[1]];
		}, $text );
	}

	/**
	 * Escape string for JavaSctipt.
	 * @param string $text
	 * @return string
	 */
	private static function escape4JS( $text ) {
		return strtr( html_entity_decode( $text, ENT_NOQUOTES ), [ '\\' => '\\\\', "\n" => '\n', "'" => "\'" ] );
	}

	/**
	 * Suppress warnings absolutely.
	 */
	private static function suppressWarnings() {
		if ( method_exists( AtEase::class, 'suppressWarnings' ) ) {
			// MW >= 1.33
			AtEase::suppressWarnings();
		} else {
			suppressWarnings();
		}
	}

	/**
	 *  Restore warnings.
	 */
	private static function restoreWarnings() {
		if ( method_exists( AtEase::class, 'restoreWarnings' ) ) {
			// MW >= 1.33
			AtEase::restoreWarnings();
		} else {
			restoreWarnings();
		}
	}
}
