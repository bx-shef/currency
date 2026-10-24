<?php declare(strict_types=1);

/**
 * Установка и удаление: своё уносим, чужое не трогаем.
 *
 * * Настройки уходят вместе с модулем (решение владельца в shef.options):
 *   иначе повторная установка молча поднимает прежние значения. Проверяются
 *   обе стороны — Option::delete() работает по модулю, и ошибка в
 *   идентификаторе унесла бы настройки соседа.
 * * savedata = Y оставляет настройки — уговор ядра.
 * * Агент курсов ставится установщиком — выключенным — и уходит вместе с
 *   модулем. В 1.x он оставался в b_agent и
 *   после удаления модуля падал на каждом запуске с «class not found».
 * * Обработчики — те же два, что в 1.x, на тот же класс: портал,
 *   обновлённый заменой файлов, держит их регистрацию, и она обязана
 *   указывать на существующий метод.
 * * Установщик ничего не копирует.
 * * Зависимости — с версиями: на shef.insync 1.x агент не соберётся.
 *
 * Ядро подменяется заглушками, установщик подключается настоящий.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/stub/shef.php';
require_once $root.'/tests/stub/currencylang.php';
require_once $root.'/tests/assert.php';

use Bitrix\Main\Config\Option;
use Bitrix\Main\EventManager;

// region Заглушка ядра ////
class CoreCalls
{
	/** @var list<string> */
	public static array $unregistered = [];

	public static int $cacheCleaned = 0;

	/** @var list<array{0: string, 1: string}> что установщик копировал */
	public static array $copied = [];

	public static function reset(): void
	{
		static::$unregistered = [];
		static::$cacheCleaned = 0;
		static::$copied = [];
		CAgent::$removed = [];
		EventManager::$unregistered = [];
		EventManager::$registered = [];
	}
}

if(!class_exists('CModule'))
{
	class CModule
	{
	}
}

class CAgent
{
	/** @var string[] чьи агенты сняты */
	public static array $removed = [];

	public static function RemoveModuleAgents(string $moduleId): void
	{
		static::$removed[] = $moduleId;
	}
}

function IsModuleInstalled(string $moduleId): bool
{
	return false;
}

function UnRegisterModule(string $moduleId): void
{
	CoreCalls::$unregistered[] = $moduleId;
}

function RegisterModule(string $moduleId): void
{
}

/** Копирование каталогов ядра: запоминаем откуда и куда. */
function CopyDirFiles(string $from, string $to, bool $rewrite = true, bool $recursive = false): bool
{
	CoreCalls::$copied[] = [$from, $to];
	return true;
}

$GLOBALS['APPLICATION'] = new class
{
	public function ThrowException(string $message): void {}
};

$GLOBALS['CACHE_MANAGER'] = new class
{
	public function CleanAll(): void
	{
		CoreCalls::$cacheCleaned++;
	}
};

// Установщик читает installEvents из настоящего .settings.php.
\Bitrix\Main\Config\Configuration::$settings = require $root.'/.settings.php';
// endregion ////

require_once $root.'/install/index.php';

const NEIGHBOUR = 'shef.options';

$given = static function(): shef_currency
{
	CoreCalls::reset();
	Option::$values = [];

	Option::set('shef.currency', 'DEF_factor', '1.05');
	Option::set('shef.currency', 'DEF_sizeChange', '2');
	Option::set(NEIGHBOUR, 'DEF_systemuserid', '9');

	return new shef_currency();
};

Check::group('установка ставит агент');

$module = $given();
Check::same('InstallDB отработал', $module->InstallDB(), true);
Check::same('агент курсов поставлен — выключенным', array_map(
	static fn(array $agent): array => [$agent['name'], $agent['module'], $agent['active']],
	\Shef\InSync\Agents\Manager::$installed
), [['\Shef\Currency\Sync\Agent::process([]);', 'shef.currency', false]]);

Check::group('знак BYN: установка ставит, удаление возвращает (2.1.0)');

$module = $given();
CCurrencyLang::$rows = ['ru' => ['FORMAT_STRING' => '# руб.']];
$module->InstallDB();
Check::same('новая установка — знак: формат «# BYN», прежний запомнен', [Option::get('shef.currency', 'DEF_sign', ''), CCurrencyLang::$rows['ru']['FORMAT_STRING'], Option::get('shef.currency', 'SYS_prev_ru', '')], ['sign', '# BYN', '# руб.']);
$module->UnInstallDB();
Check::same('удаление вернуло прежний и стёрло настройки (restore — до Option::delete)', [CCurrencyLang::$rows['ru']['FORMAT_STRING'], Option::get('shef.currency', 'SYS_prev_ru', 'нет')], ['# руб.', 'нет']);

