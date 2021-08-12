local getenv = os.getenv
os.getenv = function(s)
        return nil
end
local mathjax = require 'vendor.symmath.export.MathJax'
os.getenv = getenv
return mathjax
