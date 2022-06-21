<?php
use MediaWiki\MediaWikiServices;
use MediaWiki\Shell\Shell;
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
	/** @var string $envRegex A regular expression for searching for equations outside <math>. */
	private static $envRegex;
	/** @var string $noMathInTheseTags A regex to find HTML tags that cannot contain maths and screen them. */
	private static $noMathInTheseTags;
	/** @var array $tagLike TeX commands that are somewhat like HTML tags. */
	private static $tagLike = [ '>[^<>]*>[^<>]*>', '<[^<>]*<[^<>]*<' ];
	/** @var string $mjConf Complete (with macros) configuration for MathJax as JSON. */
	private static $mjConf;
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
	 * Entry point 2. Hooked by "ParserBeforeInternalParse": "MathJax::blockDisplay" in extension.json.
	 *
	 * Replace :<math>...</math>  with <math display="block">...</math>,
	 *         :{{#tag:math|...}} with {{#tag:math|...|display="block"}}.
	 * Also, bring a following comma, full stop, etc. into the formula,
	 *
	 * @param Parser $parser The Parser object.
	 * @param string $text The wikitext to process.
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
	 * Entry point 3. Hooked by "InternalParseBeforeLinks": "MathJax::freeEnvironments" in extension.json.
	 *
	 * Wikify free TeX environments \begin{...}...\end{...}, then hide them.
	 *
	 * @param Parser $parser The Parser object.
	 * @param string $text The wikitext to process.
	 * @return bool Return true on success.
	 */
	public static function freeEnvironments( Parser $parser, string &$text ): bool {
		$title = $parser->getTitle();
		if ( !$title ) {
			return true;
		}
		$namespace = $title->getNamespace();
		if ( $namespace < 0 && $namespace === NS_MEDIAWIKI ) {
			return true;
		}
		$counter = 0;
		// Process TeX environments outside <math>:
		$text = preg_replace_callback(
			self::envRegex(),
			function ( array $matches ) use ( $parser, &$counter ): string {
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
	 * @param Parser $parser The parser object.
	 * @param PPFrame $frame The frame object.
	 *
	 * @return array The resulting [ '(markup)', 'markerType' => 'nowiki' ] array.
	 */
	public static function renderMath( string $input, array $args, Parser $parser, PPFrame $frame ): array {
		return self::renderTag( $input, $args );
	}

	/**
	 * Entry point 4a. Hooked by $parser->setHook( $wgmjMathChem, __CLASS__ . '::renderChem' ); in self::setup().
	 *
	 * Render MML or TEX (<chem> or {{#tag:chem}}).
	 *
	 * @param string $input The content of the <chem> tag.
	 * @param array $args The attributes of the <chem> tag.
	 * @param Parser $parser The parser object.
	 * @param PPFrame $frame The frame object.
	 *
	 * @return array The resulting [ '(markup)', 'markerType' => 'nowiki' ] array.
	 */
	public static function renderChem( string $input, array $args, Parser $parser, PPFrame $frame ): array {
		return self::renderTag( '\ce{' . $input . '}', $args );
	}

	/**
	 * Entry point 5. Hooked by "BeforePageDisplay": "MathJax::processPage" in extension.json.
	 *
	 * Process TeX server-side, if configured.
	 * Raise "Attach MathJax" flag, if needed.
	 *
	 * @param OutputPage $output The OutputPage object.
	 * @param Skin $skin The Skin object.
	 *
	 * @return bool Return true on success.
	 */
	public static function processPage( OutputPage &$output, Skin $skin ): bool {
		$title = $output->getTitle();
		if ( !$title ) {
			return true;
		}
		$namespace = $title->getNamespace();
		if ( !$output->mBodytext || $namespace < 0 || $namespace === NS_MEDIAWIKI ) {
			return true;
		}

		// <math> (already processed). Set flag: MathJax needed:
		self::$mathJaxNeeded = self::$mathJaxNeeded
							|| ( self::$mathRegex ? preg_match( self::$mathRegex, $output->mBodytext ) : false );

		if ( self::$mathJaxNeeded ) {
			// Process TeX server-side, if configured.
			global $wgmjServerSide;
			if ( $wgmjServerSide ) {
				$output->mBodytext = self::allTex2mml( $output->mBodytext );
			}

			// Attach MathJax JavaScript, if necessary:
			global $wgmjClientSide;
			if ( $wgmjClientSide ) {
				self::attachMathJaxIfNotYet( $output, $skin );
			} else {
				if ( $wgmjServerSide ) {
					$output->addInlineStyle( <<<'STYLE'
						math {font-size: 150%;}
						math>semantics>annotation {display:none;}
					STYLE
					);
				}
			}
		}
		return true;
	}

	/**
	 * Convert all TeX formulae in $html to MathML.
	 *
	 * @param string $html HTML to find TeX formulae in.
	 * @return string Processed HTML.
	 */
	private static function allTex2mml( string $html ): string {
		$conf_file = sys_get_temp_dir() . '/MathJax.config.json';
		if ( !file_exists( $conf_file ) ) {
			file_put_contents( $conf_file, self::mjConf() );
		}

		// @todo as service.
		$command = 'node --stack-size=1024 --stack-trace-limit=1000 -r esm '
				 . __DIR__ . '/tex2mml.js --conf=' . $conf_file . ' --dist=1';
		$shell = Shell::command( explode( ' ', $command ) ) // Shell class demands an array of words.
			->environment( [ 'NODE_PATH' => __DIR__ . '/../node_modules' ] ) // MathJax installed locally.
			->limits( [ 'memory' => 0, 'time' => 0, 'filesize' => 0 ] );
		if ( strlen( $html ) >= 65536 /* hardcoded in Command::execute() */ ) {
			// Create a temporary HTML file and pass its name to tex2mml.js:
			$html_file = sys_get_temp_dir() . '/' . md5( $html );
			if ( !file_exists( $html_file ) ) {
				file_put_contents( $html_file, $html );
			}
			$shell = $shell->params( $html_file );
		} else {
			// HTML is sent to the standard input:
			$shell = $shell->params( '-' )->input( $html );
		}
		self::suppressWarnings();
		try {
			$result = $shell->execute();
		} catch ( Exception $e ) {
			$error = '<span class="error">'
				. wfMessage( 'mathjax-broken-tex', $e->getMessage() )->inContentLanguage()->text()
				. '</span>';
			self::restoreWarnings();
			return $error . $html;
		}
		self::restoreWarnings();

		if ( $result->getExitCode() === 0 ) {
			return $result->getStdout();
		} else {
			$error = '<span class="error">'
				   . wfMessage( 'mathjax-broken-tex', $result->getStderr() )->inContentLanguage()->text()
				   . '</span>';
			return $error . $html;
		}
	}

	/**
	 * Configure MathJax and attach MathJax scripts:
	 *
	 * @param OutputPage $output The OutputPage object.
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
				'/\<maction\s+actiontype\s*=\s*"tooltip"\s+'
				. 'href\s*=\s*"(?!http)(.+?)"\s*\>\s*(.+?)\<\/maction\>/si' // href="..."
				, '/\[\[(.+?)(?:\|(.*?))?\]\]/ui' // [[...]].
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

	/**
	 * Entry point 6.
	 *
	 * Register used MathJax version for Special:Version.
	 *
	 * @param array $software
	 */
	public static function register( array &$software ) {
		$cache = MediaWikiServices::getInstance()->getLocalServerObjectCache();
		$software['[https://www.mathjax.org/ MathJax]'] = $cache->getWithSetCallback(
			$cache->makeGlobalKey( __CLASS__, 'MathJax version' ),
			3600,
			static function () {
				try {
					$result = Shell::command(
						explode( ' ', 'npm list -l --prefix ' . dirname( __DIR__ ) . ' mathjax-full' )
					)->restrict( Shell::RESTRICT_DEFAULT | Shell::NO_NETWORK )
					 ->execute();
				} catch ( Exception $e ) {
					return null;
				}
				if ( $result->getExitCode() === 0 && preg_match( '/mathjax.+$/s', $result->getStdout(), $match ) ) {
					return trim( $match[0] );
				}
			}
		) ?: '(unknown version)';
	}
}
