<?php declare(strict_types=1);

namespace Shef\Currency\Main;

use Bitrix\Main\Config\Option;

class Constants
{
	public const MODULE_ID = 'shef.currency';
	
	/**
	 * Курсы НБ РБ — к белорусскому рублю. Модуль пишет их как есть, поэтому
	 * базовой валютой портала обязан быть BYN.
	 */
	public const BASE_CURRENCY = 'BYN';
	
	public const DEFAULT_SIZE_CHANGE = 0;
	
	/**
	 * Потолок порога, % — тот же, что у поля на странице настроек.
	 */
	public const MAX_SIZE_CHANGE = 100;
	public const DEFAULT_FACTOR = 1.0;
	
	/**
	 * Потолок коэффициента — тот же, что у поля на странице настроек.
	 */
	public const MAX_FACTOR = 400.0;
	
	public static function getModuleId(): string
	{
		return static::MODULE_ID;
	}
	
	public static function getModuleBaseCurrency(): string
	{
		return static::BASE_CURRENCY;
	}
	
	/**
	 * Порог колебания курса, %: при меньшем изменении курс не пишется.
	 */
	public static function getSizeChange(): int
	{
		return static::parseSizeChange(
			Option::get(static::getModuleId(), 'DEF_sizeChange', (string)static::DEFAULT_SIZE_CHANGE)
		);
	}
	
	/**
	 * Коэффициент к курсу НБ РБ.
	 */
	public static function getFactor(): float
	{
		return static::parseFactor(
			Option::get(static::getModuleId(), 'DEF_factor', (string)static::DEFAULT_FACTOR)
		);
	}
	
	/**
	 * Строгий разбор порога. Целое от нуля до MAX_SIZE_CHANGE, всё
	 * остальное — умолчание.
	 *
	 * Не (int): (int)'5 %' даёт 5, (int)'-3' — отрицательный порог, при
	 * котором курс пишется всегда, как и при нуле, но молча.
	 */
	public static function parseSizeChange(mixed $value): int
	{
		if(is_string($value) && 1 === preg_match('/^(0|[1-9][0-9]*)$/', trim($value)))
		{
			$value = (int)trim($value);
		}
		
		if(is_int($value) && $value >= 0 && $value <= static::MAX_SIZE_CHANGE)
		{
			return $value;
		}
		
		return static::DEFAULT_SIZE_CHANGE;
	}
	
	/**
	 * Строгий разбор коэффициента: число больше нуля и не больше
	 * MAX_FACTOR, дробная часть — через точку или запятую. Всё остальное —
	 * умолчание.
	 *
	 * До 2.0.0 здесь стоял (float): сохранённая пустая строка давала 0, и
	 * агент записывал ВСЕ курсы нулями.
	 */
	public static function parseFactor(mixed $value): float
	{
		if(is_int($value) || is_float($value))
		{
			$value = (string)$value;
		}
		
		if(!is_string($value))
		{
			return static::DEFAULT_FACTOR;
		}
		
		$value = str_replace(',', '.', trim($value));
		if(1 !== preg_match('/^[0-9]+(\.[0-9]+)?$/', $value))
		{
			return static::DEFAULT_FACTOR;
		}
		
		$factor = (float)$value;
		if($factor <= 0 || $factor > static::MAX_FACTOR)
		{
			return static::DEFAULT_FACTOR;
		}
		
		return $factor;
	}
}
