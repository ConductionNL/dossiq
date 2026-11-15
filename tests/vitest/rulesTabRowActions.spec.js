// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The row menu of the Rules tab's own case type rules (edit, turn off or on,
 * delete), the message when storing fails, and trying a rule against a case.
 *
 * @spec openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-the-rules-tab-reads-and-writes-the-case-types-rules
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const mockGet = vi.fn()
const mockPost = vi.fn()
const mockSave = vi.fn()
vi.mock('@nextcloud/axios', () => ({ default: { get: (...a) => mockGet(...a), post: (...a) => mockPost(...a) } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (u) => u }))
vi.mock('../../src/store/modules/settings.js', () => ({ useSettingsStore: () => ({ config: { case_schema: 'zaak', register: 'reg' } }) }))

const RULES = [
	{ id: 'a', enabled: true, when: { event: 'saved' }, conditions: [], actions: [{ action: 'addTask', title: 'Eerste' }] },
	{ id: 'b', enabled: false, when: { event: 'saved' }, conditions: [], actions: [{ action: 'addTask', title: 'Tweede' }] },
]
let caseType
vi.mock('../../src/store/modules/object.js', () => ({
	useObjectStore: () => ({
		fetchObject: async () => caseType,
		fetchCollection: async () => [],
		saveObject: (...a) => mockSave(...a),
	}),
}))

