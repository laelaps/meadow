<?php

spl_autoload_register();

$tpl = new JWronsky\Meadow\Meadow();
$tpl->render(file_get_contents('templates/simple.mustache'));