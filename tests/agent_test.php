<?php declare(strict_types=1);

/**
 * Агент курсов: от ответа НБ РБ до записи в таблицу курсов.
 *
 * Классы модуля настоящие; ядро, «Валюты» и shef.insync — заглушки
 * (tests/stub/shef.php, currency.php). Таблица курсов — в памяти, запись —
 * через CCurrencyRates, как на портале.
 *
 * Что держит:
 *
 * * агент ставится с аргументами по именам: в 1.x id пользователя шёл
 *   шестым позиционным и попадал в сортировку;
 * * строка агента та же, что в 1.x, — агент с портала находится по имени;
 * * дата курса — в формате сайта (в 1.x — d.m.Y, и на сайте с другим
 *   форматом курс не писался);
 * * курс на дату есть — обновляется, нет — добавляется; порог и
 *   коэффициент — из настроек;
 * * базовая валюта не BYN — ничего не пишется, ошибка;
 * * ошибка ядра — с текстом (в 1.x читался пустой LAST_ERROR), остальные
 *   валюты при этом пишутся;
 * * нет курсов на дату — ошибка, а не «всё хорошо»;
 * * preview() показывает тот же план и ничего не пишет.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/stub/shef.php';
require_once $root.'/tests/assert.php';

use Bitrix\Main\Config\Option;
use Bitrix\Main\Type\Date;
use Bitrix\Currency\CurrencyManager;
use Bitrix\Currency\CurrencyRateTable;
use Shef\InSync\Agents;
use Shef\InSync\Api\AConnector;
use Shef\Options\Options\Singleton;
use Shef\Problems\Factory\Trait\TestLogger;
use Shef\Currency\Sync\Agent;

$xml = (string)file_get_contents($root.'/tests/fixtures/nbrb-2026-09-29.xml');

$GLOBALS['APPLICATION'] = new CMain();

$given = static function() use ($xml): void
{
	Singleton::resetInstances();
	CurrencyRateTable::$rows = [];
	CCurrencyRates::$calls = [];
	CCurrencyRates::$reject = null;
	AConnector::$responses = [AConnector::answer($xml)];
	AConnector::$requests = [];
	TestLogger::$records = [];
	CurrencyManager::$baseCurrency = 'BYN';
	Option::$values = [];
	Date::$today = '2026-09-29';
	Date::$cultureFormat = 'd.m.Y';
	$GLOBALS['APPLICATION'] = new CMain();
};

$rates = static fn(): array => array_map(
	static fn(array $row): string => sprintf('%s %s %s/%d', $row['DATE_RATE'], $row['CURRENCY'], $row['RATE'], $row['RATE_CNT']),
	array_values(CurrencyRateTable::$rows)
);

Check::group('агент ставится');

$given();
$entity = Agent::buildAgentsEntity();
Check::same('строка агента — как в 1.x', $entity->prepareNameForDb(), '\Shef\Currency\Sync\Agent::process([]);');
Check::same('поставлен один раз', count(Agents\Manager::$installed), 1);
Check::same('модуль, сортировка по умолчанию, служебный пользователь, 00:20', Agents\Manager::$installed[0], [
	'name' => '\Shef\Currency\Sync\Agent::process([]);',
	'module' => 'shef.currency',
	'nextExec' => '00:20',
	'sort' => 100,
	'userId' => 7,
]);
Agent::buildAgentsEntity();
Check::same('второй вызов не ставит заново', count(Agents\Manager::$installed), 1);
Check::same('агент зовёт то, что вернул getName()', Agent::getName(['debug' => 'Y']), '\Shef\Currency\Sync\Agent::process([]);');

Check::group('новый портал: курсы на сегодня');

$given();
$response = Agent::getInstance()->action();
Check::same('успех', $response->getErrorMessages(), []);
Check::same('запрос — на сегодня, дата m/d/Y', AConnector::$requests[0]['url'], 'https://services.nbrb.by/XmlExRates.aspx?ondate=09%2F29%2F2026');
Check::same('записаны валюты портала, кроме BYN', $response->getData()['written'], ['USD', 'EUR', 'RUB']);
Check::same('в таблице', $rates(), [
	'2026-09-29 USD 3.0257/1',
	'2026-09-29 EUR 3.4418/1',
	'2026-09-29 RUB 3.5891/100',
]);
Check::same('дата — в формате сайта', CCurrencyRates::$calls[0][2]['DATE_RATE'], '29.09.2026');

Check::group('предпросмотр ничего не пишет');

$given();
CurrencyRateTable::add('USD', '2026-09-28', 3.02, 1);
Option::set('shef.currency', 'DEF_sizeChange', '1');
$response = Agent::getInstance()->preview(new Date());
Check::same('план — тот, что записал бы sync()', array_column($response->getData()['plan'], 'CURRENCY'), ['EUR', 'RUB']);
Check::same('ответ банка — целиком', count($response->getData()['rates']), 30);
Check::same('ядро не звали', CCurrencyRates::$calls, []);
Check::same('в таблице только вчерашний курс', $rates(), ['2026-09-28 USD 3.02/1']);

Check::group('сайт с другим форматом даты');

$given();
Date::$cultureFormat = 'm/d/Y';
$response = Agent::getInstance()->action();
Check::same('курсы записаны', [$response->isSuccess(), count(CurrencyRateTable::$rows)], [true, 3]);
Check::same('дата — m/d/Y', CCurrencyRates::$calls[0][2]['DATE_RATE'], '09/29/2026');

Check::group('курс на дату уже есть — обновление');

$given();
$id = CurrencyRateTable::add('USD', '2026-09-29', 2.5, 1);
$response = Agent::getInstance()->action();
Check::same('USD обновлён, остальные добавлены', array_map(static fn(array $call): string => $call[0].' '.$call[2]['CURRENCY'], CCurrencyRates::$calls), ['Update USD', 'Add EUR', 'Add RUB']);
Check::same('та же запись', CurrencyRateTable::$rows[$id]['RATE'], 3.0257);

Check::group('порог и коэффициент из настроек');

$given();
CurrencyRateTable::add('USD', '2026-09-28', 3.02, 1);
CurrencyRateTable::add('EUR', '2026-09-28', 3.30, 1);
Option::set('shef.currency', 'DEF_sizeChange', '1');
$response = Agent::getInstance()->action();
// USD: 3.0257 против 3.02 — 0.19 %, меньше порога. EUR: 3.4418 против
// 3.30 — 4.3 %. RUB: курса не было — пишется.
Check::same('пишется, что сдвинулось на порог и больше', $response->getData()['written'], ['EUR', 'RUB']);

$given();
Option::set('shef.currency', 'DEF_factor', '1.1');
Agent::getInstance()->action();
Check::same('коэффициент применён', CurrencyRateTable::$rows[1]['RATE'], 3.3283);

$given();
Option::set('shef.currency', 'DEF_factor', '');
Agent::getInstance()->action();
Check::same('пустой коэффициент — курс как есть, не ноль', CurrencyRateTable::$rows[1]['RATE'], 3.0257);

Check::group('базовая валюта не BYN');

$given();
CurrencyManager::$baseCurrency = 'RUB';
$response = Agent::getInstance()->action();
Check::same('ошибка', $response->isSuccess(), false);
Check::same('в НБ РБ не ходили', AConnector::$requests, []);
Check::same('ничего не записано', CurrencyRateTable::$rows, []);

Check::group('ядро отклонило одну валюту');

$given();
CCurrencyRates::$reject = 'EUR';
$response = Agent::getInstance()->action();
Check::same('ошибка с текстом ядра', $response->getErrorMessages(), ['Error add rate EUR: Rate rejected']);
Check::same('остальные записаны', $response->getData()['written'], ['USD', 'RUB']);

Check::group('курсов на дату нет');

$given();
$response = Agent::getInstance()->sync((new Date())->add('1D'));
Check::same('запрос — на завтра', AConnector::$requests[0]['url'], 'https://services.nbrb.by/XmlExRates.aspx?ondate=09%2F30%2F2026');
Check::same('ошибка, не «всё хорошо»', $response->getErrorMessages(), ['NB RB: no rates on 2026-09-30']);
Check::same('ничего не записано', CurrencyRateTable::$rows, []);

Check::group('НБ РБ не ответил');

$given();
AConnector::$responses = [];
$response = Agent::getInstance()->action();
Check::same('ошибка соединения — ошибка агента', $response->getErrorMessages(), ['status: 0']);

$given();
AConnector::$responses = [AConnector::answer('<html>502 Bad Gateway</html>')];
$response = Agent::getInstance()->action();
Check::same('не курсы — ошибка', $response->getErrorMessages(), ['NB RB: root element is not DailyExRates']);

Check::group('запуск агентом');

$given();
AConnector::$responses = [];
Check::same('агент остаётся в b_agent и при ошибке', Agent::process(), '\Shef\Currency\Sync\Agent::process([]);');
Check::same('ошибка — в журнал, тип «синхронизация», от shef.currency', [
	TestLogger::$records[0]['level'] ?? null,
	TestLogger::$records[0]['auditType'] ?? null,
	TestLogger::$records[0]['moduleId'] ?? null,
	TestLogger::$records[0]['assigned'] ?? null,
], ['critical', 'SH_PROBLEMS_SYNC', 'shef.currency', 5]);

Check::finish();
