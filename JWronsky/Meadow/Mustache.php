<?php namespace JWronsky\Meadow;

use JWronsky\Meadow\Mustache\Exception_Mustache;

/**
 * A Mustache implementation in PHP.
 *
 * {@link http://defunkt.github.com/mustache}
 *
 * Mustache is a framework-agnostic logic-less templating language. It enforces separation of view
 * logic from template files. In fact, it is not even possible to embed logic in the template.
 *
 * This is very, very rad.
 *
 * @author Justin Hileman {@link http://justinhileman.com}
 * @author Mateusz Charytoniuk <mateusz.charytoniuk@absolvent.pl>
 */
class Mustache
{

	const FILENAME_EXTENSION = '.mustache';

	/**
	 * Should this Mustache throw exceptions when it finds unexpected tags?
	 *
	 * @see self::_throwsException()
	 */
	protected $_throwsExceptions = array(
		Exception_Mustache::INVALID_FILTER_NAME => true,
		Exception_Mustache::INVALID_FILTER_ARGUMENT_NAME => true,
		Exception_Mustache::INVALID_MACRO_INVOCATION => true,
		Exception_Mustache::UNKNOWN_MACRO => false,
		Exception_Mustache::UNKNOWN_VARIABLE => false,
		Exception_Mustache::UNCLOSED_SECTION => true,
		Exception_Mustache::UNEXPECTED_CLOSE_SECTION => true,
		Exception_Mustache::UNEXPECTED_SYMBOL => true,
		Exception_Mustache::UNKNOWN_PARTIAL => false,
		Exception_Mustache::UNKNOWN_PRAGMA => false,
	);

	// Override charset passed to htmlentities() and htmlspecialchars(). Defaults to UTF-8.
	protected $_charset = 'UTF-8';

	/**
	 * Pragmas are macro-like directives that, when invoked, change the behavior or
	 * syntax of Mustache.
	 *
	 * They should be considered extremely experimental. Most likely their implementation
	 * will change in the future.
	 */

	/**
	 * After using too many UPPER_CONTEXT symbols, do not throw exception, but stop on global
	 * scope.
	 *
	 * @author Mateusz Charytoniuk <mateusz.charytoniuk@absolvent.pl>
	 */
	const PRAGMA_IMPLICIT_GLOBAL_SCOPE = 'IMPLICIT_GLOBAL_SCOPE';

	/**
	 * Try to load partials dynamically from filesystem if not specified
	 * directly by user.
	 *
	 * @author Mateusz Charytoniuk <mateusz.charytoniuk@absolvent.pl>
	 */
	const PRAGMA_PARTIALS_AUTOLOAD = 'PARTIALS_AUTOLOAD';

	/**
	 * The {{%UNESCAPED}} pragma swaps the meaning of the {{normal}} and {{{unescaped}}}
	 * Mustache tags. That is, once this pragma is activated the {{normal}} tag will not be
	 * escaped while the {{{unescaped}}} tag will be escaped.
	 *
	 * Pragmas apply only to the current template. Partials, even those included after the
	 * {{%UNESCAPED}} call, will need their own pragma declaration.
	 *
	 * This may be useful in non-HTML Mustache situations.
	 */
	const PRAGMA_UNESCAPED    = 'UNESCAPED';

	const ARGUMENT_TYPE_BOOLEAN = 0;
	const ARGUMENT_TYPE_INTEGER = 1;
	const ARGUMENT_TYPE_STRING = 2;
	const ARGUMENT_TYPE_VARIABLE = 4;

	const FILTER_EVENT_NOOP = 0;
	const FILTER_EVENT_ITERATION_BEFORE = 1;
	const FILTER_EVENT_ITERATION_ROLL = 2;
	const FILTER_EVENT_ITERATION_AFTER = 4;

	const PHRASE_ARGUMENT_FEEDBACK = '<-';
	const PHRASE_ARGUMENT_FEEDBACK_NAMED = '<=';
	const PHRASE_FILTER_ARGUMENT_FEEDBACK = '_';
	const PHRASE_FILTER_ARGUMENT_FEEDBACK_NAMED = '__';
	const PHRASE_FILTER_ESCAPE = 'escape';
	const PHRASE_FILTER_QUINE = 'quine';

	const REGEXP_FILTER_NAME = '/^([a-zA-Z_][a-zA-Z0-9_]*)$/s';
	const REGEXP_FILTER_ARGUMENT_VARIABLE = '/^([a-zA-Z_\.][a-zA-Z0-9_\.]*)$/s';

	const SYMBOL_ARGUMENT_DELIMITER = ' ';
	const SYMBOL_ARGUMENT_STRING_DELIMITER = '"';
	const SYMBOL_ARGUMENT_STRING_ESCAPE = '\\';
	const SYMBOL_ACCESS_OPERATOR = '.';
	const SYMBOL_CONTEXT_KEY = '$';
	const SYMBOL_ESCAPE = '\\';
	const SYMBOL_MACRO_INVOCATION = '.';
	const SYMBOL_NOOP = '~';
	const SYMBOL_PIPE = '|';
	const SYMBOL_UPPER_CONTEXT = '^';

	/**
	 * Constants used for section and tag RegEx
	 */
	const SECTION_TYPES = '!@#\/\?:';
	const TAG_TYPES = '#@\^\/=<>&\*\\\\';

	const TAG_OPEN = '{{';
	const TAG_CLOSE = '}}';

	protected $_tagRegEx;

	protected $_arguments_cache = array();
	protected $_helper = NULL;
	protected $_macros = array();
	protected $_template = '';
	protected $_context  = array();
	protected $_partials = array();
	protected $_partials_directory = NULL;
	protected $_pragmas  = array();

	protected $_pragmasImplemented = array(
		self::PRAGMA_IMPLICIT_GLOBAL_SCOPE,
		self::PRAGMA_PARTIALS_AUTOLOAD,
		self::PRAGMA_UNESCAPED
	);

	protected $_localPragmas = array();

