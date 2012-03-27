<?php namespace JWronsky\Meadow;

/**
 * @todo use actual Mustache for syntax checking
 * @todo filters -> $ivk($tag, 'filter_name')
 * @todo arrows: ->, <-, =>, <=, ~>, <~
 */
class Compiler
{

    const TAG_DELIMITER_CLOSE = '}}';
    const TAG_DELIMITER_OPEN = '{{';

    const GLOBAL_CONTEXT = 'c';
    const GLOBAL_CONTEXT_UPPER = 'u';
    const GLOBAL_FUNCTION = 'f';
    const GLOBAL_IF = 'd';
    const GLOBAL_ITERATOR = 'i';
    const GLOBAL_KEY = 'k';
    const GLOBAL_MACRO_DEFINE = 'm';
    const GLOBAL_MACRO_INVOKE = 'n';
    const GLOBAL_PRINT = 'p';
    const GLOBAL_REQUIRE = 'r';
    const GLOBAL_TEMPLATE = 't';
    const GLOBAL_UNLESS = 'n';

    protected $blocks = array(
        '@' => 'macro',
        '#' => 'foreach',
        '?' => 'if',
        '!' => 'unless',
        '/' => null,
    );

    protected $mnemonics = array(
        '@.' => 'runMacro',
        '$' => 'key',
        '&' => 'unescaped',
        '^' => 'upperContext',
        '>' => 'partial',
    );

