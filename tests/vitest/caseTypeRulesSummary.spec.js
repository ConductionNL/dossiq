// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqZaaktypeOverzicht, Regels: the Overview card lists the case
 * type's own rules as the Rules tab does ("Als … / dan …" with an Aan pill),
 * without the tab's menus, Add button, inventory or trial.
 *
 * @spec openspec/specs/case-types/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import nl from '../../l10n/nl.json'

vi.mock('@nextcloud/l10n', () => {
	const fill = (text, vars) => String(text).replace(/\{(\w+)\}/g, (m, k) => (vars && k in vars ? vars[k] : m))
	return {
		translate: (app, text, vars) => fill((app === 'dossiq' ? nl.translations : require('@conduction/nextcloud-vue/l10n/nl.json').translations)[text] || text, vars),
		translatePlural: (app, one, other, n, vars) => fill(n === 1 ? one : other, { count: n, ...vars }),
		getLanguage: () => 'nl',
		getLocale: () => 'nl_NL',
		getCanonicalLocale: () => 'nl-NL',
	}
})

const mockGet = vi.fn()
const mockSave = vi.fn()
vi.mock('@nextcloud/axios', () => ({ default: { get: (...a) => mockGet(...a), post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (u) => u }))
vi.mock('../../src/store/modules/settings.js', () => ({ useSettingsStore: () => ({ config: {} }) }))

const CATALOGUE = {
	propertyDefinition: [{ id: 'p0', name: 'situatietekening', propertyType: 'string' }, { id: 'p2', name: 'welstandsgebied', title: 'Welstandsgebied', propertyType: 'boolean' }],
	statusType: [{ id: 'st-geweigerd', name: 'Geweigerd' }],
	documentType: [],
	emailTemplate: [{ id: 'tpl-weigering', name: 'Weigering met bezwaarclausule' }],
	roleType: [{ id: 'role-architect', name: 'Architect' }],
}
// The parent's bouwkosten comes in through the blueprint; the type's own rows hold only welstandsgebied.
const BLUEPRINT = {
	propertyDefinitions: [{ id: 'p1', name: 'bouwkosten', propertyType: 'number', origin: 'inherited' }, ...CATALOGUE.propertyDefinition.slice(1)],
	statusTypes: CATALOGUE.statusType,
}
const RULES = [
	{ id: 'constructeur', enabled: true, when: { event: 'saved' }, conditions: [{ kind: 'fieldCompare', field: 'bouwkosten', op: 'gt', value: 100000 }], actions: [{ action: 'addTask', title: 'Toets door constructeur' }] },
	{ id: 'welstand', enabled: true, when: { event: 'saved' }, conditions: [{ kind: 'fieldEquals', field: 'welstandsgebied', value: true }], actions: [{ action: 'chooseVariant', variant: 'Met welstand' }] },
	{ id: 'weigering', enabled: false, when: { event: 'statusEntered', status: 'st-geweigerd' }, conditions: [], actions: [{ action: 'sendMessage', emailTemplate: 'tpl-weigering' }] },
]
let caseType = { id: 'type-1', isDraft: true, title: 'Omgevingsvergunning regulier', rules: RULES, variants: [{ name: 'Met welstand' }] }
vi.mock('../../src/store/modules/object.js', () => ({
	useObjectStore: () => ({
		fetchObject: async () => caseType,
		fetchCollection: async (type) => CATALOGUE[type] || [],
		saveObject: async (type, object) => {
			mockSave(type, object)
			return object
		},
	}),
}))

function stub(name) {
	return defineComponent({ name, props: ['modelValue', 'options', 'inputLabel', 'label', 'size', 'disabled', 'name', 'open', 'title', 'ariaLabel'], emits: ['click', 'update:modelValue'], render() {
		return h('div', { class: name, onClick: () => this.$emit('click') }, [this.$slots.default?.() ?? [], this.$slots.actions?.() ?? []])
	} })
}
vi.mock('@nextcloud/vue', () => ({
	NcActionButton: stub('NcActionButton'),
	NcActions: stub('NcActions'),
	NcButton: stub('NcButton'),
	NcDialog: stub('NcDialog'),
	NcLoadingIcon: stub('NcLoadingIcon'),
	NcSelect: stub('NcSelect'),
	NcTextField: stub('NcTextField'),
}))

const INVENTORY = [
	{ id: 'calculation:case:deadline', kind: 'calculation', key: 'deadline', label: 'deadline', enabled: true },
	{ id: 'flow:case:f1', kind: 'flow', key: 'f1', label: 'f1', enabled: true, scope: { property: 'caseType', value: 'type-1' } },
]

/**
 * Mount the tab for case type type-1 over the inventory.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountTab(props = {}) {
	mockGet.mockImplementation((url, options) => Promise.resolve({ data: url.includes('/rules/vocabulary')
		? { kinds: [], verdicts: [], actions: [] }
		: url.endsWith('/rules') ? { rules: INVENTORY, asked: options }
			: url.endsWith('/blueprint') ? BLUEPRINT
				: { properties: { deadline: { title: 'Uiterste datum' } } } }))
	const { default: RulesTab } = await import('../../src/views/settings/tabs/RulesTab.vue')
	const w = mount(RulesTab, { props: { caseTypeId: 'type-1', ...props }, global: { mocks: { t: (_app, text) => text } } })
	await flushPromises()
	return w
}

describe('the Overview Regels card', () => {
	it('lists the case type\'s own rules as sentences with their pill', async () => {
		const w = await mountTab({ summary: true })
		const rows = w.findAll('[data-testid="case-rule-row"]')
		expect(rows.map((row) => row.find('.rules-tab__title').text().replace(/\s/g, ' '))).toEqual([
			'Als Bouwkosten groter is dan € 100.000',
			'Als Welstandsgebied ja is',
			'Als status Geweigerd wordt',
		])
		expect(rows[0].find('.rules-tab__line').text()).toBe('dan taak Toets door constructeur toevoegen')
		expect(w.findAll('[data-testid="case-rule-pill"]').map((pill) => pill.text())).toEqual(['On', 'On', 'Off'])
		expect([nl.translations.On, nl.translations.Off]).toEqual(['Aan', 'Uit'])
	})

	it('leaves the tab\'s menus, Add button, inventory and trial out, and reads no inventory', async () => {
		const w = await mountTab({ summary: true })
		expect(w.find('.NcActions').exists()).toBe(false)
		expect(w.find('[data-testid="rules-add"]').exists()).toBe(false)
		expect(w.find('[data-testid="rules-list"]').exists()).toBe(false)
		expect(w.find('.rules-tab__trial').exists()).toBe(false)
		expect(mockGet.mock.calls.some(([url]) => url.endsWith('/rules') || url.includes('/rules/vocabulary'))).toBe(false)
	})

	it('says so when the case type has no rules', async () => {
		const kept = caseType
		caseType = { ...kept, rules: [] }
		const w = await mountTab({ summary: true })
		caseType = kept
		expect(w.find('[data-testid="case-rules-empty"]').text()).toBe('No rules for cases yet')
		expect(nl.translations['No rules for cases yet']).toBe('Nog geen regels voor zaken')
	})

	it('is the Overview card, with Add a rule opening the Rules tab', () => {
		const layout = require('../../src/menu-layout.simple.json')
		const page = layout.pages.find((p) => p.id === 'CaseTypeDetail')
		const card = page.configAppend.widgets.find((widget) => widget.id === 'case-type-rules-summary')
		expect(card.type).toBe('CaseTypeRulesWidget')
		expect(card.content).toEqual({ mode: 'summary' })
		expect(card.headerActions[0]).toMatchObject({ label: 'Add a rule', action: { type: 'navigate', target: '/settings/case-types/@objectId#rules' } })
	})
})
