<?php namespace JWronsky\Meadow;

use InvalidArgumentException;
use RuntimeException;

/**
 * @todo use actual Mustache for syntax checking
 * @todo filters -> $ivk($tag, 'filter_name')
 * @todo arrows: ->, <-, =>, <=, ~>, <~
 */
class Compiler
{

    const TAG_DELIMITER_CLOSE = '}}';
    const TAG_DELIMITER_OPEN = '{{';
    const TAG_ESCAPER = '\\';
    const TAG_FILTER_SEPARATOR = '|';

    const GLOBAL_CONTEXT = 'c';
    const GLOBAL_CONTEXT_UPPER = 'u';
    const GLOBAL_ESCAPE = 'e';
    const GLOBAL_FUNCTION = 'f';
    const GLOBAL_ITERATOR = 'i';
    const GLOBAL_KEY = 'k';
    const GLOBAL_MACRO_DEFINE = 'd';
    const GLOBAL_MACRO_INVOKE = 'a';
    const GLOBAL_TRUTH = 't';

    protected $blocks = array(
        '@' => 'macro',
        '#' => 'foreach',
        '?' => 'if',
        '!' => 'unless',
        '/' => null,
    );

    protected $mnemonics = array(
        '\\' => 'comment',
        '~' => 'NOOP',
        '@.' => 'runMacro',
        '$' => 'key',
        '^' => 'upperContext',
        '>' => 'partial',
        '&' => 'unescaped',
    );

    public function __construct()
    {
        $this->lint = new Lint();
    }

    /**
     * @param string $code
     * @param string $filename
     * @return string
     * @throws InvalidArgumentException If syntax error occurred.
     */
    public function compile($code, $filename)
    {
        if ($this->lint->isOk($code)) {
            $response = array();
            $tokens = $this->tokenizeCode($code);
            return $this->compileTokens($tokens, $code);
        } else {
            throw new InvalidArgumentException(
                'Syntax error occurred in file: "' . $filename . '".'
            );
        }
    }

    /**
     * @param string $body
     * @param array $tokens
     * @param string $code
     * @return string
     */
    public function compileDelimitersExternal($body, array $tokens, $code)
    {
        return '<?php ' . $body . ' ?>';
    }

    /**
     * @param string $body
     * @param array $tokens
     * @param string $code
     * @return string
     */
    public function compileDelimitersInternal($body, array $tokens, $code)
    {
        return ' ?>' . $body . '<?php ';
    }

    /**
     * @param string $body
     * @param string $filter
     * @param array $tokens
     * @param string $code
     * @return string
     */
    public function compileFilter($body, $filter, array $tokens, $code)
    {
        $items = $this->tokenizeArguments($filter, $tokens, $code);
        $arguments = array(
            $this->compileString(array_shift($items), $tokens, $code),
            $this->compileVariable(self::GLOBAL_KEY, $tokens, $code),
            $this->compileVariable(self::GLOBAL_CONTEXT, $tokens, $code),
            $this->compileVariable(self::GLOBAL_CONTEXT_UPPER, $tokens, $code)
        );
        if (!empty($body)) {
            $arguments[] = $body;
        } else {
            $arguments[] = 'null';
        }
        return $this->compileVariableInvoke(
            self::GLOBAL_FUNCTION,
            array_merge(
                $arguments,
                $this->compileTagArgumentsList(null, null, $items, null, $tokens, $code)
            ), $tokens, $code
        );
    }

    /**
     * Compile given tag by symbol
     *
     * @param string $body
     * @param string $tag
     * @param string $tagCode
     * @param array $tokens
     * @param string $code
     * @return string
     */
    public function compileFilters($body, $filters, array $tokens, $code)
    {
        $filters = $this->tokenizeFilters($filters, $tokens, $code);
        $response = $body;
        foreach ($filters as $filter) {
            $response = $this->compileFilter($response, $filter, $tokens, $code);
        }
        return $response;
    }

    /**
     * @param array $arguments
     * @param array $uses
     * @param array $tokens
     * @param string $code
     * @return string
     */
    public function compileFunction(array $arguments, array $uses, $body, array $tokens, $code)
    {
        if (!empty($uses)) {
            $usesList = 'use('
                      . $this->compileVariablesList($uses, $tokens, $code)
                      . ')';
        } else {
            $usesList = '';
        }
        return 'function('
             . $this->compileVariablesList($arguments, $tokens, $code)
             . ')'
             . $usesList
             . '{'
             . $body
             . '}';
    }

