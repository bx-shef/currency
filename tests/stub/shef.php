<?php declare(strict_types=1);

/**
 * Заглушки модулей линейки, на которых стоят классы shef.currency:
 * shef.options 3.x, shef.problems 2.x, shef.insync 2.x.
 *
 * Базовый класс или трейт нужен уже в момент объявления класса модуля, и
 * без него настоящий класс не подключить. Сигнатуры — дословно из
 * зависимостей, логика — только та, от которой зависит проверяемое
 * поведение: что агент поставлен с нужными аргументами, что ушло в лог,
 * какой ответ API вернулся. Остальное — пустые оболочки.
 *
 * Поменяется API зависимости — заглушка обязана поменяться вместе с ним,
 * иначе тест проверяет прошлое. Источники:
 * https://github.com/bx-shef/options, https://github.com/bx-shef/problems,
 * https://github.com/bx-shef/insync.
 */

namespace
{
	require_once __DIR__.'/bitrix.php';
	require_once __DIR__.'/currency.php';
	require_once __DIR__.'/options.php';
}

// region shef.options ////
namespace Shef\Options\Options
{
	abstract class Singleton
	{
		private static array $instanceList = [];

		protected function __construct() {}

		public static function getInstance(): static
		{
			return self::$instanceList[static::class] ??= new static();
		}

		/** Только для тестов: следующий getInstance() создаст объект заново. */
		public static function resetInstances(): void
		{
			self::$instanceList = [];
		}
	}

	class SmartStd extends \stdClass
	{
		public function toArray(): array
		{
			return array_map(
				static fn($value) => $value instanceof self ? $value->toArray() : $value,
				get_object_vars($this)
			);
		}
	}
}

namespace Shef\Options\Main
{
	class Context
	{
		public const SCOPE_TASK = 'task';

		private int $userId = 0;

		public function setUserId(int $userId): static
		{
			$this->userId = $userId;
			return $this;
		}

		public function setScope(string $scope): static
		{
			return $this;
		}

		public function getUserId(): int
		{
			return $this->userId;
		}
	}

	class Constants
	{
		/** Служебный пользователь из настроек shef.options. */
		public static int $systemUserId = 7;

		public static function getSystemUserId(): int
		{
			return static::$systemUserId;
		}
	}

	class Utils
	{
		public static function getCMainApplication(): ?\CMain
		{
			$application = $GLOBALS['APPLICATION'] ?? null;

			return $application instanceof \CMain ? $application : null;
		}
	}
}

namespace Shef\Options\TraitList
{
	trait Modules
	{
		abstract protected static function getModulesList(): array;

		protected static function includeModules(): \Bitrix\Main\Result
		{
			$result = new \Bitrix\Main\Result();

			foreach(static::getModulesList() as $module)
			{
				if(!\Bitrix\Main\Loader::includeModule($module))
				{
					return $result->addError(new \Bitrix\Main\Error('module '.$module.' not loaded'));
				}
			}

			return $result;
		}
	}
}

namespace Shef\Options\TraitList\Tools
{
	trait SelfClass
	{
		final public static function getClassName(): string
		{
			return '\\'.get_called_class();
		}
	}

	trait DateTime
	{
		protected function initDateTime(): void {}
	}

	trait IsDebug
	{
		private bool $isDebugMode = false;

		public function setIsDebug(bool $isDebug): self
		{
			$this->isDebugMode = $isDebug;
			return $this;
		}

		public function isDebug(): bool
		{
			return $this->isDebugMode;
		}
	}

	trait Encoding
	{
		abstract public static function getEncodingFrom(): string;
	}

	trait OptionCollection
	{
		private \Bitrix\Main\Type\Dictionary $optionCollection;

		protected function initOptionCollection(): void
		{
			$this->optionCollection = new \Bitrix\Main\Type\Dictionary();
		}

		final public function addOptionCollection(string $name, mixed $value): self
		{
			$this->optionCollection->set($name, $value);
			return $this;
		}

		final public function getOptionCollection(): \Bitrix\Main\Type\Dictionary
		{
			return $this->optionCollection;
		}
	}
}

namespace Shef\Options\TraitList\Security
{
	trait FixUser
	{
		abstract protected static function getInitedUserId(): int;

		protected static function initUser(): void {}

		public static function closeUser(): void {}
	}
}
// endregion ////

