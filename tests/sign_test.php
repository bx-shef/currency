<?php declare(strict_types=1);

/**
 * Main\Sign — обозначение белорусского рубля (2.1.0): знак НБРБ, «Br» или «BYN».
 *
 * Что держит:
 *
 * * формат валюты — всегда текст («# BYN», «# Br»): HTML в нём экранировался бы в выгрузках, REST,
 *   письмах (правила знака); знак рисуют стили и скрипт на страницах;
 * * разбор настройки строгий; умолчание — «не менять» (обновлённый заменой файлов портал не трогаем);
 * * пишется только при смене режима с прошлого применения: просмотр страницы настроек формат,
 *   поменянный руками, не переписывает; установка — принудительно;
 * * прежний формат запоминается при первой записи любым (и «# Br» клиента), не перезаписывается;
 * * удаление возвращает прежний, только если формат всё ещё записанный модулем;
 * * стили и скрипт — только при «знаке»; обработчик и файлы для обновления заменой — повторяемо.
 */

namespace
{
	$root = dirname(__DIR__);

	require_once $root.'/tests/stub/autoload.php';
	require_once $root.'/tests/stub/currency.php';
	require_once $root.'/tests/stub/currencylang.php';
	require_once $root.'/tests/assert.php';

	use Bitrix\Main\Config\Option;
	use Shef\Currency\Main\Sign;

	$reset = static function(array $rows, string $mode, string $applied = ''): void {
		Option::delete('shef.currency');
		CCurrencyLang::$rows = $rows;
		CCurrencyLang::$writes = 0;
		Option::set('shef.currency', Sign::OPTION, $mode);
		if($applied !== '')
		{
			Option::set('shef.currency', Sign::APPLIED, $applied);
		}
	};

	Check::group('разбор настройки и формат');

	Check::same('известные режимы', array_map([Sign::class, 'parseMode'], ['sign', 'br', 'code', 'keep']), ['sign', 'br', 'code', 'keep']);
	Check::same('пустое, мусор, не строка — «не менять»', array_map([Sign::class, 'parseMode'], ['', 'SIGN', 'html', null, 5]), ['keep', 'keep', 'keep', 'keep', 'keep']);
	Check::same('умолчание совпадает с default_option.php', str_contains((string)file_get_contents($root.'/default_option.php'), "'DEF_sign' => '".Sign::DEFAULT_MODE."'"), true);
	Check::same('знак и BYN — «# BYN» текстом, Br — «# Br», «не менять» — null', [Sign::formatString('sign'), Sign::formatString('code'), Sign::formatString('br'), Sign::formatString('keep')], ['# BYN', '# BYN', '# Br', null]);
	foreach(['sign', 'br', 'code'] as $mode)
	{
		Check::same('в формате '.$mode.' нет разметки', strpbrk((string)Sign::formatString($mode), '<>&"'), false);
	}
	Check::same('стили знака задают класс, который ставит скрипт', [
		str_contains((string)file_get_contents($root.'/install/css/shef.currency/byn.css'), '.'.Sign::CLASS_NAME.' {'),
		str_contains((string)file_get_contents($root.'/install/js/shef.currency/byn.js'), "span.className = '".Sign::CLASS_NAME."'"),
	], [true, true]);

	Check::group('план: что писать и что запомнить');

	Check::same('прежний запоминается любым, нет локализации — добавить', Sign::plan('sign', ['ru' => '# руб.', 'en' => null, 'be' => '# Br'], []), [
		'ru' => ['format' => '# BYN', 'add' => false, 'keep' => '# руб.'],
		'en' => ['format' => '# BYN', 'add' => true, 'keep' => '-'],
		'be' => ['format' => '# BYN', 'add' => false, 'keep' => '# Br'],
	]);
	Check::same('уже так — ничего', Sign::plan('br', ['ru' => '# Br'], []), []);
	Check::same('прежний уже сохранён — не перезаписывается', Sign::plan('sign', ['ru' => '# Br'], ['ru' => '# руб.'])['ru']['keep'], null);
	Check::same('«не менять» — пусто', Sign::plan('keep', ['ru' => '# руб.'], []), []);
	Check::same('писать: смена режима — да, тот же — нет, «не менять» — нет, установка — да', [
		Sign::needApply('sign', ''), Sign::needApply('sign', 'sign'), Sign::needApply('keep', 'sign'), Sign::needApply('sign', 'sign', true), Sign::needApply('keep', '', true),
	], [true, false, false, true, false]);

	Check::group('apply/restore на заглушках ядра');

