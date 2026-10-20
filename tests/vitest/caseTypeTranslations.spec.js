/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case type page edits a label in a second language, and says what is
 * still missing.
 *
 * The editor is the library widget `object-translations`
 * (CnObjectTranslationsWidget); its rules (chips per register language, a
 * stale label counted as missing, the whole language map written) are pinned
 * by the library's own spec. What is pinned here is the dossiq half: the case
 * type page declares the widget under its stored id, places it in the layout,
 * and names a registered icon, in both the full and the simple menu layout.
 *
 * @spec openspec/specs/case-configuration-i18n/spec.md
 */
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)
const simpleLayout = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'menu-layout.simple.json'), 'utf8'),
)
const registrySource = fs.readFileSync(path.join(ROOT, 'src', 'registry.js'), 'utf8')
const iconsSource = fs.readFileSync(path.join(ROOT, 'src', 'icons.js'), 'utf8')

/**
 * Every object anywhere in a JSON value that has the given key and value.
 *
 * @param {unknown} node The JSON value.
 * @param {string} key The key.
 * @param {unknown} value The value.
 * @return {Array<object>} The matches.
 */
function findAll(node, key, value) {
	const found = []
	if (Array.isArray(node)) {
		node.forEach((entry) => found.push(...findAll(entry, key, value)))
	} else if (node && typeof node === 'object') {
		if (node[key] === value) {
			found.push(node)
		}
		Object.values(node).forEach((entry) => found.push(...findAll(entry, key, value)))
	}
	return found
}

describe('the case type page renders the library translations widget', () => {
	const page = manifest.pages.find((entry) => entry.id === 'CaseTypeDetail')
	const widget = page.config.widgets.find(
		(entry) => entry.id === 'case-type-translations',
	)

	it('declares object-translations under the stored widget id', () => {
		expect(widget.type).toBe('object-translations')
		expect(widget.icon).toBe('Translate')
		expect(iconsSource).toContain(
			"import Translate from 'vue-material-design-icons/Translate.vue'",
		)
	})

	it('reads the case type in the register and schema the page is on', () => {
		expect(page.config.register).toBe('dossiq')
		expect(page.config.schema).toBe('caseType')
	})

	it('places it in the layout', () => {
		expect(
			page.config.layout.some((cell) => cell.widgetId === 'case-type-translations'),
			'a widget with no layout cell is declared and never placed',
		).toBe(true)
	})

	it('keeps no dossiq copy, slot or registry entry behind', () => {
		expect(page.slots?.['widget-case-type-translations']).toBeUndefined()
		expect(registrySource).not.toContain('CaseTypeTranslationsWidget')
		expect(
			fs.existsSync(path.join(ROOT, 'src', 'components', 'caseType', 'CaseTypeTranslationsWidget.vue')),
		).toBe(false)
	})

	it('patches the simple layout to the same library type', () => {
		const patched = findAll(simpleLayout, 'type', 'object-translations')
		expect(patched.length).toBeGreaterThan(0)
		expect(JSON.stringify(simpleLayout)).not.toContain('CaseTypeTranslationsWidget')
	})
})
