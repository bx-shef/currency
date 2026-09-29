# [`\Shef\Currency`] Программная часть

Классы и методы, на которые можно опираться из своего кода. Устройство —
в [module-structure.md](module-structure.md), настройка и разбор сбоев —
в [README](../README.md).

Подключение — как у любого модуля:

```php
\Bitrix\Main\Loader::includeModule('shef.currency');
```

## Курсы: запросить, посмотреть, записать

| метод | что делает |
|---|---|
| Sync\Agent::action | курсы на сегодня: то, что делает агент. Внешний вход агента — `process()` из `AAgent` shef.insync: `['debug' => 'Y']` — ошибки ещё и на экран, возвращает строку агента |
| Sync\Agent::sync | курсы на дату — в Б24. В данных — `date` и `written`: коды записанных валют |
| Sync\Agent::preview | то же без записи. В данных — `date`, `rates` (ответ банка) и `plan` (что было бы записано) |
| Sync\Agent::buildAgentsEntity | сущность агента; нет агента в `b_agent` — ставит выключенным |
| Sync\Api::getRates | курсы НБ РБ на дату; нет курсов на дату — ошибка |

`sync()` и `preview()` — методы объекта:

```php
$agent = \Shef\Currency\Sync\Agent::getInstance();

$preview = $agent->preview(new \Bitrix\Main\Type\Date());
$written = $agent->sync((new \Bitrix\Main\Type\Date())->add('1D'));
```

Ошибка — в `Result`, текстом, тем же, что агент пишет в журнал событий
(тип `SH_PROBLEMS_SYNC`, источник `shef.currency`). Причины —
в навыке [shef-currency-rates](../.claude/skills/shef-currency-rates/SKILL.md).

## Правило записи — без ядра

| метод | что делает |
|---|---|
| Main\Rates::parseXml | разбор ответа `XmlExRates.aspx`: курсы по коду валюты; дата ответа не та — пусто; не XML — `UnexpectedValueException` |
| Main\Rates::plan | что писать: валюты портала, коэффициент, масштаб, порог |
| Main\Rates::isChangeEnough | изменение курса за единицу не меньше порога, % |

`Main\Rates` не зовёт ни ядро, ни `Loc` — его можно звать откуда угодно,
хоть из своего отчёта. Пример целиком — [rates.php](../examples/rates.php).

## Настройки

| метод | что возвращает |
|---|---|
| Main\Constants::getFactor | коэффициент из настроек, `1.0` — если не задан или испорчен |
| Main\Constants::getSizeChange | порог, %, `0` — если не задан или испорчен |
| Main\Constants::parseFactor | строгий разбор коэффициента: больше 0 и не больше `MAX_FACTOR` |
| Main\Constants::parseSizeChange | строгий разбор порога: целое от 0 до `MAX_SIZE_CHANGE` |
| Main\Constants::getModuleBaseCurrency | `BYN` |
| Main\Utils::checkBaseCurrency | базовая валюта портала — BYN? В данных — обе валюты |

Коды в `b_option`: `DEF_sizeChange`, `DEF_factor`, модуль `shef.currency`.

## Кнопка на странице курсов

| метод | что делает |
|---|---|
| Sync\Events::onAdminListDisplayHandler | `main:OnAdminListDisplay` — кнопка и итог последнего запроса |
| Sync\Events::onBeforePrologHandler | `main:OnBeforeProlog` — нажатие: права, `sessid`, запрос, переадресация с итогом |
| Sync\Events::canWrite | может ли текущий пользователь писать курсы: «W» и выше на `currency` |

Параметры адреса — константы `Sync\Events`: `PARAM_ACTION` (`getCurrency`),
`PARAM_TOMORROW` (`tomorrow`), `PARAM_RESULT` (`shefCurrencyResult`).
