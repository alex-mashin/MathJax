<?php
/**
 * This class encapsulates code needed to render mathematical formulae in wikitext.
 *
 * @author Alexaner Mashin
 * @author Mithgol the Webmaster
 */
class MathJax {

	/** @var bool $mathJaxNeeded Page has formulas. */
	private static $mathJaxNeeded = false;
	/** @var bool $alreadyAttached Prevent multiple attaching. */
	private static $alreadyAttached = false;
	/** @var string $envRegex A regular expression for searching for regular expressions outside <math>. */
	private static $envRegex;
	/** @var array $linked An array of already linked pages. */
	private static $linked = [];


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

		// Powered by MathJax:
		global $wgFooterIcons, $wgmjPoweredByIconURI;
		$wgFooterIcons ['poweredby'] ['MathJax'] = [
			'src' => $wgmjPoweredByIconURI,
			'url' => 'https://www.mathjax.org/',
			'alt' => 'Formulas rendered by MathJax'
		];

		// Switch standard math off:
		global $wgUseTeX;
		$wgUseTeX = false;

		return true;
	}   // -- public static function setup(Parser $parser): bool

	/**
	 * Entry point 2. Hooked by "ParserBeforeInternalParse": "MathJax::blockDisplay" in extension.json.
	 *
	 * Replace :<math>...</math> with <math display="block">...</math>,
	 *                        :{{#tag:math|...}} with :{{#tag:math|...|display="block"}}.
	 *
	 * @param Parser &$parser The Parser object.
	 * @param string &$text The wikitext to process.
	 * @param StripState &$strip_state.
	 * @return bool Return true on success.
	 */
	public static function blockDisplay( Parser &$parser, string &$text, StripState &$strip_state ): bool {
		// Change :<math>...</math> into <math display="block">...</math>.
		//     Bring a following comma, full stop, etc. into the formula:
		if ( $parser->getTitle()
			&& $parser->getTitle()->getNamespace() >= 0 // -- not in Special: or Media:.
			&& $parser->getTitle()->getNamespace() !== NS_MEDIAWIKI
		) {
			global $wgmjMathTag;
			// : <math>...</math>:
			$tag = preg_quote( $wgmjMathTag, '/' );
			$text = preg_replace( '/\n\s*(?::+\s*)+<' . $tag . '>(.+?)<\/' . $tag . '>[  ]*([\.,:;]?)/si',
			                      "\n<" . $wgmjMathTag . ' display="block">$1$2</' . $wgmjMathTag . '>',
			                      $text );
			// : {{#tag:math|...}}:
			$text = preg_replace( '/\n\s*(?::+\s*)+\{\{\#tag\:' . $tag . '\|(.+?)\}\}[  ]*([\.,:;]?)/si',
			                      "\n{{\#tag:" . $wgmjMathTag . '|$1$2|display="block"}}',
			                      $text );
		}
		return true;
	}    // -- public static function blockDisplay (Parser &$parser, string &$text, StripState &$strip_state): bool

	/**
	 * Entry point 3. Hooked by $parser->setHook( $wgmjMathTag, __CLASS__ . '::renderMath' ); in self::setup().
	 *
	 * Render MML or TEX (<math>).
	 *
	 * @param string $input The content of the <math> tag.
	 * @param array $args The attributes of the <math> tag.
	 * @param Parser $parser The parser object.
	 * @param PPFrame @frame The frame object.
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
			if ( $name === 'xmlns' && in_array( $value, $wgmjMMLnamespaces ) ) {
				// This seems to be MathML (<math xmlns="http://www.w3.org/1998/Math/MathML">):
				$attributes = ' xmlns="' . $value . '"';
				$MML = true;
			}
			if ( $name === 'display' && ($value === 'block' || $value === 'inline') ) {
				// Block or inline display:
				$block = true;
				$attributes .= ' display="' . $value . '"';
			}
			// Any other attribute will be ignored.
		}
		if ( $MML ) {
			// Form sanitised MathML code (<math xmlns="http://www.w3.org/1998/Math/MathML">...</math>) for MathJax:
			global $wgmjMMLTag;
			$return = "<$wgmjMMLTag$attributes>"
				. self::wikifyMML( $input )
				. "</$wgmjMMLTag>";
		} else { // -- TeX.
			// Form inline TeX surrounded by \(...\) or block TeX surrounded with $$...$$ for MathJax:
			global $wgmjMathJax;
			$return = $wgmjMathJax['tex'][$block ? 'displayMath' : 'inlineMath'][0][0]
					. self::wikifyTeX( $input )
					. $wgmjMathJax['tex'][$block ? 'displayMath' : 'inlineMath'][0][1];
		}

		// Flag: MathJax needed:
		self::$mathJaxNeeded = true;

		// No further processing by MediaWiki:
		return [ $return, 'markerType' => 'nowiki' ];
	}    // -- public static function renderMath (string $input, array $args, Parser $parser, PPFrame $frame): array

	/**
	 * Prepare TeX for MathJax.
	 *
	 * @param sring $tex The TeX code to wikify.
	 *
	 * @return string The wikified TeX code.
	 */
	private static function wikifyTeX( string $tex ): string {
		// Replace article titles in \href{...} or [[...]] with their canonical URLs, then strip HTML tags:
		return strip_tags( preg_replace_callback(
			// \href{...}, [[...]]
			[ '/\\href\s*\{(?!http)(.+?)\}\s*\{(.+?)\}/ui', '/\[\[(.+?)(?:\|(.*?))?\]\]/ui' ],
			function ( $matches ): string {
				return self::texHyperlink( $matches [1], $matches [2] );
			},
			$tex
		) );
	}    // -- private static function wikifyTeX (string $tex): string

	/**
	 * Create a TeX hyperlink.
	 *
	 * @param string $page The title of the wiki page.
	 * @param ?string $alias The wisible link text.
	 *
	 * @return string The TeX code for the hyperlink.
	 */
	private static function texHyperlink( string $page, ?string $alias = null ): string {
		return '\href {' . Title::newFromText( $page )->getFullURL() . '}'
			. '{ \texttip {' . ($alias ?? $page) . '}'
			. '{ ' . $page . ' }}';
	}    // -- private static function texHyperlink (string $page, ?string $alias = null): string

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
				. 'href\s*=\s*"(?!http)(.+?)"\s*\>\s*(.+?)\<\/maction\>/si' // -- href="...".
				, '/\[\[(.+?)(?:\|(.*?))?\]\]/ui' // -- [[...]].
			],
			function ( array $matches ): string {
				return '<maction actiontype="texttip" href="' // was "tooltip"
					. Title::newFromText( $matches [1] )->getFullURL() . '">'
					. "\n" . ($matches [2] ? $matches [2] : $matches [1])
					. "\n<mtext>{$matches [1]}</mtext>\n</maction>";
			},    // -- function (array $matches): string
			$mml
		);
		global $wgmjMMLtagsAllowed;
		static $mmlTagsAllowedGlued;
		if ( !$mmlTagsAllowedGlued ) {
			$mmlTagsAllowedGlued = '<' . implode( '><', $wgmjMMLtagsAllowed ) . '>';
		}
		return strip_tags( $ret, $mmlTagsAllowedGlued ); // -- remove HTML.
	}    // -- private static function prepareMML (string $body): string

	/**
	 * Entry point 4. Hooked by "BeforePageDisplay": "MathJax::processPage" in extension.json.
	 *
	 * Process environments outside <math> tags, attach MathJax, if needed.
	 *
	 * @param OutputPage &$output The OutputPage object.
	 * @param Skin &$skin The Skin object.
	 *
	 * @return bool Return true on success.
	 */
	public static function processPage( OutputPage &$output, Skin &$skin ): bool {
		$namespace = $output->getTitle()->getNamespace();
		$text = $output->mBodytext;
		if (!$text || $namespace < 0 || $namespace === NS_MEDIAWIKI) {
			return true;
		}

		// Screen <textarea>s:
		$textareas = [];
		$text = preg_replace_callback(
			'%<textarea\s+(.*?)id\s*=\s*"([^"]+)"(.*?)>(.*?)</textarea>%si',
			function ( array $matches ) use ( &$textareas ): string {
				$textareas [$matches [2]] = $matches [4];
				return '<textarea ' . $matches [1] . 'id="' . $matches [2] . '"' . $matches [3] . '></textarea>';
			},    // -- function ($matches) use ($textareas)
			$text
		);    // -- $text = preg_replace_callback (...)

		// Process TeX environments outside <math>:
		$text = self::sanitizeFreeEnvironments( $text );

		// <math> (already processed):
		global $wgmjMathJax;
		$inline_regex =	'/' . preg_quote( $wgmjMathJax['tex']['inlineMath'][0][0], '/' )
					  . '.+?'
					  . preg_quote( $wgmjMathJax['tex']['inlineMath'][0][1], '/' ) . '/';
		$display_regex =	'/' . preg_quote( $wgmjMathJax['tex']['displayMath'][0][0], '/' )
					   . '.+?'
					   . preg_quote( $wgmjMathJax['tex']['displayMath'][0][1], '/' ) . '/';
		// Set flag: MathJax needed:
		self::$mathJaxNeeded = self::$mathJaxNeeded || preg_match( $inline_regex, $text ) || preg_match( $display_regex, $text );

		// Unscreen <textarea>s:
		$output->mBodytext = preg_replace_callback(
			'%<textarea\s+(.*?)id\s*=\s*"([^"]+)"(.*?)></textarea>%si',
			function ( array $matches ) use ( $textareas ): string {
				return '<textarea ' . $matches [1] . 'id="' . $matches [2] . '"' . $matches [3] . '>' . $textareas [$matches [2]] . '</textarea>';
			},    // -- function ($matches) use ($textareas)
			$text
		);	// -- $output->mBodytext = preg_replace_callback (...)

		// Attach MathJax JavaScript, if necessary:
		self::attach( $output, $skin);

		return true;
	}   // -- public static function processPage( OutputPage &$output, Skin &$skin ): bool

	/**
	 * Get a regular expression for free TeX environments.
	 *
	 * @return string The regular expression.
	 */
	private static function envRegex(): string {
		if ( !self::$envRegex ) {
			// Find out sequences that seem to be out-of-tag TEX markup like \begin{equation}...\end{equation}:
			$environments = trim( preg_replace( '/\s*,\s*/s', '|', preg_quote( wfMessage( 'mathjax-environments' )->inContentLanguage()->text(), '/' ) ) );
			self::$envRegex = '/\\\\begin\\s*\\{(' . $environments . ')\\}(.+?)\\\\end\\s*\\{\1\\}/si';
		}
		return self::$envRegex;
	}

	/**
	 * Find TeX environments outside <math>:
	 *
	 * @param string $text Wikitext to find free TeX environments in.
	 *
	 * @return string The wikitext with sanitized free TeX environments.
	 */
	private static function sanitizeFreeEnvironments( string $text ): string {
		// Allow indentation in free equations -- remove all <p> and <pre> tags from within formulae. Also remove <a>:
		$text = preg_replace_callback(
			self::envRegex(),
			function ( array $matches ): string {
				// Set flag: MathJax needed:
				self::$mathJaxNeeded = true;
				// Prepare free math just as math within <math></math> tag:
				return self::wikifyTeX( $matches [0] );
			},    // -- function ( array $matches ): string
			$text
		);    // -- $output->mBodytext = preg_replace_callback (...)
		return $text;
	}   // -- private static function sanitizeFreeEnvironments( string $text ): string

	/*
	 * Attach MathJax scripts if the page contains prepared TeX or MathML.
	 *
	 * @param OutputPage &$output OutputPage object.
	 * @param Skin &$skin Skin object.
	 *
	 * @return bool Return true on success.
	 */
	private static function attach( OutputPage &$output, Skin &$skin ): bool {
		$namespace = $output->getTitle()->getNamespace();
		if ( $namespace >= 0 && $namespace !== NS_MEDIAWIKI // -- not in Special: or Media:.
			&& self::$mathJaxNeeded /* -- MathJax needed flag has been raised. */
		) {
			self::attachMathJaxIfNotYet( $output, $skin );
		}
		return true;
	}    // -- public static function attach (OutputPage &$output, Skin &$skin): bool

	/**
	 * Configure MathJax and attach MathJax scripts:
	 *
	 * @param OutputPage &$output The OutputPage object.
	 * @param Skin &$skin The Skin object.
	 *
	 * @return bool Return true on success.
	 */
	private static function attachMathJaxIfNotYet( OutputPage &$output, Skin &$skin ): bool {

		// Prevent multiple attaching:
		if ( self::$alreadyAttached ) {
			return true;
		}

		// CDN or local:
		global $wgmjUseCDN, $wgmjCDNDistribution, $wgmjLocalDistribution;
		global $wgmjMathJax;
		$wgmjMathJax['tex']['macros'] = self::texMacros();
		// Attaching scripts:
		$output->addScript( "<script>\nwindow.MathJax = " . json_encode( $wgmjMathJax ) . "\n</script>" );
		$output->addScript( '<script id="MathJax-script" async type="text/javascript" src="'
			. ($wgmjUseCDN ? $wgmjCDNDistribution : $wgmjLocalDistribution)
			. '?locale=' . $skin->getLanguage()->getCode() . '">'
			. "</script>\n"	);

		self::$alreadyAttached = true; // -- MathJax configuring and attachment complete.
		return true;
	}    // -- private static function attachMathJaxIfNotYet (OutputPage &$output, Skin &$skin): bool

	/**
	 * Prepare a list of TeX macros for user's language in JavaScript format.
	 * Also, convert linked TeX commands to macros.
	 *
	 * @return array An associative array of macros.
	 */
	private static function texMacros(): array {
		$macros = [];
		// Macros per se.
		foreach( preg_split( '/\s*,\s*/', wfMessage( 'mathjax-macros' )->inContentLanguage()->text() ) as $macro ) {
			$msg = wfMessage( "mathjax-macro-$macro" )->inContentLanguage();
			if ( $msg->exists() ) {
				$macros[$macro] = $msg->text();
			}
		}
		// Linked TeX commands.
		global $wgmjAddWikilinks;
		if ( $wgmjAddWikilinks ) {
			foreach ( preg_split( '/\s*,\s*/', wfMessage( 'mathjax-pages' )->inContentLanguage()->text() ) as $linked ) {
				$page = wfMessage( "mathjax-page-$linked" )->inContentLanguage();
				if ( $page->exists() ) {
					$macros[$linked] =
						self::texHyperlink( $page->text(), $macros[$linked] ?? '\\operatorname{' . $linked . '}' );
				}
			}
		}
		return $macros;
	}    // -- private static function prepareTeXMacros (): array

}   // -- class MathJax
