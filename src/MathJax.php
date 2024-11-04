<?php
namespace MediaWiki\Extensions\MathJax;

use Exception;
use MediaWiki\Hook\ParserFirstCallInitHook;
use MediaWiki\Hook\ParserBeforeInternalParseHook;
use MediaWiki\Hook\InternalParseBeforeLinksHook;
use MediaWiki\Hook\BeforePageDisplayHook;
use MediaWiki\Hook\SoftwareInfoHook;
use MediaWiki\MediaWikiServices;
use MWException;
use OutputPage;
use Parser;
use PPFrame;
use Skin;
use StripState;
use Title;

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
class MathJax
	implements
		ParserFirstCallInitHook,
		ParserBeforeInternalParseHook,
		InternalParseBeforeLinksHook,
		BeforePageDisplayHook,
		SoftwareInfoHook
{
	/** @const int VERSION_TTL Number of seconds that software version is to be cached. */
	private const VERSION_TTL = 3600; // one hour.

	/** @var ?Engines\Base $engine MathJax engine that will convert TeX to MML. */
	private static ?Engines\Base $engine = null;
	/** @var bool $mathJaxNeeded Page has formulas. */
	private static bool $mathJaxNeeded = false;
	/** @var bool $alreadyAttached Prevent multiple attaching. */
	private static bool $alreadyAttached = false;
	/** @var string $mathRegex A regular expression to check if <math> tags are present. */
	private static string $mathRegex = '';
	/** @var string[] $blockRegexes Regexps with replacements to search for : <math>...</math> and {{#tag:math|...}}. */
	private static array $blockRegexes = [];
	/** @var string $envRegex A regular expression for searching for equations outside <math>. */
	private static string $envRegex = '';
	/** @var string $noMathInTheseTags A regex to find HTML tags that cannot contain maths and screen them. */
	private static string $noMathInTheseTags = '';
	/** @var string[] $tagLike TeX commands that are somewhat like HTML tags. */
	private static array $tagLike = [ '>[^<>]*>[^<>]*>', '<[^<>]*<[^<>]*<' ];
	/** @var string $mjConf Complete (with macros) configuration for MathJax as JSON. */
	private static string $mjConf = '';
	/** @var int $screenCounter A counter for the screen() method. */
	private static int $screenCounter = 0;

	/**
	 * Entry point 1. Hooked by "ParserFirstCallInit": "MathJaxHooks" in extension.json.
	 *
	 * Make the parser aware of <math> tag on initialisation, do some other initialisations.
	 *
	 * @param Parser $parser The parser object.
	 * @return bool Return true on success.
	 * @throws MWException
	 */
	public function onParserFirstCallInit( $parser ): bool {
		// When the parser sees the <math> tag, it executes
		// the self::renderMath function:
		global $wgmjMathTag;
		$parser->setHook( $wgmjMathTag, __CLASS__ . '::renderMath' );

		// When the parser sees the <chem> tag, it executes
		// the self::renderChem function:
		global $wgmjChemTag;
		$parser->setHook( $wgmjChemTag, __CLASS__ . '::renderChem' );

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
		global $wgmjMathJax;
		$tex = $wgmjMathJax['tex'];
		self::$mathRegex = '/('
			. preg_quote( $tex['inlineMath'][0][0], '/' )
			. '.+?'
			. preg_quote( $tex['inlineMath'][0][1], '/' )
			. '|'
			. preg_quote( $tex['displayMath'][0][0], '/' )
			. '.+?'
			. preg_quote( $tex['displayMath'][0][1], '/' )
			. '|'
			. "<$tag"
			. ')/';
		// Regex to screen tags that cannot contain maths:
		$any_tag = implode( '|', $wgmjMathJax['options']['skipHtmlTags'] );
		// Should deal with nested tags correctly:
		self::$noMathInTheseTags
			= "%<($any_tag)[^>]*?(?<!/)>(\n" // open tag.
			. '| (' . implode( '|', self::$tagLike ) . ")\n" // >>> in CD.
			. "| (?>[^<>]+)\n" // non-tag.
			. "| <($any_tag)[^>]*/>\n" // self-closing tag.
			. "| (?R)\n" // another tag (recursion).
			. ")* </\\1>%six"; // close tag.

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
	 * Entry point 2. Hooked by "ParserBeforeInternalParse": "MathJaxHooks" in extension.json.
	 *
	 * Replace :<math>...</math>  with <math display="block">...</math>,
	 *         :{{#tag:math|...}} with {{#tag:math|...|display="block"}}.
	 * Also, bring a following comma, full stop, etc. into the formula,
	 *
	 * @param Parser $parser The Parser object.
	 * @param string $text The wikitext to process.
	 * @param StripState $_
	 * @return bool Return true on success.
	 */
	public function onParserBeforeInternalParse( $parser, &$text, $_ ): bool {
		$title = $parser->{ method_exists( $parser, 'getPage' ) ? 'getPage' : 'getTitle' }();
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
	 * Entry point 3. Hooked by "InternalParseBeforeLinks": "MathJaxHooks" in extension.json.
	 *
	 * Wikify free TeX environments \begin{...}...\end{...}, then hide them.
	 *
	 * @param Parser $parser The Parser object.
	 * @param string $text The wikitext to process.
	 * @return bool Return true on success.
	 */
	public function onInternalParseBeforeLinks( $parser, &$text, $_ ): bool {
		$title = $parser->{ method_exists( $parser, 'getPage' ) ? 'getPage' : 'getTitle' }();
		if ( !$title ) {
			return true;
		}
		$namespace = $title->getNamespace();
		if ( $namespace < 0 || $namespace === NS_MEDIAWIKI ) {
			return true;
		}
		$counter = 0;
		// Process TeX environments outside <math>:
		$text = preg_replace_callback(
			self::envRegex(),
			static function ( array $matches ) use ( $parser, &$counter ): string {
				// Set flag: MathJax needed:
				self::$mathJaxNeeded = true;
				// Prepare free math just as math within <math></math> tag, then replace it with a strip item:
				$marker = $parser::MARKER_PREFIX . '-tex-free-environment-' .
					sprintf( '%08d', $counter++ ) . $parser::MARKER_SUFFIX;
				$parser->getStripState()->addNoWiki( $marker, self::wikifyTeX( $matches[0] ) );
				return $marker;
			},
			$text
		);
		return true;
	}

	/**
	 * Implementation of <math> and <chem>.
	 *
	 * @param string $input The content of the <math>/<chem> tag.
	 * @param array $args The attributes of the <math>/<chem> tag.
	 *
	 * @return array The resulting [ '(markup)', 'markerType' => 'nowiki' ] array.
	 */
	private static function renderTag( string $input, array $args ): array {
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
			// Form inline TeX surrounded by \(...\) or block TeX surrounded with $$...$$ for MathJax:
			global $wgmjMathJax;
			$tex = $wgmjMathJax['tex'];
			$return = $tex[$block ? 'displayMath' : 'inlineMath'][0][0]
				. $wikified
				. $tex[$block ? 'displayMath' : 'inlineMath'][0][1];
		}

		// Flag: MathJax needed:
		self::$mathJaxNeeded = true;

		// No further processing by MediaWiki:
		return [ $return, 'markerType' => 'nowiki' ];
	}

	/**
	 * Entry point 4. Hooked by $parser->setHook( $wgmjMathTag, __CLASS__ . '::renderMath' ); in self::setup().
	 *
	 * Render MML or TEX (<math> or {{#tag:math}}).
	 *
	 * @param string $input The content of the <math> tag.
	 * @param array $args The attributes of the <math> tag.
	 * @param Parser $_ The parser object.
	 * @param PPFrame $__ The frame object.
	 *
	 * @return array The resulting [ '(markup)', 'markerType' => 'nowiki' ] array.
	 */
	public static function renderMath( string $input, array $args, Parser $_, PPFrame $__ ): array {
		return self::renderTag( $input, $args );
	}

	/**
	 * Entry point 4a. Hooked by $parser->setHook( $wgmjMathChem, __CLASS__ . '::renderChem' ); in self::setup().
	 *
	 * Render MML or TEX (<chem> or {{#tag:chem}}).
	 *
	 * @param string $input The content of the <chem> tag.
	 * @param array $args The attributes of the <chem> tag.
	 * @param Parser $_ The parser object.
	 * @param PPFrame $__e The frame object.
	 *
	 * @return array The resulting [ '(markup)', 'markerType' => 'nowiki' ] array.
	 */
	public static function renderChem( string $input, array $args, Parser $_, PPFrame $__ ): array {
		return self::renderTag( "\ce{ $input }", $args );
	}

	/**
	 * Entry point 5. Hooked by "BeforePageDisplay": "MathJaxHooks" in extension.json.
	 *
	 * Process TeX server-side, if configured.
	 * Raise "Attach MathJax" flag, if needed.
	 *
	 * @param OutputPage $out The OutputPage object.
	 * @param Skin $skin The Skin object.
	 *
	 * @return void.
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		$title = $out->getTitle();
		if ( !$title ) {
			return;
		}
		$namespace = $title->getNamespace();
		if ( !$out->mBodytext || $namespace < 0 || $namespace === NS_MEDIAWIKI ) {
			return;
		}

		// <math> (already processed). Set flag: MathJax needed:
		self::$mathJaxNeeded = self::$mathJaxNeeded || preg_match( self::$mathRegex, $out->mBodytext );

		if ( self::$mathJaxNeeded ) {
			// Process TeX server-side, if configured.
			$lang = $skin->getLanguage()->getCode();
			$out->mBodytext = self::engine()->tex2mml( $out->mBodytext, self::mjConf(), $lang );

			// Add scripts, styles, etc.
			self::engine()->processPage( $out, $skin );

			// Attach MathJax JavaScript, if necessary:
			global $wgmjClientSide;
			if ( $wgmjClientSide ) {
				self::attachMathJaxIfNotYet( $out, $skin );
			}
		}
	}

	/**
	 * Configure MathJax and attach MathJax scripts:
	 *
	 * @param OutputPage $output The OutputPage object.
	 * @param Skin $skin The Skin object.
	 *
	 * @return void
	 */
	private static function attachMathJaxIfNotYet( OutputPage $output, Skin $skin ): void {
		// Prevent multiple attaching:
		if ( self::$alreadyAttached ) {
			return;
		}
		// Attaching scripts:
		$jsonConfig = self::mjConf();
		$output->addInlineScript( "window.MathJax = $jsonConfig;" );
		global $wgmjUseCDN;
		// CDN or local:
		if ( $wgmjUseCDN ) {
			// Add CDN domain to CSP header:
			global $wgCSPHeader, $wgmjCDNDistribution;
			$domain = parse_url( $wgmjCDNDistribution,  PHP_URL_HOST );
			if ( $wgCSPHeader !== false ) {
				$wgCSPHeader = is_array( $wgCSPHeader ) ? $wgCSPHeader : [];
				foreach ( [ 'script-src', 'default-src' ] as $src ) {
					if ( !is_array( $wgCSPHeader[$src] ) ) {
						$wgCSPHeader[$src] = [];
					}
					if ( !in_array( $domain, $wgCSPHeader[$src], true ) ) {
						$wgCSPHeader[$src][] = $domain;
					}
				}
			}
			$mathjax_domain = $wgmjCDNDistribution;
		} else {
			// No CDN:
			$mathjax_domain = self::engine()->serverSideMathJaxDir();
		}
		$script = self::engine()->browserMathJaxScriptBasename();
		$lang = $skin->getLanguage()->getCode();
		$output->addScriptFile( "$mathjax_domain/$script?locale=$lang" );
		self::$alreadyAttached = true;
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
			[ '/\\href\s*\{(?!http)(.+?)}\s*\{(.+?)}/ui', '/\[\[(.+?)(?:\|(.*?))?]]/ui' ],
			function ( array $matches ): string {
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
				'/<maction\s+actiontype\s*=\s*"tooltip"\s+'
				. 'href\s*=\s*"(?!http)(.+?)"\s*>\s*(.+?)<\/maction>/si' // href="..."
				, '/\[\[(.+?)(?:\|(.*?))?]]/ui' // [[...]].
			],
			static function ( array $matches ): string {
				$title = Title::newFromText( $matches[1] );
				if ( $title ) {
					$alias = $matches[2] ?? $matches[1];
					return '<maction actiontype="texttip" href="'
						. $title->getFullURL() . '">'
						. "\n$alias"
						. "\n<mtext>$alias</mtext>\n</maction>";
				}
				return $matches[0]; // code execution should not normally reach this point.
			},
			$mml
		);
		global $wgmjMMLtagsAllowed;
		static $mml_tags_allowed_glued;
		if ( !$mml_tags_allowed_glued ) {
			$mml_tags_allowed_glued = '<' . implode( '><', $wgmjMMLtagsAllowed ) . '>';
		}
		return strip_tags( $ret, $mml_tags_allowed_glued ); // remove HTML.
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
	 */
	private static function mjConf(): string {
		if ( !self::$mjConf ) {
			global $wgmjMathJax;
			if ( !isset( $wgmjMathJax['tex']['macros'] ) || count( $wgmjMathJax['tex']['macros'] ) === 0 ) {
				$wgmjMathJax['tex']['macros'] = self::texMacros();
			}
			try {
				self::$mjConf = json_encode( $wgmjMathJax, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT );
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
	 * External Lua library paths for Scribunto
	 *
	 * @param string $engine To be used for the call.
	 * @param array $extraLibraryPaths Additional libs.
	 * @return bool
	 */
	public static function registerLua( string $engine, array &$extraLibraryPaths ): bool {
		if ( $engine !== 'lua' ) {
			return true;
		}
		// Path containing pure Lua libraries that don't need to interact with PHP:
		$extraLibraryPaths[] = __DIR__ . '/../lualib';
		$extraLibraryPaths[] = __DIR__ . '/../lualib/vendor';
		$extraLibraryPaths[] = __DIR__ . '/../lualib/vendor/symmath';
		$extraLibraryPaths[] = __DIR__ . '/../lualib/vendor/complex';
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
					$screened[ $counter ] = $matches[0];
					return '&&&&' . ( $counter++ ) . '&&&&';
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
	 * TeX engine.
	 * @return Engines\Base
	 */
	private static function engine(): Engines\Base {
		if ( !self::$engine ) {
			// Initialise MathJax engine.
			global $wgmjServerSide, $wgmjServiceUrl;
			$class = __NAMESPACE__ . '\\Engines\\' . (
				$wgmjServiceUrl ? 'Service' : (
				$wgmjServerSide ? 'ServerSide'
								: 'ClientSide'
			) );
			self::$engine = new $class();
		}
		return self::$engine;
	}

	/**
	 * Entry point 6. Hooked by "SoftwareInfo": "MathJaxHooks" in extension.json.
	 * Register used MathJax version for Special:Version.
	 * @param array $software
	 */
	public function onSoftwareInfo( &$software ) {
		$cache = MediaWikiServices::getInstance()->getLocalServerObjectCache();
		$software['[https://www.mathjax.org/ MathJax]'] = $cache->getWithSetCallback(
			$cache->makeGlobalKey( get_class( self::engine() ), 'MathJax version' ),
			self::VERSION_TTL,
			static function (): ?string {
				return self::engine()->version();
			}
		) ?: '(unknown version)';
	}
}
