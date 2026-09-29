<?php

use Bitrix\Main\Entity\Event;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\EventManager;
use Bitrix\Main\Application;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Result;
use Bitrix\Main\Error;
use Bitrix\Main\Config;
use Bitrix\Main\Text;
use Bitrix\Intranet\CustomSection\Entity\CustomSectionPageTable;
use Bitrix\Intranet\CustomSection\Entity\CustomSectionTable;
use Bitrix\Intranet\CustomSection\Entity\EO_CustomSection;
use Bitrix\Intranet\CustomSection\Entity\EO_CustomSectionPage;

if(class_exists('shef_currency'))
{
	return;
}

Loc::loadMessages(__FILE__);

Class shef_currency
	extends CModule
{
	public $MODULE_ID = 'shef.currency';
	public $MODULE_VERSION;
	public $MODULE_VERSION_DATE;
	public $MODULE_NAME;
	public $MODULE_DESCRIPTION;
	public $MODULE_SORT;
	public $MODULE_GROUP_RIGHTS = 'Y';

	public $PARTNER_NAME;
	public $PARTNER_URI;
	
	/** @var string  */
	public $PHP_MIN_VER = '8.1.0';
	/** @var string  */
	public $NEED_MAIN_VERSION = '22.600.300';
	/** @var array  */
	public $NEED_MODULES = [
		'shef.options',
		'shef.uiclear',
		'shef.problems',
		'shef.insync',
		'currency'
	];
	
	/** @var \CMain  */
	private $application;
	
	public function __construct()
	{
		$arModuleVersion = [];
		
		require __DIR__.'/version.php';

		$this->MODULE_VERSION = $arModuleVersion["VERSION"];
		$this->MODULE_VERSION_DATE = $arModuleVersion["VERSION_DATE"];
		
		$this->MODULE_NAME = Loc::getMessage('shef.currency_MODULE_NAME');
		$this->MODULE_DESCRIPTION = Loc::getMessage('shef.currency_MODULE_DESC');
		
		$sort = 'MODULE_SORT';
		$this->{$sort} = (int)'1101';

		$this->PARTNER_NAME = Loc::getMessage('shef.currency_PARTNER_NAME');
		$this->PARTNER_URI = Loc::getMessage('shef.currency_PARTNER_URI');
		
		$this->application = $GLOBALS['APPLICATION'];
	}
	
	// region DB && Register Module ////
	/**
	 * Register Module
	 *
	 * @param array $arParams
	 * @return bool
	 * @throws \Bitrix\Main\LoaderException
	 *
	 * @memo Need call first
	 */
	public function InstallDB(array $arParams = []): bool
	{
		RegisterModule($this->MODULE_ID);
		
		try
		{
			\Bitrix\Main\Loader::includeModule($this->MODULE_ID);
			
			$this->installLeftMenu();
		}
		catch(\Throwable $throwable)
		{
		
		}
		
		
		return true;
	}
	
	/**
	 * UnRegister Module
	 *
	 * @param array $arParams
	 * @return bool
	 * @throws \Bitrix\Main\LoaderException
	 *
	 * @memo Need call last
	 */
	public function UnInstallDB(array $arParams = []): bool
	{
		try
		{
			\Bitrix\Main\Loader::includeModule($this->MODULE_ID);
		}
		catch(\Bitrix\Main\LoaderException $loaderException)
		{
		
		}
		
		$this->unInstallLeftMenu();
		
		UnRegisterModule($this->MODULE_ID);
		
		$GLOBALS['CACHE_MANAGER']->CleanAll();
		
		return true;
	}
	// endregion ////
	
	// region LeftMenu ////
	private function getLeftMenuList(): array
	{
		$configuration = Config\Configuration::getInstance($this->MODULE_ID);
		$list = $configuration->get('installLeftMenu');
		if(!is_array($list))
		{
			$list = [];
		}
		
		return $list;
	}
	
	public function installLeftMenu(): bool
	{
		if(!ModuleManager::isModuleInstalled('intranet'))
		{
			return false;
		}
		
		/** @var CustomSectionTable $sectionDataManager */
		$sectionDataManager = CustomSectionTable::class;
		
		/** @var CustomSectionPageTable $pageDataManager */
		$pageDataManager = CustomSectionPageTable::class;
		
		foreach($this->getLeftMenuList() as $sectionFields)
		{
			if(isset($sectionFields['moduleId']))
			{
				$section = $sectionDataManager::getList([
					'filter' => [
						'=MODULE_ID' => (string)$sectionFields['moduleId'],
						'=CODE' => (string)$sectionFields['code']
					],
					'select' => [
						'ID',
						'MODULE_ID'
					]
				])->fetchObject();
			}
			else
			{
				$section = $sectionDataManager::createObject();
				$section->setModuleId($this->MODULE_ID);
				$section->setTitle((string)$sectionFields['title']);
				$section->setCode((string)$sectionFields['code']);
				
				$response = $section->save();
				if(!$response->isSuccess())
				{
					return false;
				}
			}
			
			if(empty($section))
			{
				return false;
			}
			
			foreach((array)$sectionFields['pages'] as $pageFields)
			{
				$page = ($pageDataManager::createObject())
					->setModuleId($section->getModuleId())
					->setCustomSection($section)
					->setCode((string)$pageFields['code'])
					->setTitle((string)$pageFields['title'])
					->setSort((int)$pageFields['sort'])
					->setSettings((string)$pageFields['settingsRow'])
				;
				
				$response = $page->save();
				if(!$response->isSuccess())
				{
					return false;
				}
			}
		}
		
		return true;
	}
	
	public function unInstallLeftMenu(): bool
	{
		if(!IsModuleInstalled('intranet'))
		{
			return false;
		}
		
		/** @var CustomSectionTable $sectionDataManager */
		$sectionDataManager = CustomSectionTable::class;
		
		/** @var CustomSectionPageTable $pageDataManager */
		$pageDataManager = CustomSectionPageTable::class;
		
		$list = $sectionDataManager::getList([
			'filter' => [
				'=MODULE_ID' => $this->MODULE_ID
			]
		])->fetchCollection()->getAll();
		
		array_walk(
			$list,
			function(EO_CustomSection $section)
			{
				$section->delete();
			}
		);
		
		foreach($this->getLeftMenuList() as $sectionFields)
		{
			if(isset($sectionFields['moduleId']))
			{
				$pageList = $pageDataManager::getList([
					'filter' => [
						'=MODULE_ID' => (string)$sectionFields['moduleId'],
						'=CODE' => array_column((array)$sectionFields['pages'], 'code')
					],
					'select' => ['ID']
				])->fetchCollection()->getAll();
				
				array_walk(
					$pageList,
					function(EO_CustomSectionPage $page)
					{
						$page->delete();
					}
				);
			}
		}
		
		return true;
	}
	// endregion ////
	
	// region Events ////
	private function getEventsList(): array
	{
		$configuration = Config\Configuration::getInstance($this->MODULE_ID);
		$list = $configuration->get('installEvents');
		if(!is_array($list))
		{
			$list = [];
		}
		
		return $list;
	}

	public function InstallEvents(): bool
	{
		$eventManager = EventManager::getInstance();

		foreach($this->getEventsList() as $event)
		{
			if($event['isCompatible'])
			{
				$eventManager->registerEventHandlerCompatible(
					$event['from']['module'],
					$event['from']['event'],
					$event['to']['module'],
					$event['to']['class'],
					$event['to']['function'],
					$event['to']['sort'] ?? 100,
				);
			}
			else
			{
				$eventManager->registerEventHandler(
					$event['from']['module'],
					$event['from']['event'],
					$event['to']['module'],
					$event['to']['class'],
					$event['to']['function'],
					$event['to']['sort'] ?? 100,
				);
			}
		}

		return true;
	}

	public function UnInstallEvents(): bool
	{
		$eventManager = EventManager::getInstance();
		
		// region init Event onModuleUnInstall ////
		$eventsList = $eventManager->findEventHandlers($this->MODULE_ID, 'onModuleUnInstall');
		if (!empty($eventsList))
		{
			$event = new Event($this->MODULE_ID, 'onModuleUnInstall');
			$event->send();
		}
		// endregion ////
		
		foreach($this->getEventsList() as $event)
		{
			$eventManager->unRegisterEventHandler(
				$event['from']['module'],
				$event['from']['event'],
				$event['to']['module'],
				$event['to']['class'],
				$event['to']['function'],
			);
		}

		return true;
	}
	// endregion ////
	
	// region Files ////
	private function getDirList(): array
	{
		$configuration = Config\Configuration::getInstance($this->MODULE_ID);
		$list = $configuration->get('installDir');
		if(!is_array($list))
		{
			$list = [];
		}
		
		return $list;
	}
	
	public function InstallFiles(array $arParams = []): bool
	{
		$this->innerConvertEncoding();
		
		$docRoot = Application::getDocumentRoot();
		$fromPath = $docRoot.'/bitrix/modules/'.$this->MODULE_ID;
		$toPath = $docRoot;
		
		foreach($this->getDirList() as $map)
		{
			\CopyDirFiles(
				$fromPath.$map['from'],
				$toPath.$map['to'],
				true,
				true
			);
		}
		
		return true;
	}
	
	private function innerConvertEncoding(): void
	{
		if(!Application::isUtfMode())
		{
			return;
		}
		
		$docRoot = Application::getDocumentRoot();
		$fromPath = $docRoot.'/bitrix/modules/'.$this->MODULE_ID;
		
		$originalModuleFiles = static::getFiles(
			$fromPath,
			['.svn', '.hg', '.git'],
			true
		);
		
		foreach($originalModuleFiles as $file)
		{
			$fromFile = $fromPath.$file;
			$toFile = $fromFile;
			
			if(!(
				mb_substr($file, -4) === '.php'
				|| mb_substr($file, -3) === '.md'
				|| mb_substr($file, -4) === '.csv'
				|| mb_substr($file, -4) === '.xml'
			))
			{
				continue;
			}
			
			$fileContents = file_get_contents($fromFile);
			if(!$fileContents)
			{
				continue;
			}
			
			$currentCharset = static::getStringCharset($fileContents);
			
			if($currentCharset === 'cp1251')
			{
				$fileContents = Text\Encoding::convertEncoding(
					$fileContents,
					'cp1251',
					'utf8'
				);
			}
			
			file_put_contents($toFile, $fileContents);
		}
	}
	
	public function UnInstallFiles(): bool
	{
		$response = $this->checkChildModules();

		if(!$response->isSuccess())
		{
			$this->application->ThrowException(
				join(PHP_EOL.'<br>',
					array_merge(
						[Loc::getMessage('SH_PROBLEM_UNINSTALL_MODULE')],
						$response->getErrorMessages()
					)
				)
			);
			
			return false;
		}
		
		if($_ENV['COMPUTERNAME'] !== 'SH')
		{
			$docRoot = Application::getDocumentRoot();
			$toPath = $docRoot;
			
			foreach($this->getDirList() as $map)
			{
				if($map['isNeedUnInstall'] === false)
				{
					continue;
				}
				
				if(
					isset($map['customPathUnInstall'])
					&& is_array($map['customPathUnInstall'])
					&& !empty($map['customPathUnInstall'])
				)
				{
					foreach($map['customPathUnInstall'] as $customPath)
					{
						\Bitrix\Main\IO\Directory::deleteDirectory(
							$toPath.$customPath
						);
					}
				}
				else
				{
					$list = [
						$this->MODULE_ID,
						str_replace('.', '-', $this->MODULE_ID)
					];
					
					foreach($list as $moduleId)
					{
						\Bitrix\Main\IO\Directory::deleteDirectory(
							$toPath.$map['to'].'/'.$moduleId
						);
					}
				}
			}
		}
		
		return true;
	}
	// endregion ////
	
	// region Install.Public ////
	public function DoInstall(): void
	{
		$phpVer = phpversion();
		
		if(version_compare($phpVer, $this->PHP_MIN_VER, '<'))
		{
			$this->ShowForm(
				'ERROR',
				Loc::getMessage('SH_NEED_PHP_VER', [
					'#CURRENT#' => $phpVer,
					'#NEED#' => $this->PHP_MIN_VER,
				])
			);
		}
		
		if(
			is_array($this->NEED_MODULES)
			&& !empty($this->NEED_MODULES)
		){
			foreach($this->NEED_MODULES as $module)
			{
				if(!ModuleManager::isModuleInstalled($module))
				{
					$this->ShowForm(
						'ERROR',
						Loc::getMessage('SH_NEED_MODULES', [
							'#NEED#' => $module
						])
					);
				}
			}
		}

		if(
			mb_strlen($this->NEED_MAIN_VERSION) <= 0
			|| version_compare(SM_VERSION, $this->NEED_MAIN_VERSION) >= 0
		)
		{
			$response = $this->InstallDB();
			$response = $this->InstallEvents();
			$response = $this->InstallFiles();

			$this->ShowForm(
				'OK',
				Loc::getMessage('SH_MOD_INST_OK')
			);
		}
		else
		{
			$this->ShowForm(
				'ERROR',
				Loc::getMessage('SH_NEED_RIGHT_VER', [
					'#NEED#' => $this->NEED_MAIN_VERSION
				])
			);
		}
	}

	public function DoUninstall(): void
	{
		$response = $this->UnInstallFiles();
		if(!$response)
		{
			/** @var \CApplicationException $problem */
			$problem = $this->application->GetException();
			$this->ShowForm(
				'ERROR',
				(
					$problem instanceof \CApplicationException
					? $problem->GetString()
					: 'Error Uninstall'
				)
			);
		}
		$response = $this->UnInstallEvents();
		$response = $this->UnInstallDB();
	}

	private function ShowForm(
		string $type,
		string $message,
		string $buttonName = ''
	): void
	{
		/** @memo need for show title at prolog */
		$keys = array_keys($GLOBALS);
		for($i = 0; $i < count($keys); $i++)
		{
			if(
				$keys[$i] != 'i'
				&& $keys[$i] != 'GLOBALS'
				&& $keys[$i] != 'strTitle'
				&& $keys[$i] != 'filepath'
			)
			{
				global ${$keys[$i]};
			}
		}
		global $APPLICATION, $adminPage, $adminMenu, $adminChain, $USER;
		
		$this->application->SetTitle(
			Loc::getMessage('shef.currency_MODULE_NAME')
		);

		include(Application::getDocumentRoot().'/bitrix/modules/main/include/prolog_admin_after.php');

		\CAdminMessage::ShowMessage([
			'MESSAGE' => $message,
			'TYPE' => $type,
			'HTML' => true
		]);
		?>
		<form action="<?=$this->application->GetCurPage()?>" method="get">
			<p>
				<input type="hidden" name="lang" value="<?=LANG?>">
				<input type="submit" value="<?=($buttonName <> '' ? $buttonName : Loc::getMessage('SH_MOD_BACK'))?>">
			</p>
		</form>
		<?php
		include(Application::getDocumentRoot().'/bitrix/modules/main/include/epilog_admin.php');
		die();
	}
	// endregion /////
	
	// region Tools ////
	private static function getFiles(
		string $path,
		array $filter = [],
		bool $isAllFiles = false,
		bool $recursive = false
	): array
	{
		static $len;
		if(!$recursive || !$len)
		{
			$len = mb_strlen($path);
		}
		
		$retVal = [];
		if($dir = opendir($path))
		{
			while(false !== $item = readdir($dir))
			{
				if(in_array($item, array_merge(
					['.', '..', '.svn', '.hg', '.git'],
					$filter
				)))
				{
					continue;
				}
				
				if(is_dir($file = $path.'/'.$item))
				{
					$retVal = array_merge(
						$retVal,
						static::getFiles(
							$file,
							$filter,
							$isAllFiles,
							true
						)
					);
				}
				else
				{
					if($isAllFiles || substr($file, -4) == '.php')
					{
						$retVal[] = str_replace(
							'\\',
							'/',
							substr($file, $len)
						);
					}
				}
			}
			
			closedir($dir);
		}
		
		return $retVal;
	}
	
	private static function getStringCharset(string $str): string
	{
		if(preg_match("/[\xe0\xe1\xe3-\xff]/", $str))
		{
			return 'cp1251';
		}
		
		$str0 = Text\Encoding::convertEncoding(
			$str,
			'utf8',
			'cp1251'
		);
		
		if(preg_match("/[\xe0\xe1\xe3-\xff]/", $str0, $regs))
		{
			return 'utf8';
		}
		
		return 'ascii';
	}
	
	private function checkChildModules(): Result
	{
		$result = new Result();
		foreach(ModuleManager::getInstalledModules() as $moduleId => $moduleDesc)
		{
			if($this->MODULE_ID === $moduleId)
			{
				continue;
			}
			
			$requireModules = Config\Configuration::getInstance($moduleId)->get('requireModules');
			if(empty($requireModules) || !is_array($requireModules))
			{
				continue;
			}
			
			if(in_array($this->MODULE_ID, $requireModules))
			{
				$result->addError(new Error(Loc::getMessage('SH_NEED_UNINSTALL_MODULE_BEFORE', [
					'#TARGET#' => $moduleId
				])));
			}
		}
		
		return $result;
	}
	// endregion /////
}