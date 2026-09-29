<?php

/**
 * Автозагрузка для тестов и примеров без портала.
 *
 * Shef\Currency\Foo\Bar -> lib/foo/bar.php, путь СТРОЧНЫМИ — ровно так
 * \Bitrix\Main\Loader отображает классы модуля; держит это соглашение
 * tests/autoload_test.php.
 *
 * Классы модуля подключаются настоящие: тест, проверяющий свою копию логики,
 * ничего не проверяет.
 */

require_once __DIR__.'/bitrix.php';

spl_autoload_register(static function(string $class): void
{
	$prefix = 'Shef\\Currency\\';
	if(!str_starts_with($class, $prefix))
	{
		return;
	}

	$path = dirname(__DIR__, 2).'/lib/'.mb_strtolower(str_replace('\\', '/', substr($class, strlen($prefix)))).'.php';
	if(is_file($path))
	{
		require_once $path;
	}
});
