#! /usr/bin/env -S node
"use strict";

/*************************************************************************
 *
 *  Based on
 *  https://github.com/mathjax/MathJax-demos-node/blob/master/simple/tex2mml-page
 *
 *  Uses MathJax v3 to convert all TeX in an HTML document to MathML.
 *
 * ----------------------------------------------------------------------
 *
 *  Copyright (c) 2020 The MathJax Consortium, 2021-2024 Alexander Mashin
 *
 *  Licensed under the Apache License, Version 2.0 (the "License");
 *  you may not use this file except in compliance with the License.
 *  You may obtain a copy of the License at
 *
 *      http://www.apache.org/licenses/LICENSE-2.0
 *
 *  Unless required by applicable law or agreed to in writing, software
 *  distributed under the License is distributed on an "AS IS" BASIS,
 *  WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 *  See the License for the specific language governing permissions and
 *  limitations under the License.
 */

//  Get the command-line arguments
import { Command } from 'commander';
const program = new Command();
program
	.name( 'tex2mml')
	.description( 'Convert all TeX formulæ in HTML to MathML using MathJax' )
	.argument( '[string]', 'file name to process or "-" to use standard input (default)', '-' )
	.option( '--conf <path>', 'path to MathJax comfiguration', '/usr/share/downloads/config.fixed.json' )
	.option( '-V, --version', 'only print MathJax version' )
	.parse();
const { conf, version } = program.opts();
const file = program.args[0] || '-';

/*
 * A renderAction to take the place of typesetting.
 *	It renders the output to MathML instead.
 */
let toMML = null;
function actionMML( math, doc ) {
	const adaptor = doc.adaptor;
	const mml = MathJax.startup
		.toMML( math.root )
		.replace(
			/^<math(.+?)>(.+?)<\/math>$/su,
			'<math$1>\n<semantics><mrow>$2</mrow>' +
			'\n<annotation encoding="application/x-tex">' +
			math.math.replace(/&/g, '&#38;') +
			'</annotation>\n</semantics>\n</math>'
		);
	const emsg = '<math xmlns="http://www.w3.org/1998/Math/MathML"><mtext>Could not parse TeX.</mtext></math>';
	let parsed;
	try {
		parsed = adaptor.parse( mml, 'text/html' );
	} catch ( e ) {
		parsed = adaptor.parse( emsg );
	}
	if ( typeof parsed === 'undefined' ) {
		parsed = adaptor.parse( emsg );
	}
	math.typesetRoot = adaptor.firstChild( adaptor.body( parsed ) );
}

import * as fs from 'fs';

/*
 * Extract configuration:
 */
const defaultConfig = path => {
	try {
		const data = fs.readFileSync( path, 'utf8' );
		return JSON.parse( data );
	} catch ( err ) {
		console.error( 'Could not read MathJax configuration at ' + path );
	}
};

const makeConfig = input => {
	// Extract TeX configuration:
	const parsed = /^\s*<script\s+type\s*=\s*"text\/json"\s*>(.+?)<\s*\/script\s*>(.*)$/s.exec( input );
	let config = null;
	if ( parsed ) {
		// Extract configuration from the input file:
		config = JSON.parse( parsed[1] );
		input = parsed[2];
	} else {
		// Read the configuration file:
		config = defaultConfig( conf );
	}
	const ui = config.loader.load.indexOf( 'ui/safe' );
	if ( ui > -1 ) {
		config.loader.load.splice( ui, 1 );
		config.loader.load.push( 'adaptors/liteDOM' );
	}
	config.loader.source = {};
	config.options.renderActions = {
		typeset: [ 150, ( doc ) => { for ( const math of doc.math ) {
			actionMML( math, doc );
		} } ]
	};
	delete config.options.menuOptions;
	config.startup = { document: input };
	return config;
};

//  Read the HTML file or stdin:
fs.readFile( file === '-' ? 0 : file, 'utf-8', ( err, data ) => {
	if ( err ) {
		console.error( 'Could not read file with math ' + file + ': ' + err );
		return;
	}
	const input = data.toString();
	const config = makeConfig( input );

	import( 'mathjax-full/es5/node-main.js' ).then( mathjax => {
		mathjax.init( config ).then( async MathJax => {
			if ( version ) {
				console.log ( MathJax.version );
				process.exit();
			}
			const adaptor = MathJax.startup.adaptor;
			const html = MathJax.startup.document;
			toMML = MathJax.startup.toMML;
			await MathJax._.mathjax.mathjax.handleRetriesFor( () => html.render() );
			console.log( adaptor.outerHTML( adaptor.root( html.document ) ) );
		} ).catch( err => console.error( err ) );
	} ).catch( err => console.error( err ) );
} );
