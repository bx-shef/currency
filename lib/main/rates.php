<?php declare(strict_types=1);

namespace Shef\Currency\Main;

use SimpleXMLElement;
use UnexpectedValueException;

/**
 * Курсы НБ РБ: разбор ответа и решение, что писать в Б24.
 *
 * Класс самодостаточен — не зовёт ни ядро, ни Loc. Всё, что решает, какой
 * курс окажется на портале, живёт здесь и проверяется тестами без портала
 * (tests/rates_test.php). Агент только достаёт данные из Б24 и пишет
 * результат.
 */
final class Rates
{
	/**
	 * Формат даты в запросе и в ответе сервиса XmlExRates.aspx.
	 */
	public const DATE_FORMAT = 'm/d/Y';

	/**
	 * Знаков после запятой в курсе: столько хранит b_catalog_currency_rate.
	 */
	public const PRECISION = 4;
	
	/**
	 * Границы того, что вообще может прийти от банка. Самый крупный масштаб
	 * в ответе — 100 000 (вьетнамский донг), самый большой курс — единицы
	 * рублей. Число за границей — не курс, а сбой источника или подмена:
	 * записанный порогом он не останавливается (порог отсекает только малые
	 * сдвиги), а в RATE_CNT за пределами колонки он даёт ошибку БД.
	 */
	public const MAX_SCALE = 1000000;
	public const MAX_RATE = 1000000.0;

	/**
	 * Разбор ответа https://services.nbrb.by/XmlExRates.aspx.
	 *
	 * На дату, для которой курсов ещё нет (завтра — до их установки
	 * банком), сервис отдаёт пустой «<DailyExRates />» без даты. Дата в
	 * ответе сверяется с запрошенной в любом случае: не совпала — курсов на
	 * эту дату нет, пустой массив. Курс другого дня курсом этого дня не
	 * станет, что бы ни отдал сервис.
	 *
	 * Строка с кодом не из трёх латинских букв, масштабом не от 1 до
	 * MAX_SCALE или курсом не числом больше нуля и не больше MAX_RATE
	 * пропускается: писать её в Б24 нельзя, а остальные курсы от неё не
	 * портятся.
	 *
	 * @param string $xml тело ответа
	 * @param string $onDate запрошенная дата в формате DATE_FORMAT
	 * @return array<string, array{CODE: string, NUM_CODE: string, TITLE: string, SCALE: int, RATE: float, NB_RB: int}>
	 *     по коду валюты
	 * @throws UnexpectedValueException ответ — не XML курсов
	 */
	public static function parseXml(string $xml, string $onDate): array
	{
		$root = static::loadXml($xml);

		if($root->getName() !== 'DailyExRates')
		{
			throw new UnexpectedValueException('NB RB: root element is not DailyExRates');
		}

		if((string)$root['Date'] !== $onDate)
		{
			return [];
		}

		$list = [];
		foreach($root->Currency as $currency)
		{
			$code = trim((string)$currency->CharCode);
			$scale = trim((string)$currency->Scale);
			$rate = trim((string)$currency->Rate);

			if(
				1 !== preg_match('/^[A-Z]{3}$/', $code)
				|| 1 !== preg_match('/^[1-9][0-9]{0,6}$/', $scale)
				|| (int)$scale > static::MAX_SCALE
				|| 1 !== preg_match('/^[0-9]{1,7}(\.[0-9]{1,8})?$/', $rate)
				|| (float)$rate <= 0
				|| (float)$rate > static::MAX_RATE
			)
			{
				continue;
			}

			$list[$code] = [
				'CODE' => $code,
				'NUM_CODE' => trim((string)$currency->NumCode),
				'TITLE' => trim((string)$currency->Name),
				'SCALE' => (int)$scale,
				'RATE' => (float)$rate,
				'NB_RB' => (int)$currency['Id'],
			];
		}

		return $list;
	}

