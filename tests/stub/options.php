<?php

/**
 * Заглушка API страницы настроек shef.options 3.x — ровно то, что зовёт
 * options_conf.php этого модуля. Числовые поля запоминают границы: их
 * сверяет tests/optionsconf_test.php с разбором в Main\Constants.
 *
 * Сигнатуры повторяют shef.options 3.x (optionsconfig.php,
 * lib/main/options/*.php), логики нет: опции только запоминают, что им
 * передали. Ради сигнатур всё и затеяно — в 1.x options_conf.php звал
 * ShOptionsConfig::getInstance(indexDoc: ...), в 3.x такого параметра нет, и
 * страница настроек падала «Unknown named parameter». Здесь это Error.
 *
 * Поменяется API в shef.options — эта заглушка обязана поменяться вместе с
 * ним, иначе тест проверяет прошлое.
 */

namespace Shef\Options\Main\Options
{
	enum TypeUIAlert: string
	{
		case Error = 'ui-alert-danger';
		case Note = 'ui-alert-default';
		case Warning = 'ui-alert-warning';
	}

	abstract class AOption
	{
		protected string $title = '';
		protected string $description = '';

		public function __construct(protected readonly string $code) {}

		public function getCode(): string
		{
			return $this->code;
		}

		public function setTitle(string $value): static
		{
			$this->title = $value;
			return $this;
		}

		public function getTitle(): string
		{
			return $this->title;
		}

		public function setDescription(string $value): static
		{
			$this->description = $value;
			return $this;
		}

		public function getDescription(): string
		{
			return $this->description;
		}

		protected string $defValue = '';

		public function setDefValue(string $value): static
		{
			$this->defValue = $value;
			return $this;
		}

		public function getDefValue(): string
		{
			return $this->defValue;
		}
	}

	class RowInfo extends AOption
	{
		public ?TypeUIAlert $type = null;

		public function setType(TypeUIAlert $value): static
		{
			$this->type = $value;
			return $this;
		}
	}

	class Text extends AOption
	{
		public int $size = 15;

		public function setSize(int $value): static
		{
			$this->size = $value;
			return $this;
		}
	}

	class NumberInt extends AOption
	{
		public ?int $min = null;
		public ?int $max = null;
		public ?int $step = null;

		public function setMin(int $value): static
		{
			$this->min = $value;
			return $this;
		}

		public function setMax(int $value): static
		{
			$this->max = $value;
			return $this;
		}

		public function setStep(int $value): static
		{
			$this->step = $value;
			return $this;
		}
	}

	class NumberFloat extends AOption
	{
		public ?float $min = null;
		public ?float $max = null;
		public ?float $step = null;

		public function setMin(float $value): static
		{
			$this->min = $value;
			return $this;
		}

		public function setMax(float $value): static
		{
			$this->max = $value;
			return $this;
		}

		public function setStep(float $value): static
		{
			$this->step = $value;
			return $this;
		}
	}

	class Tab
	{
		protected string $name = '';
		protected string $title = '';
		/** @var AOption[] */
		protected array $options = [];

		public function __construct(protected readonly string $code) {}

		public function getCode(): string
		{
			return $this->code;
		}

		public function setName(string $value): static
		{
			$this->name = $value;
			return $this;
		}

		public function getName(): string
		{
			return $this->name;
		}

		public function setTitle(string $value): static
		{
			$this->title = $value;
			return $this;
		}

		public function addOption(AOption $value): static
		{
			$this->options[] = $value;
			return $this;
		}

		/** @return AOption[] */
		public function getOptionList(): array
		{
			return $this->options;
		}
	}
}

namespace
{
	use Bitrix\Main\Result;
	use Shef\Options\Main\Options;

	class ShOptionsConfig
	{
		/** @var Options\Tab[] */
		private array $tabs = [];

		protected function __construct(public readonly string $moduleId) {}

		public static function getInstance(string $moduleId): Result
		{
			return (new Result())->setData(['OPTIONS' => new static($moduleId)]);
		}

		public function addTab(null|Options\Tab $tab): static
		{
			if(null !== $tab)
			{
				$this->tabs[$tab->getCode()] = $tab;
			}

			return $this;
		}

		public function getTab(string $code): null|Options\Tab
		{
			return $this->tabs[$code] ?? null;
		}

		/** @return Options\Tab[] */
		public function get(): array
		{
			return array_values($this->tabs);
		}
	}
}
