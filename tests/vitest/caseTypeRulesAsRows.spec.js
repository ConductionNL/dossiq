// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Rules tab against board DqZaaktypeRegels: one row per rule with a title
 * a reader understands, a line saying what it does and a pill (Calculated, On,
 * Off), in a titled card; trying a rule sits behind a disclosure.
 *
 * @spec openspec/specs/case-types/spec.md#requirement-the-rules-tab-lists-rules-as-rows
 */

import { flushPromises, mount } from '@vue/test-utils'
import fs from 'fs'
import path from 'path'
import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import nl from '../../l10n/nl.json'
import manifest from '../../src/manifest.json'
import { ruleRows } from '../../src/services/schemaRules.js'

const mockGet = vi.fn()
vi.mock('@nextcloud/axios', () => ({ default: { get: (...a) => mockGet(...a), post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (u) => u }))
vi.mock('../../src/store/modules/settings.js', () => ({ useSettingsStore: () => ({ config: {} }) }))
function stub (name) {
  return defineComponent({ name, props: ['modelValue', 'options', 'inputLabel', 'label', 'size', 'disabled'], render() {
	return h('div', { class: name }, this.$slots.default?.() ?? [])
} })
}
vi.mock('@nextcloud/vue', () => ({ NcActionButton: stub('NcActionButton'), NcActions: stub('NcActions'), NcButton: stub('NcButton'), NcDialog: stub('NcDialog'), NcLoadingIcon: stub('NcLoadingIcon'), NcSelect: stub('NcSelect'), NcTextField: stub('NcTextField') }))

const KINDS = { calculation: { description: 'Derives a value from the object and writes it before the object is stored.' }, flow: { description: 'Runs a flow after the object is stored.' } }
const PROPERTIES = {
	deadline: { title: 'Deadline', description: 'Processing deadline. While the case has a statutory term, this is that term\'s current end date.' },
	daysOverdue: {},
}
const RULES = [
	{ id: 'calculation:case:deadline', kind: 'calculation', key: 'deadline', label: 'deadline', enabled: true },
	{ id: 'calculation:case:daysOverdue', kind: 'calculation', key: 'daysOverdue', label: 'daysOverdue', enabled: true },
	{ id: 'flow:case:30e07359-1a37-4799-815f-19d586eb1e14', kind: 'flow', key: '30e07359-1a37-4799-815f-19d586eb1e14', label: '30e07359-1a37-4799-815f-19d586eb1e14', enabled: false },
]

describe('ruleRows', () => {
	it('titles a rule by the field it writes, says what it does in one sentence, and pills it', () => {
		expect(ruleRows(RULES, PROPERTIES, KINDS)).toEqual([
			{ id: 'calculation:case:deadline', title: 'Deadline', line: 'Processing deadline.', pill: 'calculated' },
			{ id: 'calculation:case:daysOverdue', title: 'Days overdue', line: 'Derives a value from the object and writes it before the object is stored.', pill: 'calculated' },
			{ id: 'flow:case:30e07359-1a37-4799-815f-19d586eb1e14', title: 'Runs a flow after the object is stored.', line: '', pill: 'off' },
		])
	})
})

describe('the Rules tab against DqZaaktypeRegels', () => {
	it('is a titled card with the board\'s line under "Regels op de zaak"', () => {
		const registry = fs.readFileSync(path.resolve(__dirname, '../../src/registry.js'), 'utf8')
		const block = registry.slice(registry.indexOf('CaseTypeRulesWidget: {'))
		expect(block.slice(0, block.indexOf('},'))).toContain("chrome: 'card'")
		const def = manifest.pages.find((p) => p.id === 'CaseTypeDetail').config.widgets.find((w) => w.id === 'case-type-rules')
		expect(nl.translations[def.title]).toBe('Regels op de zaak')
		expect(nl.translations[def.subtitle]).toBe('Alles wat het platform controleert bij het opslaan van een zaak, in de volgorde waarin het dat doet.')
	})

	it('draws rows with pills and keeps the trial behind a disclosure, without a table', async () => {
		mockGet.mockImplementation((url) => Promise.resolve({ data: url.includes('/rules/vocabulary')
			? { kinds: [{ kind: 'calculation', description: KINDS.calculation.description }, { kind: 'flow', description: KINDS.flow.description }], verdicts: [], actions: [] }
			: url.endsWith('/rules') ? { rules: RULES } : { properties: PROPERTIES } }))
		const { default: RulesTab } = await import('../../src/views/settings/tabs/RulesTab.vue')
		const w = mount(RulesTab)
		await flushPromises()
		expect(w.find('table').exists()).toBe(false)
		const rows = w.findAll('[data-testid="rules-row"]')
		expect(rows.map((r) => r.find('.rules-tab__title').text())).toEqual(['Deadline', 'Days overdue', 'Runs a flow after the object is stored.'])
		expect(rows.map((r) => r.find('.rules-tab__pill').text())).toEqual(['Calculated', 'Calculated', 'Off'])
		expect(w.find('details [data-testid="rules-case-id"]').exists()).toBe(true)
	})
})
