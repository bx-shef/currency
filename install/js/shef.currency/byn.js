/**
 * Знак белорусского рубля на страницах портала — shef.currency 2.1.0.
 *
 * Формат валюты BYN в Битрикс24 — текст «# BYN» (его же видят выгрузки, REST, письма). Здесь «BYN»
 * после суммы оборачивается в <span class="nbrb-icon"> — стили (byn.css) рисуют его шрифтом НБРБ
 * знаком. Только текст страницы: поля ввода, скрипты и редактируемые области не трогаются.
 * Новые узлы (слайдеры, гриды, канбан) — наблюдателем, пачкой раз в кадр.
 */
(function (w, d) {
	'use strict';
	if (w.ShefBynSign) {
		return;
	}
	// цифра, пробел (обычный, неразрывный, узкий) или без него, «BYN» отдельным словом
	var RE = /(\d)([\s  ]?)BYN(?![A-Za-z0-9_])/;
	var SKIP = { SCRIPT: 1, STYLE: 1, TEXTAREA: 1, INPUT: 1, SELECT: 1, OPTION: 1, NOSCRIPT: 1, CODE: 1, PRE: 1, TITLE: 1 };

	function skip(el) {
		for (; el && el.nodeType === 1; el = el.parentNode) {
			if (SKIP[el.nodeName] || el.isContentEditable || (el.classList && el.classList.contains('nbrb-icon'))) {
				return true;
			}
		}
		return false;
	}

	function wrap(node) {
		var text = node.nodeValue, m = RE.exec(text);
		if (!m || skip(node.parentNode)) {
			return;
		}
		var at = m.index + m[1].length + m[2].length;
		var rest = node.splitText(at);
		var after = rest.splitText(3);
		var span = d.createElement('span');
		span.className = 'nbrb-icon';
		span.setAttribute('title', 'BYN');
		span.appendChild(d.createTextNode('BYN'));
		rest.parentNode.replaceChild(span, rest);
		wrap(after);
	}

	function scan(root) {
		if (!root) {
			return;
		}
		if (root.nodeType === 3) {
			wrap(root);
			return;
		}
		if (root.nodeType !== 1 || skip(root)) {
			return;
		}
		var it = d.createTreeWalker(root, 4, null), list = [], n;
		while ((n = it.nextNode())) {
			if (n.nodeValue.indexOf('BYN') !== -1) {
				list.push(n);
			}
		}
		list.forEach(wrap);
	}

	var queue = [], planned = false;
	function flush() {
		planned = false;
		var batch = queue;
		queue = [];
		batch.forEach(scan);
	}
	function plan(node) {
		queue.push(node);
		if (!planned) {
			planned = true;
			(w.requestAnimationFrame || setTimeout)(flush);
		}
	}

	function start() {
		scan(d.body);
		if (w.MutationObserver) {
			new w.MutationObserver(function (records) {
				records.forEach(function (r) {
					if (r.type === 'characterData') {
						plan(r.target);
					}
					for (var i = 0; i < r.addedNodes.length; i++) {
						plan(r.addedNodes[i]);
					}
				});
			}).observe(d.body, { childList: true, subtree: true, characterData: true });
		}
	}

	w.ShefBynSign = { scan: scan, re: RE };
	if (d.body) {
		start();
	} else {
		d.addEventListener('DOMContentLoaded', start);
	}
})(window, document);
