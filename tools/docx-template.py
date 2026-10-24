#!/usr/bin/env python3
"""Шаблон печатной формы «Сделка» со знаком белорусского рубля — install/docgen/deal-byn.docx.

    python3 tools/docx-template.py

Генератор документов Битрикс24 подставляет плейсхолдеры {SOURCE.…} (framework-docs,
modules/documentgenerator/templates-and-fields.md). Сумма — модификатором NS=Y (без обозначения
валюты), знак — картинкой рядом: шрифтов НБРБ в Word и PDF нет, а картинка видна везде.
Пересобирается одинаково (время в архиве — фиксированное).
"""
import os
import zipfile
from xml.sax.saxutils import escape

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(ROOT, 'install', 'docgen', 'deal-byn.docx')
PNG = os.path.join(ROOT, 'tools', 'byn-192.png')
# Знак высотой с прописную букву 11 pt: 8 pt = 101600 EMU, пропорции картинки 155×192
SIGN_H = 101600
SIGN_W = SIGN_H * 155 // 192

NS = ('xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" '
      'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
      'xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" '
      'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
      'xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"')


def run(text, bold=False, size=None):
    rpr = ''.join(['<w:b/>' if bold else '', f'<w:sz w:val="{size}"/>' if size else ''])
    return f'<w:r>{f"<w:rPr>{rpr}</w:rPr>" if rpr else ""}<w:t xml:space="preserve">{escape(text)}</w:t></w:r>'


def sign(pid):
    # Статичная картинка, не плейсхолдер: генератор оставляет её как есть
    return (f'<w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0"><wp:extent cx="{SIGN_W}" cy="{SIGN_H}"/>'
            f'<wp:docPr id="{pid}" name="Знак BYN {pid}" descr="BYN"/>'
            '<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:pic>'
            f'<pic:nvPicPr><pic:cNvPr id="{pid}" name="byn.png" descr="BYN"/><pic:cNvPicPr/></pic:nvPicPr>'
            '<pic:blipFill><a:blip r:embed="rIdSign"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
            f'<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="{SIGN_W}" cy="{SIGN_H}"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr>'
            '</pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r>')


def p(*runs, align=None, after=120):
    jc = '<w:jc w:val="%s"/>' % align if align else ''
    ppr = f'<w:pPr>{jc}<w:spacing w:after="{after}"/></w:pPr>'
    return f'<w:p>{ppr}{"".join(runs)}</w:p>'


def row(label, *value):
    cell = lambda content, w: f'<w:tc><w:tcPr><w:tcW w:w="{w}" w:type="dxa"/></w:tcPr>{content}</w:tc>'
    return f'<w:tr>{cell(p(run(label)), 3600)}{cell(p(*value), 5800)}</w:tr>'


body = ''.join([
    p(run('Сделка № {SOURCE.ID}', bold=True, size=32), after=60),
    p(run('{DOCUMENT.DOCUMENT_NUMBER} от {DOCUMENT.DOCUMENT_CREATE_TIME~format=DD.MM.YYYY}', size=20)),
    '<w:tbl><w:tblPr><w:tblW w:w="9400" w:type="dxa"/><w:tblBorders>'
    + ''.join(f'<w:{s} w:val="single" w:sz="4" w:color="BFC8D6"/>' for s in ('top', 'left', 'bottom', 'right', 'insideH', 'insideV'))
    + '</w:tblBorders></w:tblPr><w:tblGrid><w:gridCol w:w="3600"/><w:gridCol w:w="5800"/></w:tblGrid>',
    row('Сделка', run('{SOURCE.TITLE}')),
    row('Клиент', run('{SOURCE.CLIENT.NAME}')),
    row('Сумма', run('{SOURCE.OPPORTUNITY~WZ=Y,NS=Y}', bold=True), run(' '), sign(1)),
    row('Сумма прописью', run('{SOURCE.OPPORTUNITY~W=Y}')),
    '</w:tbl>',
    p(run(''), after=200),
    p(run('Знак белорусского рубля — Национальный банк Республики Беларусь (постановление № 25 от 27.01.2026).', size=16)),
])

document = (f'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document {NS}><w:body>{body}'
            '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1134" w:right="850" w:bottom="1134" w:left="1418" w:header="709" w:footer="709" w:gutter="0"/></w:sectPr>'
            '</w:body></w:document>')

files = {
    '[Content_Types].xml': '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
    '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
    '<Default Extension="png" ContentType="image/png"/>'
    '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
    '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/></Types>',
    '_rels/.rels': '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>',
    'word/_rels/document.xml.rels': '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    '<Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
    '<Relationship Id="rIdSign" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/byn.png"/></Relationships>',
    'word/styles.xml': '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
    '<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:cs="Arial"/><w:sz w:val="22"/><w:lang w:val="ru-RU"/></w:rPr></w:rPrDefault></w:docDefaults></w:styles>',
    'word/document.xml': document,
}

os.makedirs(os.path.dirname(OUT), exist_ok=True)
with zipfile.ZipFile(OUT, 'w', zipfile.ZIP_DEFLATED) as z:
    for name, data in files.items():
        info = zipfile.ZipInfo(name, (2026, 1, 1, 0, 0, 0))
        info.compress_type = zipfile.ZIP_DEFLATED
        z.writestr(info, data)
    info = zipfile.ZipInfo('word/media/byn.png', (2026, 1, 1, 0, 0, 0))
    z.writestr(info, open(PNG, 'rb').read())
print(OUT)