// region shef.problems ////
namespace Shef\Problems\Main
{
	class Constants
	{
		public const AuditTypeSync = 'SH_PROBLEMS_SYNC';

		public static int $syncUserId = 5;

		public static function getSyncUserId(): int
		{
			return static::$syncUserId;
		}

		public static function getDefUserId(): int
		{
			return 1;
		}
	}
}

namespace Shef\Problems\Throwable
{
	class Manager
	{
		public static function buildError(\Throwable $throwable, bool $isTrace = false, int $code = 0, array $data = []): \Bitrix\Main\Error
		{
			return new \Bitrix\Main\Error($throwable->getMessage());
		}
	}
}

namespace Shef\Problems\Factory\Trait
{
	/**
	 * Логгер: запоминает, что записано и от чьего имени. Логгер shef.problems
	 * принимает Result, Error, массив и строку — здесь так же, mixed.
	 */
	final class TestLogger
	{
		/** @var list<array{level: string, message: mixed, auditType: string, moduleId: string, assigned: int}> */
		public static array $records = [];

		public function __construct(
			private readonly string $auditType = '',
			private readonly string $moduleId = '',
			private readonly int $assigned = 0
		) {}

		public function __call(string $level, array $arguments): void
		{
			static::$records[] = [
				'level' => $level,
				'message' => $arguments[0] ?? null,
				'auditType' => $this->auditType,
				'moduleId' => $this->moduleId,
				'assigned' => $this->assigned,
			];
		}
	}

	trait LoggerProblems
	{
		protected TestLogger $logger;

		abstract public static function getClassName(): string;

		abstract public static function getModuleId(): string;

		public static function getAssignedId(): int
		{
			return \Shef\Problems\Main\Constants::getDefUserId();
		}

		protected static function getAuditType(): string
		{
			return 'SH_PROBLEMS_PROBLEM';
		}

		protected static function createLogger(): TestLogger
		{
			return new TestLogger(static::getAuditType(), static::getModuleId(), static::getAssignedId());
		}

		protected function initLogger(): void
		{
			$this->logger = static::createLogger();
		}

		public function configureLogger(TestLogger $logger): static
		{
			$this->logger = $logger;
			return $this;
		}
	}

	trait DebuggerProblems
	{
		protected TestLogger $debugger;

		protected static function createDebugger(): TestLogger
		{
			return new TestLogger('debug', static::getModuleId());
		}

		protected function initDebugger(): void
		{
			$this->debugger = static::createDebugger();
		}

		public function configureDebugger(TestLogger $debugger): static
		{
			$this->debugger = $debugger;
			return $this;
		}
	}
}
// endregion ////

// region shef.insync ////
namespace Shef\InSync\Agents
{
	use Bitrix\Main\Result;
	use Bitrix\Main\Type\DateTime;
	use Bitrix\Main\Type\Dictionary;
	use Shef\Options\Main\Context;
	use Shef\Options\Options\Singleton;
	use Shef\Options\TraitList;
	use Shef\Problems\Factory\Trait as ProblemsTraitList;

	/**
	 * Сущность агента. Конструктор — с теми же именами и порядком
	 * аргументов, что в shef.insync 2.x: агент модуля зовёт его по именам,
	 * и имя, которого нет, здесь — Error, как на портале. Свойства —
	 * закрытые, наружу — геттеры, как в настоящем классе.
	 */
	class Entity
	{
		/** @var array<string, int> «b_agent»: имя агента -> ID */
		public static array $agents = [];

		private int $id = 0;
		private Dictionary $params;
		private int $userId;

		public function __construct(
			private string $module,
			private string $name,
			array $params,
			private bool $isPeriodic = true,
			private int $period = 86400,
			private int $sort = 100,
			?int $userId = null
		)
		{
			$this->params = new Dictionary($params);
			$this->userId = $userId ?? \Shef\Options\Main\Constants::getSystemUserId();
			$this->id = static::$agents[$this->prepareNameForDb()] ?? 0;
		}

		final public function getId(): int { return $this->id; }
		final public function getName(): string { return $this->name; }
		final public function getModule(): string { return $this->module; }
		final public function isActive(): bool { return false; }
		final public function isPeriodic(): bool { return $this->isPeriodic; }
		final public function getPeriod(): int { return $this->period; }
		final public function getSort(): int { return $this->sort; }
		final public function getUserId(): int { return $this->userId; }

