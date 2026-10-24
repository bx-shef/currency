<?php declare(strict_types=1);

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Loader;
use Shef\Options\Main\Options;
use Shef\InSync;
use Shef\Currency\Main\Constants;

/**
 * Опции для страницы настроек
 *
 * языковой файл options.php
 *
 * Tab(prefix)->Option(code) ~> код свойства: prefix_code
 */

// indexDoc больше не передаём: вкладка «Документация» ушла из shef.options в
// 3.0.0 вместе с параметром, и именованный аргумент, которого нет, — это
// Error «Unknown named parameter», то есть неоткрывающаяся страница настроек.
$response = ShOptionsConfig::getInstance(
	moduleId: 'shef.currency'
);
if(!$response->isSuccess())
{
	return $response;
}

/** @var ShOptionsConfig $options */
$options = $response->getData()['OPTIONS'];

$resultCheckBaseCurrency = \Shef\Currency\Main\Utils::checkBaseCurrency();

$options->addTab(
	(new Options\Tab('DEF'))
		->setName(Loc::getMessage($options->moduleId.'_TAB_DEF_NAME'))
		->setTitle(Loc::getMessage($options->moduleId.'_TAB_DEF_TITLE'))
		->addOption(
			(new Options\RowInfo('DEF_TEXT_BASE_CURRENCY'))
				->setDescription(Loc::getMessage(
					(
						!$resultCheckBaseCurrency->isSuccess()
						? $options->moduleId.'_DEF_TEXT_BASE_CURRENCY_ERROR'
						: $options->moduleId.'_DEF_TEXT_BASE_CURRENCY_SUCCESS'
					),
					[
						'#BASE_CURRENCY#' => $resultCheckBaseCurrency->getData()['baseCurrency'],
						'#MODULE_CURRENCY#' => $resultCheckBaseCurrency->getData()['moduleCurrency']
					]
				))
				->setType(
					!$resultCheckBaseCurrency->isSuccess()
						? Options\TypeUIAlert::Error
						: Options\TypeUIAlert::Note
				)
		)
		->addOption(
			(new Options\NumberInt('sizeChange'))
				->setTitle(Loc::getMessage($options->moduleId.'_TAB_DEF_sizeChange'))
				->setDescription(Loc::getMessage($options->moduleId.'_TAB_DEF_sizeChange_descr'))
				->setMin(0)
				->setMax(Constants::MAX_SIZE_CHANGE)
				->setDefValue((string)Constants::DEFAULT_SIZE_CHANGE)
		)
		->addOption(
			(new Options\NumberFloat('factor'))
				->setTitle(Loc::getMessage($options->moduleId.'_TAB_DEF_factor'))
				->setDescription(Loc::getMessage($options->moduleId.'_TAB_DEF_factor_descr'))
				->setMin(Constants::MIN_FACTOR)
				->setMax(Constants::MAX_FACTOR)
				->setStep(0.01)
				->setDefValue((string)Constants::DEFAULT_FACTOR)
		)
		->addOption(
			(new Options\Enum('sign'))
				->setTitle(Loc::getMessage($options->moduleId.'_TAB_DEF_sign'))
				->setDescription(Loc::getMessage($options->moduleId.'_TAB_DEF_sign_descr'))
				->setList([
					\Shef\Currency\Main\Sign::MODE_SIGN => Loc::getMessage($options->moduleId.'_TAB_DEF_sign_sign'),
					\Shef\Currency\Main\Sign::MODE_BR => Loc::getMessage($options->moduleId.'_TAB_DEF_sign_br'),
					\Shef\Currency\Main\Sign::MODE_CODE => Loc::getMessage($options->moduleId.'_TAB_DEF_sign_code'),
					\Shef\Currency\Main\Sign::MODE_KEEP => Loc::getMessage($options->moduleId.'_TAB_DEF_sign_keep'),
				])
				->setDefValue(\Shef\Currency\Main\Sign::DEFAULT_MODE)
		)
);

// Формат BYN — по сохранённой настройке: страница открывается и после «Сохранить», так что
// выбор применяется сразу. Пишет только тот, кто может менять настройки; без изменений — ничего.
if(\Shef\Options\Main\Utils::getCMainApplication()?->GetGroupRight($options->moduleId) >= 'W')
{
	\Shef\Currency\Main\Sign::apply();
}

// Агента ставит установщик. Страница ставит его, только если его нет, —
// и только тому, кто может менять настройки модуля: просмотр страницы с
// правом «R» в b_agent не пишет.
//
// Ветка else — на случай, если shef.insync не подключился. Штатно до неё не
// доходит: без зависимости из requireModules autoload.php бросает
// LoaderException раньше, и options.php показывает его текст.
if(Loader::includeModule('shef.insync'))
{
	$canWrite = \Shef\Options\Main\Utils::getCMainApplication()?->GetGroupRight($options->moduleId) >= 'W';
	
	$options->getTab('DEF')->addOption(
		(new InSync\Main\Options\Agent\Option('Agent'))
			->setAgentEntity(\Shef\Currency\Sync\Agent::buildAgentsEntity($canWrite))
	);
}
else
{
	$options->getTab('DEF')->addOption(
		(new Options\RowInfo('DEF_NOTE'))
			->setDescription(Loc::getMessage($options->moduleId.'_TAB_DEF_NOT_SET_MODULE_shef_insync'))
			->setType(Options\TypeUIAlert::Error)
	);
}

return $options->get();
