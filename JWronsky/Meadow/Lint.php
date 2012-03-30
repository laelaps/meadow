<?php namespace JWronsky\Meadow;

class Lint
{

	protected $mustache;

	public function __construct()
	{
		$this->mustache = new Mustache();
	}

	public function isOk($code)
	{
		return !!is_string($this->mustache->render($code));
	}

	public function lint($code)
	{
		$this->mustache->render($code);
	}

}
