<?php declare(strict_types=1);

/**
 * .settings.php: ссылки наружу обязаны никуда не висеть.
 *
 * Этот файл — единственное место, где модуль говорит ядру, что и откуда
 * брать: какие модули подключить, что копировать установщику, чьи
 * обработчики событий регистрировать. Все ссылаются на классы и методы
 * ИМЕНЕМ, и ни одну не проверяет ни php -l, ни автозагрузка. Промах
 * молчалив: обработчик события просто не вызовется, и сообщения об ошибке
 * установки не будет.
 *
 * И отдельно — shef.uiclear. В 1.x модуль требовал его, ни разу не позвав.
 * Модуля не будет: зависимость на него — это модуль, который не ставится.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/bitrix.php';
require_once $root.'/tests/assert.php';

/** Файл класса модуля по соглашению автозагрузки либо null. */
$classFile = static function(string $class) use ($root): null|string
{
	$class = ltrim($class, '\\');
	if(!str_starts_with($class, 'Shef\\Currency\\'))
	{
		return null;
	}

	$path = $root.'/lib/'.mb_strtolower(str_replace('\\', '/', mb_substr($class, mb_strlen('Shef\\Currency\\')))).'.php';

	return is_file($path) ? $path : null;
};

$hasMethod = static fn(string $file, string $method): bool => 1 === preg_match(
	'/function\s+'.preg_quote($method, '/').'\s*\(/',
	(string)file_get_contents($file)
);

$settings = require $root.'/.settings.php';

Check::group('структура файла');

Check::same('.settings.php вернул массив', is_array($settings), true);

$shape = [];
foreach($settings as $key => $section)
{
	if(!is_array($section) || !array_key_exists('value', $section) || !array_key_exists('readonly', $section))
	{
		$shape[] = $key;
	}
}

Check::same('у каждой секции есть value и readonly', $shape, []);
Check::same(
	'обязательные модули — линейка и «Валюты», без shef.uiclear',
	$settings['requireModules']['value'],
	['shef.options', 'shef.problems', 'shef.insync', 'currency']
);
Check::same('расширение PHP — simplexml, им разбирается ответ НБ РБ', $settings['requirePhpExt']['value'], ['simplexml']);
Check::same(
	'simplexml и зовётся',
	str_contains((string)file_get_contents($root.'/lib/main/rates.php'), 'simplexml_load_string('),
	true
);
Check::same('чужих namespace нет — свой даёт соглашение', $settings['registerNamespace']['value'], []);

Check::group('installDir — только стили и шрифт знака BYN (2.1.0)');

// Кнопку на странице курсов рисует обработчик события, страницу настроек — ядро. Раскладывается
// одно: стили и шрифт знака белорусского рубля — каталог модуля браузеру закрыт.
Check::same('installDir — install/css в /bitrix/css', $settings['installDir']['value'], [[
	'type' => 'css', 'from' => '/install/css', 'to' => '/bitrix/css', 'customPathUnInstall' => [], 'isNeedUnInstall' => true,
]]);
Check::same('в install/ — установщик, стили знака и шаблон печатной формы', array_values(array_diff(scandir($root.'/install'), ['.', '..'])), ['css', 'docgen', 'index.php', 'version.php']);
Check::same('стили знака — по пути, который подключает Main\\Sign', is_file($root.'/install/css/shef.currency/byn.css') && str_contains((string)file_get_contents($root.'/lib/main/sign.php'), "CSS = '/bitrix/css/shef.currency/byn.css'"), true);
foreach(['nbrb.woff2', 'nbrb.woff', 'nbrb.ttf'] as $font)
{
	Check::same('шрифт '.$font.' рядом со стилями и назван в них', is_file($root.'/install/css/shef.currency/'.$font) && str_contains((string)file_get_contents($root.'/install/css/shef.currency/byn.css'), 'url("'.$font.'")'), true);
}

Check::group('installEvents — обработчики существуют');

$brokenHandlers = [];
foreach($settings['installEvents']['value'] as $i => $event)
{
	$class = (string)($event['to']['class'] ?? '');
	$method = (string)($event['to']['function'] ?? '');
	$file = $classFile($class);

	if(($event['to']['module'] ?? '') !== 'shef.currency')
	{
		$brokenHandlers[] = sprintf('запись %d: обработчик не в этом модуле', $i);
	}
	elseif(null === $file)
	{
		$brokenHandlers[] = sprintf('запись %d: класса %s нет', $i, $class);
	}
	elseif(!$hasMethod($file, $method))
	{
		$brokenHandlers[] = sprintf('запись %d: метода %s::%s нет', $i, $class, $method);
	}
}

Check::same('обработчиков — три: два из 1.x и знак BYN (2.1.0)', count($settings['installEvents']['value']), 3);
Check::same('каждый обработчик — существующий метод', $brokenHandlers, []);

$fromModules = array_unique(array_map(static fn(array $event): string => $event['from']['module'], $settings['installEvents']['value']));
Check::same('события только ядра — от shef.uiclear модуль не зависит', array_values($fromModules), ['main']);

Check::group('shef.uiclear нигде не зовут');

$uiclear = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach($iterator as $file)
{
	$path = mb_substr($file->getPathname(), mb_strlen($root) + 1);
	if(!preg_match('#^(admin|cli|install|lang|lib)/.+\.php$|^[^/]+\.php$#', $path))
	{
		continue;
	}

	// Код, а не комментарии: рассказать, откуда что взялось, можно.
	$code = '';
	foreach(token_get_all((string)file_get_contents($file->getPathname())) as $token)
	{
		if(is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true))
		{
			continue;
		}

		$code .= is_array($token) ? $token[1] : $token;
	}

	if(preg_match('/shef\.uiclear|shef-uiclear|Shef\\\\UiClear|ShUiClear/i', $code))
	{
		$uiclear[] = $path;
	}
}
Check::same('ни shef.uiclear, ни его классов и расширений в поставке', $uiclear, []);

Check::group('controllers');

Check::same('своих ajax-контроллеров нет', $settings['controllers']['value']['namespaces'], []);

Check::finish();