$module = $given();
CCurrencyLang::$rows = ['ru' => ['FORMAT_STRING' => '# руб.']];
$module->InstallDB();
$module->UnInstallDB(['savedata' => 'Y']);
Check::same('savedata = Y — формат тоже возвращён, прежний остаётся в настройках', [CCurrencyLang::$rows['ru']['FORMAT_STRING'], Option::get('shef.currency', 'SYS_prev_ru', '')], ['# руб.', '# руб.']);

$module = $given();
Option::set('shef.currency', 'SYS_applied', 'br');
Option::set('shef.currency', 'DEF_sign', 'br');
CCurrencyLang::$rows = ['ru' => ['FORMAT_STRING' => '# Br']];
$module->InstallDB();
Check::same('переустановка с сохранёнными настройками — выбор администратора не меняется', [Option::get('shef.currency', 'DEF_sign', ''), CCurrencyLang::$rows['ru']['FORMAT_STRING']], ['br', '# Br']);

$module = $given();
CCurrency::$hasByn = false;
CEventLog::$added = [];
Check::same('нет BYN — установка всё равно проходит', $module->InstallDB(), true);
Check::same('причина — в журнале событий', array_map(static fn(array $e): string => $e['AUDIT_TYPE_ID'], CEventLog::$added), ['SHEF_CURRENCY_SIGN']);
CCurrency::$hasByn = true;

Check::group('удаление уносит настройки и агент модуля');

$module = $given();
$module->UnInstallDB();

Check::same('идентификатор модуля тот самый', $module->MODULE_ID, 'shef.currency');
Check::same('настройка стёрта', Option::get('shef.currency', 'DEF_factor', 'нет'), 'нет');
Check::same('вторая тоже', Option::get('shef.currency', 'DEF_sizeChange', 'нет'), 'нет');
Check::same('агенты модуля сняты', CAgent::$removed, ['shef.currency']);
Check::same('модуль снят с регистрации', CoreCalls::$unregistered, ['shef.currency']);
Check::same('кеш сброшен', CoreCalls::$cacheCleaned, 1);

Check::group('чужое не трогаем');

Check::same('настройка shef.options на месте', Option::get(NEIGHBOUR, 'DEF_systemuserid', 'нет'), '9');

Check::group('savedata');

$module = $given();
$module->UnInstallDB(['savedata' => 'Y']);
Check::same('savedata = Y оставляет настройки', Option::get('shef.currency', 'DEF_factor', 'нет'), '1.05');
Check::same('агент уходит и с savedata = Y: без модуля ему нечего звать', CAgent::$removed, ['shef.currency']);

$module = $given();
$module->UnInstallDB(['savedata' => 'N']);
Check::same('savedata = N стирает', Option::get('shef.currency', 'DEF_factor', 'нет'), 'нет');

Check::group('обработчики — те же, что в 1.x');

$describe = static fn(array $call): string => $call[0].':'.$call[1].' -> '.ltrim($call[3], '\\').'::'.$call[4];
$handlers = [
	'main:OnAdminListDisplay -> Shef\\Currency\\Sync\\Events::onAdminListDisplayHandler',
	'main:OnBeforeProlog -> Shef\\Currency\\Sync\\Events::onBeforePrologHandler',
	'main:OnProlog -> Shef\\Currency\\Main\\Sign::onPrologHandler',
];

$module = $given();
$module->InstallEvents();
Check::same('ставятся три (два из 1.x и знак BYN)', array_map($describe, EventManager::$registered), $handlers);

$module = $given();
$module->UnInstallEvents();
Check::same('снимаются те же три', array_map($describe, EventManager::$unregistered), $handlers);

Check::group('зависимости');

$module = $given();
Check::same('модуль «Валюты»', $module->NEED_MODULES, ['currency']);
Check::same('линейка — с версиями', $module->NEED_MODULES_BY_VERSION, [
	'shef.options' => '3.0.0',
	'shef.problems' => '2.0.0',
	'shef.insync' => '2.0.0',
]);

Check::group('установка файлов');

$module = $given();
Check::same('InstallFiles отработал', $module->InstallFiles(), true);
Check::same('копируются стили и скрипт знака BYN', array_map(static fn(array $c): array => [preg_replace('~^.*(/install/)~', '$1', $c[0]), preg_replace('~^.*(/bitrix/)~', '$1', $c[1])], CoreCalls::$copied), [['/install/css', '/bitrix/css'], ['/install/js', '/bitrix/js']]);
Check::same('UnInstallFiles отработал', $module->UnInstallFiles(), true);

Check::finish();
