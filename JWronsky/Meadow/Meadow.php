<?php namespace JWronsky\Meadow;

use Closure;
use DomainException;
use Duck;
use LogicException;

class Meadow
{

    protected $cacheDirectory = 'cache/';

    /**
     * @var Compiler
     */
    protected $compiler = null;

    /**
     * @var array
     */
    protected $globalsCache = null;

    /**
     * @var Duck
     */
    protected $duck = null;

    /**
     * @var array
     */
    protected $templateFunctionsCache = array();

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
        ob_start();
        $scope($filename);
        return ob_get_clean();
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
     * @return Duck
     */
    public function getDuck()
    {
        if (!$this->duck instanceof Duck) {
            $this->duck = new Duck();
        }
        return $this->duck;
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
     * @param string $name
     * @param array $cotnext
     * @return function|object
     */
    public function getTemplateGlobal($name, array $context)
    {
        switch ($name)
        {
            case Compiler::GLOBAL_CONTEXT:
                return $this->getTemplateGlobalContext($name, $context);
            break;
            case Compiler::GLOBAL_CONTEXT_UPPER:
                return $this->getTemplateGlobalContext($name, $context);
            break;
            case Compiler::GLOBAL_ESCAPE:
                return $this->getTemplateGlobalEscape($name, $context);
            break;
            case Compiler::GLOBAL_FUNCTION:
                return $this->getTemplateGlobalFunction($name, $context);
            break;
            case Compiler::GLOBAL_ITERATOR:
                return $this->getTemplateGlobalIterator($name, $context);
            break;
            case Compiler::GLOBAL_KEY:
                return $this->getTemplateGlobalKey($name, $context);
            break;
            case Compiler::GLOBAL_MACRO_DEFINE:
                return $this->getTemplateGlobalMacroDefine($name, $context);
            break;
            case Compiler::GLOBAL_MACRO_INVOKE:
                return $this->getTemplateGlobalMacroInvoke($name, $context);
            break;
            case Compiler::GLOBAL_TRUTH:
                return $this->getTemplateGlobalTruth($name, $context);
            break;
        }
        throw new DomainException(
            'Unsupported global variable: "' . $name . '".'
        );
    }

    /**
     * Get template global context support variable
     *
     * @param string $name
     * @param array $context
     * @return function
     */
    public function getTemplateGlobalContext($name, $context)
    {
        $cache = array();
        $duck = $this->getDuck();
        return function ($item/*, polymorphic */) use ($cache, $context, $duck) {
            if (empty($item)) {
                return $context;
            }
            if (array_key_exists($item, $cache)) {
                return $cache[$item];
            }
            $response = $duck->dothusk($context, $item, $isLoud = true);
            if ($duck->isFunction($response)) {
                $arguments = func_get_args();
                array_shift($arguments);
                return call_user_func_array($response, $arguments);
            } else {
                $cache[$item] = $response;
            }
            return $response;
        };
    }

    /**
     * @param string $name
     * @param array $context
     * @return function
     */
    public function getTemplateGlobalEscape($name, array $context)
    {
        return function ($item) {
            if (!is_string($item)) {
                $item = strval($item);
            }
            return htmlentities($item);
        };
    }

    /**
     * @param string $name
     * @param array $context
     * @return function
     */
    public function getTemplateGlobalFunction($name, array $context)
    {
        $self = $this;
        return function ($name, $key, $context, $contextUpper, $item/*, polymorphic */) use ($self) {
            $function = $self->getTemplateGlobalFunctionFunction($name);
            if (!is_array($function)) {
                return call_user_func_array(
                    $function, array_slice(func_get_args(), 4)
                );
            }
            return call_user_func_array(
                $function, array_merge(
                    array($item, $item), array_slice(func_get_args(), 5)
                )
            );
        };
    }

    public function getTemplateGlobalFunctionFunction($name)
    {
        if (array_key_exists($name, $this->templateFunctionsCache)) {
            return array($this->templateFunctionsCache[$name], 'render');
        }
        $className = '\Mustache\Filter\\' . $name;
        if (!class_exists($className)) {
            return $name;
        }
        $instance = new $className();
        $this->templateFunctionsCache[$name] = $instance;
        return array($instance, 'render');
    }

    /**
     * @param string $name
     * @param array $context
     * @return function
     */
    public function getTemplateGlobalIterator($name, array $context)
    {
        // $itr($ctx('hello'),$key,$ctx,$utx,function($key,$ctx,$utx)
        $self = $this;
        $duck = $this->getDuck();
        return function ($item, $key, $context, $contextUpper, Closure $callback) use ($duck, $self) {
            if ($duck->isEmpty($item)) {
                return false;
            }
            if ( is_array($item)
              || $item instanceof Traversable
              || is_a($item, 'Traversable')
            ) {
                foreach ($item as $key => $value) {
                    $callback(
                        $key, $self->getTemplateGlobalContext(
                            Compiler::GLOBAL_CONTEXT, $value
                        ), $context
                    );
                }
            } else if ($item) {
                $callback($key, $context, $item);
            }
        };
    }

    /**
     * @param string $name
     * @param array $context
     * @return function
     */
    public function getTemplateGlobalKey($name, array $context)
    {
        return function ($item) {
            return htmlentities($item);
        };
    }

    /**
     * @param string $name
     * @param array $context
     * @return function
     */
    public function getTemplateGlobalMacroDefine($name, array $context)
    {
        return function () {
            throw new LogicException('Macro defining is not implemented yet.');
        };
    }

    /**
     * @param string $name
     * @param array $context
     * @return function
     */
    public function getTemplateGlobalMacroInvoke($name, array $context)
    {
        return function () {
            throw new LogicException('Macro invoking is not implemented yet.');
        };
    }

    /**
     * @param string $name
     * @param array $context
     * @return function
     */
    public function getTemplateGlobalTruth($name, array $context)
    {
        $duck = $this->getDuck();
        return function ($item) use($duck) {
            if ($duck->isFunction($item)) {
                $item = $item();
            }
            return !$duck->isEmpty($item);
        };
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
            Compiler::GLOBAL_ITERATOR => null,
            Compiler::GLOBAL_KEY => null,
            Compiler::GLOBAL_MACRO_DEFINE => null,
            Compiler::GLOBAL_MACRO_INVOKE => null,
            Compiler::GLOBAL_TRUTH => null,
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
