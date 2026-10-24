<?php declare(strict_types=1);

/**
 * Main\Sign — обозначение белорусского рубля (2.1.0): знак НБРБ, «Br» или «BYN».
 *
 * Что держит:
 *
 * * разбор настройки строгий: неизвестное и пустое — знак (умолчание, как в default_option.php);
 * * формат знака — «BYN» в обёртке со шрифтом НБРБ: без стилей остаётся «BYN», а не квадрат;
 * * прежний формат запоминается один раз и не своим: повторное применение и смена режима
 *   не затирают исходный формат портала, который вернётся при удалении;
 * * удаление возвращает прежний; где прежнего нет — «BYN» (знак без стилей модуля — HTML);
 * * «не менять» ничего не пишет; без изменений ничего не пишется;
 * * стили подключаются только при «знаке».
 */

namespace
{
	$root = dirname(__DIR__);

	require_once $root.'/tests/stub/autoload.php';
	require_once $root.'/tests/stub/currency.php';
	require_once $root.'/tests/assert.php';

	/** Валюты портала: есть ли BYN. */
	class CCurrency
	{
		public static bool $hasByn = true;

		public static function GetByID(string $id): array|false
		{
			return $id === 'BYN' && static::$hasByn ? ['CURRENCY' => 'BYN'] : false;
		}
	}

	/** Локализации валюты: язык => поля; записи запоминаются. */
	class CCurrencyLang
	{
		/** @var array<string, array<string, mixed>> */
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

namespace Bitrix\Main\Localization
{
	class LanguageTable
	{
		public static array $langs = ['ru', 'en'];

		public static function getList(array $params): \Bitrix\Currency\Cursor
		{
			return new \Bitrix\Currency\Cursor(array_map(static fn(string $l): array => ['LID' => $l], static::$langs));
		}
	}
}

namespace
{
	use Bitrix\Main\Config\Option;
	use Shef\Currency\Main\Sign;

	Check::group('разбор настройки и формат');

	Check::same('известные режимы', array_map([Sign::class, 'parseMode'], ['sign', 'br', 'code', 'keep']), ['sign', 'br', 'code', 'keep']);
	Check::same('пустое, мусор, не строка — знак', array_map([Sign::class, 'parseMode'], ['', 'SIGN', 'html', null, 5]), ['sign', 'sign', 'sign', 'sign', 'sign']);
	Check::same('умолчание совпадает с default_option.php', str_contains((string)file_get_contents(dirname(__DIR__).'/default_option.php'), "'DEF_sign' => '".Sign::DEFAULT_MODE."'"), true);
	Check::same('знак — «BYN» в обёртке со шрифтом НБРБ', Sign::formatString('sign'), '# <span class="nbrb-icon">BYN</span>');
	Check::same('Br и BYN — текстом, «не менять» — null', [Sign::formatString('br'), Sign::formatString('code'), Sign::formatString('keep')], ['# Br', '# BYN', null]);
	Check::same('стили знака задают тот же класс', str_contains((string)file_get_contents(dirname(__DIR__).'/install/css/shef.currency/byn.css'), '.'.Sign::CLASS_NAME.' {'), true);

	Check::group('план: что писать и что запомнить');

	Check::same('чужой формат запоминается, нет локализации — добавить', Sign::plan('sign', ['ru' => '# руб.', 'en' => null], []), [
		'ru' => ['format' => '# <span class="nbrb-icon">BYN</span>', 'add' => false, 'keep' => '# руб.'],
		'en' => ['format' => '# <span class="nbrb-icon">BYN</span>', 'add' => true, 'keep' => null],
	]);
	Check::same('уже так — ничего', Sign::plan('br', ['ru' => '# Br'], []), []);
	Check::same('свой формат не запоминается как прежний', Sign::plan('code', ['ru' => '# Br'], [])['ru']['keep'], null);
	Check::same('прежний уже сохранён — не перезаписывается', Sign::plan('sign', ['ru' => '# Bel'], ['ru' => '# руб.'])['ru']['keep'], null);
	Check::same('«не менять» — пусто', Sign::plan('keep', ['ru' => '# руб.'], []), []);

	Check::group('apply/restore на заглушках ядра');

	CCurrencyLang::$rows = ['ru' => ['FORMAT_STRING' => '# руб.', 'FULL_NAME' => 'Белорусский рубль']];
	Option::set('shef.currency', Sign::OPTION, 'sign');
	Check::same('apply — успех', Sign::apply()->isSuccess(), true);
	Check::same('ru — знак, исходный «# руб.» запомнен', [CCurrencyLang::$rows['ru']['FORMAT_STRING'], Option::get('shef.currency', 'SYS_prev_ru', '')], ['# <span class="nbrb-icon">BYN</span>', '# руб.']);
	Check::same('en добавлен со знаком и разделителями', [CCurrencyLang::$rows['en']['FORMAT_STRING'], CCurrencyLang::$rows['en']['DEC_POINT']], ['# <span class="nbrb-icon">BYN</span>', ',']);
	$writes = CCurrencyLang::$writes;
	Sign::apply();
	Check::same('повторно — без записей', CCurrencyLang::$writes, $writes);
	Sign::apply('br');
	Check::same('смена на Br — прежний остаётся исходным', [CCurrencyLang::$rows['ru']['FORMAT_STRING'], Option::get('shef.currency', 'SYS_prev_ru', '')], ['# Br', '# руб.']);
	Sign::restore();
	Check::same('удаление: ru — исходный, en (прежнего не было) — «BYN»', [CCurrencyLang::$rows['ru']['FORMAT_STRING'], CCurrencyLang::$rows['en']['FORMAT_STRING']], ['# руб.', '# BYN']);

	CCurrencyLang::$rows = ['ru' => ['FORMAT_STRING' => '# свой']];
	Sign::restore();
	Check::same('удаление не трогает формат, поменянный руками после модуля', CCurrencyLang::$rows['ru']['FORMAT_STRING'], '# свой');

	CCurrency::$hasByn = false;
	Check::same('нет BYN — ошибка, ничего не пишется', [Sign::apply('sign')->isSuccess(), CCurrencyLang::$rows['ru']['FORMAT_STRING']], [false, '# свой']);
	CCurrency::$hasByn = true;

	Check::group('стили на страницах');

	\Bitrix\Main\Page\Asset::$css = [];
	Option::set('shef.currency', Sign::OPTION, 'br');
	Sign::onPrologHandler();
	Check::same('Br — стили не нужны', \Bitrix\Main\Page\Asset::$css, []);
	Option::set('shef.currency', Sign::OPTION, 'sign');
	Sign::onPrologHandler();
	Check::same('знак — стили со шрифтом', \Bitrix\Main\Page\Asset::$css, ['/bitrix/css/shef.currency/byn.css']);

	Check::finish();
}
