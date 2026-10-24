// install/js/shef.currency/byn.js — знак BYN на страницах: что оборачивается, что нет.
// Маленький DOM здесь же (без зависимостей): узлы, splitText, обход текста — ровно то, что зовёт скрипт.
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { runInNewContext } from 'node:vm'

class Node {
	constructor(type, name) { this.nodeType = type; this.nodeName = name; this.childNodes = []; this.parentNode = null }
	appendChild(n) { n.parentNode = this; this.childNodes.push(n); return n }
	insertAfter(n, ref) { n.parentNode = this; this.childNodes.splice(this.childNodes.indexOf(ref) + 1, 0, n) }
	replaceChild(n, old) { const i = this.childNodes.indexOf(old); n.parentNode = this; this.childNodes[i] = n; old.parentNode = null }
	get textContent() { return this.nodeType === 3 ? this.nodeValue : this.childNodes.map((c) => c.textContent).join('') }
}
class Text extends Node {
	constructor(v) { super(3, '#text'); this.nodeValue = v }
	splitText(at) { const t = new Text(this.nodeValue.slice(at)); this.nodeValue = this.nodeValue.slice(0, at); this.parentNode.insertAfter(t, this); return t }
}
class El extends Node {
	constructor(name) { super(1, name.toUpperCase()); this.className = ''; this.attrs = {}; this.isContentEditable = false }
	get classList() { return { contains: (c) => this.className.split(' ').includes(c) } }
	setAttribute(k, v) { this.attrs[k] = v }
	get html() { return this.childNodes.map((c) => c.nodeType === 3 ? c.nodeValue : `<${c.nodeName.toLowerCase()}${c.className ? ` class="${c.className}"` : ''}>${c.html}</${c.nodeName.toLowerCase()}>`).join('') }
}
const el = (name, ...kids) => { const e = new El(name); kids.forEach((k) => e.appendChild(typeof k === 'string' ? new Text(k) : k)); return e }

function texts(root, out = []) { for (const c of root.childNodes) c.nodeType === 3 ? out.push(c) : texts(c, out); return out }
let observer = null
function run(body) {
	const document = {
		body, createElement: (n) => new El(n), createTextNode: (v) => new Text(v), addEventListener() {},
		createTreeWalker: (root) => { const list = texts(root); let i = 0; return { nextNode: () => list[i++] || null } },
	}
	const window = { requestAnimationFrame: (f) => f(), MutationObserver: class { constructor(cb) { observer = cb } observe() {} } }
	runInNewContext(readFileSync(new URL('../install/js/shef.currency/byn.js', import.meta.url), 'utf8'), { window, document })
	return window
}

const sign = '<span class="nbrb-icon">BYN</span>'
const body = el('body',
	el('div', '12 345,50 BYN'),
	el('div', 'Итого: 100 BYN и 5 BYN'),
	el('div', 'BYNX 10BYNE BYN без суммы'),
	el('textarea', '7 BYN'),
	el('div', el('span', 'сумма 9 BYN')),
)
const ce = el('div', '3 BYN'); ce.isContentEditable = true; body.appendChild(ce)
const w = run(body)

assert.equal(body.childNodes[0].html, '12 345,50 ' + sign, 'сумма с пробелом')
assert.equal(body.childNodes[1].html, 'Итого: 100 ' + sign + ' и 5 ' + sign, 'неразрывный пробел и две суммы в одном узле')
assert.equal(body.childNodes[2].html, 'BYNX 10BYNE BYN без суммы', 'не слово, не после цифры — не трогаем')
assert.equal(body.childNodes[3].html, '7 BYN', 'поле ввода не трогаем')
assert.equal(body.childNodes[4].html, '<span>сумма 9 ' + sign + '</span>', 'вложенный текст')
assert.equal(ce.html, '3 BYN', 'редактируемую область не трогаем')
assert.equal(body.textContent.includes('BYN'), true, 'текст «BYN» остаётся — без шрифта виден код')

const before = body.childNodes[0].html
w.ShefBynSign.scan(body)
assert.equal(body.childNodes[0].html, before, 'повторный обход не оборачивает дважды')

const late = el('div', 'новая сделка 500 BYN')
body.appendChild(late)
observer([{ type: 'childList', addedNodes: [late] }])
assert.equal(late.html, 'новая сделка 500 ' + sign, 'новые узлы — наблюдателем')

console.log('byn.js: ok')