function stub(name) {
	return defineComponent({ name, props: ['modelValue', 'options', 'inputLabel', 'label', 'size', 'disabled', 'name', 'open', 'title', 'ariaLabel', 'placeholder'], emits: ['click', 'update:modelValue'], render() {
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
vi.mock('../../src/modals/CaseTypeRuleDialog.vue', () => ({
	default: { name: 'CaseTypeRuleDialog', props: ['rule', 'newId', 'fields', 'choices'], emits: ['close', 'save'], render: () => h('div', { class: 'rule-dialog' }) },
}))

const INVENTORY = [{ id: 'calculation:case:deadline', kind: 'calculation', key: 'deadline', label: 'deadline', enabled: true }]

/**
 * Mount the tab for case type type-1.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountTab() {
	mockGet.mockImplementation((url) => Promise.resolve({ data: url.includes('/rules/vocabulary')
		? { kinds: [], verdicts: [], actions: [] }
		: url.endsWith('/rules') ? { rules: INVENTORY }
			: url.endsWith('/blueprint') ? { propertyDefinitions: [], statusTypes: [] }
				: { properties: {} } }))
	const { default: RulesTab } = await import('../../src/views/settings/tabs/RulesTab.vue')
	const w = mount(RulesTab, { props: { caseTypeId: 'type-1' } })
	await flushPromises()
	return w
}

describe('the Rules tab row menu', () => {
	beforeEach(() => {
		caseType = { id: 'type-1', isDraft: true, rules: RULES }
		mockSave.mockReset().mockImplementation(async (type, object) => object)
		mockPost.mockReset()
	})

	it('opens the dialog on the rule of the row that was edited, and closes it', async () => {
		const w = await mountTab()
		await w.findAll('[data-testid="case-rule-edit"]')[1].trigger('click')

		const dialog = w.findComponent({ name: 'CaseTypeRuleDialog' })
		expect(dialog.props('rule')).toEqual(RULES[1])
		expect(dialog.props('newId')).toBe('rule-3')

		dialog.vm.$emit('close')
		await flushPromises()
		expect(w.findComponent({ name: 'CaseTypeRuleDialog' }).exists()).toBe(false)
		expect(mockSave).not.toHaveBeenCalled()
	})

	it('replaces an edited rule in place rather than adding it', async () => {
		const w = await mountTab()
		await w.findAll('[data-testid="case-rule-edit"]')[0].trigger('click')

		const edited = { ...RULES[0], actions: [{ action: 'addTask', title: 'Anders' }] }
		w.findComponent({ name: 'CaseTypeRuleDialog' }).vm.$emit('save', edited)
		await flushPromises()

		expect(mockSave).toHaveBeenCalledWith('caseType', { ...caseType, rules: [edited, RULES[1]] })
		expect(w.findComponent({ name: 'CaseTypeRuleDialog' }).exists()).toBe(false)
	})

	it('turns an enabled rule off and a disabled one on, leaving the others as they are', async () => {
		const w = await mountTab()
		const toggles = w.findAll('[data-testid="case-rule-toggle"]')
		expect(toggles.map((b) => b.text())).toEqual(['Turn off', 'Turn on'])

		await toggles[0].trigger('click')
		await flushPromises()
		expect(mockSave.mock.calls.at(-1)[1].rules).toEqual([{ ...RULES[0], enabled: false }, RULES[1]])
		expect(w.findAll('[data-testid="case-rule-pill"]').map((p) => p.text())).toEqual(['Off', 'Off'])

		await w.findAll('[data-testid="case-rule-toggle"]')[1].trigger('click')
		await flushPromises()
		expect(mockSave.mock.calls.at(-1)[1].rules[1]).toEqual({ ...RULES[1], enabled: true })
	})

	it('deletes only the rule of its row', async () => {
		const w = await mountTab()
		await w.findAll('[data-testid="case-rule-delete"]')[0].trigger('click')
		await flushPromises()

		expect(mockSave.mock.calls.at(-1)[1].rules).toEqual([RULES[1]])
		expect(w.findAll('[data-testid="case-rule-row"]')).toHaveLength(1)
	})

	it('keeps the rules and says so when storing fails', async () => {
		mockSave.mockRejectedValue(new Error('500'))
		const w = await mountTab()
		await w.findAll('[data-testid="case-rule-delete"]')[0].trigger('click')
		await flushPromises()

		expect(w.text()).toContain('The rule could not be saved.')
		expect(w.findAll('[data-testid="case-rule-row"]')).toHaveLength(2)
	})
})

describe('trying a rule against a case', () => {
	beforeEach(() => {
		caseType = { id: 'type-1', isDraft: true, rules: [] }
		mockPost.mockReset()
	})

	it('posts the rule id, register and trimmed case id and shows what it read', async () => {
		mockPost.mockResolvedValue({ data: { trace: { verdict: 'no_match', operand: 'bouwkosten', operandValue: '' } } })
		const w = await mountTab()
		w.findComponent('[data-testid="rules-case-id"]').vm.$emit('update:modelValue', '  case-9 ')
		w.findComponent('[data-testid="rules-pick"]').vm.$emit('update:modelValue', { id: 'calculation:case:deadline' })
		await flushPromises()

		await w.find('[data-testid="rules-try"]').trigger('click')
		await flushPromises()

		const [url, body] = mockPost.mock.calls[0]
		expect(url).toBe('/apps/openregister/api/schemas/zaak/rules/calculation%3Acase%3Adeadline/evaluate')
		expect(body).toEqual({ register: 'reg', objectId: 'case-9' })
		expect(w.find('[data-testid="rules-trace"]').text()).toContain('The rule did not match this case.')
		expect(w.find('[data-testid="rules-operand"]').text()).toBe('It read bouwkosten and found nothing.')
	})

	it('says the case could not be read when the trial fails', async () => {
		mockPost.mockRejectedValue(new Error('404'))
		const w = await mountTab()
		w.findComponent('[data-testid="rules-case-id"]').vm.$emit('update:modelValue', 'case-9')
		w.findComponent('[data-testid="rules-pick"]').vm.$emit('update:modelValue', { id: 'calculation:case:deadline' })
		await flushPromises()

		await w.find('[data-testid="rules-try"]').trigger('click')
		await flushPromises()

		const trace = w.find('[data-testid="rules-trace"]').text()
		expect(trace).toContain('The rule could not be evaluated.')
		expect(trace).toContain('That case could not be read.')
	})

	it('does not post without a case id', async () => {
		const w = await mountTab()
		await w.vm.tryRule({ id: 'calculation:case:deadline' })

		expect(mockPost).not.toHaveBeenCalled()
		expect(w.find('[data-testid="rules-trace"]').exists()).toBe(false)
	})

	it('shows an unknown verdict as it came', async () => {
		mockPost.mockResolvedValue({ data: { trace: { verdict: 'skipped' } } })
		const w = await mountTab()
		w.findComponent('[data-testid="rules-case-id"]').vm.$emit('update:modelValue', 'case-9')
		await w.vm.tryRule({ id: 'calculation:case:deadline' })
		await flushPromises()

		expect(w.find('.rules-tab__verdict').text()).toBe('skipped')
		expect(w.find('[data-testid="rules-operand"]').exists()).toBe(false)
	})
})
