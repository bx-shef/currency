<?php declare(strict_types=1);

namespace Shef\Currency\Sync;

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\Date;
use Shef\Options\Main\Utils;
use Shef\Options\TraitList;
use Shef\Problems\Factory\Trait as ProblemsTraitList;
use Shef\Currency\Main\Constants;

Loc::loadMessages(__FILE__);

/**
 * Кнопка «Запросить курсы валют» на странице курсов
 * /bitrix/admin/currencies_rates.php.
 *
 * Обработчики зарегистрированы на каждый хит административной части, а
 * модуль могут снять раньше зависимостей. Поэтому без shef.options или
 * shef.problems класс — пустышка: вызов обработчика не роняет страницу.
 */

if(
	!\Bitrix\Main\ModuleManager::isModuleInstalled('shef.options')
	|| !in_array(
		\Bitrix\Main\Loader::includeSharewareModule('shef.problems'),
		[
			\Bitrix\Main\Loader::MODULE_INSTALLED,
			\Bitrix\Main\Loader::MODULE_DEMO,
		]
	)
)
{
	class Events
	{
		public static function __callStatic(string $name, array $arguments): mixed
		{
			return null;
		}
	}

	return;
}

class Events
{
	use TraitList\Tools\SelfClass;
	use ProblemsTraitList\LoggerProblems;

	public const PAGE = '/bitrix/admin/currencies_rates.php';

	/**
	 * Параметры ссылки кнопки и ответа после запроса.
	 */
	public const PARAM_ACTION = 'getCurrency';
	public const PARAM_TOMORROW = 'tomorrow';
	public const PARAM_RESULT = 'shefCurrencyResult';

	public const RESULT_OK = 'ok';
	public const RESULT_FAIL = 'fail';

	// region ProblemsTraitList\LoggerProblems ////
	public static function getModuleId(): string
	{
		return Constants::getModuleId();
	}

	public static function getAssignedId(): int
	{
		return \Shef\Problems\Main\Constants::getSyncUserId();
	}

	protected static function getAuditType(): string
	{
		return \Shef\Problems\Main\Constants::AuditTypeSync;
	}
	// endregion ////

	/**
	 * Писать курсы может тот, кто может их править руками: «W» и выше на
	 * модуль currency. До 2.0.0 хватало «R» — права на просмотр.
	 */
	public static function canWrite(): bool
	{
		$application = Utils::getCMainApplication();

		return null !== $application
			&& $application->GetGroupRight('currency') >= 'W';
	}

	private static function isRatesPage(): bool
	{
		return Utils::getCMainApplication()?->GetCurPage() === static::PAGE;
	}

	/**
	 * Кнопка на странице курсов и итог последнего запроса.
	 *
	 * В ссылках — sessid: запрос меняет курсы, и без него курсы переписывала
	 * бы любая ссылка или картинка на чужой странице, открытая
	 * администратором (CSRF).
	 *
	 * @param \CAdminList $list
	 */
	public static function onAdminListDisplayHandler(\CAdminList &$list): void
	{
		if(!static::isRatesPage() || !static::canWrite())
		{
			return;
		}

		static::showResult();

		// Контекстной панели у списка может не быть — тогда и кнопку класть
		// некуда. До 2.0.0 здесь был fatal.
		if(!is_object($list->context ?? null))
		{
			return;
		}

		$application = Utils::getCMainApplication();
		$sessid = 'sessid='.urlencode(bitrix_sessid());
		$drop = [static::PARAM_ACTION, static::PARAM_TOMORROW, static::PARAM_RESULT, 'sessid', 'mode'];

		$list->context->items[-3] = [
			'TEXT' => Loc::getMessage('shef.currency_BTN_ACTION_GET'),
			'TITLE' => Loc::getMessage('shef.currency_BTN_ACTION_GET'),
			'MENU' => [
				[
					'TEXT' => Loc::getMessage('shef.currency_BTN_ACTION_GET_TODAY'),
					'TITLE' => Loc::getMessage('shef.currency_BTN_ACTION_GET_TODAY'),
					'LINK' => $application->GetCurPageParam(
						static::PARAM_ACTION.'=Y&'.$sessid,
						$drop
					),
				],
				[
					'TEXT' => Loc::getMessage('shef.currency_BTN_ACTION_GET_TOMORROW'),
					'TITLE' => Loc::getMessage('shef.currency_BTN_ACTION_GET_TOMORROW'),
					'LINK' => $application->GetCurPageParam(
						static::PARAM_ACTION.'=Y&'.static::PARAM_TOMORROW.'=Y&'.$sessid,
						$drop
					),
				]
			]
		];
	}

	/**
	 * Итог запроса — сообщением над списком. До 2.0.0 страница молча
	 * перезагружалась, и что курсы не записались, было видно только в
	 * журнале.
	 */
	private static function showResult(): void
	{
		$request = \Bitrix\Main\Application::getInstance()->getContext()->getRequest();
		$value = (string)$request->getQuery(static::PARAM_RESULT);

		if($value === static::RESULT_OK)
		{
			\CAdminMessage::ShowMessage([
				'TYPE' => 'OK',
				'MESSAGE' => Loc::getMessage('shef.currency_RESULT_OK'),
			]);
		}
		elseif($value === static::RESULT_FAIL)
		{
			\CAdminMessage::ShowMessage([
				'TYPE' => 'ERROR',
				'MESSAGE' => Loc::getMessage('shef.currency_RESULT_FAIL'),
			]);
		}
	}

	/**
	 * Нажатие кнопки: курсы на сегодня или на завтра — в Б24, ошибки — в
	 * журнал, итог — параметром в адресе после переадресации.
	 *
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public static function onBeforePrologHandler(): void
	{
		$request = \Bitrix\Main\Application::getInstance()->getContext()->getRequest();

		if(
			!static::isRatesPage()
			|| $request->getQuery(static::PARAM_ACTION) !== 'Y'
		)
		{
			return;
		}

		$application = Utils::getCMainApplication();
		$drop = [static::PARAM_ACTION, static::PARAM_TOMORROW, static::PARAM_RESULT, 'sessid'];

		// Без прав и без sessid — ничего не делаем и убираем параметры из
		// адреса: страница откроется как обычно.
		if(!static::canWrite() || !check_bitrix_sessid())
		{
			LocalRedirect($application->GetCurPageParam('', $drop));
			// Ядро завершает хит в LocalRedirect(); return — на случай, если
			// нет: дальше идёт запись курсов.
			return;
		}

		$date = new Date();
		if($request->getQuery(static::PARAM_TOMORROW) === 'Y')
		{
			$date->add('1D');
		}

		/** @var Agent $agent */
		$agent = Agent::getInstance();
		$response = $agent->sync($date);
		if(!$response->isSuccess())
		{
			static::createLogger()->error($response);
		}

		LocalRedirect($application->GetCurPageParam(
			static::PARAM_RESULT.'='.($response->isSuccess() ? static::RESULT_OK : static::RESULT_FAIL),
			$drop
		));
	}
}
