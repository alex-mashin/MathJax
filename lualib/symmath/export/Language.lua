local load_func = load
load = function(s)
	return false, ''
end
local language = require 'vendor.symmath.export.Language'
load = load_func
return language
