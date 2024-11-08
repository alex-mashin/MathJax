<?php
namespace MediaWiki\Extensions\MathJax\Engines;

use OutputPage;
use Skin;

/**
 * Class for math engine using client-side MathJax, doing essentially nothing.
 */
class ClientSide extends Base {

	/**
	 * @inheritDoc
	 */
	public function tex2mml( string $html, string $config, string $lang ): string {
		return $html;
	}

	/**
	 * Add what is needed to the wiki page.
	 * @todo add $wgmjStyle.
	 * @param OutputPage $out
	 * @param Skin $skin
	 * @return void
	 */
	public static function processPage( OutputPage $out, Skin $skin ): void {
		// Do nothing.
	}

	/**
	 * Name of the client-side JavaScript.
	 * @return string
	 */
	public static function browserMathJaxScriptBasename(): string {
		return 'tex-mml-chtml.js';
	}

	/**
	 * Domain plus directories of the server-side JavaScript.
	 * @return string|null
	 */
	public static function serverSideMathJaxDir(): ?string {
		global $wgExtensionAssetsPath, $wgmjLocalDistribution;
		$local = "$wgExtensionAssetsPath$wgmjLocalDistribution";
		return file_exists( $local ) ? $local : null;
	}

	/**
	 * MathJax version used by Math engine.
	 * @return string|null
	 */
	public function version(): ?string {
		return null;
	}
}
