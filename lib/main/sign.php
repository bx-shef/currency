<?php declare(strict_types=1);

namespace Shef\Currency\Main;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Обозначение белорусского рубля в Битрикс24 (2.1.0): знак НБРБ, «Br» или «BYN».
 *
 * Знак утверждён НБРБ (постановление № 25 от 27.01.2026), кода в Unicode у него нет — его рисует
 * шрифт `nbrb` лигатурой: текст «BYN» в этом шрифте становится знаком. Поэтому «знак» — это
 * формат валюты `# <span class="nbrb-icon">BYN</span>` плюс стили со шрифтом на каждой странице
 * (onPrologHandler). Где Битрикс24 выводит формат текстом или без стилей (выгрузки, письма,
 * уведомления), остаётся «BYN», а не пустой квадрат. Печатные формы — шаблоном с картинкой знака
 * (install/docgen, docs/byn-sign.md).
 *
 * Формат пишется в CCurrencyLang валюты BYN для каждого активного языка — при установке и со
 * страницы настроек; прежний сохраняется (SYS_prev_<язык>) и возвращается при удалении модуля.
 * Правило — чистые функции parseMode(), formatString(), plan(); ядро — только apply()/restore().
 */
final class Sign
{
	public const OPTION = 'DEF_sign';
	public const MODE_SIGN = 'sign';
	public const MODE_BR = 'br';
	public const MODE_CODE = 'code';
	public const MODE_KEEP = 'keep';
	public const DEFAULT_MODE = self::MODE_SIGN;
	public const CSS = '/bitrix/css/shef.currency/byn.css';
	public const PREV_PREFIX = 'SYS_prev_';
	public const CLASS_NAME = 'nbrb-icon';

	/** Строгий разбор настройки: неизвестное и пустое — умолчание. */
	public static function parseMode(mixed $value): string
	{
		$value = is_string($value) ? trim($value) : '';

		return in_array($value, [self::MODE_SIGN, self::MODE_BR, self::MODE_CODE, self::MODE_KEEP], true) ? $value : self::DEFAULT_MODE;
	}

	public static function mode(): string
	{
		return static::parseMode(Option::get(Constants::MODULE_ID, self::OPTION, self::DEFAULT_MODE));
	}

	/** FORMAT_STRING валюты BYN для режима; «не менять» — null. */
	public static function formatString(string $mode): ?string
	{
		return match ($mode)
		{
			self::MODE_SIGN => '# <span class="'.self::CLASS_NAME.'">BYN</span>',
			self::MODE_BR => '# Br',
			self::MODE_CODE => '# BYN',
			default => null,
		};
	}

	/** Наш ли это формат (любой из режимов): такой не сохраняем как «прежний». */
	public static function isOwn(string $format): bool
	{
		return in_array($format, array_filter([self::formatString(self::MODE_SIGN), self::formatString(self::MODE_BR), self::formatString(self::MODE_CODE)]), true);
	}

	/**
	 * Что записать: по каждому языку — новый формат и прежний, который надо запомнить.
	 *
	 * @param array<string, ?string> $current язык => текущий FORMAT_STRING (null — локализации нет)
	 * @param array<string, string> $saved язык => уже сохранённый прежний
	 * @return array<string, array{format: string, add: bool, keep: ?string}> только языки, где есть что менять
	 */
	public static function plan(string $mode, array $current, array $saved): array
	{
		$format = static::formatString($mode);
		if($format === null)
		{
			return [];
		}
		$plan = [];
		foreach($current as $lang => $was)
		{
			if($was === $format)
			{
				continue;
			}
			$keep = ($was !== null && !isset($saved[$lang]) && !static::isOwn($was)) ? $was : null;
			$plan[$lang] = ['format' => $format, 'add' => $was === null, 'keep' => $keep];
		}

		return $plan;
	}