    /**
     * @param array $variables
     * @param array $tokens
     * @param string $code
     * @return string
     */
    public function compileList(array $items, array $tokens, $code)
    {
        return implode(',', $items);
    }

    /**
     * @param string $left
     * @param string $right
     * @param array $tokens
     * @param string $code
     * @return string
     */
    public function compileOperatorAssign($left, $right, array $tokens, $code)
    {
        return $left . '=' . $right;
    }

    /**
     * Compile given scope
     *
     * @param array $scope
     * @param array $tokens
     * @param string $code
     * @return string
     */
    public function compileScope(array $scope, array $tokens, $code)
    {
        if ($this->isScopeATag($scope, $tokens, $code)) {
            return $this->dispatchTagCompiler($scope[0], $tokens, $code);
        }
        return $this->dispatchScopeCompiler($scope, $tokens, $code);
    }

    /**
     * @param string $symbol
     * @param string $name
     * @param array $arguments
     * @param array $scope
     * @param array $tokens
     * @param string $code
     * @return string
     */
    public function compileScopeIf($symbol, $name, array $arguments, array $scope, array $tokens, $code)
    {
        return $this->compileDelimitersExternal(
            'if('
            . $this->compileVariableInvoke(
                self::GLOBAL_TRUTH,
                array(
                    $this->compileTag($symbol, $name, array(), null, $tokens, $code),
                ),
                $tokens, $code
            )
            . '){'
            , $tokens, $code
        )
        . $this->compileTokens($scope, $code)
        . $this->compileDelimitersExternal('}', $tokens, $code);
    }

    /**
     * @param string $symbol
     * @param string $name
     * @param array $arguments
     * @param array $scope
     * @param array $tokens
     * @param string $code
     * @return string
     */
    public function compileScopeIterator($symbol, $name, array $arguments, array $scope, array $tokens, $code)
    {
        return $this->compileScopeVariableCall(
            self::GLOBAL_ITERATOR, $symbol, $name, $arguments, $scope, $tokens, $code
        );
    }

    /**
     * @param string $symbol
     * @param string $name
     * @param array $arguments
     * @param array $scope
     * @param array $tokens
     * @param string $code
     * @return string
     */
    public function compileScopeMacro($symbol, $name, array $arguments, array $scope, array $tokens, $code)
    {
        $internalVariables = array(
            self::GLOBAL_KEY,
            self::GLOBAL_CONTEXT,
        );
        array_unshift($arguments, $name);
        $argumentsList = $this->compileStringsList($arguments, $tokens, $code);
        return $this->compileDelimitersExternal(
            $this->compileVariableInvoke(
                self::GLOBAL_MACRO_DEFINE,
                array(
                    $argumentsList,
                    $this->compileFunction(
                        $internalVariables,
                        $this->getGlobalsExclude($internalVariables, $tokens, $code),
                        $this->compileDelimitersInternal(
                            $this->compileTokens($scope, $code),
                            $tokens,
                            $scope
                        ),
                        $tokens, $code
                    )
                ),
                $tokens, $code
            ),
            $tokens, $code
        );

    }

    /**
     * @param string $symbol
     * @param string $name
     * @param array $arguments
     * @param array $scope
     * @param array $tokens
     * @param string $code
     * @return string
     */
    public function compileScopeUnless($symbol, $name, array $arguments, array $scope, array $tokens, $code)
    {
        return $this->compileDelimitersExternal(
            'if(!('
            . $this->compileVariableInvoke(
                self::GLOBAL_TRUTH,
                array(
                    $this->compileTag($symbol, $name, array(), null, $tokens, $code),
                ),
                $tokens, $code
            )
            . ')){'
            , $tokens, $code
        )
        . $this->compileTokens($scope, $code)
        . $this->compileDelimitersExternal('}', $tokens, $code);
    }

