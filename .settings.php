<?php declare(strict_types=1);

/**
 * Настраиваемые параметры модуля
 *
 * * requireModules -> обязательные модули
 * * requirePhpExt -> обязательные расширения PHP
 * * registerAutoLoadClasses -> авто подгрузка классов
 * * registerNamespace -> авто подгрузка Namespace
 * * options -> опции устанавливаемые через окружение
 * * installEvents -> события для установки
 * * installDir -> пути установки файлов
 * * controllers -> контроллеры для ajax
 *
 * Классы самого модуля (Shef\Currency\...) в registerNamespace не нужны:
 * ядро отображает их в lib/ по соглашению. Чужих библиотек у модуля нет.
 *
 * shef.uiclear в зависимостях до 2.0.0 стоял, но не использовался ни одной
 * строкой — снят (решение по линейке: штатные возможности Битрикс24).
 *
 * simplexml — ответ НБ РБ разбирается им (Main\Rates::parseXml()). До 2.0.0
 * здесь стоял xmlreader: разбор шёл через трейт shef.insync и его копию
 * xml-navigator.
 *
 * installDir — знак белорусского рубля (2.1.0): стили и шрифт install/css/shef.currency
 * → /bitrix/css/shef.currency, скрипт install/js/shef.currency → /bitrix/js/shef.currency
 * (каталог модуля браузеру закрыт). Подключает их обработчик
 * main:OnProlog (Main\Sign), когда обозначение BYN — «знак».
 */

return [
	'requireModules' => [
		'value' => [
			'shef.options',
			'shef.problems',
			'shef.insync',
			'currency',
		],
		'readonly' => true,
	],
	'requirePhpExt' => [
		'value' => [
			'simplexml',
		],
		'readonly' => true,
	],
	'registerAutoLoadClasses' => [
		'value' => [],
		'readonly' => true,
	],
	'registerNamespace' => [
		'value' => [],
		'readonly' => true,
	],
	'options' => [
		'value' => [],
		'readonly' => true,
	],
	'installEvents' => [
		'value' => [
			[
				'isCompatible' => true,
				'from' => [
					'module' => 'main',
					'event' => 'OnAdminListDisplay'
				],
				'to' => [
					'module' => 'shef.currency',
					'class' => '\Shef\Currency\Sync\Events',
					'function' => 'onAdminListDisplayHandler'
				]
			],
			[
				'isCompatible' => true,
				'from' => [
					'module' => 'main',
					'event' => 'OnBeforeProlog'
				],
				'to' => [
					'module' => 'shef.currency',
					'class' => '\Shef\Currency\Sync\Events',
					'function' => 'onBeforePrologHandler'
				]
			],
			[
				'isCompatible' => true,
				'from' => [
					'module' => 'main',
					'event' => 'OnProlog'
				],
				'to' => [
					'module' => 'shef.currency',
					'class' => '\Shef\Currency\Main\Sign',
					'function' => 'onPrologHandler'
				]
			]
		],
		'readonly' => true,
	],
	'installDir' => [
		'value' => [
			[
				'type' => 'css',
				'from' => '/install/css',
				'to' => '/bitrix/css',
				'customPathUnInstall' => [],
				'isNeedUnInstall' => true,
			],
			[
				'type' => 'js',
				'from' => '/install/js',
				'to' => '/bitrix/js',
				'customPathUnInstall' => [],
				'isNeedUnInstall' => true,
			],
		],
		'readonly' => true,
	],
	'controllers' => [
		'value' => [
			'namespaces' => [],
		],
		'readonly' => true,
	]
];