		public function setParams(array $params): self
		{
			$this->params = new Dictionary($params);
			return $this;
		}

		/** Дословно из shef.insync 2.x: строка агента в b_agent. */
		final public function prepareNameForDb(): string
		{
			$agentParams = [];
			foreach($this->params->toArray() as $key => $value)
			{
				if(null !== $value && !is_scalar($value))
				{
					throw new \Bitrix\Main\ArgumentException(
						sprintf('Agent param "%s" must be scalar, %s given', $key, get_debug_type($value)),
						'params'
					);
				}

				$agentParams[] = var_export((string)$key, true).'=>'.var_export((string)$value, true);
			}

			return $this->name.'(['.implode(',', $agentParams).']);';
		}

		final public function reInit(): self
		{
			$this->id = static::$agents[$this->prepareNameForDb()] ?? 0;
			return $this;
		}
	}

	class Manager
	{
		/** @var list<array{name: string, module: string, isPeriodic: bool, period: int, active: bool, nextExec: ?string, sort: int, userId: int}> */
		public static array $installed = [];

		/** Как в shef.insync 2.x: уже стоит — ничего; иначе CAgent::AddAgent. */
		public static function install(Entity $agent, ?DateTime $nextExec = null): Result
		{
			if($agent->getId() > 0)
			{
				return new Result();
			}

			static::$installed[] = [
				'name' => $agent->prepareNameForDb(),
				'module' => $agent->getModule(),
				'isPeriodic' => $agent->isPeriodic(),
				'period' => $agent->getPeriod(),
				'active' => $agent->isActive(),
				'nextExec' => $nextExec?->format('Y-m-d H:i'),
				'sort' => $agent->getSort(),
				'userId' => $agent->getUserId(),
			];
			Entity::$agents[$agent->prepareNameForDb()] = count(static::$installed);
			$agent->reInit();

			return new Result();
		}
	}

	/**
	 * AAgent shef.insync 2.x — логика process() и конструктора дословно,
	 * без вывода на экран. Логгер и отладчик конструктор НЕ задаёт: их
	 * задаёт только process(). Код, который зовёт агент мимо process(),
	 * обязан позаботиться о них сам — заглушка не должна это скрывать.
	 */
	abstract class AAgent
		extends Singleton
	{
		use TraitList\Modules;
		use TraitList\Tools\DateTime;
		use TraitList\Tools\IsDebug;
		use TraitList\Tools\SelfClass;
		use TraitList\Security\FixUser;
		use ProblemsTraitList\LoggerProblems;
		use ProblemsTraitList\DebuggerProblems;

		private bool $isNeedStop = false;

		private Dictionary $params;

		private static array $contextList = [];

		public static function getContext(): Context
		{
			return self::$contextList[static::class] ??= (new Context())
				->setUserId(\Shef\Options\Main\Constants::getSystemUserId())
				->setScope(Context::SCOPE_TASK);
		}

		protected static function getAuditType(): string
		{
			return \Shef\Problems\Main\Constants::AuditTypeSync;
		}

		protected static function getInitedUserId(): int
		{
			return static::getContext()->getUserId();
		}

		protected static function getModulesList(): array
		{
			return [
				'shef.options',
				'shef.problems',
				'shef.insync',
			];
		}

		final public static function process(array $params = []): string
		{
			$result = new Result();

			$logger = static::createLogger();
			$debugger = static::createDebugger();

			$isDebug = (string)($params['debug'] ?? '') === 'Y';
			$isNeedStop = false;

			$response = static::includeModules();
			if(!$response->isSuccess())
			{
				$result->addErrors($response->getErrors());
			}

			if($result->isSuccess())
			{
				try
				{
					static::initUser();

					$agent = static::getInstance();
					$agent->setParams($params)
						->setIsDebug($isDebug)
						->configureLogger($logger)
						->configureDebugger($debugger);

					$response = $agent->action();
					if(!$response->isSuccess())
					{
						$result->addErrors($response->getErrors());
					}
					else
					{
						$isNeedStop = $agent->isNeedStop();
					}
				}
				catch(\Throwable $throwable)
				{
					$result->addError(\Shef\Problems\Throwable\Manager::buildError($throwable, true));
				}
				finally
				{
					static::closeUser();
				}
			}

			if(!$result->isSuccess())
			{
				$logger->critical($result);
			}

			if($isNeedStop)
			{
				return '';
			}

			return static::getName($params);
		}

		public static function getName(array $params = []): string
		{
			$entity = static::buildAgentsEntity();
			unset($params['debug']);

			$entity->setParams($params);

			return $entity->prepareNameForDb();
		}

		abstract public static function buildAgentsEntity(): Entity;

		protected function __construct()
		{
			parent::__construct();
			$this->params = new Dictionary();
			$this->initDateTime();
			$this->init();
		}

		protected function init(): void {}

		final public function isNeedStop(): bool
		{
			return $this->isNeedStop;
		}

		final public function setParams(array $params): self
		{
			$this->getParams()->setValues($params);
			return $this;
		}

		final public function getParams(): Dictionary
		{
			return $this->params;
		}

		abstract public function action(): Result;
	}
}

