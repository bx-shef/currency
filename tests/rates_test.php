<?php declare(strict_types=1);

/**
 * Main\Rates — всё, что решает, какой курс окажется на портале.
 *
 * Разбор — на настоящем ответе НБ РБ (tests/fixtures/nbrb-2026-09-29.xml,
 * services.nbrb.by/XmlExRates.aspx?ondate=09/29/2026). Класс без ядра,
 * поэтому тесту заглушки не нужны: подключается один файл.
 *
 * Что держит:
 *
 * * курс на другую дату — не курс на эту: на дату без курсов сервис
 *   отдаёт пустой ответ, но и ответ на другую дату завтрашним не станет;
 * * испорченная строка пропускается, остальные разбираются;
 * * ответ не XML — исключение, а не пустой список «курсы не изменились»;
 * * внешние сущности не подставляются (XXE);
 * * порог сравнивает курс за единицу с действующим — на эту дату или
 *   последним до неё, с коэффициентом; в 1.x — только с курсом на ту же
 *   дату, без коэффициента и масштаба.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/assert.php';
require_once $root.'/lib/main/rates.php';

use Shef\Currency\Main\Rates;

$xml = (string)file_get_contents($root.'/tests/fixtures/nbrb-2026-09-29.xml');

Check::group('разбор настоящего ответа');

$list = Rates::parseXml($xml, '09/29/2026');

Check::same('курсов — все 30 из ответа', count($list), 30);
Check::same('ключ — код валюты', array_key_exists('USD', $list), true);
Check::same('доллар', $list['USD'], [
	'CODE' => 'USD',
	'NUM_CODE' => '840',
	'TITLE' => 'Доллар США',
	'SCALE' => 1,
	'RATE' => 3.0257,
	'NB_RB' => 431,
]);
Check::same('российский рубль — за 100', [$list['RUB']['SCALE'], $list['RUB']['RATE']], [100, 3.5891]);
Check::same('BYN в ответе нет', isset($list['BYN']), false);

Check::group('дата ответа — не та, что спрашивали');

Check::same('ответ на другую дату — пусто', Rates::parseXml($xml, '09/30/2026'), []);
// Так сервис отвечает на дату, курсов на которую ещё нет (проверено
// 2026-09-29 на 10/05/2026).
Check::same('курсы ещё не установлены — пусто', Rates::parseXml("<?xml version=\"1.0\" encoding=\"utf-8\"?>\r\n<DailyExRates />", '10/05/2026'), []);
Check::same('формат даты — m/d/Y', Rates::parseXml($xml, '2026-09-29'), []);
Check::same('формат даты в запросе тот же', Rates::DATE_FORMAT, 'm/d/Y');

Check::group('испорченные строки пропускаются');

$broken = <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<DailyExRates Date="09/29/2026">
  <Currency Id="1"><NumCode>840</NumCode><CharCode>USD</CharCode><Scale>1</Scale><Name>Доллар</Name><Rate>3.0257</Rate></Currency>
  <Currency Id="2"><NumCode>978</NumCode><CharCode>eur</CharCode><Scale>1</Scale><Name>Евро</Name><Rate>3.4418</Rate></Currency>
  <Currency Id="3"><NumCode>643</NumCode><CharCode>RUB</CharCode><Scale>0</Scale><Name>Рубли</Name><Rate>3.5891</Rate></Currency>
  <Currency Id="4"><NumCode>156</NumCode><CharCode>CNY</CharCode><Scale>10</Scale><Name>Юани</Name><Rate>4,1</Rate></Currency>
  <Currency Id="5"><NumCode>985</NumCode><CharCode>PLN</CharCode><Scale>10</Scale><Name>Злотые</Name><Rate>0</Rate></Currency>
  <Currency Id="6"><NumCode>392</NumCode><CharCode>JPY</CharCode><Scale>100</Scale><Name>Иены</Name></Currency>
</DailyExRates>
XML;

Check::same('осталась одна годная', array_keys(Rates::parseXml($broken, '09/29/2026')), ['USD']);

Check::group('ответ — не курсы');

Check::throws('пустой ответ', UnexpectedValueException::class, static fn() => Rates::parseXml('', '09/29/2026'));
Check::throws('HTML вместо XML', UnexpectedValueException::class, static fn() => Rates::parseXml('<html><body>502 Bad Gateway', '09/29/2026'));
Check::throws('чужой корень', UnexpectedValueException::class, static fn() => Rates::parseXml('<?xml version="1.0"?><Error/>', '09/29/2026'));

$xxe = sys_get_temp_dir().'/shef-currency-xxe-'.getmypid().'.txt';
file_put_contents($xxe, 'SECRET');
$evil = '<?xml version="1.0"?><!DOCTYPE d [<!ENTITY x SYSTEM "file://'.$xxe.'">]>'
	.'<DailyExRates Date="09/29/2026"><Currency Id="1"><CharCode>USD</CharCode><Scale>1</Scale>'
	.'<Name>&x;</Name><Rate>1.5</Rate></Currency></DailyExRates>';
$parsed = Rates::parseXml($evil, '09/29/2026');
unlink($xxe);
Check::same('внешняя сущность не подставлена', str_contains((string)($parsed['USD']['TITLE'] ?? ''), 'SECRET'), false);

Check::group('порог');

Check::same('0 — пишем всегда', Rates::isChangeEnough(3.0, 3.0, 0), true);
Check::same('ровно порог — пишем', Rates::isChangeEnough(3.03, 3.0, 1), true);
Check::same('меньше порога — нет', Rates::isChangeEnough(3.02, 3.0, 1), false);
Check::same('вниз — так же', Rates::isChangeEnough(2.97, 3.0, 1), true);
Check::same('прежний курс 0 — пишем, делить не на что', Rates::isChangeEnough(3.0, 0.0, 5), true);

Check::group('что писать');

$nb = Rates::parseXml($xml, '09/29/2026');
$currencies = ['BYN', 'USD', 'EUR', 'RUB', 'XXX'];

$plan = Rates::plan($nb, $currencies, [], [], 1.0, 0, 'BYN');
Check::same('новый портал: все валюты портала из ответа, кроме базовой', $plan, [
	['CURRENCY' => 'USD', 'RATE' => 3.0257, 'RATE_CNT' => 1, 'ID' => 0],
	['CURRENCY' => 'EUR', 'RATE' => 3.4418, 'RATE_CNT' => 1, 'ID' => 0],
	['CURRENCY' => 'RUB', 'RATE' => 3.5891, 'RATE_CNT' => 100, 'ID' => 0],
]);

$plan = Rates::plan($nb, ['USD'], ['USD' => ['ID' => 12, 'RATE' => 3.0, 'RATE_CNT' => 1]], [], 1.0, 0, 'BYN');
Check::same('курс на дату уже есть — обновить его', $plan, [
	['CURRENCY' => 'USD', 'RATE' => 3.0257, 'RATE_CNT' => 1, 'ID' => 12],
]);

$plan = Rates::plan($nb, ['USD'], [], [], 1.05, 0, 'BYN');
Check::same('коэффициент — к курсу, до 4 знаков', $plan[0]['RATE'], 3.177);

// Новый день: курса на дату нет, действует вчерашний 3.02. Изменение
// 0.19 % — меньше порога 1 %, курс не пишется. В 1.x порог сравнивал
// только с курсом на ту же дату и на новый день писал всегда.
$last = ['USD' => ['RATE' => 3.02, 'RATE_CNT' => 1]];
Check::same('новый день, колебание меньше порога — не пишем', Rates::plan($nb, ['USD'], [], $last, 1.0, 1, 'BYN'), []);
Check::same('без порога — пишем', count(Rates::plan($nb, ['USD'], [], $last, 1.0, 0, 'BYN')), 1);

// Записано с коэффициентом 1.05: 3.177. Новый курс с тем же коэффициентом
// тот же — изменения нет. В 1.x сравнивался курс без коэффициента (3.0257)
// с записанным (3.177) — «колебание» 5 % на ровном месте.
$last = ['USD' => ['RATE' => 3.177, 'RATE_CNT' => 1]];
Check::same('сравнение — с коэффициентом', Rates::plan($nb, ['USD'], [], $last, 1.05, 1, 'BYN'), []);

// На портале рубль записан за 1 единицу: 0.035891. У НБ РБ — за 100:
// 3.5891. Курс за единицу тот же — изменения нет.
$last = ['RUB' => ['RATE' => 0.035891, 'RATE_CNT' => 1]];
Check::same('сравнение — за единицу, масштаб не «колебание»', Rates::plan($nb, ['RUB'], [], $last, 1.0, 1, 'BYN'), []);

// Курс на дату важнее последнего до неё.
$plan = Rates::plan(
	$nb,
	['USD'],
	['USD' => ['ID' => 3, 'RATE' => 3.0257, 'RATE_CNT' => 1]],
	['USD' => ['RATE' => 2.0, 'RATE_CNT' => 1]],
	1.0,
	1,
	'BYN'
);
Check::same('сравнение — с курсом на дату, если он есть', $plan, []);

Check::same('базовая валюта не пишется, даже если есть в ответе', Rates::plan(['BYN' => ['RATE' => 1.0, 'SCALE' => 1]], ['BYN'], [], [], 1.0, 0, 'BYN'), []);

Check::finish();