	/** Записать формат BYN по настройке (или по $mode). Повторный вызов без изменений ничего не пишет. */
	public static function apply(?string $mode = null): Result
	{
		$result = new Result();
		$mode = $mode === null ? static::mode() : static::parseMode($mode);
		if(!static::ready())
		{
			return $result->addError(new Error('Модуль «Валюты» или валюта BYN недоступны'));
		}
		$current = [];
		$saved = [];
		foreach(static::languages() as $lang)
		{
			$row = \CCurrencyLang::GetByID(Constants::BASE_CURRENCY, $lang);
			$current[$lang] = is_array($row) ? (string)$row['FORMAT_STRING'] : null;
			$prev = Option::get(Constants::MODULE_ID, self::PREV_PREFIX.$lang, '');
			if($prev !== '')
			{
				$saved[$lang] = $prev;
			}
		}
		foreach(static::plan($mode, $current, $saved) as $lang => $step)
		{
			if($step['keep'] !== null)
			{
				Option::set(Constants::MODULE_ID, self::PREV_PREFIX.$lang, $step['keep']);
			}
			$ok = $step['add']
				? \CCurrencyLang::Add(['CURRENCY' => Constants::BASE_CURRENCY, 'LID' => $lang, 'FORMAT_STRING' => $step['format'], 'FULL_NAME' => $lang === 'ru' ? 'Белорусский рубль' : 'Belarusian ruble', 'DEC_POINT' => ',', 'THOUSANDS_VARIANT' => 'B', 'DECIMALS' => 2, 'HIDE_ZERO' => 'Y'])
				: \CCurrencyLang::Update(Constants::BASE_CURRENCY, $lang, ['FORMAT_STRING' => $step['format']]);
			if(!$ok)
			{
				$result->addError(new Error('Формат BYN не записан для языка '.$lang));
			}
		}
		static::clearCache();

		return $result;
	}

	/** Удаление модуля: вернуть сохранённые прежние форматы; где сохранять было нечего — оставить «# BYN». */
	public static function restore(): void
	{
		if(!static::ready())
		{
			return;
		}
		foreach(static::languages() as $lang)
		{
			$row = \CCurrencyLang::GetByID(Constants::BASE_CURRENCY, $lang);
			if(!is_array($row) || !static::isOwn((string)$row['FORMAT_STRING']))
			{
				continue;
			}
			$prev = Option::get(Constants::MODULE_ID, self::PREV_PREFIX.$lang, '');
			// Знак без стилей модуля — HTML-обёртка вокруг «BYN»: без модуля возвращаем текст
			$back = $prev !== '' ? $prev : (string)static::formatString(self::MODE_CODE);
			\CCurrencyLang::Update(Constants::BASE_CURRENCY, $lang, ['FORMAT_STRING' => $back]);
		}
		static::clearCache();
	}

	/** main:OnProlog — стили со шрифтом знака на каждой странице, если выбран «знак». */
	public static function onPrologHandler(): void
	{
		try
		{
			if(static::mode() === self::MODE_SIGN && class_exists(\Bitrix\Main\Page\Asset::class))
			{
				\Bitrix\Main\Page\Asset::getInstance()->addCss(self::CSS);
			}
		}
		catch(\Throwable)
		{
			// Страница важнее знака: без стилей останется «BYN»
		}
	}

	private static function ready(): bool
	{
		return \Bitrix\Main\Loader::includeModule('currency') && class_exists(\CCurrency::class) && (bool)\CCurrency::GetByID(Constants::BASE_CURRENCY);
	}

	/** @return list<string> активные языки портала */
	private static function languages(): array
	{
		$list = [];
		$rows = \Bitrix\Main\Localization\LanguageTable::getList(['select' => ['LID'], 'filter' => ['=ACTIVE' => 'Y']]);
		while($row = $rows->fetch())
		{
			$list[] = (string)$row['LID'];
		}

		return $list ?: ['ru'];
	}

	private static function clearCache(): void
	{
		if(method_exists(\Bitrix\Currency\CurrencyManager::class, 'clearCurrencyCache'))
		{
			\Bitrix\Currency\CurrencyManager::clearCurrencyCache();
		}
	}
}
