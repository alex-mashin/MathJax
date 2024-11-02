#! /usr/bin/env -S node -r esm

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
var argv = require('yargs')
	.demand(0).strict()
	.usage('$0 [options] "math"')
	.options({
		dist: {
			"boolean": true,
			"default": false,
			describe: 'true to use webpacked version, false to use MathJax source files'
		},
		conf: {
			describe: 'Path to JSON file containing TeX configuration'
		}
	})
	.argv;

//  A renderAction to take the place of typesetting.
//  It renders the output to MathML instead.
function actionMML(math, doc) {
	const adaptor = doc.adaptor;
	const mml = MathJax.startup
		.toMML(math.root)
		.replace(
			/^<math(.+?)>(.+?)<\/math>$/su,
			'<math$1>\n<semantics><mrow>$2</mrow>' +
			'\n<annotation encoding="application/x-tex">' +
			math.math.replace(/&/g, '&#38;') +
			'</annotation>\n</semantics>\n</math>'
		);
	math.typesetRoot = adaptor.firstChild(adaptor.body(adaptor.parse(mml, 'text/html')));
}

const fs = require('fs');

//  Read the HTML file or stdin:
const input = fs.readFileSync(argv._[0] === '-' ? 0 : argv._[0], 'utf-8' );

// Read the configuration file:
let config = JSON.parse(process.env.MJCONF ?? fs.readFileSync(argv.conf, 'utf8'));
config.loader.load = [ 'input/tex-full', 'adaptors/liteDOM' ];
config.loader.source = argv.dist ? {} : require('mathjax-full/components/src/source.js').source;
config.options.renderActions = {
	typeset: [ 150, (doc) => { for (const math of doc.math) actionMML(math, doc); } ]
};
delete config.options.menuOptions;
config.startup = {
	document: input
};

// Load MathJax and initialize MathJax and typeset the given math
require('mathjax-full').init(config).then((MathJax) => {
	const adaptor = MathJax.startup.adaptor;
	const html = MathJax.startup.document;
	html.render();
	console.log(adaptor.outerHTML(adaptor.root(html.document)));
}).catch(err => console.error(err));
