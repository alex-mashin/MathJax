local load_func = load
load = function(s)
	return false, ''
end
local export = require 'vendor.symmath.export.Export'
load = load_func
return export
