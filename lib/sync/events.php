<?php declare(strict_types=1);

namespace Shef\Currency\Sync;

use Bitrix\Main\Localization\Loc;
use Shef\Options\TraitList;
use Shef\Options\Main\Utils;
use Shef\Problems\Factory\Trait as ProblemsTraitList;
use Shef\Currency\Main\Constants;

Loc::loadMessages(__FILE__);

/**
 * Обработка событий
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
	use TraitList\Events;
	use TraitList\EventResponse;
	use TraitList\Tools\SelfClass;
	use ProblemsTraitList\LoggerProblems;
	
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
	 * Вывод на странице валют кнопок
	 * @param \CAdminList $list
	 * @return void
	 */
	public static function onAdminListDisplayHandler(\CAdminList &$list): void
	{
		if(Utils::getCMainApplication()->GetCurPage() === '/bitrix/admin/currencies_rates.php')
		{
			$list->context->items[-3] = [
				'TEXT' => Loc::getMessage('shef.currency_BTN_ACTION_GET'),
				'TITLE' => Loc::getMessage('shef.currency_BTN_ACTION_GET'),
				'MENU' => [
					[
						'TEXT' => Loc::getMessage('shef.currency_BTN_ACTION_GET_TODAY'),
						'TITLE' => Loc::getMessage('shef.currency_BTN_ACTION_GET_TODAY'),
						'LINK' => Utils::getCMainApplication()->GetCurPageParam('getCurrency=Y', ['mode']),
					],
					[
						'TEXT' => Loc::getMessage('shef.currency_BTN_ACTION_GET_TOMORROW'),
						'TITLE' => Loc::getMessage('shef.currency_BTN_ACTION_GET_TOMORROW'),
						'LINK' => Utils::getCMainApplication()->GetCurPageParam('getCurrency=Y&tomorrow=Y', ['mode']),
					]
				]
			];
		}
	}
	
	/**
	 * Обработка клика по кнопке
	 *
	 * @return void
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ArgumentNullException
	 * @throws \Bitrix\Main\InvalidOperationException
	 * @throws \Bitrix\Main\ObjectNotFoundException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 * @throws \Psr\Container\NotFoundExceptionInterface
	 */
	public static function onBeforePrologHandler(): void
	{
		$request = \Bitrix\Main\Application::getInstance()->getContext()->getRequest();
		
		if(
			Utils::getCMainApplication()->GetCurPage() === '/bitrix/admin/currencies_rates.php'
			&& $request->getQuery('getCurrency') === 'Y'
			&& Utils::getCMainApplication()->GetGroupRight('currency') > 'D'
		)
		{
			/** @var Agent $agent */
			$agent = Agent::getInstance();
			
			$agent->getParams()->set('mode', Mode::Undefined);
			if($request->getQuery('tomorrow'))
			{
				$agent->getParams()->set('mode', Mode::Tomorrow);
			}
			
			$response = $agent->action();
			if(!$response->isSuccess())
			{
				$logger = static::createLogger();
				$logger->error($response);
			}
			
			LocalRedirect(Utils::getCMainApplication()->GetCurPageParam(
				'',
				[
					'getCurrency',
					'tomorrow'
				]
			));
		}
	}
}