	$reset(['ru' => ['FORMAT_STRING' => '# Br']], 'sign');
	Check::same('apply — успех', Sign::apply()->isSuccess(), true);
	Check::same('ru — «# BYN», прежний «# Br» клиента запомнен, режим применён', [CCurrencyLang::$rows['ru']['FORMAT_STRING'], Option::get('shef.currency', 'SYS_prev_ru', ''), Option::get('shef.currency', Sign::APPLIED, '')], ['# BYN', '# Br', 'sign']);
	Check::same('en добавлен с разделителями', [CCurrencyLang::$rows['en']['FORMAT_STRING'], CCurrencyLang::$rows['en']['DEC_POINT']], ['# BYN', ',']);

	CCurrencyLang::$rows['ru']['FORMAT_STRING'] = '# руками';
	$writes = CCurrencyLang::$writes;
	Sign::apply();
	Check::same('просмотр без смены режима — без записей, формат руками цел', [CCurrencyLang::$writes, CCurrencyLang::$rows['ru']['FORMAT_STRING']], [$writes, '# руками']);
	Sign::restore();
	Check::same('удаление не трогает формат, поменянный руками', CCurrencyLang::$rows['ru']['FORMAT_STRING'], '# руками');

	$reset(['ru' => ['FORMAT_STRING' => '# руб.']], 'sign');
	Sign::apply();
	Option::set('shef.currency', Sign::OPTION, 'br');
	Sign::apply();
	Check::same('смена на Br — пишется, прежний остаётся исходным', [CCurrencyLang::$rows['ru']['FORMAT_STRING'], Option::get('shef.currency', 'SYS_prev_ru', '')], ['# Br', '# руб.']);
	Sign::restore();
	Check::same('удаление: ru — исходный «# руб.», en (не было) — как записал модуль', [CCurrencyLang::$rows['ru']['FORMAT_STRING'], CCurrencyLang::$rows['en']['FORMAT_STRING']], ['# руб.', '# Br']);

	$reset(['ru' => ['FORMAT_STRING' => '# руб.']], 'keep');
	Check::same('«не менять» — ничего, даже при установке', [Sign::apply(true)->isSuccess(), CCurrencyLang::$writes], [true, 0]);
	Sign::restore();
	Check::same('модуль не писал — удаление не трогает', CCurrencyLang::$rows['ru']['FORMAT_STRING'], '# руб.');

	$reset(['ru' => ['FORMAT_STRING' => '# руб.']], 'sign');
	CCurrency::$hasByn = false;
	Check::same('нет BYN — ошибка, ничего не пишется, режим не помечен применённым', [Sign::apply()->isSuccess(), CCurrencyLang::$writes, Option::get('shef.currency', Sign::APPLIED, '')], [false, 0, '']);
	CCurrency::$hasByn = true;

	Check::group('страницы: стили и скрипт');

	\Bitrix\Main\Page\Asset::$css = [];
	\Bitrix\Main\Page\Asset::$js = [];
	Option::set('shef.currency', Sign::OPTION, 'br');
	Sign::onPrologHandler();
	Check::same('Br — ничего не подключается', [\Bitrix\Main\Page\Asset::$css, \Bitrix\Main\Page\Asset::$js], [[], []]);
	Option::set('shef.currency', Sign::OPTION, 'sign');
	Sign::onPrologHandler();
	Check::same('знак — стили со шрифтом и скрипт', [\Bitrix\Main\Page\Asset::$css, \Bitrix\Main\Page\Asset::$js], [['/bitrix/css/shef.currency/byn.css'], ['/bitrix/js/shef.currency/byn.js']]);

	Check::group('обновление заменой файлов: обработчик и файлы');

	\Bitrix\Main\EventManager::$registered = [];
	$GLOBALS['copied'] = [];
	if(!function_exists('CopyDirFiles'))
	{
		function CopyDirFiles(string $from, string $to, bool $rewrite = true, bool $recursive = false): bool
		{
			$GLOBALS['copied'][] = [$from, $to];
			return true;
		}
	}
	Sign::ensureInstalled($root, '/nonexistent-docroot');
	Sign::ensureInstalled($root, '/nonexistent-docroot');
	Check::same('обработчик main:OnProlog — один раз', array_map(static fn(array $r): string => $r[0].':'.$r[1].' -> '.$r[2], \Bitrix\Main\EventManager::$registered), ['main:OnProlog -> shef.currency']);
	Check::same('файлов нет — стили и скрипт копируются', array_slice($GLOBALS['copied'], 0, 2), [[$root.'/install/css', '/nonexistent-docroot/bitrix/css'], [$root.'/install/js', '/nonexistent-docroot/bitrix/js']]);

	Check::finish();
}
