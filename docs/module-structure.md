# Раскладка репозитория

Файл про устройство репозитория. Опорные точки модуля — в [CLAUDE.md](../CLAUDE.md),
процесс — в [CONTRIBUTING.md](../CONTRIBUTING.md), сборка — в
[build-and-install.md](build-and-install.md).

## Модуль лежит в корне, и это вынужденно

Composer разворачивает в целевой каталог **корень пакета целиком** и подкаталоги
выбирать не умеет. Поэтому `lib/`, `install/`, `lang/` лежат прямо в корне
репозитория, рядом с `build.sh` и `.github/`.

Плата за это — два списка в шапке `build.sh`:

* **SHIP** — уезжает на портал и в Composer-пакет;
* **KEEP** — остаётся в репозитории.

**Файл, не попавший ни в один список, роняет сборку.** Тот же список продублирован
в `.gitattributes` через `export-ignore`; списки обязаны совпадать, сверяется
автоматически, см. `check_gitattributes`.

## Что где лежит

| путь | | что это |
|---|---|---|
| `install/index.php` | SHIP | установщик, класс `shef_currency extends CModule` |
| `install/version.php` | SHIP | `VERSION` и `VERSION_DATE` — источник истины о версии |
| `.settings.php` | SHIP | зависимости и два обработчика страницы курсов |
| `include.php` | SHIP | точка входа: подключает `autoload.php` |
| `autoload.php` | SHIP | подключает модули из `requireModules` |
| `default_option.php` | SHIP | умолчания настроек |
| `options.php`, `options_conf.php` | SHIP | страница настроек на `ShOptionsConfig` из `shef.options` |
| `lib/` | SHIP | классы модуля, **имена файлов строго строчными** |
| `lang/ru/` | SHIP | языковые файлы, зеркалят структуру `lib/` |
| `README.md`, `CHANGELOG.md`, `LICENSE` | SHIP | |
| `composer.json` | SHIP | манифест пакета `bxshef/currency` |
| `docs/` | KEEP | вся документация |
| `build.sh` | KEEP | сборка и проверки |
| `tests/` | KEEP | тесты, заглушки ядра и линейки, настоящий ответ НБ РБ (`tests/fixtures/`) |
| `examples/` | KEEP | запускаемые примеры |
| `.claude/skills/` | KEEP | навыки агента: навыки линейки — **копия** из `bx-shef/options` (`MANIFEST`, раскладывает `sync.sh --to`); `shef-currency-rates` и `shef-new-api-client` — **локальные**, правятся здесь (`LOCAL.MANIFEST`, `sync.sh --local`) |
| `.github/` | KEEP | CI и релиз |
| `CONTRIBUTING.md`, `CLAUDE.md` | KEEP | процесс и памятка агенту |
| `.gitattributes`, `.gitignore` | KEEP | |

## Классы

| класс | что делает | ядро |
|---|---|---|
| `\Shef\Currency\Main\Rates` | разбор ответа НБ РБ; что писать в курсы: валюты, коэффициент, порог | не нужно |
| `\Shef\Currency\Main\Constants` | id модуля, базовая валюта BYN, строгий разбор порога и коэффициента | `Option` |
| `\Shef\Currency\Main\Utils` | базовая валюта портала — BYN? | `currency` |
| `\Shef\Currency\Sync\Api` | запрос к `services.nbrb.by` на `AConnector` из shef.insync | да |
| `\Shef\Currency\Sync\Agent` | агент на `AAgent` из shef.insync: курсы портала, запись через `CCurrencyRates` | да |
| `\Shef\Currency\Sync\Events` | кнопка на странице курсов и её обработка | да |

«Не нужно» у `Rates` — обещание, а не случайность: всё, что решает, какой курс
окажется на портале, проверяется без портала, на настоящем ответе банка.
Держит это `tests/rates_test.php`: он подключает один файл класса, без
заглушек.

## Нижний регистр в `lib/` обязателен

`Bitrix\Main\Loader` отображает класс в путь **строчными**, разбирая первые два
сегмента namespace как id модуля: `Shef\Currency\Main\Rates` ищется как
`bitrix/modules/shef.currency/lib/main/rates.php`. Поэтому свой namespace в
`registerNamespace` не нужен, и ключ пуст.

На macOS заглавная буква сходит с рук, на боевом Linux класс просто не найдётся.
Проверяется в `build.sh`, `check_lowercase`, и в `tests/autoload_test.php`.

## Установщик ничего не копирует

`installDir` в `.settings.php` пуст и был пуст в 1.x: кнопку на странице
курсов рисует обработчик события, страницу настроек — ядро. Установщик
регистрирует модуль и обработчики, при удалении снимает их, агент курсов и
(без `savedata = Y`) настройки.

## Документация не едет на портал

Документация живёт в репозитории. В поставке остаётся только `README.md` — как
readme пакета, — и все ссылки из него ведут на GitHub.
