<?php namespace JWronsky\Meadow;

class Meadow
{

    protected $cacheDirectory = 'cache/';

    /**
     * @var Compiler
     */
    protected $compiler = null;

    public function cacheRead($template)
    {
        $filename = $this->getTemplateCacheFilename($template);
        return file_get_contents($filename);
    }

    public function cacheStore($code, $template)
    {
        $filename = $this->getTemplateCacheFilename($template);
        file_put_contents($filename, $code);
        return $this;
    }

    public function getCompiler()
    {
        if (!$this->compiler instanceof Compiler) {
            $this->compiler = new Compiler();
        }
        return $this->compiler;
    }

    public function getTemplateCacheFilename($template)
    {
        return $this->cacheDirectory . /*md5($template)*/'foo' . '.php';
    }

    public function isTemplateCached($template)
    {
        return file_exists($this->getTemplateCacheFilename($template));
    }

    /**
     * @param string $template
     * @param array $context
     * @return string
     */
    public function render($template)
    {
        // if (!$this->isTemplateCached($template)) {
            $compiler = $this->getCompiler();
            $code = $compiler->compile($template, null);
            // $this->cacheStore($code, $template);
        // } else {
            // $code = $this->cacheRead($template);
        // }
        highlight_string($code);
    }

}
