<?php declare(strict_types=1);

namespace Shef\Currency\Sync;

use Bitrix\Main\ArgumentNullException;
use Bitrix\Main\Result;
use Bitrix\Main\Error;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Web\HttpClient;
use Shef\Problems;
use Shef\InSync\Api\AConnector;
use Shef\Currency\Main\Constants;
use Shef\Currency\Main\Rates;

/**
 * Клиент сервиса курсов НБ РБ.
 *
 * Запрос и сбои соединения — AConnector из shef.insync: таймауты, запись
 * проблемы в журнал. Разбор ответа — Main\Rates, без ядра.
 */
class Api
	extends AConnector
{
	private const Url = 'https://services.nbrb.by';
	
	/**
	 * Потолок тела ответа: курсы на дату — около 5 КБ. Больше — не ответ
	 * сервиса курсов, и читать его целиком незачем.
	 */
	public const MAX_BODY_LENGTH = 1048576;
	
	public static function getModuleId(): string
	{
		return Constants::getModuleId();
	}
	
	public static function getAssignedId(): int
	{
		return Problems\Main\Constants::getSyncUserId();
	}
	
	// region Options ////
	/**
	 * Клиент без переходов по переадресации. AConnector по умолчанию идёт
	 * по ним (до 5 раз) на любой хост и схему — а адрес сервиса зашит, и
	 * переадресация с него на http:// или чужой хост значит не «курсы
	 * переехали», а подмену: такой ответ — ошибка, а не курсы.
	 */
	protected function initHttp(): void
	{
		$this->httpClient = new HttpClient([
			'version' => '1.1',
			'redirect' => false,
			'bodyLengthMax' => static::MAX_BODY_LENGTH,
		]);
		
		$this->reInitHttpParams();
	}
	
	/**
	 * @inheritDoc
	 */
	protected function getPath(string $functionName): string
	{
		return static::Url.$functionName;
	}
	
	/**
	 * @inheritDoc
	 */
	public static function getEncodingFrom(): string
	{
		return 'utf-8';
	}
	// endregion /////
	
	// region Actions /////
	/**
	 * Курсы НБ РБ на дату.
	 *
	 * В данных — list: курсы по коду валюты (Rates::parseXml()). Курсов на
	 * эту дату у банка нет (завтра — до их установки) — ошибка: пустой
	 * успешный ответ выглядел бы как «курсы не изменились».
	 *
	 * @throws ArgumentNullException
	 */
	public function getRates(Date $date): Result
	{
		$result = new Result();
		
		$onDate = $date->format(Rates::DATE_FORMAT);
		
		$response = $this->sendRequest(
			'/XmlExRates.aspx',
			['ondate' => $onDate],
			HttpClient::HTTP_GET
		);
		if(!$response->isSuccess())
		{
			return $result->addErrors($response->getErrors());
		}
		
		try
		{
			$list = Rates::parseXml(
				(string)($response->getData()['data']['response'] ?? ''),
				$onDate
			);
		}
		catch(\UnexpectedValueException $exception)
		{
			return $result->addError(new Error($exception->getMessage()));
		}
		
		if(empty($list))
		{
			return $result->addError(new Error(sprintf(
				'NB RB: no rates on %s',
				$date->format('Y-m-d')
			)));
		}
		
		return $result->setData(['list' => $list]);
	}
	// endregion /////
}
