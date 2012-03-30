<?php namespace JWronsky\Meadow\Mustache;

use Exception;

/**
 * MustacheException class.
 *
 * @extends Exception
 */
class Exception_Mustache extends Exception {

	// Spotted invalid filter name after SYMBOL_PIPE
	const INVALID_FILTER_NAME = 1;

	// Spotted invalid argument name after SYMBOL_PIPE
	const INVALID_FILTER_ARGUMENT_NAME = 2;

	// Tried to call macro with other symbol
	const INVALID_MACRO_INVOCATION = 4;

	// User tried to call undefined macro
	const UNKNOWN_MACRO = 8;

	// An UNKNOWN_VARIABLE exception is thrown when a {{variable}} is not found
	// in the current context.
	const UNKNOWN_VARIABLE = 16;

	// An UNCLOSED_SECTION exception is thrown when a {{#section}} is not closed.
	const UNCLOSED_SECTION = 32;

	// An UNEXPECTED_CLOSE_SECTION exception is thrown when {{/section}} appears
	// without a corresponding {{#section}} or {{^section}}.
	const UNEXPECTED_CLOSE_SECTION = 64;

	// Spotted one SYMBOL_* contants in an unexpected place
	const UNEXPECTED_SYMBOL = 128;

	// An UNKNOWN_PARTIAL exception is thrown whenever a {{>partial}} tag appears
	// with no associated partial.
	const UNKNOWN_PARTIAL = 256;

	// An UNKNOWN_PRAGMA exception is thrown whenever a {{%PRAGMA}} tag appears
	// which can't be handled by this Mustache instance.
	const UNKNOWN_PRAGMA = 512;

}
