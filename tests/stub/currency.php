<?php declare(strict_types=1);

/**
 * Заглушки ядра под shef.currency: даты, модуль «Валюты» (валюты, курсы,
 * CCurrencyRates), административная часть (CMain, CAdminList, sessid,
 * переадресация).
 *
 * Таблица курсов — в памяти, запросы к ней разбираются ровно по тем ключам
 * фильтра, которые зовёт агент: «@CURRENCY», «=CURRENCY», «=DATE_RATE»,
 * «<DATE_RATE». Ключ, которого заглушка не знает, — исключение: агент,
 * начавший спрашивать таблицу по-другому, должен покраснеть здесь, а не
 * тихо получить всё подряд.
 *
 * Блочный синтаксис namespace: классы в нескольких пространствах имён.
 */

namespace
{
	require_once __DIR__.'/bitrix.php';
}

namespace Bitrix\Main\Type
{
	class Dictionary
	{
		public function __construct(protected array $values = []) {}

		public function get(string $name): mixed
		{
			return $this->values[$name] ?? null;
		}

		public function set(string $name, mixed $value): void
		{
			$this->values[$name] = $value;
		}

		public function setValues(array $values): void
		{
			$this->values = array_merge($this->values, $values);
		}

		public function toArray(): array
		{
			return $this->values;
		}
	}

	/**
	 * Дата ядра. «Сегодня» задаёт тест — Date::$today, формат сайта —
	 * Date::$cultureFormat: toString() отдаёт дату в нём, как ядро.
	 */
	class Date
	{
		public static string $today = '2026-09-29';
		public static string $cultureFormat = 'd.m.Y';

		protected \DateTimeImmutable $value;

		public function __construct(?string $date = null, ?string $format = null)
		{
			$this->value = null === $date
				? new \DateTimeImmutable(static::$today.' 00:00:00')
				: \DateTimeImmutable::createFromFormat('!'.($format ?? 'Y-m-d'), $date);
		}

		public function add(string $interval): static
		{
			$this->value = $this->value->add(new \DateInterval('P'.$interval));
			return $this;
		}

		public function format(string $format): string
		{
			return $this->value->format($format);
		}

		public function toString(): string
		{
			return $this->value->format(static::$cultureFormat);
		}

		public function getTimestamp(): int
		{
			return $this->value->getTimestamp();
		}
	}

	class DateTime extends Date
	{
		public function setTime(int $hour, int $minute, int $second = 0): static
		{
			$this->value = $this->value->setTime($hour, $minute, $second);
			return $this;
		}
	}
}

namespace Bitrix\Main\Web
{
	class HttpClient
	{
		public const HTTP_GET = 'GET';
		public const HTTP_POST = 'POST';

		/** Параметры, с которыми клиент создан. */
		public function __construct(public readonly array $options = []) {}
	}
}

namespace Bitrix\Currency
{
	class CurrencyManager
	{
		public static string $baseCurrency = 'BYN';

		public static function getBaseCurrency(): string
		{
			return static::$baseCurrency;
		}
	}

	/** Выборка: fetch() по одной, fetchAll() — всё. */
	class Cursor
	{
		public function __construct(private array $rows) {}

		public function fetch(): array|false
		{
			return array_shift($this->rows) ?? false;
		}

		public function fetchAll(): array
		{
			$rows = $this->rows;
			$this->rows = [];
			return $rows;
		}
	}

	class CurrencyTable
	{
		/** @var string[] валюты портала в порядке SORT */
		public static array $currencies = ['BYN', 'USD', 'EUR', 'RUB'];

		public static function getList(array $parameters = []): Cursor
		{
			return new Cursor(array_map(
				static fn(string $currency): array => ['CURRENCY' => $currency],
				static::$currencies
			));
		}
	}

	class CurrencyRateTable
	{
		/**
		 * b_catalog_currency_rate: ID => [ID, CURRENCY, DATE_RATE (Y-m-d),
		 * RATE, RATE_CNT].
		 *
		 * @var array<int, array>
		 */
		public static array $rows = [];

		/** @var list<array> что спрашивали */
		public static array $queries = [];

		public static function add(string $currency, string $date, float $rate, int $count): int
		{
			$id = empty(static::$rows) ? 1 : max(array_keys(static::$rows)) + 1;
			static::$rows[$id] = [
				'ID' => $id,
				'CURRENCY' => $currency,
				'DATE_RATE' => $date,
				'RATE' => $rate,
				'RATE_CNT' => $count,
			];

			return $id;
		}

		public static function getList(array $parameters = []): Cursor
		{
			static::$queries[] = $parameters;

			$rows = array_values(static::$rows);
			foreach(($parameters['filter'] ?? []) as $key => $value)
			{
				$date = $value instanceof \Bitrix\Main\Type\Date ? $value->format('Y-m-d') : null;
				$rows = array_values(array_filter($rows, match($key)
				{
					'@CURRENCY' => static fn(array $row): bool => in_array($row['CURRENCY'], (array)$value, true),
					'=CURRENCY' => static fn(array $row): bool => $row['CURRENCY'] === $value,
					'=DATE_RATE' => static fn(array $row): bool => $row['DATE_RATE'] === $date,
					'<DATE_RATE' => static fn(array $row): bool => $row['DATE_RATE'] < $date,
					default => throw new \LogicException('stub CurrencyRateTable: unknown filter '.$key),
				}));
			}

			$order = $parameters['order'] ?? [];
			usort($rows, static function(array $a, array $b) use ($order): int
			{
				foreach($order as $field => $direction)
				{
					$compare = $a[$field] <=> $b[$field];
					if(0 !== $compare)
					{
						return strtoupper($direction) === 'DESC' ? -$compare : $compare;
					}
				}

				return 0;
			});

			if(isset($parameters['limit']))
			{
				$rows = array_slice($rows, 0, (int)$parameters['limit']);
			}

			$select = $parameters['select'] ?? [];
			if(!empty($select))
			{
				$rows = array_map(
					static fn(array $row): array => array_intersect_key($row, array_flip($select)),
					$rows
				);
			}

			return new Cursor($rows);
		}
	}
}

