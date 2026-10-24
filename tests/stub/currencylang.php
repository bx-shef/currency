<?php

/**
 * Заглушки для знака BYN (Main\Sign): валюта BYN (CCurrency), её локализации с форматом
 * (CCurrencyLang — в памяти, записи считаются), языки портала (LanguageTable), журнал событий
 * (CEventLog). Сигнатуры — как у ядра; логики — ровно сколько нужно тестам.
 */

namespace
{
	if(!class_exists('CCurrency'))
	{
		class CCurrency
		{
			public static bool $hasByn = true;

			public static function GetByID(string $id): array|false
			{
				return $id === 'BYN' && static::$hasByn ? ['CURRENCY' => 'BYN'] : false;
			}
		}
	}

	if(!class_exists('CCurrencyLang'))
	{
		class CCurrencyLang
		{
			/** @var array<string, array<string, mixed>> язык => поля */
			public static array $rows = [];
			public static int $writes = 0;

			public static function GetByID(string $currency, string $lang): array|false
			{
				return static::$rows[$lang] ?? false;
			}

			public static function Update(string $currency, string $lang, array $fields): bool
			{
				static::$writes++;
				static::$rows[$lang] = $fields + (static::$rows[$lang] ?? []);
				return true;
			}

			public static function Add(array $fields): bool
			{
				static::$writes++;
				static::$rows[$fields['LID']] = $fields;
				return true;
			}
		}
	}

	if(!class_exists('CEventLog'))
	{
		class CEventLog
		{
			/** @var list<array> */
			public static array $added = [];

			public static function Add(array $fields): int
			{
				static::$added[] = $fields;
				return count(static::$added);
			}
		}
	}
}

namespace Bitrix\Main\Localization
{
	if(!class_exists(LanguageTable::class))
	{
		class LanguageTable
		{
			/** @var string[] */
			public static array $langs = ['ru', 'en'];

			public static function getList(array $params): object
			{
				return new class(static::$langs)
				{
					public function __construct(private array $langs) {}

					public function fetch(): array|false
					{
						$l = array_shift($this->langs);
						return $l === null ? false : ['LID' => $l];
					}
				};
			}
		}
	}
}
