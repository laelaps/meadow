<?php namespace JWronsky\Meadow;

use Closure;

class Meadow
{

    protected $cacheDirectory = 'cache/';

    /**
     * @var Compiler
     */
    protected $compiler = null;

    /**
     * @var Lint
     */
    protected $lint = null;

    /**
     * @param string $template
     * @param array $context
     * @return string
     */
    public function applyContext($template, array $context)
    {
        $filename = $this->getTemplateCacheFilename($template);
        $globals = $this->getTemplateGlobals($template, $context);
        $scope = function ($filename) use ($globals) {
            extract($globals);
            require $filename;
        };
        $scope($filename);
    }

    /**
     * @param string $template
     * @return string
     */
    public function cacheRead($template)
    {
        $filename = $this->getTemplateCacheFilename($template);
        return file_get_contents($filename);
    }

    /**
     * @param string $code
     * @return string
     */
    public function cacheStore($code, $template)
    {
        $filename = $this->getTemplateCacheFilename($template);
        file_put_contents($filename, $code);
        return $this;
    }

    /**
     * @param string $template
     * @return void
     */
    public function compile($template)
    {
        if (!$this->isTemplateCached($template)) {
            $compiler = $this->getCompiler();
            $code = $compiler->compile($template, null);
            $this->cacheStore($code, $template);
        }
    }

    /**
     * @return Compiler
     */
    public function getCompiler()
    {
        if (!$this->compiler instanceof Compiler) {
            $this->compiler = new Compiler();
        }
        return $this->compiler;
    }

    /**
     * @param string $name
     * @param array $cotnext
     * @return function|object
     */
    public function getTemplateGlobal($name, array $context)
    {
        switch ($name)
        {
            case Compiler::GLOBAL_CONTEXT:
                return function ($item) use ($context) {
                    if (strpos($item, '.') !== false) {
                        $items = explode('.', $item);
                        $item = $context;
                        foreach ($items as $name) {
                            if ($item[$name]) {
                                $item = $item[$name];
                            }
                        }
                    } else {
                        $item = $context[$item];
                    }
                    if (is_callable($item)) {
                        $tail = func_get_args();
                        array_shift($tail);
                        return call_user_func_array($item, $tail);
                    }
                    return $item;
                };
            break;
            case Compiler::GLOBAL_CONTEXT_UPPER:
                return function ($item) {
                    return htmlentities($item);
                };
            break;
            case Compiler::GLOBAL_ESCAPE:
                return function ($item) {
                    if (!is_string($item)) {
                        $item = strval($item);
                    }
                    return htmlentities($item);
                };
            break;
            case Compiler::GLOBAL_FUNCTION:
                // $fun('upper',$key,$ctx,$utx,
                return function ($name, $key, $context, $contextUpper, $item) {
                    if ($name === 'upper') {
                        return strtoupper($item);
                    }
                    if ($name === 'ucfirst') {
                        return ucfirst($item);
                    }
                    return $item;
                };
            break;
            case Compiler::GLOBAL_IF:
                return function ($item, $key, $context, $contextUpper, Closure $callback) {
                    if (!empty($item)) {
                        $callback($key, $context, $contextUpper);
                    }
                };
            break;
            case Compiler::GLOBAL_ITERATOR:
                // $itr($ctx('hello'),$key,$ctx,$utx,function($key,$ctx,$utx)
                return function ($item, $key, $context, $contextUpper, Closure $callback) {
                    foreach ($item as $key => $value) {
                        $callback(
                            $key, function ($item) use ($value) {
                                return $value[$item];
                            }, $context
                        );
                    }
                };
            break;
            case Compiler::GLOBAL_KEY:
                return function ($item) {
                    return htmlentities($item);
                };
            break;
            case Compiler::GLOBAL_MACRO_DEFINE:
                return function ($item) {
                    return htmlentities($item);
                };
            break;
            case Compiler::GLOBAL_MACRO_INVOKE:
                return function ($item) {
                    return htmlentities($item);
                };
            break;
            case Compiler::GLOBAL_UNLESS:
                return function ($item, $key, $context, $contextUpper, Closure $callback) {
                    if (empty($item)) {
                        $callback($key, $context, $contextUpper);
                    }
                };
            break;
        }
        return;
    }

    /**
     * @param string $template
     * @param array $context
     * @return array
     */
    public function getTemplateGlobals($template, array $context)
    {
        $globals = array(
            Compiler::GLOBAL_CONTEXT => null,
            Compiler::GLOBAL_CONTEXT_UPPER => null,
            Compiler::GLOBAL_ESCAPE => null,
            Compiler::GLOBAL_FUNCTION => null,
            Compiler::GLOBAL_IF => null,
            Compiler::GLOBAL_ITERATOR => null,
            Compiler::GLOBAL_KEY => null,
            Compiler::GLOBAL_MACRO_DEFINE => null,
            Compiler::GLOBAL_MACRO_INVOKE => null,
            Compiler::GLOBAL_UNLESS => null,
        );
        foreach ($globals as $name => $value) {
            $globals[$name] = $this->getTemplateGlobal($name, $context);
        }
        return $globals;
    }

    /**
     * @param string $template
     * @return string
     */
    public function getTemplateCacheFilename($template)
    {
        return $this->cacheDirectory . sha1($template) . '.php';
    }

    /**
     * @param string $template
     * @return string
     */
    public function isTemplateCached($template)
    {
        return file_exists($this->getTemplateCacheFilename($template));
    }

    /**
     * @param string $template
     * @param array $context
     * @return string
     */
    public function render($template, array $context = array())
    {
        $this->compile($template);
        $response = $this->applyContext($template, $context);
        return $response;
    }

}