    /**
     * @param string $variable
     * @param string $symbol
     * @param string $name
     * @param array $arguments
     * @param array $scope
     * @param array $tokens
     * @param string $code
     * @return string
     */
    public function compileScopeVariableCall($variable, $symbol, $name,
        array $arguments, array $scope, array $tokens, $code
    ) {
        $internalVariables = array(
            self::GLOBAL_KEY,
            self::GLOBAL_CONTEXT,
            self::GLOBAL_CONTEXT_UPPER,
        );
        return $this->compileDelimitersExternal(
            $this->compileVariableInvoke(
                $variable, array(
                    $this->compileTag(
                        $symbol, $name, array(), null, $tokens, $code
                    ),
                    $this->compileVariable(self::GLOBAL_KEY, $tokens, $code),
                    $this->compileVariable(self::GLOBAL_CONTEXT, $tokens, $code),
                    $this->compileVariable(self::GLOBAL_CONTEXT_UPPER, $tokens, $code),
                    $this->compileFunction(
                        $internalVariables,
                        $this->getGlobalsExclude(
                            $internalVariables, $tokens, $code
                        ),
                        $this->compileDelimitersInternal(
                            $this->compileTokens($scope, $code), $tokens, $code
                        ),
                        $tokens, $code
                    ),
                ),
                $tokens, $code
            ),
            $tokens, $code
        );
    }

    /**
     * @param string $string
     * @param array $tokens
     * @param string $code
     * @return string
     */
    public function compileString($string, array $tokens, $code)
    {
        return '\'' . $this->setStringDelimiters('\'', $string) . '\'';
    }

    /**
     * @param array $string
     * @param array $tokenss
     * @param string $code
     * @return string
     */
    public function compileStrings(array $strings, array $tokens, $code)
    {
        $response = array();
        foreach ($strings as $string) {
            $response[] = $this->compileString($string, $tokens, $code);
        }
        return $response;
    }

    /**
     * @param array $strings
     * @param array $tokens
     * @param string $code
     * @return string
     */
    public function compileStringsList(array $strings, array $tokens, $code)
    {
        return $this->compileList(
            $this->compileStrings($strings, $tokens, $code), $tokens, $code
        );
    }

    /**
     * @param string $symbol
     * @param string $name
     * @param array $arguments
     * @param string $tag
     * @param array $tokens
     * @param string $code
     * @return string
     */
    public function compileTag($symbol, $name, array $arguments, $tag, array $tokens, $code)
    {
        if ($this->isSymbolAnUpperContext($symbol, $tokens, $code)) {
            $context = self::GLOBAL_CONTEXT_UPPER;
        } else {
            $context = self::GLOBAL_CONTEXT;
        }
        return $this->compileVariableInvoke(
            $context, array_merge(
                array($this->compileString($name, $tokens, $code)),
                $this->compileTagArgumentsList($symbol, $name, $arguments, $tag, $tokens, $code)
            ), $tokens, $code
        );
    }

    /**
     * @param string $symbol
     * @param string $name
     * @param array $arguments
     * @param string $tag
     * @param array $tokens
     * @param string $code
     * @return string
     */
    public function compileTagArgumentsList($symbol, $name, array $arguments, $tag, array $tokens, $code)
    {
        $argumentsList = array();
        if (!empty($arguments)) {
            foreach ($arguments as $argument) {
                $argumentsList[] = $this->dispatchTagTypeCompiler(
                    $symbol, $argument, array(), $tag, $tokens, $code
                );
            }
        }
        return $argumentsList;
    }

    /**
     * @param string $tagCode
     * @param string $symbol
     * @param string $name
     * @param string $tag
     * @param array $tokens
     * @param string $code
     * @return string
     */
    public function compileTagCommented($tagCode, $symbol, $name, array $arguments, $tag, array $tokens, $code)
    {
        return $this->compileString(
            self::TAG_DELIMITER_OPEN . $name . self::TAG_DELIMITER_CLOSE,
            $tokens, $code
        );
    }

    /**
     * @param string $tagCode
     * @param string $symbol
     * @param array $arguments
     * @param string $name
     * @param string $tag
     * @param array $tokens
     * @param string $code
     * @return string
     */
    public function compileTagConstant($symbol, $name, array $arguments, $tag, array $tokens, $code)
    {
        return $this->compileList(
            array_merge(
                array($name),
                $this->compileTagArgumentsList($symbol, $name, $arguments, $tag, $tokens, $code)
            ), $tokens, $code
        );
    }

    /**
     * @param string $tagCode
     * @param string $symbol
     * @param array $arguments
     * @param string $name
     * @param string $tag
     * @param array $tokens
     * @param string $code
     * @return string
     */
    public function compileTagKeyholder($tagCode, $symbol, $name, array $arguments, $tag, array $tokens, $code)
    {
        return $this->compileVariable(self::GLOBAL_KEY, $tokens, $code);
    }

