--[[
make lua functions for each operator.
it looks like i'm mapping 1-1 between metamethods and fields in this table.
useful for using Lua as a functional language.
--]]

return {
	symbols = {
		add		= '+',
		sub		= '-',
		mul		= '*',
		div		= '/',
		mod		= '%',
		pow		= '^',
		unm		= '-',
		eq		= '==',
		le		= '<=',
		lt		= '<',
		lor		= 'or',
		land	= 'and',
		lnot	= 'not',
		len		= '#',
		concat	= '..',
	},
	add			= function(a, b) return a + b end,
	sub			= function(a, b) return a - b end,
	mul			= function(a, b) return a * b end,
	div			= function(a, b) return a / b end,
	mod			= function(a, b) return a % b end,
	pow			= function(a, b) return a ^ b end,
	unm			= function(a) return -a end,
	eq			= function(a, b) return a == b end,
	lt			= function(a, b) return a < b end,
	le			= function(a, b) return a <= b end,
	land		= function(a, b) return a and b end,
	lor			= function(a, b) return a or b end,
	lnot		= function(a) return not a end,
	concat		= function(a, b) return a .. b end,
	len			= function(a) return #a end,
	index 		= function(t, k) return t[k] end,
	newindex	= function(t, k, v)
		t[k] = v
		return t, k, v	-- ? should it return anything ?
	end,
	call		= function(f, ...) return f(...) end,
}
