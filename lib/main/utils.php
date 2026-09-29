<?php declare(strict_types=1);

namespace Shef\Currency\Main;

use Bitrix\Main\Result;
use Bitrix\Main\Error;
use Bitrix\Currency;

class Utils
{
	public static function checkBaseCurrency(): Result
	{
		$result = new Result();
		$baseCurrency = (string)Currency\CurrencyManager::getBaseCurrency();
		$moduleCurrency = Constants::getModuleBaseCurrency();
		
		$result->setData([
			'baseCurrency' => $baseCurrency,
			'moduleCurrency' => $moduleCurrency
		]);
		
		if($baseCurrency !== $moduleCurrency)
		{
			return $result->addError(new Error(sprintf(
				'module.currency %s !== b24.currency %s',
				$moduleCurrency,
				$baseCurrency,
			)));
		}
		
		return $result;
	}
}