<?php declare(strict_types=1);

/**
 * Подключение модуля поднимает зависимости — и больше ничего не делает.
 *
 * include.php подключает autoload.php, а тот — модули из requireModules
 * .settings.php: shef.options, shef.problems, shef.insync — на них стоят
 * агент и клиент API, — и «Валюты». Своих namespace и функций у модуля нет:
 * регистрировать нечего.
 *
 * Тест подключает настоящий include.php и смотрит на результат, а не ищет
 * строку require в исходнике.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

use Bitrix\Main\Loader;

\Bitrix\Main\Config\Configuration::$settings = require $root.'/.settings.php';

$included = [];
foreach(['shef.options', 'shef.problems', 'shef.insync'] as $module)
{
	Loader::$onInclude[$module] = static function() use (&$included, $module): void
	{
		$included[] = $module;
	};
}

$functions = get_defined_functions()['user'];

require_once $root.'/include.php';

Check::group('после подключения модуля');

Check::same('модули линейки подключены в порядке зависимостей', $included, ['shef.options', 'shef.problems', 'shef.insync']);
Check::same('чужих namespace не зарегистрировано', Loader::$namespaces, []);
Check::same('глобальных функций не объявлено', array_values(array_diff(get_defined_functions()['user'], $functions)), []);

Check::finish();
