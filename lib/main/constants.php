<?php declare(strict_types=1);

namespace Shef\Currency\Main;

use Bitrix\Main\Config;

class Constants
{
	public const MODULE_ID = 'shef.currency';
	
	public static function getModuleId(): string
	{
		return static::MODULE_ID;
	}
	
	public static function getSettingsOptions(): array 
	{
		$list = Config\Configuration::getInstance(static::getModuleId())
			->get('options');
		
		if(!is_array($list))
		{
			$list = [];
		}
		
		return $list;
	}
	
	public static function getSizeChange(): int
	{
		return (int)\Bitrix\Main\Config\Option::get(static::getModuleId(), 'DEF_sizeChange', 0);
	}
	
	public static function getFactor(): float
	{
		return (float)\Bitrix\Main\Config\Option::get(static::getModuleId(), 'DEF_factor', 1.0);
	}
	
	public static function getModuleBaseCurrency(): string
	{
		return 'BYN';
	}
}