    /**
     * @param string $code
     * @return string
     */
    public function compile($code)
    {
        $response = array();
        $tokens = $this->tokenize($code);
        return $this->compileDelimitersExternal(
            $this->compileOperatorAssign(
                $this->compileVariable(self::GLOBAL_TEMPLATE, $tokens, $code),
                $this->compileFunction(
                    $this->getGlobals($tokens, $code),
                    array(),
                    $this->compileDelimitersInternal(
                        $this->compileTokens($tokens, $code), $tokens, $code
                    ),
                    $tokens, $code
                ),
                $tokens, $code
            ),
            $tokens, $code
        );
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
        if ($this->isScopeTag($scope, $tokens, $code)) {
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
        return $this->compileScopeVariableCall(
            self::GLOBAL_IF, $symbol, $name, $arguments, $scope, $tokens, $code
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
        return $this->compileScopeVariableCall(
            self::GLOBAL_UNLESS, $symbol, $name, $arguments, $scope, $tokens, $code
        );
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
                    $this->compileVariableInvoke(
                        self::GLOBAL_CONTEXT, array(
                            $this->compileString($name, $tokens, $code)
                        ), $tokens, $code
                    ),
                    $this->compileVariable(self::GLOBAL_CONTEXT, $tokens, $code),
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
        return '\'' . $string . '\'';
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
     * @param string $tag
     * @param array $tokens
     * @param string $code
     * @return string
     */
    public function compileTag($symbol, $name, $tag, array $tokens, $code)
    {
        return $this->compileVariableInvoke(
            self::GLOBAL_CONTEXT, array(
                $this->compileString($name, $tokens, $code)
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
    public function compileTagKeyholder($tagCode, $symbol, $name, $tag, array $tokens, $code)
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
    public function compileTagMacro($tagCode, $symbol, $name, $tag, array $tokens, $code)
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
    public function compileTagPartial($tagCode, $symbol, $name, $tag, array $tokens, $code)
    {
        $globals = $this->compileVariables(
            $this->getGlobals($tokens, $code), $tokens, $code
        );
        return $this->compileVariableInvoke(
            self::GLOBAL_REQUIRE,
            array_merge(
                array(
                    $this->compileString($name, $tokens, $code)
                ),
                $this->compileVariables(
                    $this->getGlobals($tokens, $code), $tokens, $code
                )
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
    public function compileTagPrinter($tagCode, $symbol, $name, $tag, array $tokens, $code)
    {
        return $this->compileDelimitersExternal(
                 'echo ' .
                 $this->compileVariableInvoke(
                     self::GLOBAL_PRINT,
                     array(
                         $tagCode
                     ),
                     $tokens, $code
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
    public function compileTagPrinterUnescaped($tagCode, $symbol, $name, $tag, array $tokens, $code)
    {
        return $this->compileDelimitersExternal(
            'echo ' . $tagCode, $tokens, $code
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
    public function compileTagUpperContext($tagCode, $symbol, $name, $tag, array $tokens, $code)
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
        switch ($symbol) {
            case '!': return $this->compileScopeUnless($symbol, $name, $arguments, $scope, $tokens, $code);
            case '@': return $this->compileScopeMacro($symbol, $name, $arguments, $scope, $tokens, $code);
            case '?': return $this->compileScopeIf($symbol, $name, $arguments, $scope, $tokens, $code);
            default: return $this->compileScopeIterator($symbol, $name, $arguments, $scope, $tokens, $code);
        }
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
        $name = $this->getTagName($tag, $tokens, $code);
        $symbol = $this->getTagSymbol($tag, $tokens, $code);
        $tagCode = $this->compileTag($symbol, $name, $tag, $tokens, $code);
        $symbols = $this->tokenizeTagSymbol($symbol, $tag, $tokens, $code);
        foreach ($symbols as $symbol) {
            $tagCode = $this->dispatchTagSymbolCompiler($symbol, $name, $tagCode, $tag, $tokens, $code);
        }
        return $tagCode;
    }

    /**
     * Compile given tag by symbol
     *
     * @param string $symbol
     * @param string $name
     * @param string $tag
     * @param string $tagCode
     * @param array $tokens
     * @param string $code
     * @return string
     */
    public function dispatchTagSymbolCompiler($symbol, $name, $tagCode, $tag, array $tokens, $code)
    {
        switch ($symbol) {
            case '@.': return $this->compileTagMacro($tagCode, $symbol, $name, $tag, $tokens, $code);
            case '$': return $this->compileTagKeyholder($tagCode, $symbol, $name, $tag, $tokens, $code);
            case '^': return $this->compileTagUpperContext($tagCode, $symbol, $name, $tag, $tokens, $code);
            case '>': return $this->compileTagPartial($tagCode, $symbol, $name, $tag, $tokens, $code);
            case '&': return $this->compileTagPrinterUnescaped($tagCode, $symbol, $name, $tag, $tokens, $code);
            default: return $this->compileTagPrinter($tagCode, $symbol, $name, $tag, $tokens, $code);
        }
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
            self::GLOBAL_FUNCTION,
            self::GLOBAL_ITERATOR,
            self::GLOBAL_IF,
            self::GLOBAL_KEY,
            self::GLOBAL_MACRO_DEFINE,
            self::GLOBAL_MACRO_INVOKE,
            self::GLOBAL_PRINT,
            self::GLOBAL_REQUIRE,
            self::GLOBAL_TEMPLATE,
            self::GLOBAL_UNLESS,
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
        if (!$this->isTagBlock($head, $tokens, $code)) {
            return array($head);
        }
        $headName = $this->getTagName($head, $tokens, $code);
        $response = array();
        $stack = array();
        foreach ($range as $index => $tag) {
            $response[] = $tag;
            if ($index & 1) {
                $tagName = $this->getTagName($tag, $tokens, $code);
                if ($this->isTagClosing($tag, $tokens, $code)) {
                    if (empty($stack) && $tagName === $headName) {
                        break;
                    }
                    if (!empty($stack)) {
                        array_pop($stack);
                    }
                } else {
                    if ($this->isTagBlock($tag, $tokens, $code)) {
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
        if (!preg_match('/\s/s', $tag)) {
            return array();
        }
        $arguments = preg_split('/\s/s', $tag);
        array_shift($arguments);
        return $arguments;
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
     * Check if given scope is actually a tag
     *
     * @param array $scope
     * @param array $tokens
     * @param string $code
     * @return boolean
     */
    public function isScopeTag(array $scope, array $tokens, $code)
    {
        return count($scope) === 1;
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
    public function isSymbolBlock($symbol, $tag, array $tokens, $code)
    {
        $symbol = trim($symbol);
        if (strlen($symbol) < 1) {
            return false;
        }
        return array_key_exists($symbol, $this->blocks);
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
    public function isSymbolClosing($symbol, $tag, array $tokens, $code)
    {
        $symbol = trim($symbol);
        return $symbol === '/';
    }

    /**
     * Check if given tag is block tag
     *
     * @param string $tag
     * @param array $tokens
     * @param string $code
     * @return boolean
     */
    public function isTagBlock($tag, array $tokens, $code)
    {
        $symbol = $this->getTagSymbol($tag, $tokens, $code);
        return $this->isSymbolBlock($symbol, $tag, $tokens, $code);
    }

    /**
     * Check if given tag closes block
     *
     * @param string $tag
     * @param array $tokens
     * @param string $code
     * @return boolean
     */
    public function isTagClosing($tag, array $tokens, $code)
    {
        $symbol = $this->getTagSymbol($tag, $tokens, $code);
        return $this->isSymbolClosing($symbol, $tag, $tokens, $code);
    }

    /**
     * @param string $code
     * @param array $context
     * @return array
     */
    public function tokenize($code)
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
        if (!in_array('&', $response)) {
            array_push($response, '');
        }
        return $response;
    }

}
