<?php declare(strict_types=1);

namespace Shef\Currency\Sync;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\Result;
use Bitrix\Main\Error;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;
use Bitrix\Currency;
use Shef\Options\Main\Utils;
use Shef\InSync\Agents;
use Shef\Currency\Main\Constants;
use Shef\Currency\Main\Rates;

/*/
//title: Shef\Currency\Sync\Agent
	\Bitrix\Main\Loader::includeModule('shef.currency');

	$response = \Shef\Currency\Sync\Agent::process(['debug' => 'Y']);
	var_dump($response);
//*/

/**
 * Агент курсов НБ РБ: раз в сутки пишет в Б24 курсы на сегодня.
 *
 * Обвязка агента — AAgent из shef.insync: подключение модулей, работа от
 * служебного пользователя, запись сбоя проблемой. Решение, какой курс
 * писать, — Main\Rates, без ядра.
 *
 * Строка агента — \Shef\Currency\Sync\Agent::process([]); — та же, что в
 * 1.x: агент, поставленный на портале до 2.0.0, находится по имени.
 */
class Agent
	extends Agents\AAgent
{
	protected static null|Agents\Entity $agentEntity = null;

	/**
	 * Первый запуск — в 00:20: курсы на новый день НБ РБ устанавливает
	 * накануне, к полуночи они уже есть.
	 */
	public const START_HOUR = 0;
	public const START_MINUTE = 20;

	public static function getModuleId(): string
	{
		return Constants::getModuleId();
	}

	public static function getAssignedId(): int
	{
		return \Shef\Problems\Main\Constants::getSyncUserId();
	}

	// region Process ////
	/**
	 * Сущность агента; нет агента в b_agent — ставит его выключенным.
	 * Включают на странице настроек модуля.
	 *
	 * Аргументы Entity — по именам: до 2.0.0 id пользователя шёл шестым
	 * позиционным и попадал в сортировку агента.
	 *
	 * @throws ObjectException
	 */
	public static function buildAgentsEntity(): Agents\Entity
	{
		if(null === static::$agentEntity)
		{
			static::$agentEntity = new Agents\Entity(
				module: Constants::getModuleId(),
				name: static::getClassName().'::process',
				params: [],
				isPeriodic: true,
				period: 86400,
				userId: static::getContext()->getUserId(),
			);
		}

		if(static::$agentEntity->getId() < 1)
		{
			$date = new DateTime();
			$date->setTime(static::START_HOUR, static::START_MINUTE);

			Agents\Manager::install(static::$agentEntity, $date);
		}

		return static::$agentEntity;
	}
	// endregion ////

	// region Modules ////
	protected static function getModulesList(): array
	{
		return array_merge(
			parent::getModulesList(),
			[
				'currency',
			]
		);
	}
	// endregion ////

	// region Work /////
	/**
	 * Курсы на сегодня.
	 */
	public function action(): Result
	{
		return $this->sync(new Date());
	}

	/**
	 * Что будет записано на дату — без записи.
	 *
	 * Тот же путь, что у sync(): базовая валюта, запрос к НБ РБ, курсы
	 * портала, Rates::plan(). Ничего не пишет — для разбора «почему курс не
	 * тот» на живом портале. В данных — date, rates (ответ НБ РБ по коду
	 * валюты) и plan (Rates::plan()).
	 *
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function preview(Date $date): Result
	{
		$result = new Result();
		
		$response = \Shef\Currency\Main\Utils::checkBaseCurrency();
		if(!$response->isSuccess())
		{
			return $result->addErrors($response->getErrors());
		}
		
		$api = new Api();
		$api->setIsDebug($this->isDebug());
		
		$response = $api->getRates($date);
		unset($api);
		if(!$response->isSuccess())
		{
			return $result->addErrors($response->getErrors());
		}
		
		$rates = (array)$response->getData()['list'];
		$currencies = static::getCurrencyList();
		
		return $result->setData([
			'date' => $date->format('Y-m-d'),
			'rates' => $rates,
			'plan' => Rates::plan(
				$rates,
				$currencies,
				static::getRatesOnDate($currencies, $date),
				static::getLastRatesBefore($currencies, $date),
				Constants::getFactor(),
				Constants::getSizeChange(),
				Constants::getModuleBaseCurrency()
			),
		]);
	}
	
	/**
	 * Курсы НБ РБ на дату — в Б24.
	 *
	 * Зовут агент (на сегодня) и кнопка на странице курсов (на сегодня или
	 * на завтра). Что писать — решает preview(). В данных — date и written:
	 * коды записанных валют.
	 *
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function sync(Date $date): Result
	{
		$result = new Result();
		
		$response = $this->preview($date);
		if(!$response->isSuccess())
		{
			return $result->addErrors($response->getErrors());
		}
		
		$plan = $response->getData()['plan'];
		
		$written = [];
		foreach($plan as $row)
		{
			$fields = [
				'CURRENCY' => $row['CURRENCY'],
				'RATE' => $row['RATE'],
				'RATE_CNT' => $row['RATE_CNT'],
				// В формате сайта: ядро сверяет дату с ним. До 2.0.0 здесь
				// стоял d.m.Y — на сайте с другим форматом курс не писался.
				'DATE_RATE' => $date->toString(),
			];

			$isSuccess = $row['ID'] > 0
				? \CCurrencyRates::Update($row['ID'], $fields)
				: \CCurrencyRates::Add($fields);

			if(!$isSuccess)
			{
				$result->addError(new Error(sprintf(
					'Error %s rate %s: %s',
					$row['ID'] > 0 ? 'update' : 'add',
					$row['CURRENCY'],
					static::getLastErrorMessage()
				)));
				continue;
			}

			$written[] = $row['CURRENCY'];

			if($this->isDebug())
			{
				$this->debugger->debug('change '.$row['CURRENCY'], [
					'fields' => $fields,
				]);
			}
		}

		$result->setData([
			'date' => $date->format('Y-m-d'),
			'written' => $written,
		]);

		if($this->isDebug())
		{
			$this->debugger->debug($result);
		}

		return $result;
	}

	/**
	 * Текст ошибки ядра: CCurrencyRates кладёт её в исключение приложения.
	 * До 2.0.0 читался LAST_ERROR, и текст всегда был пуст.
	 */
	private static function getLastErrorMessage(): string
	{
		$exception = Utils::getCMainApplication()?->GetException();

		return $exception instanceof \CApplicationException
			? (string)$exception->GetString()
			: 'unknown error';
	}

	/**
	 * Валюты портала.
	 *
	 * @return string[]
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private static function getCurrencyList(): array
	{
		return array_column(
			Currency\CurrencyTable::getList([
				'order' => [
					'SORT' => 'ASC'
				],
				'select' => [
					'CURRENCY',
				]
			])->fetchAll(),
			'CURRENCY'
		);
	}

	/**
	 * Курсы портала на дату — по коду валюты.
	 *
	 * @param string[] $currencies
	 * @return array<string, array{ID: int, RATE: float, RATE_CNT: int}>
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private static function getRatesOnDate(array $currencies, Date $date): array
	{
		if(empty($currencies))
		{
			return [];
		}

		$list = [];
		$cursor = Currency\CurrencyRateTable::getList([
			'select' => [
				'ID',
				'CURRENCY',
				'RATE',
				'RATE_CNT',
			],
			'filter' => [
				'@CURRENCY' => $currencies,
				'=DATE_RATE' => $date,
			],
			'order' => [
				'ID' => 'ASC',
			],
		]);
		while($row = $cursor->fetch())
		{
			$list[(string)$row['CURRENCY']] ??= [
				'ID' => (int)$row['ID'],
				'RATE' => (float)$row['RATE'],
				'RATE_CNT' => (int)$row['RATE_CNT'],
			];
		}

		return $list;
	}

	/**
	 * Последние курсы портала до даты — по коду валюты. Их порог колебания
	 * считает действующими, пока на дату курса нет.
	 *
	 * Запрос на валюту, а не один на все: таблица курсов растёт годами, а
	 * валют на портале единицы.
	 *
	 * @param string[] $currencies
	 * @return array<string, array{RATE: float, RATE_CNT: int}>
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private static function getLastRatesBefore(array $currencies, Date $date): array
	{
		$list = [];
		foreach($currencies as $currency)
		{
			$row = Currency\CurrencyRateTable::getList([
				'select' => [
					'RATE',
					'RATE_CNT',
				],
				'filter' => [
					'=CURRENCY' => $currency,
					'<DATE_RATE' => $date,
				],
				'order' => [
					'DATE_RATE' => 'DESC',
					'ID' => 'DESC',
				],
				'limit' => 1,
			])->fetch();

			if(is_array($row))
			{
				$list[(string)$currency] = [
					'RATE' => (float)$row['RATE'],
					'RATE_CNT' => (int)$row['RATE_CNT'],
				];
			}
		}

		return $list;
	}
	// endregion ////
}
