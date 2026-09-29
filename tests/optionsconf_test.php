<?php declare(strict_types=1);

/**
 * Страница настроек: options_conf.php собирается против API shef.options 3.x.
 *
 * Что держит:
 *
 * * options_conf.php зовёт ShOptionsConfig так, как его понимает shef.options
 *   3.x. В 1.x он передавал indexDoc — параметр ушёл в 3.0.0, и страница
 *   настроек падала «Unknown named parameter»;
 * * у каждой подписи есть перевод: setName() и setTitle() принимают строку,
 *   и пропущенный ключ языкового файла — это TypeError;
 * * коды опций — те, что читает Constants: DEF_sizeChange и DEF_factor;
 *   границы полей — те, что принимает строгий разбор;
 * * строка агента есть, и агент ставится со страницы.
 *
 * API shef.options подменяет tests/stub/options.php, файлы модуля настоящие.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/stub/autoload.php';
require_once $root.'/tests/stub/shef.php';
require_once $root.'/tests/assert.php';

use Bitrix\Main\Localization\Loc;
use Bitrix\Currency\CurrencyManager;
use Shef\Options\Main\Options;
use Shef\InSync\Agents;
use Shef\Currency\Main\Constants;

define('LANGUAGE_ID', 'ru');
Loc::loadLangFile($root.'/lang/ru/options.php');

$GLOBALS['APPLICATION'] = new CMain();

$build = static function() use ($root): array
{
	return require $root.'/options_conf.php';
};

$byCode = static function(Options\Tab $tab): array
{
	$options = [];
	foreach($tab->getOptionList() as $option)
	{
		$options[$option->getCode()] = $option;
	}

	return $options;
};

Check::group('options_conf.php собирается');

$tabs = $build();

Check::same('вернул список вкладок', is_array($tabs), true);
Check::same('одна вкладка — общие', array_map(static fn(Options\Tab $tab): string => $tab->getCode(), $tabs), ['DEF']);
Check::same('у вкладки есть название', $tabs[0]->getName(), 'Общие');

$options = $byCode($tabs[0]);

Check::same('опции по порядку', array_keys($options), ['DEF_TEXT_BASE_CURRENCY', 'sizeChange', 'factor', 'Agent']);

Check::group('базовая валюта');

Check::same('BYN — заметка с валютой', [
	$options['DEF_TEXT_BASE_CURRENCY']->type,
	$options['DEF_TEXT_BASE_CURRENCY']->getDescription(),
], [Options\TypeUIAlert::Note, 'Базовая валюта проекта [B]BYN[/B]']);

CurrencyManager::$baseCurrency = 'RUB';
$other = $byCode($build()[0]);
CurrencyManager::$baseCurrency = 'BYN';
Check::same('не BYN — ошибка с обеими валютами', [
	$other['DEF_TEXT_BASE_CURRENCY']->type,
	str_contains($other['DEF_TEXT_BASE_CURRENCY']->getDescription(), '[B]BYN[/B]')
		&& str_contains($other['DEF_TEXT_BASE_CURRENCY']->getDescription(), '[B]RUB[/B]'),
], [Options\TypeUIAlert::Error, true]);

Check::group('порог и коэффициент');

$sizeChange = $options['sizeChange'];
$factor = $options['factor'];

Check::same('порог — целое', $sizeChange instanceof Options\NumberInt, true);
Check::same('коэффициент — дробное', $factor instanceof Options\NumberFloat, true);
Check::same('подписи есть', [$sizeChange->getTitle() !== '', $factor->getTitle() !== ''], [true, true]);

// Граница поля, которую разбор не принимает, — значение, которое форма
// сохранит, а агент молча заменит умолчанием.
Check::same('границы порога — те, что принимает разбор', [
	Constants::parseSizeChange((string)$sizeChange->min),
	Constants::parseSizeChange((string)$sizeChange->max),
], [$sizeChange->min, $sizeChange->max]);
Check::same('границы коэффициента — те, что принимает разбор', [
	Constants::parseFactor((string)$factor->min),
	Constants::parseFactor((string)$factor->max),
], [$factor->min, $factor->max]);
Check::same('умолчания поля — умолчания разбора', [$sizeChange->getDefValue(), $factor->getDefValue()], ['0', '1']);

// Префикс вкладки + код опции = имя в b_option.
$constants = (string)file_get_contents($root.'/lib/main/constants.php');
Check::same('Constants читает именно их', [str_contains($constants, "'DEF_sizeChange'"), str_contains($constants, "'DEF_factor'")], [true, true]);

Check::group('агент');

$agent = $options['Agent'];
Check::same('строка агента — опция shef.insync', $agent instanceof \Shef\InSync\Main\Options\Agent\Option, true);
Check::same('агент — курсов', $agent->getAgentEntity()->prepareNameForDb(), '\Shef\Currency\Sync\Agent::process([]);');
Check::same('страница поставила агент', count(Agents\Manager::$installed), 1);

Check::finish();
