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
const argv = require( 'yargs' )
	.demand( 0 ).strict()
	.usage( '$0 [options] "math"' )
	.options( {
		dist: {
			'boolean': true,
			'default': false,
			describe: 'true to use webpacked version, false to use MathJax source files'
		},
		conf: {
			describe: 'Path to JSON file containing TeX configuration'
		}
	} )
	.argv;

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

/**
 * Mass replacement in a string.
 */
String.prototype.massReplace = function( map ) {
	let replaced = this;
	for ( const [search, replace] of map ) {
		replaced = replaced.replace (search, replace);
	}
	return replaced;
};

/*
 * A renderAction to take the place of typesetting.
 *	It renders the output to MathML instead.
 */
const actionMML = ( math, doc, replacements ) => {
	const adaptor = doc.adaptor;
	const original = math.math.replace(/&/g, '&#38;');
	const replaced = math.math.massReplace( replacements );
	const mml = MathJax.startup
		.toMML( replaced )
		// Inject TeX annotations:
		.replace(
			/^<math(.+?)>(.+?)<\/math>$/su,
			'<math$1>\n<semantics><mrow>$2</mrow>' +
			'\n<annotation encoding="application/x-tex">' +
			original +
			'</annotation>\n</semantics>\n</math>'
		);
	math.typesetRoot = adaptor.firstChild( adaptor.body( adaptor.parse( mml, 'text/html' ) ) );
};

/*
 * Extract configuration:
 */
const makeConfig = ( input, conf ) => {
	// Extract TeX configuration:
	const parsed = /^\s*<script\s+type\s*=\s*"text\/json"\s*>(.+?)<\s*\/script\s*>(.*)$/s.exec( input );
	let config = null;
	if ( parsed ) {
		config = JSON.parse( parsed[1] );
		input = parsed[2];
	} else {
		// Read the configuration file:
		config = JSON.parse( fs.readFileSync( conf, 'utf8' ) );
	}
	config.loader.load = [ 'input/tex-full', 'adaptors/liteDOM' ];
	config.loader.source = argv.dist ? {} : require( 'mathjax-full/components/src/source.js' ).source;
	const replacements = obj2map( config.replacements );
	config.options.renderActions = {
		typeset: [ 150, ( doc ) => { for ( const math of doc.math ) {
			actionMML( math, doc, replacements );
		} } ]
	};
	delete config.options.menuOptions;
	config.startup = { document: input };
	return config;
};

const fs = require( 'fs' );
//  Read the HTML file or stdin:
let input = null;
fs.readFile( argv._[0] === '-' ? 0 : argv._[0], 'utf-8', ( err, data ) => {
	if ( err ) {
		console.error( err );
		return;
	}
	input = data.toString();

	const config = makeConfig( input, argv.conf );

	// Load MathJax and initialize MathJax and typeset the given math
	require( 'mathjax-full' ).init( config ).then( ( MathJax ) => {
		const adaptor = MathJax.startup.adaptor;
		const html = MathJax.startup.document;
		html.render();
		console.log( adaptor.outerHTML( adaptor.root( html.document ) ) );
	} ).catch( err => console.error( err ) );
} );

