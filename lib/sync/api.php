<?php declare(strict_types=1);

namespace Shef\Currency\Sync;

use Bitrix\Main\ArgumentNullException;
use Bitrix\Main\InvalidOperationException;
use Bitrix\Main\Result;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\HttpClient;
use Shef\Problems;
use Shef\InSync\Api\AConnector;
use Shef\InSync\TraitList;
use Shef\Currency\Main\Constants;

Loc::loadMessages(__FILE__);

class Api
	extends AConnector
{
	use TraitList\Xml\ToArray;
	
	private const Url = 'https://services.nbrb.by';
	
	public static function getModuleId(): string
	{
		return Constants::getModuleId();
	}
	
	public static function getAssignedId(): int
	{
		return Problems\Main\Constants::getSyncUserId();
	}
	
	// region Options ////
	/**
	 * @inheritDoc
	 */
	protected function getPath(string $functionName): string
	{
		return static::Url.$functionName;
	}
	
	/**
	 * @inheritDoc
	 */
	public static function getEncodingFrom(): string
	{
		return 'utf-8';
	}
	// endregion /////
	
	// region Actions /////
	/**
	 * @throws ArgumentNullException
	 * @throws InvalidOperationException
	 */
	public function getRates(\Bitrix\Main\Type\Date $date): Result
	{
		$result = new Result();
		
		$conf = [
			'ondate' => $date->format('m/d/Y'),
		];
		
		$response = $this->sendRequest('/XmlExRates.aspx', $conf, HttpClient::HTTP_GET);
		if(!$response->isSuccess())
		{
			$result->addErrors($response->getErrors());
		}
		
		$list = [];
		$xml = $response->getData()['data']['response'];
		if($xml <> '')
		{
			$list = static::ToArrayConvert($xml, 'DailyExRates');
		}
		unset($xml);
		
		$list = array_filter($list, function($row) use ($conf) {
			return $row['a']['Date'] === $conf['ondate'];
		});
		
		$dailyExRates = reset($list);
		if(!is_array($dailyExRates))
		{
			$listRates = [];
		}
		else
		{
			$listRates = $dailyExRates['s'];
		}
		
		if(!is_array($listRates))
		{
			$listRates = [];
		}
		unset($list, $dailyExRates);
		
		return $result->setData(['list' => $listRates]);
	}
	
	// endregion /////
}