    /**
     * @param string $tagCode
     * @param string $symbol
     * @param string $name
     * @param string $tag
     * @param array $tokens
     * @param string $code
     * @return string
     */
    public function compileTagMacro($tagCode, $symbol, $name, array $arguments, $tag, array $tokens, $code)
    {
        return $this->compileVariableInvoke(
            self::GLOBAL_MACRO_INVOKE,
            array(
                $this->compileString($name, $tokens, $code),
                $this->compileVariable(self::GLOBAL_KEY, $tokens, $code),
                $this->compileVariable(self::GLOBAL_CONTEXT, $tokens, $code),
            ),
            $tokens, $code
        );
    }

    /**
     * @param string $tagCode
     * @param string $symbol
     * @param string $name
     * @param string $tag
     * @param array $tokens
     * @param string $code
     * @return string
     */
    public function compileTagNoop($tagCode, $symbol, $name, array $arguments, $tag, array $tokens, $code)
    {
        return $tagCode;
    }

    /**
     * @param string $tagCode
     * @param string $symbol
     * @param string $name
     * @param string $tag
     * @param array $tokens
     * @param string $code
     * @return string
     */
    public function compileTagPartial($tagCode, $symbol, $name, array $arguments, $tag, array $tokens, $code)
    {
        return $this->compile(
            $this->loadFile($name, $tokens, $code), $name
        );
    }

    /**
     * @param string $tagCode
     * @param string $symbol
     * @param array $arguments
     * @param string $name
     * @param string $tag
     * @param array $tokens
     * @param string $code
     * @return string
     */
    public function compileTagPrinter($tagCode, $symbol, $name, array $arguments, $tag, array $tokens, $code)
    {
        return $this->compileDelimitersExternal(
                'echo '
                . $this->compileVariableInvoke(
                     self::GLOBAL_ESCAPE,
                     array(
                        'strval(' . $tagCode . ')'
                     ),$tokens, $code
                ), $tokens, $code
             );
    }

    /**
     * @param string $tagCode
     * @param string $symbol
     * @param string $name
     * @param string $tag
     * @param array $tokens
     * @param string $code
     * @return string
     */
    public function compileTagPrinterUnescaped($tagCode, $symbol, $name, array $arguments, $tag, array $tokens, $code)
    {
        return $this->compileDelimitersExternal('echo strval(' . $tagCode . ')', $tokens, $code);
    }

    /**
     * @param string $symbol
     * @param string $name
     * @param string $tag
     * @param array $tokens
     * @param string $code
     * @return string
     */
    public function compileTagString($symbol, $name, array $arguments, $tag, array $tokens, $code)
    {
        $name = $this->trimStringDelimiters($name);
        $name = $this->setStringDelimiters('\'', $name);
        return $this->compileList(
            array_merge(
                array($this->compileString($name, $tokens, $code)),
                $this->compileTagArgumentsList($symbol, $name, $arguments, $tag, $tokens, $code)
            ), $tokens, $code
        );
    }

    /**
     * @param string $tagCode
     * @param string $symbol
     * @param string $name
     * @param string $tag
     * @param array $tokens
     * @param string $code
     * @return string
     */
    public function compileTagUpperContext($tagCode, $symbol, $name, array $arguments, $tag, array $tokens, $code)
    {
        return $this->compileVariableInvoke(
            self::GLOBAL_CONTEXT_UPPER, array(
                $this->compileString($name, $tokens, $code)
            ), $tokens, $code
        );
    }

    /**
     * @param array $tokens
     * @param string $code
     * @return string
     */
    public function compileTokens(array $tokens, $code)
    {
        $response = array();
        $length = count($tokens);
        for ($index = 0; $index < $length; ++$index) {
            if ($index & 1) {
                $scope = $this->getScope(array_slice($tokens, $index), $tokens, $code);
                $index += (count($scope) - 1);
                $response[] = $this->compileScope($scope, $tokens, $code);
            } else {
                $response[] = $tokens[$index];
            }
        }
        return implode($response);
    }

    /**
     * @param string $variable
     * @param array $tokens
     * @param string $code
     * @return string
     */
    public function compileVariable($variable, array $tokens, $code)
    {
        return '$' . $variable;
    }

    /**
     * @param string $variable
     * @param array $arguments
     * @param array $tokens
     * @param string $code
     * @return string
     */
    public function compileVariableInvoke($variable, array $arguments, array $tokens, $code)
    {
        return $this->compileVariable($variable, $tokens, $code)
             . '('
             . $this->compileList($arguments, $tokens, $code)
             . ')';
    }

