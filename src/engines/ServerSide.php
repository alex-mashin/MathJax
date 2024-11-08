<?php

namespace MediaWiki\Extensions\MathJax\Engines;

use Exception;
use MediaWiki\ProcOpenError;
use MediaWiki\Shell\Shell;
use MediaWiki\ShellDisabledError;
use OutputPage;
use Skin;

/**
 * Class for math engine using MathJax installed locally to the MediaWiki server.
 */
class ServerSide extends Base {
	/**
	 * @inheritDoc
	 */
	public function tex2mml( string $html, string $config, string $lang ): string {
		$conf_file = sys_get_temp_dir() . '/MathJax.config.json';
		if ( !file_exists( $conf_file ) ) {
			file_put_contents( $conf_file, $config );
		}

		$command = 'node --stack-size=1024 --stack-trace-limit=1000 -r esm '
			. __DIR__ . '/../tex2mml.js --conf=' . $conf_file . ' --dist=1';
		$shell = Shell::command( explode( ' ', $command ) ) // Shell class demands an array of words.
			->environment( [ 'NODE_PATH' => __DIR__ . '/../../node_modules' ] ) // MathJax installed locally.
			->limits( [ 'memory' => 0, 'time' => 0, 'filesize' => 0 ] );
		$html_file = null;
		if ( strlen( $html ) >= 65536 /* hardcoded in Command::execute() */ ) {
			// Create a temporary HTML file and pass its name to tex2mml.js:
			$html_file = sys_get_temp_dir() . '/tex_' . md5( $html );
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
		} catch ( ShellDisabledError $e ) {
			$error = '<span class="error">'
				. wfMessage( 'mathjax-broken-tex', 'Shell disabled' )->inContentLanguage()->text()
				. '</span>';
			self::restoreWarnings();
			return $error . $html;
		} catch ( ProcOpenError | Exception $e ) {
			$error = '<span class="error">'
				. wfMessage( 'mathjax-broken-tex', $e->getMessage() )->inContentLanguage()->text()
				. '</span>';
			self::restoreWarnings();
			return $error . $html;
		} finally {
			if ( $html_file ) {
				unlink( $html_file );
			}
		}
		self::restoreWarnings();

		if ( $result->getExitCode() === 0 ) {
			return $result->getStdout();
		}

		$error = '<span class="error">'
			. wfMessage( 'mathjax-broken-tex', $result->getStderr() )->inContentLanguage()->text()
			. '</span>';
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
		global $wgExtensionAssetsPath, $wgmjLocalDistribution;
		return "$wgExtensionAssetsPath$wgmjLocalDistribution";
	}

	/**
	 * MathJax version used by Math engine.
	 * @return string|null
	 */
	public function version(): ?string {
		$command = Shell::command(
			explode( ' ', 'npm list -l --prefix ' . dirname( __DIR__ ) . '/.. mathjax-full' )
		);
		$command = method_exists( $command, 'disableNetwork' )
			? $command->disableNetwork()
			: $command->restrict( Shell::NO_NETWORK );
		try {
			$result = $command->execute();
		} catch ( Exception $e ) {
			return null;
		}
		if ( $result->getExitCode() === 0 && preg_match( '/mathjax.+$/s', $result->getStdout(), $match ) ) {
			return trim( $match[0] );
		}
		return null;
	}
}
