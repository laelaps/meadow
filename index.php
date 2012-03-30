<?php

spl_autoload_register();

$tpl = new JWronsky\Meadow\Meadow();

$start = microtime(true);

$context = 	array(
	'foo' => true,
	'bar' => true,
	'hello' => array(
		array('foo' => 'one'),
		array('foo' => 'two'),
		array('foo' => 'three'),
		array('foo' => 'four'),
		array('foo' => 'five'),
	)
);
$context['polygraph'] = function ($item) {
	return $item ? 'is true' : 'is false';
};
$tpl->render(
	file_get_contents('templates/lintable.mustache'), $context
);

$stop = microtime(true);
echo '<hr>';
var_dump($stop - $start);
