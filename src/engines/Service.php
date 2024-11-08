<?php

namespace MediaWiki\Extensions\MathJax\Engines;

use Exception;
use MediaWiki\MediaWikiServices;
use OutputPage;
use Skin;

/**
 * Class for math engine using MathJax installed remotely or in a container.
 */
class Service extends Base {
	/**
	 * @inheritDoc
	 */
	public function tex2mml( string $html, string $config, string $lang ): string {
		global $wgmjServiceUrl;
		$url = "$wgmjServiceUrl?config=yes&display=inline&lang=$lang";
		// Create an HTTP request object.
		$factory = MediaWikiServices::getInstance()->getHttpRequestFactory();
		$req = $factory->create( $url, [
			'method' => 'POST',
			'postData' => "<script type=\"text/json\">$config</script>$html"
		] );

		try {
			$status = $req->execute();
		} catch ( Exception $e ) {
			$error = '<span class="error">'
				. wfMessage( 'mathjax-service-unavailable', $url, $e->getMessage() )->inContentLanguage()->text()
				. '</span>';
			return $error . $html;
		}

		if ( $status->isOK() ) {
			return $req->getContent();
		}

		$error = '<span class="error">' . wfMessage(
				'mathjax-broken-tex',
				(string)$status . '<br />' . $req->getContent()
			)->inContentLanguage()->text() . '</span>';
		return $error . $html;
	}

	/**
	 * Add what is needed to the wiki page.
	 * @todo add $wgmjStyle.
	 * @param OutputPage $out
	 * @param Skin $skin
	 * @return void
	 */
	public static function processPage( OutputPage $out, Skin $skin ): void {
		$out->addInlineStyle( <<<'STYLE'
			math { font-size: 150%; }
			math>semantics>annotation { display:none; }
		STYLE );
	}

	/**
	 * Name of the client-side JavaScript.
	 * @return string
	 */
	public static function browserMathJaxScriptBasename(): string {
		return 'mml-chtml.js';
	}

	/**
	 * Domain plus directories of the server-side JavaScript.
	 * @return string|null
	 */
	public static function serverSideMathJaxDir(): ?string {
		global $wgmjServiceExternalUrl;
		return $wgmjServiceExternalUrl;
	}

	/**
	 * MathJax version used by Math engine.
	 * @return string|null
	 */
	public function version(): ?string {
		global $wgmjServiceVersionUrl;
		$factory = MediaWikiServices::getInstance()->getHttpRequestFactory();
		$req = $factory->create( $wgmjServiceVersionUrl );
		return $req->execute()->isOK() ? trim( $req->getContent() ) : null;
	}
}
