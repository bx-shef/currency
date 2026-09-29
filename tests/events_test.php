<?php declare(strict_types=1);

/**
 * Кнопка «Запросить курсы валют» на /bitrix/admin/currencies_rates.php.
 *
 * Что держит:
 *
 * * запрос меняет курсы, поэтому без sessid не делает ничего — в 1.x
 *   курсы переписывала любая ссылка, открытая администратором (CSRF);
 * * права — «W» на модуль currency: в 1.x хватало «R»;
 * * кнопка — только на странице курсов и только тому, кто может писать;
 * * список без контекстной панели — не fatal;
 * * «на завтра» — дата завтрашняя; итог виден сообщением после
 *   переадресации, ошибка — в журнале.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/stub/shef.php';
require_once $root.'/tests/assert.php';

use Bitrix\Main\Context;
use Bitrix\Main\Localization\Loc;
use Bitrix\Currency\CurrencyRateTable;
use Shef\InSync\Api\AConnector;
use Shef\Options\Options\Singleton;
use Shef\Problems\Factory\Trait\TestLogger;
use Shef\Currency\Sync\Events;

\Bitrix\Main\ModuleManager::$installed = ['shef.options', 'shef.problems', 'shef.insync', 'currency'];

Loc::loadLangFile($root.'/lang/ru/lib/sync/events.php');

$xml = (string)file_get_contents($root.'/tests/fixtures/nbrb-2026-09-29.xml');

$given = static function(array $query, string $right = 'W', string $page = Events::PAGE) use ($xml): void
{
	Singleton::resetInstances();
	CurrencyRateTable::$rows = [];
	AConnector::$responses = [AConnector::answer($xml)];
	AConnector::$requests = [];
	TestLogger::$records = [];
	CAdminMessage::$shown = [];
	Context::$query = $query;
	$GLOBALS['APPLICATION'] = new CMain();
	$GLOBALS['APPLICATION']->page = $page;
	$GLOBALS['APPLICATION']->rights = ['currency' => $right];
};

/** Нажатие: адрес переадресации либо null, если её не было. */
$click = static function(): ?string
{
	try
	{
		Events::onBeforePrologHandler();
	}
	catch(RedirectException $redirect)
	{
		return $redirect->url;
	}

	return null;
};

$button = static function(bool $withContext = true): ?array
{
	$list = new CAdminList($withContext);
	Events::onAdminListDisplayHandler($list);

	return $list->context?->items[-3] ?? null;
};

Check::group('класс — настоящий, не пустышка');

Check::same('shef.options и shef.problems есть — обработчики работают', method_exists(Events::class, 'onBeforePrologHandler'), true);

Check::group('кнопка');

$given([]);
$menu = $button();
Check::same('есть на странице курсов', $menu['TEXT'] ?? null, '[SH] Запросить курсы валют');
Check::same('на сегодня — с sessid', $menu['MENU'][0]['LINK'] ?? null, Events::PAGE.'?getCurrency=Y&sessid=sess-test');
Check::same('на завтра — с sessid', $menu['MENU'][1]['LINK'] ?? null, Events::PAGE.'?getCurrency=Y&tomorrow=Y&sessid=sess-test');

$given(['filter' => 'Y', 'shefCurrencyResult' => 'ok', 'sessid' => 'old']);
$menu = $button();
Check::same('фильтр списка сохраняется, прежний итог и sessid — нет', $menu['MENU'][0]['LINK'] ?? null, Events::PAGE.'?filter=Y&getCurrency=Y&sessid=sess-test');

$given([], 'R');
Check::same('с правом чтения кнопки нет', $button(), null);

$given([], 'W', '/bitrix/admin/currencies.php');
Check::same('на другой странице кнопки нет', $button(), null);

$given([]);
Check::same('список без панели — без ошибки', $button(false), null);

Check::group('нажатие без защиты — ничего');

