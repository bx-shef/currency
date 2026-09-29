<?php declare(strict_types=1);

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Loader;
use Shef\Options\Main\Options;
use Shef\InSync;

/**
 * Опции для страницы настроек
 * 
 * языковой файл options.php
 *
 * Tab(prefix)->Option(code) ~> код свойства: prefix_code
 */

$response = ShOptionsConfig::getInstance(
	moduleId: 'shef.currency',
	indexDoc: 'README.md'
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
						: $options->moduleId.'_DEF_DEF_TEXT_BASE_CURRENCY_SUCCESS'
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
		)
		->addOption(
			(new Options\NumberFloat('factor'))
				->setTitle(Loc::getMessage($options->moduleId.'_TAB_DEF_factor'))
				->setDescription(Loc::getMessage($options->moduleId.'_TAB_DEF_factor_descr'))
				->setMin(1.00)
				->setMax(400.00)
				->setStep(0.01)
		)
);

if(Loader::includeModule('shef.insync'))
{
	$agentEntity = \Shef\Currency\Sync\Agent::buildAgentsEntity();
	$options->getTab('DEF')->addOption(
		(new Insync\Main\Options\Agent\Option('Agent'))
			->setAgentEntity($agentEntity)
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