	/**
	 * XML без сети и без сущностей: ответ приходит извне.
	 *
	 * LIBXML_NONET запрещает libxml ходить в сеть за DTD, внешние сущности
	 * libxml 2.9+ не подставляет сам, а LIBXML_NOENT здесь не ставится
	 * сознательно — он бы их включил.
	 *
	 * @throws UnexpectedValueException
	 */
	private static function loadXml(string $xml): SimpleXMLElement
	{
		if(trim($xml) === '')
		{
			throw new UnexpectedValueException('NB RB: empty response');
		}

		$previous = libxml_use_internal_errors(true);
		try
		{
			$root = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET);
		}
		finally
		{
			libxml_clear_errors();
			libxml_use_internal_errors($previous);
		}

		if(!($root instanceof SimpleXMLElement))
		{
			throw new UnexpectedValueException('NB RB: response is not XML');
		}

		return $root;
	}

	/**
	 * Что записать в Б24.
	 *
	 * Курс пишется для каждой валюты портала, которая есть в ответе НБ РБ,
	 * кроме базовой. Значение — курс НБ РБ, умноженный на коэффициент, за
	 * столько единиц, сколько у НБ РБ (RATE_CNT = Scale).
	 *
	 * Порог сравнивает курс за ОДНУ единицу валюты с тем, что уже действует
	 * на портале: с курсом на эту же дату, если он есть, иначе — с последним
	 * до неё. Изменение меньше порога — курс не пишется, и на портале
	 * продолжает действовать прежний. Курса нет вовсе — пишется всегда.
	 *
	 * До 2.0.0 порог сравнивал только с курсом на ту же дату: курса на новый
	 * день ещё нет, и курс писался всегда, при любом пороге. Сравнивался он к
	 * тому же без коэффициента — с записанным с коэффициентом — и без учёта
	 * масштаба.
	 *
	 * @param array<string, array{RATE: float, SCALE: int}> $nbRates курсы НБ РБ по коду, см. parseXml()
	 * @param string[] $b24Currencies валюты портала
	 * @param array<string, array{ID: int, RATE: float, RATE_CNT: int}> $sameDay курсы портала на дату по коду
	 * @param array<string, array{RATE: float, RATE_CNT: int}> $last последние курсы портала до даты по коду
	 * @param float $factor коэффициент, больше нуля
	 * @param int $sizeChange порог, %
	 * @param string $baseCurrency базовая валюта портала
	 * @return list<array{CURRENCY: string, RATE: float, RATE_CNT: int, ID: int}> ID = 0 — добавить, иначе — обновить
	 */
	public static function plan(
		array $nbRates,
		array $b24Currencies,
		array $sameDay,
		array $last,
		float $factor,
		int $sizeChange,
		string $baseCurrency
	): array
	{
		$plan = [];

		foreach($b24Currencies as $currency)
		{
			if($currency === $baseCurrency || !isset($nbRates[$currency]))
			{
				continue;
			}

			$scale = (int)$nbRates[$currency]['SCALE'];
			$rate = round((float)$nbRates[$currency]['RATE'] * $factor, static::PRECISION);
			if($scale < 1 || $rate <= 0)
			{
				continue;
			}

			$current = $sameDay[$currency] ?? $last[$currency] ?? null;
			if(
				null !== $current
				&& !static::isChangeEnough(
					$rate / $scale,
					(float)$current['RATE'] / max(1, (int)$current['RATE_CNT']),
					$sizeChange
				)
			)
			{
				continue;
			}

			$plan[] = [
				'CURRENCY' => $currency,
				'RATE' => $rate,
				'RATE_CNT' => $scale,
				'ID' => (int)($sameDay[$currency]['ID'] ?? 0),
			];
		}

		return $plan;
	}

	/**
	 * Изменение курса за единицу не меньше порога, %?
	 *
	 * Изменение ровно на порог — пишется. Порог 0 — пишется всегда. Прежний
	 * курс не больше нуля — считать процент не от чего, пишется всегда.
	 */
	public static function isChangeEnough(float $new, float $old, int $sizeChange): bool
	{
		if($sizeChange <= 0 || $old <= 0)
		{
			return true;
		}

		// Процент — округлённым: 0.03 / 3 * 100 в двоичной арифметике —
		// 0.99999…, и изменение ровно на порог считалось бы меньше него.
		return round(abs($new - $old) / $old * 100, 6) >= $sizeChange;
	}
}