	/**
	 * Mustache class constructor.
	 *
	 * This method accepts a $template string and a $view object. Optionally, pass an associative
	 * array of partials as well.
	 *
	 * Passing an $options array allows overriding certain Mustache options during instantiation:
	 *
	 *     $options = array(
	 *         // `charset` -- must be supported by `htmlspecialentities()`. defaults to 'UTF-8'
	 *         'charset' => 'ISO-8859-1',
	 *
	 *         // an array of pragmas to enable/disable
	 *         'pragmas' => array(
	 *             Mustache::PRAGMA_UNESCAPED => true
	 *         ),
	 *     );
	 *
	 * @access public
	 * @param string $template (default: null)
	 * @param mixed $view (default: null)
	 * @param array $partials (default: null)
	 * @param array $options (default: array())
	 * @return void
	 */
	public function __construct($template = null, $view = null, $partials = null, array $options = null) {
		if ($template !== null) $this->_template = $template;
		if ($partials !== null) $this->_partials = $partials;
		if ($view !== null)     $this->_context = array($view);
		if ($options !== null)
		{
			if (isset($options['charset'])) {
				$this->_charset = $options['charset'];
			}
		}
		$this->_partials_directory = 'templates/views/';
	}

	/**
	 * Render the given template and view object.
	 *
	 * Defaults to the template and view passed to the class constructor unless a new one is provided.
	 * Optionally, pass an associative array of partials as well.
	 *
	 * @access public
	 * @param string $template (default: null)
	 * @param mixed $view (default: null)
	 * @param array $partials (default: null)
	 * @param string $filename (default: null) for debugging purposes
	 * @return string Rendered Mustache template.
	 */
	public function render($template = null, $view = null, $partials = null, $filename = null) {
		if ($template === null) $template = $this->_template;
		if ($partials !== null) $this->_partials = $partials;
		if ($filename !== null) {
			$filename = str_replace(array('\\', '/'), DIRECTORY_SEPARATOR, $filename);
		}

		if ($view) {
			$this->_context = array($view);
		} else if (empty($this->_context)) {
			$this->_context = array($this);
		}

		$template = $this->_renderPragmas($template, $filename);
		$template = $this->_renderTemplate($template, $local_context_key = null, $filename);

		return $template;
	}

	/**
	 * Wrap the render() function for string conversion.
	 *
	 * @access public
	 * @return string
	 */
	public function __toString() {
		// PHP doesn't like exceptions in __toString.
		// catch any exceptions and convert them to strings.
		try {
			$result = $this->render();
			return $result;
		} catch (Exception $e) {
			return 'Error rendering mustache: "' . $e->getFile() . '": "' . $e->getMessage() . '" on line ' . $e->getLine() . '.';
		}
	}

	/**
	 * Internal render function, used for recursive calls.
	 *
	 * @access protected
	 * @param string $template
	 * @param string $local_context_key (default: null)
	 * @param string $filename (default: null) for debugging purposes
	 * @return string Rendered Mustache template.
	 */
	protected function _renderTemplate($template, $local_context_key = null, $filename = null) {
		if ($section = $this->_findSection($template, $local_context_key, $filename)) {
			list($before, $type, $tag_name, $content, $after) = $section;

			$rendered_before = $this->_renderTags($before, $local_context_key, $filename);

			if (is_array($tag_name)) {
				$tag_data = $tag_name;
				$tag_name = $tag_data['tag_name'];
			}

			switch ($type) {
				case '!':
				case '#':
				case '?':
					$val = $this->_getVariable($tag_name, $local_context_key, $filename);
				break;
			}

			$rendered_content = '';
			switch($type) {
				// inverted section
				case '!':
					if ($this->_varIsEmpty($val)) {
						$rendered_content = $this->_renderTemplate($content, $local_context_key, $filename);
					}
					break;
				// macro section
				case '@':
					$this->_macros[$tag_name] = $content;
					break;
				case '#':
					// higher order sections
					if ($val instanceof Closure) {
						$rendered_content = $this->_renderTemplate(call_user_func($val, $content), $local_context_key, $filename);
					// val is traversable
					} else if ($val instanceof Traversable || is_array($val)) {
						if (isset($tag_data) && !empty($tag_data['filters'])) {
							$rendered_content .= $this->_applyFilters(
								$rendered_content, $tag_data['filters'], self::FILTER_EVENT_ITERATION_BEFORE
							);
						}
						foreach ($val as $local_context_key => $local_context) {
							$this->_pushContext($local_context);
							$appendice = $this->_renderTemplate($content, $local_context_key, $filename);
							if (!empty($tag_data['filters'])) {
								$appendice = $this->_applyFilters(
									$appendice,
									$tag_data['filters'],
									$local_context_key,
									$local_context,
									self::FILTER_EVENT_ITERATION_ROLL
								);
							}
							$rendered_content .= $appendice;
							$this->_popContext();
						}
						if (isset($tag_data) && !empty($tag_data['filters'])) {
							$rendered_content .= $this->_applyFilters($rendered_content, $tag_data['filters'], self::FILTER_EVENT_ITERATION_AFTER);
						}
					} else if ($val) {
						if (is_array($val) || is_object($val)) {
							$this->_pushContext($val);
							$rendered_content = $this->_renderTemplate($content, $local_context_key, $filename);
							$this->_popContext();
						} else {
							$rendered_content = $this->_renderTemplate($content, $local_context_key, $filename);
						}
					}
					break;
				case ':': break;
				case '?':
					if (!$this->_varIsEmpty($val)) {
						$rendered_content = $this->_renderTemplate($content, $local_context_key, $filename);
					}
					break;
			}

			return $rendered_before . $rendered_content . $this->_renderTemplate($after, $local_context_key, $filename);
		}

		return $this->_renderTags($template, $local_context_key, $filename);
	}

	/**
	 * Escape open and close tags in given template
	 *
	 * @param string $template
	 * @return string
	 */
	protected function _escapeMustache($template) {
		return preg_replace(
			"/{{([^" . preg_quote(self::SYMBOL_ESCAPE, "/") . "])/",
			"{{" . preg_quote(self::SYMBOL_ESCAPE) . "$1",
			$template
		);
	}

