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

    protected $blocks = array(
        '@' => 'macro',
        '#' => 'foreach',
        '?' => 'if',
        '!' => 'unless',
        '/' => null,
    );

    protected $globals = array(
        'fun', // function
        'itr', // iterator
        'mcr', // macro
        'prt', // print
        'req', // require
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
        $globals = $this->compileVariablesList($this->globals, $tokens, $code);
        return '<?php $tpl = function ($key, $ctx, ' . $globals . ') { ?>'
             . $this->compileTokens($tokens, $code)
             . '<?php } ?>';
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
        return '<?php if (isset($ctx->{"' . $name . '"}) && !empty($ctx->{"' . $name . '"})): ?>'
             . $this->compileTokens($scope, $code)
             . '<?php endif /* ' . $symbol . $name . ' */ ?>';
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
        $globals = $this->compileVariablesList($this->globals, $tokens, $code);
        return '<?php $itr($ctx->{"' . $name . '"}, function($key, $ctx)) use(' . $globals . ') { ?>'
             . $this->compileTokens($scope, $code)
             . '<?php } /* ' . $symbol . $name . ' */ ?>';
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
        $arguments = implode('","', $arguments);
        return '<?php $mcr->add("' . $name . '", array("' . $arguments . '"), function($key, $ctx) { ?>'
             . $this->compileTokens($scope, $code)
             . '<?php }) /* ' . $symbol . $name . ' */ ?>';
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
        return '<?php if (!isset($ctx->{"' . $name . '"}) || empty($ctx->{"' . $name . '"})): ?>'
             . $this->compileTokens($scope, $code)
             . '<?php endif /* ' . $symbol . $name . ' */ ?>';
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
        return '$ctx->{"' . $name . '"}';
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
        return '$key';
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
        return '$mcr->run("' . $name . '", $key, $ctx)';
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
        $globals = $this->compileVariablesList($this->globals, $tokens, $code);
        return '$req("' . $name . '", $key, $ctx, ' . $globals . ')';
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
        return '<?php echo $prt(' . $tagCode . ') ?>';
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
        return '<?php echo ' . $tagCode . ' ?>';
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
        return '$utx->ct->{"' . $name . '"}';
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
     * @param array $variables
     * @param array $scope
     * @param array $tokens
     * @param string $code
     * @return string
     */
    public function compileVariablesList(array $variables, array $tokens, $code)
    {
        return '$' . implode(', $', $variables);
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
            if (strlen($symbol) < 1) {
                break;
            }
            $mnemonicLength = strlen($mnemonic);
            if (substr($symbol, 0, $mnemonicLength) === $mnemonic) {
                $response[] = $mnemonic;
                $symbol = substr($symbol, $mnemonicLength);
            }
        }
        if (!in_array('&', $response)) {
            array_push($response, '');
        }
        return $response;
    }

}
