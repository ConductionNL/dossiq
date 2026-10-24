// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case type's own conditional rules on the Rules tab, as board
 * DqZaaktypeRegels draws them: "Als … / dan …" rows with an On or Off pill
 * above the calculated fields, "Regel toevoegen" opening a form over this
 * type's own fields, and the form writing the stored rule shape.
 *
 * @spec openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-the-rules-tab-reads-and-writes-the-case-types-rules
 */

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import nl from '../../l10n/nl.json'
import { caseTypeRuleRow, fromEditorCondition, ruleFields, toEditorCondition } from '../../src/services/caseTypeRules.js'

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
async function mountTab() {
	mockGet.mockImplementation((url, options) => Promise.resolve({ data: url.includes('/rules/vocabulary')
		? { kinds: [], verdicts: [], actions: [] }
		: url.endsWith('/rules') ? { rules: INVENTORY, asked: options }
			: url.endsWith('/blueprint') ? BLUEPRINT
				: { properties: { deadline: { title: 'Uiterste datum' } } } }))
	const { default: RulesTab } = await import('../../src/views/settings/tabs/RulesTab.vue')
	const w = mount(RulesTab, { props: { caseTypeId: 'type-1' }, global: { mocks: { t: (_app, text) => text } } })
	await flushPromises()
	return w
}

describe('a case type rule as a row', () => {
	const fields = ruleFields({ propertyDefinitions: BLUEPRINT.propertyDefinitions, statusTypes: CATALOGUE.statusType })
	const names = { statuses: { 'st-geweigerd': 'Geweigerd' }, templates: { 'tpl-weigering': 'Weigering met bezwaarclausule' } }

	it('reads as the board draws it', () => {
		expect(caseTypeRuleRow(RULES[0], fields, names)).toEqual({ id: 'constructeur', title: 'Als Bouwkosten groter is dan € 100.000', line: 'dan taak Toets door constructeur toevoegen', pill: 'on' })
		expect(caseTypeRuleRow(RULES[1], fields, names).title).toBe('Als Welstandsgebied ja is')
		expect(caseTypeRuleRow(RULES[1], fields, names).line).toBe('dan variant Met welstand kiezen')
		expect(caseTypeRuleRow(RULES[2], fields, names)).toEqual({ id: 'weigering', title: 'Als status Geweigerd wordt', line: 'dan bericht Weigering met bezwaarclausule versturen', pill: 'off' })
	})

	it('round-trips every condition kind through the editor', () => {
		const kinds = [
			{ kind: 'fieldCompare', field: 'bouwkosten', op: 'gt', value: 100000 },
			{ kind: 'fieldPresent', field: 'bouwkosten' },
			{ kind: 'statusIs', status: 'st-geweigerd' },
			{ kind: 'documentPresent', documentType: 'doc-1' },
		]
		expect(kinds.map((c) => fromEditorCondition(toEditorCondition(c)))).toEqual(kinds)
		expect(fromEditorCondition(toEditorCondition({ kind: 'fieldEquals', field: 'welstandsgebied', value: true })))
			.toEqual({ kind: 'fieldCompare', field: 'welstandsgebied', op: 'eq', value: true })
	})
})

describe('the Rules tab with the case type\'s own rules', () => {
	it('lists the rules above the calculated fields, asks the inventory for this case type, and hides the projected flow', async () => {
		const w = await mountTab()
		const own = w.findAll('[data-testid="case-rule-row"]')
		expect(own.map((r) => r.find('.rules-tab__title').text())).toEqual([
			'Als Bouwkosten groter is dan € 100.000',
			'Als Welstandsgebied ja is',
			'Als status Geweigerd wordt',
		])
		expect(own.map((r) => r.find('.rules-tab__pill').text())).toEqual(['On', 'On', 'Off'])
		expect(w.findAll('[data-testid="rules-row"]').map((r) => r.find('.rules-tab__title').text())).toEqual(['Uiterste datum'])
		const asked = mockGet.mock.calls.find(([url]) => url.endsWith('/rules'))
		expect(asked[1]).toEqual({ params: { 'scope[caseType]': 'type-1' } })
	})

	it('adds a rule through the form and stores it on the case type', async () => {
		mockSave.mockClear()
		const w = await mountTab()
		await w.find('[data-testid="rules-add"]').trigger('click')
		const dialog = w.findComponent({ name: 'CaseTypeRuleDialog' })
		expect(dialog.exists()).toBe(true)
		expect(dialog.props('fields').map((f) => f.id)).toEqual(['bouwkosten', 'welstandsgebied', 'status'])

		dialog.findComponent({ name: 'CnConditionListEditor' }).vm.$emit('update:modelValue', [{ field: 'bouwkosten', operator: 'gt', value: 100000 }])
		dialog.find('[data-testid="rule-task-title"]').getComponent({ name: 'NcTextField' }).vm.$emit('update:modelValue', 'Welstandsadvies vragen')
		await flushPromises()
		await dialog.find('[data-testid="rule-save"]').trigger('click')
		await flushPromises()

		const [type, saved] = mockSave.mock.calls.at(-1)
		expect(type).toBe('caseType')
		expect(saved.rules.at(-1)).toEqual({
			id: 'rule-4',
			enabled: true,
			when: { event: 'saved' },
			conditions: [{ kind: 'fieldCompare', field: 'bouwkosten', op: 'gt', value: 100000 }],
			actions: [{ action: 'addTask', title: 'Welstandsadvies vragen' }],
		})
	})

	it('sends a message to a role the case type declares', async () => {
		mockSave.mockClear()
		const w = await mountTab()
		await w.find('[data-testid="rules-add"]').trigger('click')
		const dialog = w.findComponent({ name: 'CaseTypeRuleDialog' })
		dialog.find('[data-testid="rule-action"]').getComponent({ name: 'NcSelect' }).vm.$emit('update:modelValue', { id: 'sendMessage' })
		await flushPromises()
		dialog.find('[data-testid="rule-target"]').getComponent({ name: 'NcSelect' }).vm.$emit('update:modelValue', { id: 'tpl-weigering' })
		const role = dialog.find('[data-testid="rule-recipient-role"]').getComponent({ name: 'NcSelect' })
		expect(role.props('options').map((o) => o.label)).toEqual(['The applicant', 'Architect'])
		role.vm.$emit('update:modelValue', { id: 'role-architect' })
		await flushPromises()
		await dialog.find('[data-testid="rule-save"]').trigger('click')
		await flushPromises()

		expect(mockSave.mock.calls.at(-1)[1].rules.at(-1).actions).toEqual([{ action: 'sendMessage', emailTemplate: 'tpl-weigering', recipientRole: 'role-architect' }])
	})

	it('freezes a published version: no add, no row menu', async () => {
		caseType = { ...caseType, isDraft: false }
		const w = await mountTab()
		expect(w.findAllComponents({ name: 'NcButton' }).find((b) => b.attributes('data-testid') === 'rules-add').props('disabled')).toBe(true)
		expect(w.find('[data-testid="case-rule-edit"]').exists()).toBe(false)
		caseType = { ...caseType, isDraft: true }
	})
})