	/**
	 * Extract the first section from $template.
	 *
	 * @access protected
	 * @param string $template
	 * @param string $local_context_key (default: null)
	 * @param string $filename (default: null) for debugging purposes
	 * @return array $before, $type, $tag_name, $content and $after
	 */
	protected function _findSection($template, $local_context_key = null, $filename = null) {
		$sectionRegex = sprintf(
			'/(?:(?<=\\n)[ \\t]*)?%s(?:(?P<type>[%s])(?P<tag_name>.+?)|=(?P<delims>.*?)=)%s\\n?/s',
			preg_quote(self::TAG_OPEN, '/'),
			self::SECTION_TYPES,
			preg_quote(self::TAG_CLOSE, '/')
		);

		$section_start = null;
		$section_type = null;
		$content_start = null;

		$search_offset = 0;

		$section_stack = array();
		$matches = array();
		while (preg_match($sectionRegex, $template, $matches, PREG_OFFSET_CAPTURE, $search_offset)) {
			if (isset($matches['delims'][0])) {
				list($otag, $ctag) = explode(' ', $matches['delims'][0] . ' ');
				$search_offset = $matches[0][1] + strlen($matches[0][0]);
				continue;
			}

			$match = $matches[0][0];
			$offset = $matches[0][1];
			$type = $matches['type'][0];
			$tag_name = trim($matches['tag_name'][0]);
			$tag_data = $this->_parseArguments($tag_name);
			$tag_name = $tag_data['tag_name'];

			$search_offset = $offset + strlen($match);

			switch ($type) {
				case '@':
				case '#':
				case '!':
				case '?':
				case ':':
					if ($type === '@' && $tag_name{0} === self::SYMBOL_MACRO_INVOCATION) {
						break;
					}
					if (empty($section_stack)) {
						$section_start = $offset;
						$section_type = $type;
						$content_start = $search_offset;
					}
					$section_stack[] = $tag_data;
					break;
				case '/':
					$error_message = '';
					if (empty($section_stack)) {
						$error_message .= 'Unexpected close section: "' . $tag_name . '".';
					}
					$popped_tag = array_pop($section_stack);
					if ($popped_tag['tag_name'] !== $tag_name) {
						$names_distance = levenshtein($tag_name, $popped_tag['tag_name']);
						$error_message .= 'Unexpected close section: "' . $tag_name . '".'
						                . ' Expected: "' . $popped_tag['tag_name'] . '"'
						                . (($names_distance < 4) ? ' (a typo?)' : '');
					}

					if ( $this->_throwsException(Exception_Mustache::UNEXPECTED_CLOSE_SECTION)
					  && strlen($error_message) > 0
					  && !empty($section_stack)
					) {
						$opened_sections = array();
						foreach ($section_stack as $section) {
							$opened_sections[] = $section['tag_name'];
						}
						if (!empty($opened_sections)) {
							$error_message .= ' Previously opened sections were: "' . implode('", "', $opened_sections) . '".';
						}
						$this->_throwException(
							Exception_Mustache::UNEXPECTED_CLOSE_SECTION,
							$error_message, $local_context_key, $filename
						);
					}

					if (empty($section_stack)) {
						// $before, $type, $tag_name, $content, $after
						return array(
							substr($template, 0, $section_start),
							$section_type,
							$popped_tag,
							substr($template, $content_start, $offset - $content_start),
							substr($template, $search_offset),
						);
					}
					break;
			}
		}

		if (!empty($section_stack)) {
			$this->_throwException(Exception_Mustache::UNCLOSED_SECTION, 'Unclosed section: "' . $section_stack[0]['tag_name'] . '"', null, $filename);
		}
	}

	/**
	 * Initialize pragmas and remove all pragma tags.
	 *
	 * @access protected
	 * @param string $template
	 * @param string $filename (default: null) for debugging purposes
	 * @return string
	 */
	protected function _renderPragmas($template, $filename = null) {
		$this->_localPragmas = $this->_pragmas;

		// no pragmas
		if (strpos($template, self::TAG_OPEN . '%') === false) {
			return $template;
		}

		$pragmaRegex = sprintf(
			'/%s%%\\s*(?P<pragma_name>[\\w_-]+)(?P<options_string>(?: [\\w]+=[\\w]+)*)\\s*%s\\n?/s',
			preg_quote(self::TAG_OPEN, '/'),
			preg_quote(self::TAG_CLOSE, '/')
		);

		$mustache = $this;
		return preg_replace_callback(
			$pragmaRegex,
			function ($matches) use ($filename, $mustache) {
				$mustache_reflection = new ReflectionObject($mustache);
				$_renderPragma = $mustache_reflection->getMethod('_renderPragma');
				$_renderPragma->setAccessible(true);
				return $_renderPragma->invokeArgs($mustache, array($matches, $filename));
			},
			$template
		);
	}

	/**
	 * A preg_replace helper to remove {{%PRAGMA}} tags and enable requested pragma.
	 *
	 * @access protected
	 * @param mixed $matches
	 * @param string $filename (default: null) for debugging purposes
	 * @return void
	 * @throws Exception_Mustache unknown pragma
	 */
	protected function _renderPragma($matches, $filename = null) {
		$pragma         = $matches[0];
		$pragma_name    = $matches['pragma_name'];
		$options_string = $matches['options_string'];

		if (!in_array($pragma_name, $this->_pragmasImplemented)) {
			$this->_throwException(Exception_Mustache::UNKNOWN_PRAGMA, 'Unknown pragma: "' . $pragma_name . '"', null, $filename);
		}

		$options = array();
		foreach (explode(' ', trim($options_string)) as $o) {
			if ($p = trim($o)) {
				$p = explode('=', $p);
				$options[$p[0]] = $p[1];
			}
		}

		if (empty($options)) {
			$this->_localPragmas[$pragma_name] = true;
		} else {
			$this->_localPragmas[$pragma_name] = $options;
		}

		return '';
	}