    /**
     * @param string $variable
     * @param string $pointer
     * @param array $arguments
     * @param array $tokens
     * @param string $code
     * @return string
     */
    public function compileVariablePointerInvoke($variable, $pointer, array $arguments, array $tokens, $code)
    {
        return $this->compileVariablePointer($variable, $pointer, $tokens, $code)
             . '('
             . $this->compileList($arguments, $tokens, $code)
             . ')';
    }

    /**
     * @param string $variable
     * @param string $pointer
     * @param array $tokens
     * @param string $code
     * @return string
     */
    public function compileVariablePointer($variable, $pointer, array $tokens, $code)
    {
        return $this->compileVariable($variable, $tokens, $code)
             . '->{'
             . $this->compileString($pointer, $tokens, $code)
             . '}';
    }

    /**
     * @param array $variables
     * @param array $tokens
     * @param string $code
     * @return string
     */
    public function compileVariables(array $variables, array $tokens, $code)
    {
        $response = array();
        foreach ($variables as $variable) {
            $response[] = $this->compileVariable($variable, $tokens, $code);
        }
        return $response;
    }

    /**
     * @param array $variables
     * @param array $tokens
     * @param string $code
     * @return string
     */
    public function compileVariablesList(array $variables, array $tokens, $code)
    {
        return $this->compileList(
            $this->compileVariables($variables, $tokens, $code), $tokens, $code
        );
    }

    /**
     * Compile given scope delimiters
     *
     * @param array $range
     * @param array $tokens
     * @param string $code
     * @return string
     */
    public function dispatchScopeCompiler($scope, array $tokens, $code)
    {
        $opening = array_shift($scope);
        array_pop($scope);
        $symbol = $this->getTagSymbol($opening, $tokens, $code);
        $name = $this->getTagName($opening, $tokens, $code);
        $arguments = $this->getTagArguments($opening, $tokens, $code);
        $methodName = $this->getScopeSymbolCompilerMethod($symbol, $scope, $tokens, $code);
        return call_user_func(
            array($this, $methodName), $symbol, $name, $arguments, $scope, $tokens, $code
        );
    }

    /**
     * Compile given tag
     *
     * @param string $tag
     * @param array $tokens
     * @param string $code
     * @return string
     */
    public function dispatchTagCompiler($tag, array $tokens, $code)
    {
        $tag = $this->sanitizeTagCode($tag, $tokens, $code);

        $name = $this->getTagName($tag, $tokens, $code);
        $symbol = $this->getTagSymbol($tag, $tokens, $code);
        $symbols = $this->tokenizeTagSymbol($symbol, $tag, $tokens, $code);
        $arguments = $this->getTagArguments($tag, $tokens, $code);
        $tagCode = $this->dispatchTagTypeCompiler($symbol, $name, $arguments, $tag, $tokens, $code);
        $tagCode = $this->compileFilters(
            $tagCode, $this->getTagFilters($tag, $tokens, $code), $tokens, $code
        );
        foreach ($symbols as $symbol) {
            $tagCode = $this->dispatchTagSymbolCompiler(
                $symbol, $name, $arguments, $tagCode, $tag, $tokens, $code
            );
        }
        return $tagCode;
    }

    /**
     * Compile given tag by symbol
     *
     * @param string $symbol
     * @param string $name
     * @param array $arguments
     * @param string $tag
     * @param string $tagCode
     * @param array $tokens
     * @param string $code
     * @return string
     */
    public function dispatchTagSymbolCompiler($symbol, $name, array $arguments, $tagCode, $tag, array $tokens, $code)
    {
        $methodName = $this->getTagSymbolCompilerMethod($symbol, $name, $arguments, $tagCode, $tag, $tokens, $code);
        return call_user_func(
            array($this, $methodName),
            $tagCode, $symbol, $name, $arguments, $tag, $tokens, $code
        );
    }

    /**
     * @param string $symbol
     * @param string $name
     * @param string $tag
     * @param array $tokens
     * @param string $code
     * @return string
     */
    public function dispatchTagTypeCompiler($symbol, $name, array $arguments, $tag, array $tokens, $code)
    {
        if ($this->isSymbolANoop($symbol, $tag, $tokens, $code)) {
            return null;
        } elseif ($this->isStringAConstant($name, $tokens, $code)) {
            return $this->compileTagConstant($symbol, $name, $arguments, $tag, $tokens, $code);
        } elseif ($this->isStringAString($name, $tokens, $code)) {
            return $this->compileTagString($symbol, $name, $arguments, $tag, $tokens, $code);
        }
        return $this->compileTag($symbol, $name, $arguments, $tag, $tokens, $code);
    }