namespace Shef\InSync\Api
{
	use Bitrix\Main\Result;
	use Bitrix\Main\Web\HttpClient;
	use Shef\Options\TraitList;
	use Shef\Problems\Factory\Trait as ProblemsTraitList;

	abstract class AConnector
	{
		use TraitList\Tools\IsDebug;
		use TraitList\Tools\OptionCollection;
		use TraitList\Tools\Encoding;
		use TraitList\Tools\SelfClass;
		use ProblemsTraitList\LoggerProblems;
		use ProblemsTraitList\DebuggerProblems;

		/**
		 * Ответы «сервера» по очереди: Result, как его отдал бы
		 * sendRequest() — в data.data.response тело ответа.
		 *
		 * @var Result[]
		 */
		public static array $responses = [];

		/** @var list<array{url: string, params: array, method: string}> что запрашивали */
		public static array $requests = [];

		protected static function getAuditType(): string
		{
			return \Shef\Problems\Main\Constants::AuditTypeSync;
		}

		protected null|HttpClient $httpClient = null;

		/** Как в shef.insync 2.x: клиент, логгер, отладчик. */
		public function __construct()
		{
			$this->initOptionCollection();

			$this->initHttp();
			$this->initLogger();
			$this->initDebugger();
		}

		/** Как в shef.insync 2.x: переадресация — да, до пяти раз. */
		protected function initHttp(): void
		{
			$this->httpClient = new HttpClient([
				'version' => '1.1',
				'redirect' => true,
				'redirectMax' => 5,
			]);

			$this->reInitHttpParams();
		}

		public function reInitHttpParams(): self
		{
			return $this;
		}

		protected function getHttpClient(): null|HttpClient
		{
			return $this->httpClient;
		}

		/** Только для тестов: с какими параметрами создан клиент. */
		public function getHttpOptions(): array
		{
			return $this->httpClient?->options ?? [];
		}

		abstract protected function getPath(string $functionName): string;

		/** Дословно из shef.insync 2.x: GET — параметры в адрес. */
		final public function sendRequest(
			string $functionName,
			array $params,
			string $method = HttpClient::HTTP_POST,
			array $headers = []
		): Result
		{
			$url = $this->getPath($functionName);
			if($method === HttpClient::HTTP_GET)
			{
				$url = $url.'?'.http_build_query($params);
				$params = [];
			}

			static::$requests[] = ['url' => $url, 'params' => $params, 'method' => $method];

			return array_shift(static::$responses) ?? (new Result())->addError(new \Bitrix\Main\Error('status: 0'));
		}

		/** Ответ «сервера» с телом $body. */
		public static function answer(string $body): Result
		{
			return (new Result())->setData(['data' => ['response' => $body]]);
		}
	}
}

namespace Shef\InSync\Main\Options\Agent
{
	use Shef\InSync\Agents;
	use Shef\Options\Main\Options as ShefOptions;

	/** Строка агента на странице настроек. */
	class Option
		extends ShefOptions\RowInfo
	{
		private ?Agents\Entity $agentEntity = null;

		public function setAgentEntity(Agents\Entity $agentEntity): self
		{
			$this->agentEntity = $agentEntity;
			return $this;
		}

		public function getAgentEntity(): Agents\Entity
		{
			if(null === $this->agentEntity)
			{
				throw new \Bitrix\Main\ArgumentNullException('agentEntity');
			}

			return $this->agentEntity;
		}
	}
}
// endregion ////