namespace
{
	class CApplicationException
	{
		public function __construct(private readonly string $message) {}

		public function GetString(): string
		{
			return $this->message;
		}
	}

	/**
	 * $APPLICATION: страница, адрес с параметрами, права на модуль,
	 * исключение приложения.
	 */
	class CMain
	{
		public string $page = '/bitrix/admin/currencies_rates.php';

		/** @var array<string, string> права по модулю */
		public array $rights = ['currency' => 'W'];

		private ?CApplicationException $exception = null;

		public function GetCurPage(): string
		{
			return $this->page;
		}

		/** Как ядро: текущие параметры без $remove, плюс $add. */
		public function GetCurPageParam(string $add = '', array $remove = []): string
		{
			$query = array_diff_key(\Bitrix\Main\Context::$query, array_flip($remove));
			$query = http_build_query($query);

			$tail = implode('&', array_filter([$query, $add], static fn(string $part): bool => $part !== ''));

			return $this->page.($tail !== '' ? '?'.$tail : '');
		}

		public function GetGroupRight(string $moduleId): string
		{
			return $this->rights[$moduleId] ?? 'D';
		}

		public function ThrowException(string $message): void
		{
			$this->exception = new CApplicationException($message);
		}

		public function GetException(): ?CApplicationException
		{
			return $this->exception;
		}

		public function ResetException(): void
		{
			$this->exception = null;
		}
	}

	/**
	 * Курсы: Add и Update пишут в таблицу-заглушку. Дату принимают только в
	 * формате сайта — как ядро ($DB->IsDate()): не тот формат — отказ.
	 */
	class CCurrencyRates
	{
		/** @var list<array{0: string, 1: int, 2: array}> что звали */
		public static array $calls = [];

		/** Валюта, запись которой ядро «отклонит». */
		public static ?string $reject = null;

		/** Отклонить молча: false без исключения приложения. */
		public static bool $rejectSilently = false;

		private static function date(array $fields): ?string
		{
			$date = \DateTimeImmutable::createFromFormat('!'.\Bitrix\Main\Type\Date::$cultureFormat, (string)($fields['DATE_RATE'] ?? ''));

			return $date instanceof \DateTimeImmutable && $date->format(\Bitrix\Main\Type\Date::$cultureFormat) === $fields['DATE_RATE']
				? $date->format('Y-m-d')
				: null;
		}

		private static function reject(string $message): false
		{
			if(!static::$rejectSilently)
			{
				$GLOBALS['APPLICATION']->ThrowException($message);
			}

			return false;
		}

		public static function Add(array $fields): int|false
		{
			static::$calls[] = ['Add', 0, $fields];

			$date = static::date($fields);
			if(null === $date)
			{
				return static::reject('Wrong date');
			}

			if($fields['CURRENCY'] === static::$reject)
			{
				return static::reject('Rate rejected');
			}

			return \Bitrix\Currency\CurrencyRateTable::add($fields['CURRENCY'], $date, (float)$fields['RATE'], (int)$fields['RATE_CNT']);
		}

		public static function Update(int $id, array $fields): int|false
		{
			static::$calls[] = ['Update', $id, $fields];

			$date = static::date($fields);
			if(null === $date || !isset(\Bitrix\Currency\CurrencyRateTable::$rows[$id]))
			{
				return static::reject('Wrong date or ID');
			}

			if($fields['CURRENCY'] === static::$reject)
			{
				return static::reject('Rate rejected');
			}

			\Bitrix\Currency\CurrencyRateTable::$rows[$id] = [
				'ID' => $id,
				'CURRENCY' => $fields['CURRENCY'],
				'DATE_RATE' => $date,
				'RATE' => (float)$fields['RATE'],
				'RATE_CNT' => (int)$fields['RATE_CNT'],
			];

			return $id;
		}
	}

	if(!class_exists('CAdminMessage'))
	{
		class CAdminMessage
		{
			/** @var list<array> */
			public static array $shown = [];

			public static function ShowMessage(array|string $message): void
			{
				static::$shown[] = $message;
			}
		}
	}

	/** Список административной части; панели может и не быть. */
	class CAdminList
	{
		public ?object $context = null;

		public function __construct(bool $withContext = true)
		{
			if($withContext)
			{
				$this->context = new class
				{
					public array $items = [];
				};
			}
		}
	}

	/** Переадресация ядра завершает хит — здесь исключение с адресом. */
	final class RedirectException extends \RuntimeException
	{
		public function __construct(public readonly string $url)
		{
			parent::__construct('redirect: '.$url);
		}
	}

	function LocalRedirect(string $url): never
	{
		throw new RedirectException($url);
	}

	/** Ключ сессии и его проверка — по параметру sessid запроса. */
	function bitrix_sessid(): string
	{
		return 'sess-test';
	}

	function check_bitrix_sessid(): bool
	{
		return (\Bitrix\Main\Context::$query['sessid'] ?? '') === bitrix_sessid();
	}
}
