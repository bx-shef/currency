<?php declare(strict_types=1);

/**
 * Что модуль запишет сегодня — на живом портале, ничего не записывая.
 *
 * ЦЕЛЬ
 *   Пройти путь агента — базовая валюта, запрос к НБ РБ, курсы портала,
 *   порог и коэффициент из настроек — и показать план записи. Курсы на
 *   портале не меняются.
 *
 * ГДЕ ПРИМЕНЯТЬ
 *   Разобрать «почему курс на портале не тот» или «почему агент ничего не
 *   записал»; проверить, что с сервера портала открыт services.nbrb.by;
 *   посмотреть, что даст новый порог, до того как ждать ночного запуска.
 *
 * ЧТО ДОЛЖНО ПОЛУЧИТЬСЯ
 *   На заглушках: «сегодня» 2026-09-29, банк отвечает сохранённым ответом,
 *   на портале вчерашний доллар 3.02 и порог 1 % — в плане евро и рубль,
 *   доллара нет, ядро не звали. На портале: план на сегодня либо ошибка с
 *   причиной — та же, что агент записал бы в журнал.
 *
 * ЗАПУСК
 *   php examples/preview.php                                # заглушки
 *   DOCUMENT_ROOT=/var/www/portal php examples/preview.php  # живой Битрикс
 */

require __DIR__.'/_bootstrap.php';

use Bitrix\Main\Config\Option;
use Bitrix\Main\Type\Date;
use Shef\Currency\Main\Constants;
use Shef\Currency\Sync\Agent;

title('Что модуль запишет сегодня');

if('заглушки' === $exampleMode)
{
	Option::set('shef.currency', 'DEF_sizeChange', '1');
}

step('1. Настройки');

note('порог, %: '.Constants::getSizeChange());
note('коэффициент: '.Constants::getFactor());

step('2. План на сегодня');

$response = Agent::getInstance()->preview(new Date());

if(!$response->isSuccess())
{
	note('не записал бы ничего: '.implode('; ', $response->getErrorMessages()));
	check('на заглушках ошибки нет', 'портал' === $exampleMode, true);
	done('preview');
}

$data = $response->getData();
note(sprintf('дата %s, курсов у банка: %d', $data['date'], count($data['rates'])));

foreach($data['plan'] as $row)
{
	note(sprintf(
		'%s %s: %s за %d',
		$row['ID'] > 0 ? 'обновить' : 'добавить',
		$row['CURRENCY'],
		$row['RATE'],
		$row['RATE_CNT']
	));
}

if([] === $data['plan'])
{
	note('писать нечего: валют портала нет в ответе банка или всё отсеял порог');
}

if('заглушки' === $exampleMode)
{
	check('доллар отсеял порог', array_column($data['plan'], 'CURRENCY'), ['EUR', 'RUB']);
	check('ядро не звали', \CCurrencyRates::$calls, []);
}

done('preview');
