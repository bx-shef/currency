<?php declare(strict_types=1);

namespace Shef\Currency\Sync;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\ArgumentNullException;
use Bitrix\Main\InvalidOperationException;
use Bitrix\Main\ObjectException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\Result;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\SystemException;
use Bitrix\Currency;
use Shef\Options\Main\Utils;
use Shef\Options\Options\SmartStd;
use Shef\InSync\Agents;
use Shef\Currency\Main\Constants;

Loc::loadMessages(__FILE__);

/*/
//title: Shef\Currency\Sync\Agent
	\Bitrix\Main\Loader::includeModule('shef.currency');

	$response = \Shef\Currency\Sync\Agent::process(['debug' => 'Y']);
	\Shef\Problems\Logger::PrHtml->getLogger()->debug($response);
//*/
	
class Agent
	extends Agents\AAgent
{
	protected static null|Agents\Entity $agentEntity = null;
	
	private array $rates = [];
	private array $b24Rates = [];
	private array $b24Currency = [];
	private null|\Bitrix\Main\Type\Date $syncDate = null;
	
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
	 * Создание сущности агента
	 *
	 * @throws ObjectException
	 */
	public static function buildAgentsEntity(): Agents\Entity
	{
		if(null === static::$agentEntity)
		{
			static::$agentEntity = new Agents\Entity(
				Constants::MODULE_ID,
				static::getClassName().'::process',
				[],
				true,
				86400,
				static::getContext()->getUserId()
			);
		}
		
		if(static::$agentEntity->getId() < 1)
		{
			$date = new \Bitrix\Main\Type\DateTime();
			$date->setTime(0, 20, 0);
			
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
	 * Используется для дополнительной инициализации объекта агента
	 *
	 * @throws ArgumentNullException
	 * @throws InvalidOperationException
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	protected function init(): void
	{
		$this->b24Rates = [];
		$this->b24Currency = [];
		
		$this->initCurrent();
	}
	
	
	/**
	 * Обработка курсов из НБРБ и внесение в Б24
	 *
	 * @throws ArgumentNullException
	 * @throws InvalidOperationException
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function action(): Result
	{
		$result = new Result();
		
		$response = \Shef\Currency\Main\Utils::checkBaseCurrency();
		if(!$response->isSuccess())
		{
			if($this->isDebug())
			{
				$this->debugger->debug($response);
			}
			return $result->addErrors($response->getErrors());
		}
		
		$this->syncDate = new \Bitrix\Main\Type\Date();
		
		if($this->getParams()->get('mode') === Mode::Tomorrow)
		{
			$this->syncDate->add('1D');
		}
		
		$response = $this->getRates();
		if(!$response->isSuccess())
		{
			return $result->addErrors($response->getErrors());
		}

		$this->prepareListRates((array)$response->getData()['list']);
		
		$params = new SmartStd();
		$params->factor = Constants::getFactor();
		$params->sizeChange = Constants::getSizeChange();

		foreach($this->b24Currency as $currency)
		{
			if(!isset($this->rates[$currency]))
			{
				continue;
			}
			
			// region Test Change Rate by proc ////
			if(
				(
					abs($this->rates[$currency]['RATE'] - $this->rates[$currency]['B24']['RATE'])
					/ $this->rates[$currency]['RATE']
				) * 100
				< $params->sizeChange)
			{
				continue;
			}
			// endregion ////
			
			$conf = [
				'RATE' => $this->rates[$currency]['RATE'] * $params->factor,
				'RATE_CNT' => $this->rates[$currency]['SCALE'],
				'CURRENCY' => $currency,
				'DATE_RATE' => $this->syncDate->format('d.m.Y')
			];
			
			if((int)$this->rates[$currency]['B24']['ID'] > 0)
			{
				if(!\CCurrencyRates::Update($this->rates[$currency]['B24']['ID'], $conf))
				{
					$result->addError(new Error(sprintf(
						'Error Update Rate %s: %s',
						$currency,
						Utils::getCMainApplication()->LAST_ERROR
					)));
				}
			}
			else
			{
				if(!\CCurrencyRates::Add($conf))
				{
					$result->addError(new Error(sprintf(
						'Error Add Rate %s: %s',
						$currency,
						Utils::getCMainApplication()->LAST_ERROR
					)));
				}
			}
			
			if($this->isDebug())
			{
				$this->debugger->debug('change '.$currency, [
					'conf' => $conf
				]);
			}
		}
		
		if($this->isDebug())
		{
			$this->debugger->debug($result);
		}
		
		return $result;
	}
	
	/**
	 * Получение курсов из НБРБ
	 *
	 * @throws ArgumentNullException
	 * @throws InvalidOperationException
	 */
	private function getRates(): Result
	{
		$result = new Result();
		
		$api = new Api();
		/*/
		$api->setIsDebug(true);
		$api->addOptionCollection('socketTimeout', 10);
		$api->addOptionCollection('streamTimeout', 10);
		$api->addOptionCollection('waitResponse', 10);
		$api->reInitHttpParams();
		//*/
		
		$response = $api->getRates($this->syncDate);
		unset($api);
		
		if(!$response->isSuccess())
		{
			return $result->addErrors($response->getErrors());
		}
		
		return $result->setData($response->getData());
	}
	
	/**
	 * Разбор пришедших курсов
	 *
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws ArgumentException
	 */
	private function prepareListRates(array $listRates): void
	{
		$this->initCurrentRates();
		
		$this->rates = array_map(function(array $row)
		{
			$row['s'] = array_combine(
				array_column($row['s'], 'n'),
				array_column($row['s'], 'v')
			);
			
			$currency = $row['s']['CharCode'];
			
			return [
				'NB_RB' => (int)$row['a']['Id'],
				'CODE' => $currency,
				'TITLE' => $row['s']['Name'],
				'NUM_CODE' => $row['s']['NumCode'],
				'SCALE' => (int)$row['s']['Scale'],
				'RATE' => (float)$row['s']['Rate'],
				'B24' => (
					isset($this->b24Rates[$currency])
					? [
						'ID' => (int)$this->b24Rates[$currency]['ID'],
						'SCALE' => (int)$this->b24Rates[$currency]['RATE_CNT'],
						'RATE' => (float)$this->b24Rates[$currency]['RATE']
					]
					: null
				)
			];
		}, $listRates);
		
		$this->rates = array_combine(
			array_column($this->rates, 'CODE'),
			$this->rates
		);
	}
	
	/**
	 * Устанавливает текущие курсы по Б24
	 * 
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws ArgumentException
	 */
	private function initCurrentRates(): void
	{
		$this->b24Rates = Currency\CurrencyRateTable::getList([
			'order' => [
				'DATE_RATE' => 'DESC'
			],
			'select' => [
				'ID',
				'CURRENCY',
				'BASE_CURRENCY',
				'DATE_RATE',
				'RATE',
				'RATE_CNT',
			],
			'filter' => [
				'DATE_RATE' => $this->syncDate
			]
		])->fetchAll();
		
		$this->b24Rates = array_combine(
			array_column($this->b24Rates, 'CURRENCY'),
			$this->b24Rates
		);
	}
	
	/**
	 * Получает список валют Б24
	 * 
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws ArgumentException
	 */
	private function initCurrent(): void
	{
		$this->b24Currency = Currency\CurrencyTable::getList([
			'order' => [
				'SORT' => 'ASC'
			],
			'select' => [
				'CURRENCY',
			]
		])->fetchAll();
		$this->b24Currency = array_column($this->b24Currency, 'CURRENCY');
	}
	// endregion ////
}