    /**
     * @param array $tokens
     * @param string $code
     * @return array
     */
    public function getGlobals(array $tokens, $code)
    {
        return array(
            self::GLOBAL_CONTEXT,
            self::GLOBAL_CONTEXT_UPPER,
            self::GLOBAL_ESCAPE,
            self::GLOBAL_FUNCTION,
            self::GLOBAL_ITERATOR,
            self::GLOBAL_KEY,
            self::GLOBAL_MACRO_DEFINE,
            self::GLOBAL_MACRO_INVOKE,
            self::GLOBAL_TRUTH,
        );
    }

    /**
     * @param array $exclude
     * @param array $tokens
     * @param string $code
     * @return array
     */
    public function getGlobalsExclude(array $exclude, array $tokens, $code)
    {
        return array_filter(
            $this->getGlobals($tokens, $code),
            function ($variable) use ($exclude) {
                return !in_array($variable, $exclude);
            }
        );
    }

    /**
     * Husk next scope from given code
     *
     * @param array $range
     * @param array $tokens
     * @param string $code
     * @return array
     */
    public function getScope(array $range, array $tokens, $code)
    {
        $head = array_shift($range);
        if (!$this->isTagABlock($head, $tokens, $code)) {
            return array($head);
        }
        $headName = $this->getTagName($head, $tokens, $code);
        $response = array();
        $stack = array();
        foreach ($range as $index => $tag) {
            $response[] = $tag;
            if ($index & 1) {
                $tagName = $this->getTagName($tag, $tokens, $code);
                if ($this->isTagAClosingTag($tag, $tokens, $code)) {
                    if (empty($stack) && $tagName === $headName) {
                        break;
                    }
                    if (!empty($stack)) {
                        array_pop($stack);
                    }
                } else {
                    if ($this->isTagABlock($tag, $tokens, $code)) {
                        $stack[] = $tagName;
                    }
                }
            }
        }
        array_unshift($response, $head);
        return $response;
    }

    /**
     * @param string $tag
     * @param array $tokens
     * @param string $code
     * @return array
     */
    public function getTagArguments($tag, array $tokens, $code)
    {
        $arguments = $this->trimTagName($tag, $tokens, $code);
        return $this->tokenizeArguments($arguments, $tokens, $code);
    }

    /**
     * @param string $tag
     * @param array $tokens
     * @param string $code
     * @return array
     */
    public function getTagFilters($tag, array $tokens, $code)
    {
        $head = $this->rtrimFilters($tag, $tokens, $code);
        $filters = substr($tag, strlen($head));
        $filters = ltrim($filters);
        $filters = ltrim($filters, self::TAG_FILTER_SEPARATOR);
        $filters = ltrim($filters);
        return $filters;
    }

    /**
     * @param string $tag
     * @param array $tokens
     * @param string $code
     * @return string
     */
    public function getTagName($tag, array $tokens, $code)
    {
        $tag = trim($tag);
        $tagName = substr(
            $tag, strlen($this->getTagSymbol($tag, $tokens, $code))
        );
        $tagName = preg_split('/\s/', $tagName);
        return $tagName[0];
    }

    /**
     * @param string $tag
     * @param array $tokens
     * @param string $code
     * @return string
     */
    public function getTagSymbol($tag, array $tokens, $code)
    {
        $tag = trim($tag);
        if (preg_match('/^([^a-zA-Z0-9]+)/s', $tag, $response)) {
            return $response[0];
        }
        return '';
    }

    /**
     * Compile given scope delimiters
     *
     * @param string $symbol
     * @param array $scope
     * @param array $tokens
     * @param string $code
     * @return string
     */
    public function getScopeSymbolCompilerMethod($symbol, $scope, $tokens, $code)
    {
        if (strpos($symbol, '!') !== false) {
            return 'compileScopeUnless';
        } elseif (strpos($symbol, '@') !== false) {
            return 'compileScopeMacro';
        } elseif (strpos($symbol, '?') !== false) {
            return 'compileScopeIf';
        }
        return 'compileScopeIterator';
    }

