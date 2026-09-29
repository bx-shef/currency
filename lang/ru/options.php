<?php
$MESS['shef.currency_TAB_DEF_NAME'] = 'Общие';
$MESS['shef.currency_TAB_DEF_TITLE'] = 'Общие настройки';
$MESS['shef.currency_TAB_DEF_NOT_SET_MODULE_shef_insync'] = 'Для работы агента нужен модуль shef.insync';

$MESS['shef.currency_DEF_TEXT_BASE_CURRENCY_ERROR'] = join('<br>', [
	'Для нормальной работы нужно [URL=/bitrix/admin/settings.php?mid=currency]сделать[/URL] валюту [B]#MODULE_CURRENCY#[/B] базовой',
	'Базовая валюта проекта [B]#BASE_CURRENCY#[/B]',
]);
$MESS['shef.currency_DEF_TEXT_BASE_CURRENCY_SUCCESS'] = 'Базовая валюта проекта [B]#BASE_CURRENCY#[/B]';

$MESS['shef.currency_TAB_DEF_sizeChange'] = 'Менять курс только при колебании не менее чем на %';
$MESS['shef.currency_TAB_DEF_sizeChange_descr'] = 'Изменение курса за единицу валюты в процентах от действующего на портале. Меньше — курс не записывается, действует прежний. 0 — записывать всегда';
$MESS['shef.currency_TAB_DEF_factor'] = 'Коэффициент';
$MESS['shef.currency_TAB_DEF_factor_descr'] = 'На него умножается курс НБ РБ перед записью: от 1 до 400. 1 — курс как есть';
