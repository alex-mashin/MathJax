<?php
namespace MediaWiki\Extensions\MathJax\Engines;

use OutputPage;
use Skin;
use Wikimedia\AtEase\AtEase;

/**
 * Abstract base class for math engines.
 */
abstract class Base {
	/**
	 * @param string $html HTML to process.
	 * @param string $config MathJax configuration as string.
	 * @param string $lang Language code.
	 * @return string
	 */
	abstract public function tex2mml( string $html, string $config, string $lang ): string;

	/**
	 * Add what is needed to the wiki page.
	 * @TODO: add $wgmjStyle.
	 * @param OutputPage $out
	 * @param Skin $skin
	 * @return void
	 */
	abstract public static function processPage( OutputPage $out, Skin $skin ): void;

	/**
	 * URL of the client-side JavaScript.
	 * @return string
	 */
	abstract public static function browserMathJaxScriptBasename(): string;

	/**
	 * Domain plus directories of the server-side JavaScript.
	 * @return string|null
	 */
	abstract public static function serverSideMathJaxDir(): ?string;

	/**
	 * MathJax version used by Math engine.
	 * @return string|null
	 */
	abstract function version(): ?string;

	/**
	 * Suppress warnings absolutely.
	 * @return void
	 */
	protected static function suppressWarnings(): void {
		if ( method_exists( AtEase::class, 'suppressWarnings' ) ) {
			// MW >= 1.33
			AtEase::suppressWarnings();
		}
	}

	/**
	 * Restore warnings.
	 * @return void
	 */
	protected static function restoreWarnings(): void {
		if ( method_exists( AtEase::class, 'restoreWarnings' ) ) {
			// MW >= 1.33
			AtEase::restoreWarnings();
		}
	}
}