    /**
     * Compile given tag by symbol
     *
     * @param string $symbol
     * @param string $name
     * @param array $arguments
     * @param string $tag
     * @param string $tagCode
     * @param array $tokens
     * @param string $code
     * @return string
     */
    public function getTagSymbolCompilerMethod($symbol, $name, array $arguments, $tagCode, $tag, array $tokens, $code)
    {
        switch ($symbol) {
            case '\\': return 'compileTagCommented';
            case '@.': return 'compileTagMacro';
            case '$': return 'compileTagKeyholder';
            case '^': return 'compileTagUpperContext';
            case '>': return 'compileTagPartial';
            case '&': return 'compileTagPrinterUnescaped';
            case '~': return 'compileTagNoop';
        }
        return 'compileTagPrinter';
    }

    /**
     * Check if given scope is actually a tag
     *
     * @param array $scope
     * @param array $tokens
     * @param string $code
     * @return boolean
     */
    public function isScopeATag(array $scope, array $tokens, $code)
    {
        return count($scope) === 1;
    }

    /**
     * @param string $string
     * @param array $tokens
     * @param string $code
     * @return boolean
     */
    public function isStringAConstant($string, array $tokens, $code)
    {
        $string = strtolower($string);
        return is_numeric($string)
            || $string === 'null'
            || $string === 'true'
            || $string === 'false';
    }

    /**
     * @param string $string
     * @param array $tokens
     * @param string $code
     * @return boolean
     */
    public function isStringAString($string, array $tokens, $code)
    {
        return preg_match('/^"(?:[^"\\\\]|\\\\.)*"$/', $string);
    }

