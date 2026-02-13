<?php
namespace MediaWiki\Extensions\MathJax;

use Exception;
use MediaWiki\Hook\BeforePageDisplayHook;
use MediaWiki\Hook\InternalParseBeforeLinksHook;
use MediaWiki\Hook\ParserBeforeInternalParseHook;
use MediaWiki\Hook\ParserFirstCallInitHook;
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
class MathJax implements
	ParserFirstCallInitHook,
	ParserBeforeInternalParseHook,
	InternalParseBeforeLinksHook,
	BeforePageDisplayHook,
	SoftwareInfoHook
{
	/** @const string MSGPREFIX Prefix to the extension's message codes. */
	private const MSGPREFIX = 'mathjax';

	/** @const int VERSION_TTL Number of seconds that software version is to be cached. */
	private const VERSION_TTL = 3600; // one hour.

	/** @const string TEXCOMMAND Regular expression for TeX command. */
	private const TEXCOMMAND = '/(?<=\\\\)(`|\'|^|"|~|=|\.|#|$|%|&|,|:|;|>|\\\\|_|\{|\||}|[A-Za-z0-9]+)/';
	/** @const string WIKILINK Format for commands wrapping a TeX command with a wiki link and a tooltip. */
	private const WIKILINK = '\texttip{ \href{ %3$s }{ %1$s } }{ %2$s }';
	/** @const string[] Formats for redefinition commands. */
	private const DEFINITIONS = [
		// @TODO: add macros: linked or not.
		'\newcommand{ \%1$s }[%2$d]{ \href{ %3$s }{ %5$s } }', // -- define; page
		'\newcommand{ \%1$s }[%2$d]{ %5$s }', // -- define; no page
		'\let\old%1$s\%1$s \renewcommand{ \%1$s }[%2$d]{ \href{ %3$s }{ \old%1$s%4$s } }', // -- redefine; page.
		'\let\old%1$s\%1$s \renewcommand{ \%1$s }[%2$d]{ \old%1$s%4$s }' // -- redefine; no page.
	];

	/** @var ?Engines\Base $engine Server-side MathJax engine. */
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
		$tag = preg_quote( $wgmjMathTag, '/' ) . '|' . preg_quote( $wgmjChemTag, '/' );
		self::$blockRegexes = [
			// : <math>...</math> -> <math display="block">...</math>.
			'/(?:^|\n)\s*(?::+\s*)+<(' . $tag . ')>(.+?)<\/\1>[  ]*([.,:;]?)/si'
			=> "\n" . '<$1 display="block">$2$3</$1>',
			// : {{#tag:math|...}} -> {{#tag:math|display="block"|...}}.
			'/(?:^|\n)\s*(?::+\s*)+\{\{\#tag:(' . $tag . ')\|(.+?)}}[  ]*([.,:;]?)/si'
			=> "\n" . '{{#tag:$1|$2$3|display="block"}}'
		];
		global $wgmjMathJax;
		$tex = $wgmjMathJax['tex'];
		$options = [];
		foreach ( [ 'displayMath', 'inlineMath' ] as $mode ) {
			foreach ( $tex[$mode] as [ $open, $close ] ) {
				$options[] = preg_quote( $open, '/' ) . '.+?' . preg_quote( $close, '/' );
			}
		}
		$options[] = "<($tag)";
		self::$mathRegex = '/(' . implode( '|', $options ) . ')/';
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
		if ( $title && $text ) {
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
	 * @param StripState $_ Not used.
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
	 * @param Parser $_ The parser object (not used).
	 * @param PPFrame $__ The frame object (not used).
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
	 * @param PPFrame $__ The frame object.
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

			// There is no need to cache this: already cached by the parser cache.
			global $wgmjMathJax;
			$delims = $wgmjMathJax['tex']['displayMath'][0];
			$used = self::usedCommands( $out->mBodytext );
			global $wgmjAddWikilinks;
			$html = $out->mBodytext;
			if ( $wgmjAddWikilinks ) {
				$html = self::definitions( $used, $delims[0], $delims[1] ) . $html;
			}
			$out->mBodytext = self::engine()->tex2mml( $html, self::mjConf(), $lang );

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
				return self::texHyperlink( $matches[1], $matches[2] ?? null );
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
			// @TODO: tell existing pages from non-existing
			return sprintf( self::WIKILINK, $alias, $page, $title->getLocalURL() );
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
	 * Prepare a list of TeX macros for user's language in JavaScript format.
	 * @return array An associative array of macros.
	 */
	private static function texMacros(): array {
		// If TeX commands are not linked, new macros can simply be passed in MathJax configuration.
		$macros = [];
		global $wgmjAddWikilinks;
		if ( !$wgmjAddWikilinks ) {
			foreach ( self::msg2array( 'macros' ) as $code ) {
				$macro = self::msg( 'macro', $code );
				if ( $macro ) {
					$macros[$code] = $macro;
				}
			}
		}
		return $macros;
	}

	/**
	 * Return configuration for MathJax as JSON.
	 * @return string Configuration as JSON.
	 */
	private static function mjConf(): string {
		if ( !self::$mjConf ) {
			global $wgmjMathJax;
			try {
				$wgmjMathJax['tex']['macros'] = self::texMacros();
				self::$mjConf = json_encode( $wgmjMathJax, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT );
			} catch ( Exception $_ ) {
				self::$mjConf = '{}';
			}
		}
		return self::$mjConf;
	}

	/**
	 * Returns a list of TeX commands that are likely to be actually used in $html.
	 * @param string $html
	 * @return array
	 */
	private static function usedCommands( string $html ): array {
		if ( preg_match_all( self::TEXCOMMAND, $html, $commands ) ) {
			return array_unique( $commands[0] );
		} else {
			return [];
		}
	}

	/**
	 * Splits the message with code $code into an array.
	 * @param string $code Message code.
	 * @return array List as an ordered array, [], if no message.
	 */
	private static function msg2array( string $code ): array {
		$msg = wfMessage( self::MSGPREFIX . '-' . $code )->inContentLanguage();
		return $msg->exists() ? preg_split( '/\s*,\s*/', $msg->text() ) : [];
	}

	/**
	 * Return the contents of the message $prefix-$code (macro (re)definition or wikipage).
	 * @param string $prefix
	 * @param string $command
	 * @return string
	 */
	private static function msg( string $prefix, string $command ): string {
		$msg = wfMessage( self::MSGPREFIX . "-$prefix-$command" )->inContentLanguage();
		return $msg->exists() ? $msg->text() : '';
	}

	/**
	 * Returns an associative array of number of parametres indexed by TeX command.
	 * @return array
	 */
	private static function noParams(): array {
		$commands = [];
		for ( $i = 1; $i <= 9; $i++ ) {
			foreach ( self::msg2array( "params-$i" ) as $command ) {
				$commands[$command] = $i;
			}
		}
		return $commands;
	}

	/**
	 * Generates a (re)definition for TeX command.
	 * @param string $command Command name.
	 * @param string $definition Command definition. '' for redefinition.
	 * @param int $no_args Number of arguments that the href-wrapped standard command takes.
	 * @param string $page Wiki page about the command. '' for none.
	 * @return string
	 */
	private static function definition( string $command, string $definition, int $no_args, string $page ): string {
		if ( !mb_check_encoding( $command, 'ASCII' ) ) {
			return '';
		}
		if ( $no_args === 0 && preg_match_all( '/#\d/', $definition, $matches ) ) {
			$no_args = count( $matches[0] );
		}
		$args = '';
		for ( $i = 1; $i <= $no_args; $i++ ) {
			$args .= "{ #$i }";
		}
		$format = ( $definition ? 0 : 2 ) + ( $page ? 0 : 1 );
		$title = Title::newFromText( $page );
		$url = $title ? $title->getLocalURL() : '';
		return sprintf( self::DEFINITIONS[$format], $command, $no_args, $url, $args, $definition );
	}

	/**
	 * Generate a string with all necessary definitions.
	 * @param array $used Actually used commands.
	 * @param string $open TeX open tag, i.e. $$.
	 * @param string $close TeX close tag, i.e. $$.
	 * @return string
	 */
	private static function definitions( array $used, string $open, string $close ): string {
		$macros = self::msg2array( 'macros' );
		$pages = self::msg2array( 'pages' );
		$commands = array_intersect( $used, array_unique( array_merge( $macros, $pages ), SORT_STRING ) );
		$params = self::noParams();
		$definitions = array_map( static function ( string $command ) use ( $params ): string {
			return self::definition(
				$command,
				self::msg( 'macro', $command ),
				$params[$command] ?? 0,
				self::msg( 'page', $command )
			);
		}, $commands );
		return '<div id="definitions" style="display: none">' . $open
			. implode( "\n", $definitions )
			. $close . '</div>';
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
