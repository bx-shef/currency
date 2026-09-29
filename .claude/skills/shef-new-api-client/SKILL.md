---
name: shef-new-api-client
description: Сделать обращение к внешнему HTTP-API (REST, JSON, XML, SOAP по HTTP) из модуля Битрикс24 «коробки» или БУС на shef.insync, любой свой вендор — класс-клиент на AConnector с адресами функций, заголовками авторизации, таймаутами, разбором ответа, отделением ошибки бизнес-логики от HTTP 200 и записью сбоев проблемой в журнал событий без утечки ключей в лог. Брать на задачи «подключиться к API поставщика/маркетплейса/1С по HTTP», «забрать заказы из внешней системы», «отправить данные во внешний сервис», «клиент для REST с токеном», «обработать 500/таймаут внешнего сервера». Не для разбора файла выгрузки — навык shef-new-import репозитория shef.insync (он же, если ответ API надо разнести пачками агентом через таблицу импорта); не для входящих запросов к порталу (ajax, контроллер) — shef-new-ajax-action; не для курсов НБ РБ, которые уже пишет модуль shef.currency, — shef-currency-rates.
---

# Клиент внешнего API на shef.insync

Операция: завести в своём модуле класс, который ходит во внешнее HTTP-API, —
по канону shef.insync: один базовый класс
`\Shef\InSync\Api\AConnector` на `HttpClient` ядра, сбои — проблемой в журнал
событий через shef.problems. Модуль
[shef.insync](https://github.com/bx-shef/insync) должен быть **установлен**.

## Класс

**Модуль подключается до слова `class`** — PHP разбирает `extends` при чтении
файла.

```php
<?php declare(strict_types=1);

namespace Acme\Exchange\Api;

\Bitrix\Main\Loader::includeModule('shef.insync');

use Bitrix\Main\Config\Option;
use Bitrix\Main\Result;
use Bitrix\Main\Error;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;
use Shef\InSync\Api\AConnector;

final class Supplier extends AConnector
{
	// Сбои пишутся проблемой от имени этого модуля.
	public static function getModuleId(): string { return 'acme.exchange'; }

	// Имя функции API -> адрес.
	protected function getPath(string $functionName): string
	{
		return 'https://api.supplier.example/v1/'.$functionName;
	}

	public function getOrders(string $since): Result
	{
		return $this->sendRequest(
			'orders',
			['since' => $since],
			HttpClient::HTTP_GET,
			// Ключ — из настроек модуля, не из кода. В лог уйдёт маской.
			['Authorization' => 'Bearer '.Option::get('acme.exchange', 'DEF_apikey')]
		);
	}

	// 200 — это ещё не успех бизнес-логики: разбираем и проверяем.
	public function processSuccess(): Result
	{
		$result = new Result();

		try
		{
			$body = Json::decode((string)$this->getHttpClient()->getResult());
		}
		catch(\Throwable $throwable)
		{
			return $result->addError(new Error('Wrong JSON: '.$throwable->getMessage()));
		}

		if(($body['status'] ?? '') !== 'ok')
		{
			return $result->addError(new Error((string)($body['error'] ?? 'API error')));
		}

		return $result->setData(['response' => $body['data'] ?? []]);
	}
}
```

Вызов:

```php
$response = (new \Acme\Exchange\Api\Supplier())->getOrders('2026-09-01');
if($response->isSuccess())
{
	$orders = $response->getData()['data']['response'] ?? [];
}
```

`sendRequest()` возвращает `Result`, в `getData()` — что отправлено (`send`:
метод, адрес, параметры, заголовки) и что вернул `processSuccess()` (`data`).

## Что делает `sendRequest()` сам

* `GET` — параметры в адрес, остальные методы (`POST`, `PUT`, `PATCH`,
  `DELETE`) — телом;
* ответ 200 — `processSuccess()`; 500, 504 и 0 (нет соединения) —
  `processError50x()`; прочие — `processError()`. Переопределяйте их, когда
  у API своё тело ошибки;
* при любой ошибке пишет запрос и ответ в лог проблем
  (`$this->logger->error()`), в режиме отладки — ещё и на экран
  администратору;
* ошибки соединения (`HttpClient::getError()`) добавляет в `Result`.

## Таймауты

Из опций объекта. Задавайте после конструктора (он заводит опции заново);
`sendRequest()` применяет их сам перед каждым запросом, `reInitHttpParams()`
— чтобы применить сразу:

```php
$client = new Supplier();
$client->addOptionCollection('socketTimeout', 10)
	->addOptionCollection('streamTimeout', 120)
	->reInitHttpParams();
```

По умолчанию: соединение 30 с, чтение 60 с, `waitResponse` — да.

## Секреты

* ключи и токены — в настройках модуля (shef-new-option) или
  `/bitrix/.settings_extra.php`, не в коде: репозиторий модуля — не место
  для них;
* передавайте их **заголовком**. Заголовки с `authorization`, `token`, `key`,
  `secret`, `password`, `cookie`, `session` в имени уходят в лог маской
  (`\Shef\InSync\Api\Headers::mask()`; ещё `auth`, `pass`, `sign`, `access`),
  а **параметры запроса — как есть**:
  ключ в `?api_key=` окажется в логе.

## Большие объёмы

Не разбирайте тысячу записей ответа в одном хите. Кладите их в таблицу
импорта и разносите агентом — навык
[shef-new-import](https://github.com/bx-shef/insync/blob/main/.claude/skills/shef-new-import/SKILL.md)
репозитория shef.insync: процесс на
`\Shef\InSync\Sync\AProcess` (свой `run()` зовёт API) и агент разбора на
`\Shef\InSync\Sync\FromFile\AAgent`.

## Чего не делать

* не считать 200 успехом без проверки тела;
* не ловить исключения внутри `sendRequest()` вокруг него — он сам их
  превращает в ошибку `Result` с трассировкой;
* не собирать `HttpClient` руками рядом: таймауты, логирование и маска
  заголовков уже в базовом классе.

## В конце

Запишите отзыв о навыке — навык `shef-feedback`.