    /**
     * Check if given symbol is block like
     *
     * @param string $symbol
     * @param string $tag
     * @param array $tokens
     * @param string $code
     * @return boolean
     */
    public function isSymbolABlock($symbol, $tag, array $tokens, $code)
    {
        $symbol = trim($symbol);
        if (strlen($symbol) < 1) {
            return false;
        }
        foreach ($this->blocks as $blockSymbol => $description) {
            if (strpos($symbol, $blockSymbol) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if given symbol is closing block
     *
     * @param string $symbol
     * @param string $tag
     * @param array $tokens
     * @param string $code
     * @return boolean
     */
    public function isSymbolAClosingSymbol($symbol, $tag, array $tokens, $code)
    {
        $symbol = trim($symbol);
        return strpos($symbol, '/') !== false;
    }

    /**
     * @param string $symbol
     * @param array $tokens
     * @param string $code
     * @return boolean
     */
    public function isSymbolAFilterSeparator($symbol, array $tokens, $code)
    {
        return $symbol === self::TAG_FILTER_SEPARATOR;
    }

    /**
     * Check if given symbol is block like
     *
     * @param string $symbol
     * @param string $tag
     * @param array $tokens
     * @param string $code
     * @return boolean
     */
    public function isSymbolANoop($symbol, $tag, array $tokens, $code)
    {
        return (strpos($symbol, '~') !== false);
    }

    /**
     * @param string $string
     * @param array $tokens
     * @param string $code
     * @return boolean
     */
    public function isSymbolAStringDelimiter($string, array $tokens, $code)
    {
        return $string === '"';
    }

    /**
     * @param string $symbol
     * @param array $tokens
     * @param string $code
     * @return boolean
     */
    public function isSymbolAnEscaper($symbol, array $tokens, $code)
    {
        return $symbol === self::TAG_ESCAPER;
    }

    /**
     * @param string $symbol
     * @param array $tokens
     * @param string $code
     * @return boolean
     */
    public function isSymbolAnUpperContext($symbol, array $tokens, $code)
    {
        return strpos($symbol, '^') !== false;
    }

    /**
     * Check if given tag is block tag
     *
     * @param string $tag
     * @param array $tokens
     * @param string $code
     * @return boolean
     */
    public function isTagABlock($tag, array $tokens, $code)
    {
        $symbol = $this->getTagSymbol($tag, $tokens, $code);
        return $this->isSymbolABlock($symbol, $tag, $tokens, $code);
    }

    /**
     * Check if given tag closes block
     *
     * @param string $tag
     * @param array $tokens
     * @param string $code
     * @return boolean
     */
    public function isTagAClosingTag($tag, array $tokens, $code)
    {
        $symbol = $this->getTagSymbol($tag, $tokens, $code);
        return $this->isSymbolAClosingSymbol($symbol, $tag, $tokens, $code);
    }

    /**
     * @param string $filters
     * @param array $tokens
     * @param string $code
     * @return boolean
     */
    public function lchopFilters($filters, array $tokens, $code)
    {
        $head = $this->rtrimFilters($filters, $tokens, $code);
        $headLength = strlen($head) + strlen(self::TAG_FILTER_SEPARATOR);
        return substr($filters, $headLength);
    }

    /**
     * @param filename $tag
     * @param array $tokens
     * @param string $code
     * @return boolean
     */
    public function loadFile($filename, array $tokens, $code)
    {
        if (!file_exists($filename) || !is_readable($filename)) {
            throw new RuntimeException('Given file is not readable or does not exist: ' . $filename);
        }
        return file_get_contents($filename);
    }

    /**
     * @param string $tag
     * @param array $tokens
     * @param string $code
     * @return string
     */
    public function sanitizeTagCode($tag, array $tokens, $code)
    {
        return trim($tag);
    }

    /**
     * @param string $arguments
     * @param array $tokens
     * @param string $code
     * @return array
     */
    public function rtrimFilters($arguments, array $tokens, $code)
    {
        $response = array();
        $phrase = str_split($arguments);
        $isEscaped = false;
        $isInString = false;
        foreach ($phrase as $character) {
            if ($this->isSymbolAStringDelimiter($character, $tokens, $code)) {
                if (!$isEscaped) {
                    $isInString = !$isInString;
                } else {
                    $isEscaped = false;
                }
            } elseif ($this->isSymbolAnEscaper($character, $tokens, $code)) {
                $isEscaped = !$isEscaped;
            } elseif ($this->isSymbolAFilterSeparator($character, $tokens, $code)) {
                if (!$isInString) {
                    return implode($response);
                }
            }
            $response[] = $character;
        }
        return implode($response);
    }

    /**
     * @param string $arguments
     * @param array $tokens
     * @param string $code
     * @return array
     */
    public function tokenizeArguments($arguments, array $tokens, $code)
    {
        $response = array();
        $arguments = $this->rtrimFilters($arguments, $tokens, $code);
        preg_match_all('/(([a-zA-Z0-9_\.]+)|("(?:[^"\\\\]|\\\\.)*"))/', $arguments, $arguments);
        $arguments = $arguments[0];
        return $arguments;
    }

    /**
     * @param string $delimiter
     * @param string $string
     * @return string
     */
    public function setStringDelimiters($delimiter, $string)
    {
        $string = str_replace(
            array('\"', '\\\''), array('"', '\''), $string
        );
        return str_replace($delimiter, '\\' . $delimiter, $string);
    }

    /**
     * @param string $code
     * @param array $context
     * @return array
     */
    public function tokenizeCode($code)
    {
        $response = array();
        $tokens = explode(self::TAG_DELIMITER_OPEN, $code);
        foreach ($tokens as $index => $token) {
            $response = array_merge(
                $response, explode(self::TAG_DELIMITER_CLOSE, $token)
            );
        }
        return $response;
    }

    /**
     * @param string $filters
     * @param array $tokens
     * @param string $code
     * @return array
     */
    public function tokenizeFilters($filters, array $tokens, $code)
    {
        $response = array();
        while (strlen($filters) > 0) {
            $appendice = $this->rtrimFilters($filters, $tokens, $code);
            if (strlen($appendice) > 0) {
                $response[] = trim($appendice);
            }
            $filters = $this->lchopFilters($filters, $tokens, $code);
        }
        return $response;
    }

    /**
     * @param string $symbol
     * @param string $tag
     * @param array $tokens
     * @param string $code
     * @return array
     */
    public function tokenizeTagSymbol($symbol, $tag, array $tokens, $code)
    {
        $response = array();
        foreach ($this->mnemonics as $mnemonic => $description) {
            if (strpos($symbol, $mnemonic) !== false) {
                $response[] = $mnemonic;
            }
        }
        if (in_array('>', $response)) {
            return array('>');
        }
        if (!in_array('&', $response)) {
            array_push($response, '');
        }
        return $response;
    }

    /**
     * @param string $string
     * @return string
     */
    public function trimStringDelimiters($string)
    {
        $string = trim($string, '"');
        return trim($string, '\'');
    }

    /**
     * @param string $tag
     * @param array $tokens
     * @param string $code
     * @return array
     */
    public function trimTagName($tag, array $tokens, $code)
    {
        $tagSymbol = $this->getTagSymbol($tag, $tokens, $code);
        $tagName = $this->getTagName($tag, $tokens, $code);
        $tag = substr($tag, strlen($tagSymbol) + strlen($tagName));
        return ltrim($tag);
    }

}
