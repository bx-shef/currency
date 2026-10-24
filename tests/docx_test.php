<?php declare(strict_types=1);

/**
 * install/docgen/deal-byn.docx — шаблон печатной формы «Сделка» со знаком BYN (2.1.0).
 *
 * Держит: состав архива DOCX; плейсхолдеры генератора документов на месте (сумма без обозначения
 * валюты `~NS=Y` — модификатор Money, framework-docs documentgenerator/templates-and-fields.md);
 * картинка знака в архиве и на неё есть связь из документа; время записей фиксированное —
 * файл пересобирается tools/docx-template.py тем же.
 */

$root = dirname(__DIR__);

require_once $root.'/tests/assert.php';

$path = $root.'/install/docgen/deal-byn.docx';
$zip = new ZipArchive();
Check::same('архив открывается', $zip->open($path), true);

$names = [];
for($i = 0; $i < $zip->numFiles; $i++)
{
	$names[] = $zip->getNameIndex($i);
}
Check::same('состав DOCX', $names, ['[Content_Types].xml', '_rels/.rels', 'word/_rels/document.xml.rels', 'word/styles.xml', 'word/document.xml', 'word/media/byn.png']);

$doc = (string)$zip->getFromName('word/document.xml');
foreach(['{SOURCE.ID}', '{SOURCE.TITLE}', '{SOURCE.CLIENT.NAME}', '{SOURCE.OPPORTUNITY~WZ=Y,NS=Y}', '{SOURCE.OPPORTUNITY~W=Y}', '{DOCUMENT.DOCUMENT_NUMBER}', '{DOCUMENT.DOCUMENT_CREATE_TIME~format=DD.MM.YYYY}'] as $placeholder)
{
	Check::same('плейсхолдер '.$placeholder, str_contains($doc, $placeholder), true);
}
Check::same('знак — картинка с подписью BYN рядом с суммой', (bool)preg_match('~NS=Y\}</w:t></w:r>.*?<w:drawing>.*?descr="BYN".*?r:embed="rIdSign"~s', $doc), true);
Check::same('связь rIdSign ведёт на картинку', str_contains((string)$zip->getFromName('word/_rels/document.xml.rels'), 'Id="rIdSign" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/byn.png"'), true);
Check::same('картинка — PNG', substr((string)$zip->getFromName('word/media/byn.png'), 1, 3), 'PNG');
Check::same('XML документа разбирается', simplexml_load_string($doc) !== false, true);

$times = [];
for($i = 0; $i < $zip->numFiles; $i++)
{
	$times[] = date('Y-m-d H:i', $zip->statIndex($i)['mtime']);
}
Check::same('время записей фиксированное (пересборка даёт тот же файл)', array_unique($times), ['2026-01-01 00:00']);
$zip->close();

Check::finish();
