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
	.version( '3.1' )
	.argument( '<string>', 'file name to process or "_" to use standard input' )
	.option( '--dist <path>', 'path to MathJax distribution' )
	.option( '--conf <confguration>', 'MathJax configuration' )
	.parse();
const { dist, conf } = program.opts();
const file = program.args[0];

/**
 * Cast object into a Map.
 */
const obj2map = ( obj ) => {
	let map = new Map();
	for ( const key in obj ) {
		if ( obj.hasOwnProperty( key ) ) {
			map.set( new RegExp( '\\\\' + key, 'g' ), obj[key] );
		}
	}
	return map;
};

//  A renderAction to take the place of typesetting.
//  It renders the output to MathML instead.
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
	math.typesetRoot = adaptor.firstChild( adaptor.body( adaptor.parse( mml, 'text/html' ) ) );
}

import * as fs from 'fs';

/*
 * Extract configuration:
 */
const makeConfig = ( input, conf, dist ) => {
	// Extract TeX configuration:
	const parsed = /^\s*<script\s+type\s*=\s*"text\/json"\s*>(.+?)<\s*\/script\s*>(.*)$/s.exec( input );
	let config = null;
	if ( parsed ) {
		// Extract configuration from the input file:
		config = JSON.parse( parsed[1] );
		input = parsed[2];
	} else {
		// Read the configuration file:
		fs.readFile( conf, 'utf-8', ( err, data ) => {
			if ( err ) {
				console.error( 'Could not read configuration file ' + conf + ': ' + err );
				return;
			}
			config = JSON.parse( data );
		} );
	}
	// config.loader.load = [ 'input/tex-full', 'adaptors/liteDOM' ];
	if ( dist ) {
		config.loader.source = {};
	} else {
		import( 'mathjax-full/components/src/source.js' ).then( source => {
			config.loader.source = source;
		} ).catch( err => console.error( err ) );
	}
	const replacements = obj2map( config.tex.replacements );
	config.options.renderActions = {
		typeset: [ 150, ( doc ) => { for ( const math of doc.math ) {
			actionMML( math, doc, replacements );
		} } ]
	};
	delete config.options.menuOptions;
	delete config.tex.replacements;
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
	const config = makeConfig( input, conf, dist );

	import( 'mathjax-full/es5/node-main.js' ).then( mathjax => {
		mathjax.init( config ).then( MathJax => {
			const adaptor = MathJax.startup.adaptor;
			const html = MathJax.startup.document;
			toMML = MathJax.startup.toMML;
			html.render();
			console.log( adaptor.outerHTML( adaptor.root( html.document ) ) );
		} ).catch( err => console.error( err ) );
	} ).catch( err => console.error( err ) );
} );