	/**
	 * Check whether this Mustache has a specific pragma.
	 *
	 * @access protected
	 * @param string $pragma_name
	 * @return bool
	 */
	protected function _hasPragma($pragma_name) {
		if (array_key_exists($pragma_name, $this->_localPragmas) && $this->_localPragmas[$pragma_name]) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 * Return pragma options, if any.
	 *
	 * @access protected
	 * @param string $pragma_name
	 * @param string $filename (default: null) for debugging purposes
	 * @return mixed
	 * @throws Exception_Mustache Unknown pragma
	 */
	protected function _getPragmaOptions($pragma_name, $filename) {
		if (!$this->_hasPragma($pragma_name)) {
			$this->_throwException(Exception_Mustache::UNKNOWN_PRAGMA, 'Unknown pragma: "' . $pragma_name . '"', null, $filename);
		}

		return (is_array($this->_localPragmas[$pragma_name])) ? $this->_localPragmas[$pragma_name] : array();
	}

	/**
	 * @access protected
	 * @param mixed $exception
	 * @param string $message
	 * @param string $local_context_key (default: null)
	 * @param string $filename (default: null) for debugging purposes
	 * @return void
	 */
	protected function _throwException($exception, $message, $local_context_key = null, $filename = null) {
		if ($this->_throwsException($exception)) {
			if ($filename) {
				$message = '"' . $filename . '": ' . $message;
			}
			if ($local_context_key !== null) {
				$message .= ' at iteration "' . $local_context_key . '"';
			}
			throw new Exception_Mustache($message, $exception);
		}
	}

	/**
	 * Check whether this Mustache instance throws a given exception.
	 *
	 * @access protected
	 * @param mixed $exception
	 * @return void
	 */
	protected function _throwsException($exception) {
		if (isset($this->_throwsExceptions[$exception]) && $this->_throwsExceptions[$exception]) {
			return true;
		}
		return false;
	}

	/**
	 * Prepare a tag RegEx for the given opening/closing tags.
	 *
	 * @access protected
	 * @param string $otag
	 * @param string $ctag
	 * @return string
	 */
	protected function _prepareTagRegEx($otag, $ctag, $first = false) {
		return sprintf(
			'/(?P<leading>(?:%s\\r?\\n)[ \\t]*)?%s(?P<type>[%s]?)(?P<tag_name>.+?)(?:\\2|})?%s(?P<trailing>\\s*(?:\\r?\\n|\\Z))?/s',
			($first ? '\\A|' : ''),
			preg_quote(self::TAG_OPEN, '/'),
			self::TAG_TYPES,
			preg_quote(self::TAG_CLOSE, '/')
		);
	}

	/**
	 * Loop through and render individual Mustache tags.
	 *
	 * @access protected
	 * @param string $template
	 * @param string $local_context_key (default: null)
	 * @param string $filename (default: null) for debugging purposes
	 * @return void
	 */
	protected function _renderTags($template, $local_context_key = null, $filename = null) {
		if (strpos($template, self::TAG_OPEN) === false) {
			return $template;
		}

		$first = true;
		$this->_tagRegEx = $this->_prepareTagRegEx(self::TAG_OPEN, self::TAG_CLOSE, true);

		$html = '';
		$matches = array();
		while (preg_match($this->_tagRegEx, $template, $matches, PREG_OFFSET_CAPTURE)) {
			$tag      = $matches[0][0];
			$offset   = $matches[0][1];
			$modifier = $matches['type'][0];
			$tag_name = trim($matches['tag_name'][0]);

			if (isset($matches['leading']) && $matches['leading'][1] > -1) {
				$leading = $matches['leading'][0];
			} else {
				$leading = null;
			}

			if (isset($matches['trailing']) && $matches['trailing'][1] > -1) {
				$trailing = $matches['trailing'][0];
			} else {
				$trailing = null;
			}

			$html .= substr($template, 0, $offset);

			$next_offset = $offset + strlen($tag);
			if ((substr($html, -1) == "\n") && (substr($template, $next_offset, 1) == "\n")) {
				$next_offset++;
			}
			$template = substr($template, $next_offset);

			$html .= $this->_renderTag($modifier, $tag_name, $leading, $trailing, $local_context_key, $filename);

			if ($first == true) {
				$first = false;
				$this->_tagRegEx = $this->_prepareTagRegEx(self::TAG_OPEN, self::TAG_CLOSE);
			}
		}

		return $html . $template;
	}

	/**
	 * Render the named tag, given the specified modifier.
	 *
	 * Accepted modifiers are `!` (comment), `>` (partial) `&` (don't escape
	 * output), or none (render escaped output).
	 *
	 * @access protected
	 * @param string $modifier
	 * @param string $tag_name
	 * @param string $leading Whitespace
	 * @param string $trailing Whitespace
	 * @param string $local_context_key (default: null)
	 * @param string $filename (default: null) for debugging purposes
	 * @throws Exception_Mustache Unmatched section tag encountered.
	 * @return string
	 */
	protected function _renderTag($modifier, $tag_name, $leading, $trailing, $local_context_key = null, $filename = null) {
		switch ($modifier) {
			case '@':
				if ($tag_name{0} !== self::SYMBOL_MACRO_INVOCATION) {
					$this->_throwException(
						Exception_Mustache::INVALID_MACRO_INVOCATION,
						'Currently, the one and only way to invoke macro is use of "'
						. self::SYMBOL_MACRO_INVOCATION
						. '" symbol.',
						$local_context_key, $filename
					);
				}
				$tag_name = substr($tag_name, 1);
				$tag_name = explode(self::SYMBOL_PIPE, $tag_name);
				$tag_name[0] = str_replace(
					array(
						self::PHRASE_ARGUMENT_FEEDBACK,
						self::PHRASE_ARGUMENT_FEEDBACK_NAMED
					),
					array(
						self::SYMBOL_PIPE . self::PHRASE_FILTER_ARGUMENT_FEEDBACK,
						self::SYMBOL_PIPE . self::PHRASE_FILTER_ARGUMENT_FEEDBACK_NAMED,
					),
					$tag_name[0]
				);
				$tag_name = implode(self::SYMBOL_PIPE, $tag_name);
				$name = $this->_parseArguments($tag_name);
				if (!array_key_exists($name['tag_name'], $this->_macros)) {
					$this->_throwException(
						Exception_Mustache::UNKNOWN_MACRO,
						'Unknown macro: "' . $tag_name . '".',
						$local_context_key, $filename
					);
				}
				$arguments = array();
				foreach ($name['filters'] as $key => $filter) {
					if ($filter['name'] === self::PHRASE_FILTER_ARGUMENT_FEEDBACK) {
						foreach ($filter['arguments'] as $argument) {
							if ($argument['type'] === self::ARGUMENT_TYPE_VARIABLE) {
								$arguments[] = $this->_getVariable($argument['value'], $local_context_key, $filename);
							} else {
								$arguments[] = $argument['value'];
							}
						}
						unset($name['filters'][$key]);
					} else if ($filter['name'] === self::PHRASE_FILTER_ARGUMENT_FEEDBACK_NAMED) {
						$i = 0;
						$localKey = NULL;
						foreach ($filter['arguments'] as $argument) {
							++ $i;
							if ($argument['type'] === self::ARGUMENT_TYPE_VARIABLE) {
								$val = $this->_getVariable($argument['value'], $local_context_key, $filename);
							} else {
								$val = $argument['value'];
							}
							if ($i & 1) {
								$localKey = $val;
							} else {
								$arguments[$localKey] = $val;
							}
						}
						unset($name['filters'][$key]);
					} else if ($filter['name'] === self::PHRASE_FILTER_QUINE) {
						$response = $this->_macros[$name['tag_name']];
						foreach ($name['filters'] as $key => $filter) {
							if ($filter['name'] === self::PHRASE_FILTER_ESCAPE) {
								return $this->_escapeMustache($response);
							}
						}
						return $response;
					}
				}
				if (!$this->_helper) {
					$this->_helper = new self();
				}
				$response = $template = $this->_helper->render($this->_macros[$name['tag_name']], $arguments);
				$response = $this->_applyFilters($response, $name['filters']);
				return $response;
				break;
			case '=':
				$dieAfter = false;
				if (strpos($tag_name, '=') === 0) {
					$dieAfter = true;
					while (ob_get_level()) {
						ob_end_clean();
					}
					if (strpos($tag_name, '\\=') === 0) {
						$tag_name = substr($tag_name, 1);
						$arguments = func_get_args();
						$arguments[0] = null;
						$arguments[1] = $tag_name;
						return call_user_func_array(array($this, '_renderTag'), $arguments);
					}
				}
				$tag_name = trim($tag_name, '=');
				$val = $this->_getVariable($tag_name, $local_context_key, $filename);
				debug::breakpoint($val, $dieAfter, $filename, NULL, $modifier, $tag_name);
				break;
			case '*':
				$val = $this->_getVariable($tag_name, $local_context_key, $filename);
				return self::TAG_OPEN . $val . self::TAG_CLOSE;
				break;
			case '>':
				return $this->_renderPartial($tag_name, $leading, $trailing, $filename);
				break;
			case '&':
				if ($this->_hasPragma(self::PRAGMA_UNESCAPED)) {
					return $this->_renderEscaped($tag_name, $leading, $trailing, $local_context_key, $filename);
				} else {
					return $this->_renderUnescaped($tag_name, $leading, $trailing, $local_context_key, $filename);
				}
				break;
			case self::SYMBOL_ESCAPE:
					preg_match('/^([\\\\]+)/s', $tag_name, $matches);
					if ($matches)
					{
						$matches = $matches[1];
						$tag_name = str_replace('\\\\', '\\', $tag_name);
						if (strlen($matches) & 1)
						{
							$arguments = func_get_args();
							$arguments[0] = null;
							$arguments[1] = $tag_name;
							return call_user_func_array(array($this, '_renderTag'), $arguments);
						}
					}
					return self::TAG_OPEN . $tag_name . self::TAG_CLOSE;
				break;
			case '@':
			case '#':
			case '!':
			case '?':
			case '*':
			case '/':
				// remove any leftover section tags
				return $leading . $trailing;
				break;
			default:
				if ($this->_hasPragma(self::PRAGMA_UNESCAPED)) {
					return $this->_renderUnescaped($modifier . $tag_name, $leading, $trailing, $local_context_key, $filename);
				} else {
					return $this->_renderEscaped($modifier . $tag_name, $leading, $trailing, $local_context_key, $filename);
				}
				break;
		}
	}

	/**
	 * Escape and return the requested tag.
	 *
	 * @access protected
	 * @param string $tag_name
	 * @param string $leading Whitespace
	 * @param string $trailing Whitespace
	 * @param string $local_context_key (default: null)
	 * @param string $filename (default: null) for debugging purposes
	 * @return string
	 */
	protected function _renderEscaped($tag_name, $leading, $trailing, $local_context_key = null, $filename = null) {
		$rendered = htmlentities($this->_renderUnescaped($tag_name, '', '', $local_context_key, $filename), ENT_QUOTES, $this->_charset);
		return $leading . $rendered . $trailing;
	}

	/**
	 * Process tag name
	 *
	 * @access protected
	 * @param string $local_context_key (default: null)
	 * @param string $filename (default: null) for debugging purposes
	 * @return array
	 */
	protected function _parseArguments($tag_name, $local_context_key = null, $filename = null) {
		if (array_key_exists($tag_name, $this->_arguments_cache)) {
			return $this->_arguments_cache[$tag_name];
		}
		$filters = explode(self::SYMBOL_PIPE, $tag_name);
		$response = array();
		$response['tag_name'] = array_shift($filters); // actual tag name
		$response['tag_name'] = trim($response['tag_name']);
		$response['filters'] = array();
		$debug_context = null;
		while(!empty($filters)) {
			$filter_key = key($filters);
			$filter_value = array_shift($filters);
			$filter_value = trim($filter_value);
			$delimiter_position = strpos($filter_value, self::SYMBOL_ARGUMENT_DELIMITER);
			if ($delimiter_position !== false)  {
				$filter_name = substr($filter_value, 0, $delimiter_position);
			} else {
				$filter_name = $filter_value;
				$filter_value = '';
			}
			if (is_numeric($filter_name) && $filter_name == intval($filter_name)) {
				$filter_value = explode(' ', $filter_value);
				array_shift($filter_value);
				$filter_value = implode(' ', $filter_value);
				$repeats = intval($filter_name);
				for ($i = 0; $i < $repeats; ++$i) {
					array_unshift($filters, $filter_value);
				}
				continue;
			}
			$filter_name = trim($filter_name);
			if ($filter_name !== self::SYMBOL_NOOP && !preg_match(self::REGEXP_FILTER_NAME, $filter_name)) {
				$error_message = 'Invalid filter name: "' . $filter_name
				               . '" at tag "' . self::TAG_OPEN . $tag_name . self::TAG_CLOSE
				               . '". Valid filter name regular expression is "'
				               . '/^[a-zA-Z_][a-zA-Z0-9_]*$/"';
				$this->_throwException(Exception_Mustache::INVALID_FILTER_NAME, $error_message, $local_context_key, $filename);
				return $response;
			}
			if ($delimiter_position !== false)  {
				$filter_value = substr($filter_value, $delimiter_position);
				$filter_value = trim($filter_value);
			}
			// to clear trailing variables
			$filter_value .= self::SYMBOL_ARGUMENT_DELIMITER;
			$filter_value = str_split($filter_value);
			$arguments = array();
			$argument_template = array(
				'value' => '',
				'type' => null
			);
			$argument = $argument_template;
			$expect = null;
			$status = array(
				'delimited' => false,
				'escaped' => false
			);
			$unexpected_symbol = null;
			foreach ($filter_value as $char) {
				if ($expect !== null) {
					if ($char !== $expect) {
						$unexpected_symbol = $char;
						break;
					} else {
						$expect = null;
					}
				}
				switch ($char) {
					case self::SYMBOL_ARGUMENT_DELIMITER:
						if ($argument['type'] === self::ARGUMENT_TYPE_STRING) {
							$argument['value'] .= $char;
						} else {
							if ($argument['type'] !== null) {
								if (is_numeric($argument['value'])) {
									$argument['type'] = self::ARGUMENT_TYPE_INTEGER;
								} else {
									if ($argument['value'] === self::SYMBOL_NOOP) {
										$argument = array(
											'value' => '',
											'type' => self::ARGUMENT_TYPE_STRING
										);
									} else {
										if (!preg_match(self::REGEXP_FILTER_ARGUMENT_VARIABLE, $argument['value'])) {
											// TODO create and use 'debug context' closure if mustache throws appropriate exceptions
											$error_message = 'Invalid variable name: "'
											               . $argument['value'] . '" (argument ' . (count($arguments) + 1) . ')'
											               . ' at filter "' . $filter_name . '" (pipe ' . ($filter_key + 1) . ')'
											               . ' at tag "' . self::TAG_OPEN . $tag_name . self::TAG_CLOSE . '".'
											               . ' Valid variable name regular expression is "'
											               . '/^[a-zA-Z_][a-zA-Z0-9_]*$/"';
											$this->_throwException(
												Exception_Mustache::INVALID_FILTER_ARGUMENT_NAME,
												$error_message,
												$local_context_key,
												$filename
											);
										}
									}
								}
								if ($argument['type'] === self::ARGUMENT_TYPE_VARIABLE) {
									if ($argument['value'] === 'true' || $argument['value'] === 'false') {
										$argument['type'] = self::ARGUMENT_TYPE_BOOLEAN;
										if ($argument['value'] === 'true') {
											$argument['value'] = true;
										} else {
											$argument['value'] = false;
										}
									}
								}
								$arguments[] = $argument;
								$argument = $argument_template;
							}
						}
					break;
					case self::SYMBOL_ARGUMENT_STRING_ESCAPE:
						if (!$status['escaped']) {
							$status['escaped'] = true;
						} else {
							if ($argument['type'] === self::ARGUMENT_TYPE_STRING) {
								$argument['value'] .= self::SYMBOL_ARGUMENT_STRING_ESCAPE;
							} else {
								$unexpected_symbol = self::SYMBOL_ARGUMENT_STRING_ESCAPE;
								break 2;
							}
							$status['escaped'] = false;
						}
					break;
					case self::SYMBOL_ARGUMENT_STRING_DELIMITER:
						if ($argument['type'] === self::ARGUMENT_TYPE_STRING) {
							if (!$status['escaped']) {
								$arguments[] = $argument;
								$argument = $argument_template;
								$expect = self::SYMBOL_ARGUMENT_DELIMITER;
							} else {
								$argument['value'] .= self::SYMBOL_ARGUMENT_STRING_DELIMITER;
								$status['escaped'] = false;
							}
						} else {
							if ($argument['type']) {
								$unexpected_symbol = self::SYMBOL_ARGUMENT_STRING_DELIMITER;
								break 2;
							}
							$argument['type'] = self::ARGUMENT_TYPE_STRING;
						}
					break;
					default:
						if ($argument['type'] === null) {
							$argument['type'] = self::ARGUMENT_TYPE_VARIABLE;
						}
						if ($status['escaped'])
						{
							$argument['value'] .= self::SYMBOL_ARGUMENT_STRING_ESCAPE;
							$status['escaped'] = false;
						}
						$argument['value'] .= $char;
					break;
				}
			}
			if ($argument != $argument_template) {
				$argument['value'] = substr($argument['value'], 0, (strlen($argument['value']) - 1));
				$unexpected_symbol = self::SYMBOL_ARGUMENT_DELIMITER;
			}
			if ($unexpected_symbol !== null) {
				$mustache_reflection = new ReflectionClass($this);
				$constants = $mustache_reflection->getConstants();
				$symbols = array();
				$constant_name = null;
				foreach ($constants as $_constant_name => $constant_value) {
					if ( strpos($_constant_name, 'SYMBOL_') === 0
					  && $constant_value === $unexpected_symbol
					) {
						$constant_name = $_constant_name;
						break;
					}
				}
				if ($constant_name === null) {
					$constant_name = 'character: "' . $char . '"';
				}
				$error_message = 'Unexpected ' . $constant_name;
				$argument['value'] = trim($argument['value']);
				$argument_ordinal = count($arguments);
				if ($argument['type'] !== null && strlen($argument['value'])) {
					$error_message .= ' after "'
					                . $argument['value']
					                . '"';
				} else {
					if (!empty($arguments)) {
						// there is at least one valid argument
						$argument = array_pop($arguments);
						if (array_key_exists('type', $argument) && $argument['type'] !== null) {
							$error_message .= ' after "'
							                . $argument['value']
							                . '"';
						}
					}
				}
				$error_message .= ' (argument ' . $argument_ordinal . ')'
				                . ' at filter "' . $filter_name . '" (pipe ' . ($filter_key + 1) . ')'
				                . ' at tag "' . self::TAG_OPEN . $tag_name . self::TAG_CLOSE . '"';
				$this->_throwException(Exception_Mustache::UNEXPECTED_SYMBOL, $error_message, $local_context_key, $filename);
				$arguments = array();
			}
			$response['filters'][] = array(
				'arguments' => $arguments,
				'name' => $filter_name
			);
		}
		if (!array_key_exists($tag_name, $this->_arguments_cache)) {
			$this->_arguments_cache[$tag_name] = $response;
		}
		return $response;
	}

	/**
	 * Apply filters to specified variable
	 *
	 * @param mixed $variable
	 * @param array $filters
	 * @return mixed
	 */
	protected function _applyFilters($val, array $filters/*, polymorphic */) {
		if (empty($filters)) {
			return strval($val);
		}
		$arguments = func_get_args();
		$initial_value = $val;
		$raw_value_key = null;
		$raw_value = $val;
		switch (count($arguments)) {
			case 2:
				$event = self::FILTER_EVENT_NOOP;
			break;
			case 3:
				$event = $arguments[2];
			break;
			case 4:
				$raw_value = $arguments[2];
				$event = $arguments[3];
			break;
			case 5:
				$raw_value_key = $arguments[2];
				$raw_value = $arguments[3];
				$event = $arguments[4];
			break;
			default:
				throw new BadMethodCallException(
					"This method takes at least two arguments amd maximally five arguments. Received " . count($arguments)
				);
		}
		foreach ($filters as $filter) {
			$filter_name = $filter['name'];
			$filter_arguments = $filter['arguments'];
			if ($filter_name === self::SYMBOL_NOOP) {
				$val = $initial_value;
			}
		}
		return $val;
	}

	/**
	 * Return the requested tag unescaped.
	 *
	 * @access protected
	 * @param string $tag_name
	 * @param string $leading Whitespace
	 * @param string $trailing Whitespace
	 * @param string $local_context_key (default: null)
	 * @param string $filename (default: null) for debugging purposes
	 * @return string
	 */
	protected function _renderUnescaped($tag_name, $leading, $trailing, $local_context_key = null, $filename = null) {
		$filters = array();
		if (strpos($tag_name, self::SYMBOL_PIPE) !== FALSE) {
			$tag_data = $this->_parseArguments($tag_name, $local_context_key, $filename);
			$tag_name = $tag_data['tag_name'];
			$tag_name = trim($tag_name);
		}
		$val = $this->_getVariable($tag_name, $local_context_key, $filename);
		if (isset($tag_data) && array_key_exists('filters', $tag_data) && is_array($tag_data['filters'])) {
			$val = $this->_applyFilters($val, $tag_data['filters']);
		}
		if ($val instanceof Closure) {
			try {
				$val = $this->_renderTemplate(call_user_func($val), $local_context_key, $filename);
			}
			catch (Exception $e) {
				$this->_throwException(
					Exception_Mustache::UNKNOWN_VARIABLE,
					'Exception occurred while rendering callable variable "' . $tag_name . '": "' . $e->getMessage() . '"',
					$local_context_key,
					$filename
				);
			}
		}
		return $leading . $val . $trailing;
	}

	/**
	 * Render the requested partial.
	 *
	 * @access protected
	 * @param string $tag_name
	 * @param string $leading Whitespace
	 * @param string $trailing Whitespace
	 * @param string $filename (default: null) for debugging purposes
	 * @return string
	 */
	protected function _renderPartial($tag_name, $leading, $trailing, $filename = null) {
		$partial = $this->_getPartial($tag_name, $filename);
		if (is_array($partial)) {
			$filename = $partial['filename'];
			$partial = $partial['content'];
		}
		if ($leading !== null && $trailing !== null) {
			$whitespace = trim($leading, "\r\n");
			$partial = preg_replace('/(\\r?\\n)(?!$)/s', "\\1" . $whitespace, $partial);
		}

		$view = clone($this);

		if ($leading !== null && $trailing !== null) {
			return $leading . $view->render($partial, null, null, $filename);
		} else {
			return $leading . $view->render($partial, null, null, $filename) . $trailing;
		}
	}

	/**
	 * Push a local context onto the stack.
	 *
	 * @access protected
	 * @param array &$local_context
	 * @return void
	 */
	protected function _pushContext(&$local_context) {
		$new = array();
		$new[] =& $local_context;
		foreach (array_keys($this->_context) as $key) {
			$new[] =& $this->_context[$key];
		}
		$this->_context = $new;
	}

	/**
	 * Remove the latest context from the stack.
	 *
	 * @access protected
	 * @return void
	 */
	protected function _popContext() {
		$new = array();

		$keys = array_keys($this->_context);
		array_shift($keys);
		foreach ($keys as $key) {
			$new[] =& $this->_context[$key];
		}
		$this->_context = $new;
	}

	/**
	 * Get a variable from the context array.
	 *
	 * If the view is an array, returns the value with array key $tag_name.
	 * If the view is an object, this will check for a public member variable
	 * named $tag_name. If none is available, this method will execute and return
	 * any class method named $tag_name. Failing all of the above, this method will
	 * return an empty string.
	 *
	 * @access protected
	 * @param string $tag_name
	 * @param string $filename (default: null) for debugging purposes
	 * @throws Exception_Mustache Unknown variable name.
	 * @return string
	 */
	protected function _getVariable($tag_name, $local_context_key = null, $filename = null) {
		$context = $this->_context;
		$modified_context = false;
		if ($tag_name{0} === self::SYMBOL_UPPER_CONTEXT) {
			$modified_context = true;
			$context_unindent = strlen($tag_name);
			$tag_name = ltrim($tag_name, self::SYMBOL_UPPER_CONTEXT);
			$context_unindent = $context_unindent - strlen($tag_name);
			for ($i = 0; $i < $context_unindent; ++$i) {
				if ($this->_hasPragma(self::PRAGMA_IMPLICIT_GLOBAL_SCOPE) && count($this->_context) < 2) {
					// stop at global scope
					break;
				}
				$this->_popContext();
			}
		}
		if ($tag_name === self::SYMBOL_NOOP) {
			return '';
		} elseif ($tag_name === self::SYMBOL_CONTEXT_KEY) {
			$return = $local_context_key;
		} elseif ($tag_name === self::SYMBOL_ACCESS_OPERATOR) {
			$return = $this->_context[0];
		} elseif (strpos($tag_name, self::SYMBOL_ACCESS_OPERATOR) !== false) {
			$chunks = explode(self::SYMBOL_ACCESS_OPERATOR, $tag_name);
			$first = array_shift($chunks);
			$return = $this->_findVariableInContext($first, $this->_context, $tag_name, $local_context_key, $filename);
			while (!empty($chunks)) {
				// Slice off a chunk of context for dot notation traversal.
				$next = array_shift($chunks);
				$c = array($return);
				$return = $this->_findVariableInContext($next, $c, $tag_name, $local_context_key, $filename);
			}
		} else {
			$return = $this->_findVariableInContext($tag_name, $this->_context, $tag_name, $local_context_key, $filename);
		}
		if ($modified_context) {
			$this->_context = $context;
		}
		return $return;
	}

	/**
	 * Get a variable from the context array. Internal helper used by getVariable() to abstract
	 * variable traversal for dot notation.
	 *
	 * @access protected
	 * @param string $tag_name
	 * @param array $context
	 * @param mixed $backtrace (default: null) for debugging purposes
	 * @param string $local_context_key (default: null)
	 * @param string $filename (default: null) for debugging purposes
	 * @throws Exception_Mustache Unknown variable name.
	 * @return string
	 */
	protected function _findVariableInContext($tag_name, $context, $backtrace = null, $local_context_key = null, $filename = null) {
		foreach ($context as $view) {
			if (is_object($view)) {
				if (method_exists($view, $tag_name)) {
					return $view->$tag_name();
				} else if ($view instanceof ArrayAccess) {
					if ($view->offsetExists($tag_name)) {
						return $view->offsetGet($tag_name);
					}
				} else if (isset($view->$tag_name)) {
					return $view->$tag_name;
				}
			} else if (is_array($view) && array_key_exists($tag_name, $view)) {
				return $view[$tag_name];
			}
		}
		if ($this->_throwsException(Exception_Mustache::UNKNOWN_VARIABLE)) {
			$possible_names = array();
			foreach ($context as $view) {
				$appendice = array();
				if (is_array($view)) {
					$appendice = array_keys($view);
				}
				if (is_object($view)) {
					$appendice = get_object_vars($view);
				}
				$possible_names = array_merge($possible_names, $appendice);
			}
			$error_message = 'Undefined variable: "' . $tag_name . '"';
			if ($backtrace !== NULL && $backtrace !== $tag_name) {
				$error_message .= ' in "' . $backtrace . '"';
			}
			if (count($possible_names)) {
				$closest_distance = NULL;
				$closest_name = 0;
				foreach ($possible_names as $name) {
					$name = strval($name);
					$distance = levenshtein($name, $tag_name);
					if ($closest_distance === NULL || $distance < $closest_distance) {
						$closest_distance = $distance;
						$closest_name = $name;
					}
				}
				foreach ($possible_names as $key => $name) {
					if ($name === $closest_name) {
						unset($possible_names[$key]);
					}
					$possible_names[$key] = strval($name);
				}
				$error_message .= ' Closest variable name is "' . $closest_name . '"';
				if ($closest_distance < 4) {
					$error_message .= ' (a typo?)';
				}
				$error_message .= '. Some other possible values are: "' . implode('", "', $possible_names) . '".';
			}
		}
		$error_message = isset($error_message) ? $error_message : '';
		$this->_throwException(
			Exception_Mustache::UNKNOWN_VARIABLE, $error_message, $local_context_key, $filename
		);
		return '';
	}

	/**
	 * Retrieve the partial corresponding to the requested tag name.
	 *
	 * Silently fails (i.e. returns '') when the requested partial is not found.
	 *
	 * @access protected
	 * @param string $tag_name
	 * @param string $filename (default: null) for debugging purposes
	 * @throws Exception_Mustache Unknown partial name.
	 * @return string
	 */
	protected function _getPartial($tag_name, $filename = null) {
		if ((is_array($this->_partials) || $this->_partials instanceof ArrayAccess) && isset($this->_partials[$tag_name])) {
			return $this->_partials[$tag_name];
		}
		if ( array_key_exists(self::PRAGMA_PARTIALS_AUTOLOAD, $this->_localPragmas)
		  && $this->_localPragmas[self::PRAGMA_PARTIALS_AUTOLOAD]
		) {
			$partial_path = $this->_partials_directory
			              . str_replace('.', DIRECTORY_SEPARATOR, $tag_name)
			              . self::FILENAME_EXTENSION;
			if (file_exists($partial_path)) {
				$this->partials[$tag_name] = file_get_contents($partial_path);
				return array(
					'content' => $this->partials[$tag_name],
					'filename' => $partial_path
				);
			}
		}
		$this->_throwException(Exception_Mustache::UNKNOWN_PARTIAL, 'Unknown partial: "' . $tag_name . '"', null, $filename);
		return '';
	}

	/**
	 * Check if specified variable is empty (in the understanding of Mustache)
	 *
	 * @param mixed &$var
	 * @return boolean
	 */
	protected function _varIsEmpty(&$var) {
		if ( (is_string($var) && !strlen(trim($var)))
		  || ($var instanceof Countable && count($var) < 1)
		  || (!$var instanceof Countale && empty($var))
		) {
			return true;
		}
		return false;
	}

}
