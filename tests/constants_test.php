<?php declare(strict_types=1);

/**
 * Настройки модуля: строгий разбор порога и коэффициента.
 *
 * Значение опции приходит из формы строкой, и разбирать его приходится
 * модулю. В 1.x стояли (int) и (float): сохранённый пустой коэффициент
 * давал 0, и агент записывал все курсы нулями.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/assert.php';

use Bitrix\Main\Config\Option;
use Shef\Currency\Main\Constants;

Check::group('коэффициент');

foreach([
	['1', 1.0],
	['1.05', 1.05],
	['1,05', 1.05],
	[' 2 ', 2.0],
	['400', 400.0],
	['', 1.0],
	['0', 1.0],
	['0.0', 1.0],
	['-1', 1.0],
	['400.01', 1.0],
	['0.95', 1.0],
	['0.5', 1.0],
	['1e3', 1.0],
	['abc', 1.0],
	['1.05 руб', 1.0],
] as [$value, $expected])
{
	Check::same('«'.$value.'»', Constants::parseFactor($value), $expected);
}

Check::same('не строка — умолчание', Constants::parseFactor(null), 1.0);
Check::same('число как есть', Constants::parseFactor(1.5), 1.5);

Check::group('порог');

foreach([
	['0', 0],
	['5', 5],
	[' 10 ', 10],
	['100', 100],
	['', 0],
	['-3', 0],
	['05', 0],
	['5 %', 0],
	['1.5', 0],
	['101', 0],
	['99999999999999999999', 0],
] as [$value, $expected])
{
	Check::same('«'.$value.'»', Constants::parseSizeChange($value), $expected);
}

Check::group('из настроек модуля');

Check::same('не сохранено — умолчания', [Constants::getFactor(), Constants::getSizeChange()], [1.0, 0]);

Option::set('shef.currency', 'DEF_factor', '');
Option::set('shef.currency', 'DEF_sizeChange', '');
Check::same('сохранено пустым — умолчания, а не ноль', [Constants::getFactor(), Constants::getSizeChange()], [1.0, 0]);

Option::set('shef.currency', 'DEF_factor', '1.02');
Option::set('shef.currency', 'DEF_sizeChange', '3');
Check::same('коды опций — те, что пишет страница настроек', [Constants::getFactor(), Constants::getSizeChange()], [1.02, 3]);

Check::group('умолчания в одном месте');

$shef_currency_default_option = [];
require $root.'/default_option.php';
Check::same('default_option.php — те же умолчания', [
	Constants::parseFactor($shef_currency_default_option['DEF_factor'] ?? null),
	Constants::parseSizeChange($shef_currency_default_option['DEF_sizeChange'] ?? null),
], [Constants::DEFAULT_FACTOR, Constants::DEFAULT_SIZE_CHANGE]);

Check::same('базовая валюта — BYN', Constants::getModuleBaseCurrency(), 'BYN');

Check::finish();
