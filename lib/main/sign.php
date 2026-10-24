<?php declare(strict_types=1);

namespace Shef\Currency\Main;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Обозначение белорусского рубля в Битрикс24 (2.1.0): знак НБРБ, «Br» или «BYN».
 *
 * Знак утверждён НБРБ (постановление № 25 от 27.01.2026), кода в Unicode у него нет — его рисует
 * шрифт `nbrb` лигатурой: текст «BYN» в этом шрифте становится знаком.
 *
 * Формат валюты — всегда текст (`# BYN` или `# Br`): он уходит в выгрузки, REST, письма, печатные
 * формы и мобильное приложение, и HTML там экранируется или ломает данные (правила знака,
 * references/bitrix24.md). Знак рисуется только на страницах портала: onPrologHandler подключает
 * стили со шрифтом и скрипт, который оборачивает «BYN» после суммы в `<span class="nbrb-icon">`.
 * Печатные формы — шаблоном с картинкой знака (install/docgen, docs/byn-sign.md).
 *
 * Формат пишется в CCurrencyLang валюты BYN для каждого активного языка: при установке (знак) и
 * когда администратор сменил настройку (SYS_applied — последний применённый режим; просмотр
 * страницы настроек без смены ничего не пишет). Прежний формат запоминается при первой записи
 * (SYS_prev_<язык>) и возвращается при удалении — только если формат всё ещё наш.
 * Обновление заменой файлов установщик не запускает: обработчик и файлы ставит ensureInstalled()
 * со страницы настроек. Умолчание — «не менять»: у портала, где модуль обновили, ничего не меняется,
 * пока администратор не выберет обозначение.
 */
final class Sign
{
	public const OPTION = 'DEF_sign';
	public const MODE_SIGN = 'sign';
	public const MODE_BR = 'br';
	public const MODE_CODE = 'code';
	public const MODE_KEEP = 'keep';
	/** Умолчание обновлённого портала; новая установка ставит MODE_SIGN явно (InstallDB). */
	public const DEFAULT_MODE = self::MODE_KEEP;
	public const INSTALL_MODE = self::MODE_SIGN;
	public const CSS = '/bitrix/css/shef.currency/byn.css';
	public const JS = '/bitrix/js/shef.currency/byn.js';
	public const PREV_PREFIX = 'SYS_prev_';
	public const APPLIED = 'SYS_applied';
	public const CLASS_NAME = 'nbrb-icon';
	/** «Прежнего не было» (локализацию добавил модуль): удаление её не трогает. */
	public const PREV_NONE = '-';

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

	/** FORMAT_STRING валюты BYN для режима — всегда текст; «не менять» — null. */
	public static function formatString(string $mode): ?string
	{
		return match ($mode)
		{
			self::MODE_SIGN, self::MODE_CODE => '# BYN',
			self::MODE_BR => '# Br',
			default => null,
		};
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
			// Прежний — при первой записи, какой бы он ни был (и «# Br» клиента тоже); локализации не
			// было — помечаем, чтобы следующая запись не приняла формат модуля за прежний
			$keep = isset($saved[$lang]) ? null : ($was ?? self::PREV_NONE);
			$plan[$lang] = ['format' => $format, 'add' => $was === null, 'keep' => $keep];
		}

		return $plan;
	}

	/** Нужно ли писать: режим сменился с последнего применения (или запись принудительная). */
	public static function needApply(string $mode, string $applied, bool $force = false): bool
	{
		return $mode !== self::MODE_KEEP && ($force || $mode !== $applied);
	}

	/**
	 * Записать формат BYN по настройке. Без смены режима с прошлого раза — ничего (просмотр
	 * страницы настроек не переписывает формат, поменянный руками); $force — установка модуля.
	 */
	public static function apply(bool $force = false): Result
	{
		$result = new Result();
		$mode = static::mode();
		if(!static::needApply($mode, (string)Option::get(Constants::MODULE_ID, self::APPLIED, ''), $force))
		{
			return $result;
		}
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
		if($result->isSuccess())
		{
			Option::set(Constants::MODULE_ID, self::APPLIED, $mode);
		}
		static::clearCache();

		return $result;
	}

	/** Удаление модуля: вернуть прежний формат там, где формат всё ещё тот, что записал модуль. */
	public static function restore(): void
	{
		$written = static::formatString(static::parseMode(Option::get(Constants::MODULE_ID, self::APPLIED, '')));
		if($written === null || !static::ready())
		{
			return;
		}
		foreach(static::languages() as $lang)
		{
			$row = \CCurrencyLang::GetByID(Constants::BASE_CURRENCY, $lang);
			$prev = Option::get(Constants::MODULE_ID, self::PREV_PREFIX.$lang, '');
			if(is_array($row) && (string)$row['FORMAT_STRING'] === $written && $prev !== '' && $prev !== self::PREV_NONE)
			{
				\CCurrencyLang::Update(Constants::BASE_CURRENCY, $lang, ['FORMAT_STRING' => $prev]);
			}
		}
		static::clearCache();
	}

	/**
	 * Обновление заменой файлов (установщик не запускался): обработчик знака и файлы стилей и скрипта —
	 * повторяемо. Зовёт страница настроек у того, кто может её менять.
	 *
	 * @param string $moduleDir каталог модуля (где install/)
	 */
	public static function ensureInstalled(string $moduleDir, string $documentRoot): void
	{
		$em = \Bitrix\Main\EventManager::getInstance();
		$has = false;
		foreach($em->findEventHandlers('main', 'OnProlog') as $handler)
		{
			if(($handler['TO_MODULE_ID'] ?? '') === Constants::MODULE_ID)
			{
				$has = true;
			}
		}
		if(!$has)
		{
			$em->registerEventHandlerCompatible('main', 'OnProlog', Constants::MODULE_ID, '\\'.self::class, 'onPrologHandler');
		}
		foreach(['/install/css' => '/bitrix/css', '/install/js' => '/bitrix/js'] as $from => $to)
		{
			if(!is_file($documentRoot.($from === '/install/css' ? self::CSS : self::JS)) && is_dir($moduleDir.$from) && function_exists('CopyDirFiles'))
			{
				\CopyDirFiles($moduleDir.$from, $documentRoot.$to, true, true);
			}
		}
	}

	/** main:OnProlog — стили со шрифтом и скрипт знака на страницах, если выбран «знак». */
	public static function onPrologHandler(): void
	{
		try
		{
			if(static::mode() === self::MODE_SIGN && class_exists(\Bitrix\Main\Page\Asset::class))
			{
				$asset = \Bitrix\Main\Page\Asset::getInstance();
				$asset->addCss(self::CSS);
				$asset->addJs(self::JS);
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
