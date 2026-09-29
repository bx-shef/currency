# shef.currency

Модуль Битрикс24 «коробки» и БУС: курсы валют Национального банка Республики
Беларусь в штатный модуль «Валюты». Раз в сутки агент берёт курсы на сегодня,
а на странице курсов есть кнопка — запросить курсы на сегодня или на завтра.
Курс можно поднять коэффициентом и не трогать при мелких колебаниях.

Опирается на модули линейки [shef.options](https://github.com/bx-shef/options),
[shef.problems](https://github.com/bx-shef/problems) и
[shef.insync](https://github.com/bx-shef/insync): их нужно поставить первыми.

* [Изменения](CHANGELOG.md)

# Что нужно для установки

| | |
|---|---|
| PHP | 8.2 и выше, расширения `mbstring`, `simplexml` |
| Главный модуль Битрикс | 22.600.300 и выше |
| Модуль «Валюты» (`currency`) | установлен |
| `shef.options` | 3.0.0 и выше |
| `shef.problems` | 2.0.0 и выше |
| `shef.insync` | 2.0.0 и выше |
| Кодировка портала | **только UTF-8** |
| Базовая валюта портала | **BYN** |
| Сеть | с сервера портала открыт `https://services.nbrb.by` |

# Установка

**Порядок шагов важен:** сначала модули линейки, потом файлы этого модуля,
потом установка в административном разделе.

## Через Composer

```bash
composer require bxshef/currency
```

Модуль развернётся в `bitrix/modules/shef.currency/` сам, вместе с ним
приедут `bxshef/options`, `bxshef/problems` и `bxshef/insync`. Composer 2.2+
требует разрешить плагин раскладки — один раз, в `composer.json` проекта:

```json
{
	"config": {
		"allow-plugins": {
			"composer/installers": true
		}
	}
}
```

## Из архива

Скачайте `shef.currency.zip` со [страницы релизов](https://github.com/bx-shef/currency/releases)
и распакуйте в `bitrix/modules/`. Должно получиться
`bitrix/modules/shef.currency/` — именно через точку.

## Дальше — в административном разделе

1. **Настройки → Marketplace → Установленные решения** → «[SH] Валюты НБРБ» →
   **Установить**.
2. Убедитесь, что базовая валюта портала — **BYN**: страница
   валют `/bitrix/admin/currencies.php` либо в CRM `/crm/configs/currency/`.
   Иначе модуль не пишет ничего, а на странице настроек модуля об этом
   красная строка.
3. У каждой валюты проверьте **курс по умолчанию**: он действует там, где
   курса на дату нет.
4. **Настройки → Настройки продукта → Настройки модулей → [SH] Валюты НБРБ**:
   задайте порог и коэффициент (см. ниже) и **включите агент** — страница
   ставит его выключенным. Первый запуск — в 00:20, дальше раз в сутки.

# Как пользоваться

Курсы приходят сами — агентом, раз в сутки, на сегодня. Пишутся курсы
валют, **заведённых на портале**: нужен курс юаня — заведите валюту CNY, курс
придёт следующим запуском. Базовая валюта не пишется никогда.

Руками — на странице курсов валют `/bitrix/admin/currencies_rates.php`: **[SH] Запросить курсы валют** →
«На сегодня» или «На завтра». Кнопка есть у тех, кто может править курсы
(право «W» на модуль «Валюты»). После запроса над списком сообщение:
записано или нет.

Курсы на завтра банк устанавливает днём; до этого «На завтра» ответит «не
записаны», и это не сбой.

## Настройки

| настройка | по умолчанию | что делает |
|---|---|---|
| **Менять курс только при колебании более чем %** | 0 | Курс за единицу валюты сравнивается с действующим на портале — на эту дату, иначе с последним до неё. Изменение меньше порога — курс не пишется, действует прежний. 0 — писать всегда. Целое от 0 до 100 |
| **Коэффициент** | 1 | На него умножается курс НБ РБ перед записью: 1.02 — на 2 % выше банка. Больше 0 и не больше 400 |

Пустое или испорченное значение модуль читает как значение по умолчанию.

## Если курс не пришёл

Причина каждого сбоя — в журнале событий (`/bitrix/admin/event_log.php`):
тип «Проблема с синхронизацией» (`SH_PROBLEMS_SYNC`), источник
`shef.currency`. Логи shef.problems пишет и в файл — где он лежит, сказано в
[shef.problems](https://github.com/bx-shef/problems).

Разбор по шагам — в навыке агента
[shef-currency-rates](https://github.com/bx-shef/currency/blob/main/.claude/skills/shef-currency-rates/SKILL.md),
посмотреть, что модуль записал бы сегодня, ничего не записывая, — пример
[preview.php](https://github.com/bx-shef/currency/blob/main/examples/preview.php).

# Программная часть

| класс | что делает |
|---|---|
| `\Shef\Currency\Sync\Agent` | агент: `process()` — раз в сутки на сегодня; `sync(Date)` — записать на дату; `preview(Date)` — план без записи |
| `\Shef\Currency\Sync\Api` | запрос к `services.nbrb.by/XmlExRates.aspx` |
| `\Shef\Currency\Main\Rates` | разбор ответа банка и правило, что писать; ядро не нужно |
| `\Shef\Currency\Sync\Events` | кнопка на странице курсов и её обработка |

```php
\Bitrix\Main\Loader::includeModule('shef.currency');

$result = \Shef\Currency\Sync\Agent::getInstance()->sync(
	(new \Bitrix\Main\Type\Date())->add('1D')
);
// $result->getData()['written'] — коды записанных валют
```

# Документация

Живёт в репозитории: [github.com/bx-shef/currency](https://github.com/bx-shef/currency).

* [Программная часть](https://github.com/bx-shef/currency/blob/main/docs/1_api.md)
* [Проверка на портале](https://github.com/bx-shef/currency/blob/main/docs/portal-check.md)
* [Безопасность](https://github.com/bx-shef/currency/blob/main/docs/security.md)
* [Раскладка репозитория](https://github.com/bx-shef/currency/blob/main/docs/module-structure.md)
* [Сборка, CI и релиз](https://github.com/bx-shef/currency/blob/main/docs/build-and-install.md)
* [Примеры](https://github.com/bx-shef/currency/blob/main/examples/README.md)