$given(['getCurrency' => 'Y']);
Check::same('без sessid — переадресация без параметров', $click(), Events::PAGE);
Check::same('в НБ РБ не ходили', AConnector::$requests, []);

$given(['getCurrency' => 'Y', 'sessid' => 'чужой']);
$click();
Check::same('с чужим sessid — не ходили', AConnector::$requests, []);

$given(['getCurrency' => 'Y', 'sessid' => 'sess-test'], 'R');
$click();
Check::same('с правом чтения — не ходили', AConnector::$requests, []);
Check::same('курсы не тронуты', CurrencyRateTable::$rows, []);

$given(['getCurrency' => 'Y', 'sessid' => 'sess-test'], 'W', '/bitrix/admin/index.php');
Check::same('на другой странице обработчик молчит', $click(), null);

$given(['getCurrency' => 'N', 'sessid' => 'sess-test']);
Check::same('без getCurrency=Y — тоже', $click(), null);

Check::group('нажатие');

$given(['getCurrency' => 'Y', 'sessid' => 'sess-test']);
Check::same('итог — в адресе', $click(), Events::PAGE.'?shefCurrencyResult=ok');
Check::same('на сегодня', AConnector::$requests[0]['url'] ?? null, 'https://services.nbrb.by/XmlExRates.aspx?ondate=09%2F29%2F2026');
Check::same('курсы записаны', count(CurrencyRateTable::$rows), 3);

$given(['getCurrency' => 'Y', 'tomorrow' => 'Y', 'sessid' => 'sess-test']);
Check::same('на завтра курсов ещё нет — итог «не записано»', $click(), Events::PAGE.'?shefCurrencyResult=fail');
Check::same('запрос — на завтра', AConnector::$requests[0]['url'] ?? null, 'https://services.nbrb.by/XmlExRates.aspx?ondate=09%2F30%2F2026');
Check::same('причина — в журнал', [TestLogger::$records[0]['level'] ?? null, TestLogger::$records[0]['auditType'] ?? null], ['error', 'SH_PROBLEMS_SYNC']);

Check::group('итог на странице');

$given(['shefCurrencyResult' => 'ok']);
$button();
Check::same('успех', CAdminMessage::$shown[0]['TYPE'] ?? null, 'OK');

$given(['shefCurrencyResult' => 'fail']);
$button();
Check::same('неудача — с подсказкой, где причина', [
	CAdminMessage::$shown[0]['TYPE'] ?? null,
	str_contains((string)(CAdminMessage::$shown[0]['MESSAGE'] ?? ''), 'журнале событий'),
], ['ERROR', true]);

$given(['shefCurrencyResult' => '<script>']);
$button();
Check::same('чужое значение не показывается', CAdminMessage::$shown, []);

Check::group('модуль без зависимостей');

// Класс объявляется один раз при подключении файла, поэтому — отдельный
// процесс: shef.options снят, обработчики ещё зарегистрированы, страница
// курсов должна открыться.
$script = sprintf(
	<<<'PHP'
	require %s;
	require %s;
	\Bitrix\Main\ModuleManager::$installed = ['shef.problems'];
	\Bitrix\Main\Context::$query = ['getCurrency' => 'Y', 'sessid' => 'sess-test'];
	$GLOBALS['APPLICATION'] = new CMain();
	$list = new CAdminList();
	echo json_encode([
		\Shef\Currency\Sync\Events::onBeforePrologHandler(),
		\Shef\Currency\Sync\Events::onAdminListDisplayHandler($list),
		$list->context->items,
	]);
	PHP,
	var_export($root.'/tests/stub/autoload.php', true),
	var_export($root.'/tests/stub/shef.php', true)
);
exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script).' 2>&1', $output, $code);
Check::same('пустышка: ни переадресации, ни кнопки, ни ошибки', [$code, implode("\n", $output)], [0, '[null,null,[]]']);

Check::finish();
