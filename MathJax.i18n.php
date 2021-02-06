<?php
$messages = [];

// TeX macros common for all languages:
$messages ['en'] = $messages ['ru'] = [
  // MathJax configuration (JavaScript):
  /*
  'mathjax-configuration' => <<<CONF
MathJax.Hub.Config ({
    config: ["MMLorHTML.js"]
  , extensions: ["tex2jax.js", "mml2jax.js", "TeX/AMSmath.js", "TeX/AMSsymbols.js", "TeX/color.js", "TeX/AMScd.js", "TeX/action.js", "TeX/cancel.js", "TeX/mhchem.js", "TeX/mediawiki-texvc.js", "[a11y]/accessibility-menu.js"] // Also HTML.js but it will be included automatically
  , jax: ["input/MathML", "input/TeX"]
  , tex2jax: {
        inlineMath: [["\$1", "\$2"]]
      , displayMath: [["\$3", "\$4"]]
      , element: "mw-content-text"
      , skipTags: ["\$5"]
      , ignoreClass: "\$6"
    }
  , mml2jax: {
        element: "mw-content-text"
    }
  , TeX: {
        equationNumbers: { autoNumber: "AMS" }
	  , mhchem: { legacy: false }
      , Macros: \$7
    }
  , MathMenu: {
        showFontMenu: true
    }
  , menuSettings: {
        zoom: "Double-Click"
    }
});
CONF
  */
  /*
  'mathjax-configuration' => <<<CONF
window.MathJax = {
  tex: {
    inlineMath: [["\$1", "\$2"]],
    displayMath: [["\$3", "\$4"]],
    tags: "ams",
    macros: \$7,
    packages: ['base', 'ams', 'color', 'amscd', 'action', 'cancel', 'mhchem']
  },
  options: {
    skipHtmlTags: ["\$5"],
    ignoreHtmlClass: "\$6",
    menuOptions: {
      settings: {
        zoom: "Double-Click"
      }
    },
    processHtmlClass: 'tex2jax_process'
  },
  loader: {
    load: ['[tex]/color', '[tex]/amscd', '[tex]/action', '[tex]/cancel', '[tex]/mhchem']
  }
};
CONF
  */
];