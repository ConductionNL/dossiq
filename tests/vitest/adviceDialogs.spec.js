// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The two advice dialogs the case page opens: asking for advice (board
 * DqZaakBesluiten, "Advies vragen") and giving it (board DqZaak, "Advies
 * uitbrengen"). Each posts once, tells the page, and keeps a refusal in the
 * dialog in the server's words.
 *
 * @spec openspec/specs/advice-management/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import AdviceRequestDialog from '../../src/dialogs/AdviceRequestDialog.vue'
import AdviceResponseDialog from '../../src/dialogs/AdviceResponseDialog.vue'

const mockGet = vi.fn()
const mockPost = vi.fn()
const mockEmit = vi.fn()
vi.mock('@nextcloud/axios', () => ({ default: { get: (...a) => mockGet(...a), post: (...a) => mockPost(...a) } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (u) => u, generateOcsUrl: (u) => '/ocs/v2.php/' + u }))
vi.mock('@nextcloud/event-bus', () => ({ emit: (...a) => mockEmit(...a), subscribe: vi.fn(), unsubscribe: vi.fn() }))

const STUBS = {
	CnDialog: { template: '<div><slot /><slot name="actions" /></div>' },
	CnSegmentedControl: { props: ['modelValue', 'options'], template: '<div class="segments" />' },
	NcButton: { template: '<button v-bind="$attrs" @click="$emit(\'click\')"><slot /></button>' },
	NcCheckboxRadioSwitch: { template: '<label><slot /></label>' },
	NcDateTimePicker: { template: '<div />' },
	NcNoteCard: { props: ['heading'], template: '<div class="note">{{ heading }} <slot /></div>' },
	NcSelect: { template: '<div />' },
	NcTextArea: { template: '<textarea />' },
	NcTextField: { template: '<input />' },
}

/**
 * Mount one dialog with the library's controls stubbed.
 *
 * @param {object} component The dialog.
 * @param {object} props Its props.
 * @return {Promise<object>} The wrapper.
 */
async function mountDialog(component, props) {
	mockGet.mockResolvedValue({ data: { title: 'Verlichting fietspad Lindelaan', identifier: '2026-0110' } })
	const wrapper = mount(component, {
		props,
		global: { stubs: STUBS, mocks: { $route: { params: { id: 'case-route' } } } },
	})
	await flushPromises()
	return wrapper
}

describe('AdviceRequestDialog', () => {
	beforeEach(() => vi.clearAllMocks())

	it('starts on internal advice with the answer date two weeks out', async () => {
		const wrapper = await mountDialog(AdviceRequestDialog, { caseId: 'case-1' })
		const expected = new Date()
		expected.setHours(0, 0, 0, 0)
		expected.setDate(expected.getDate() + 14)

		expect(wrapper.vm.adviceKind).toBe('internal')
		expect(wrapper.vm.deadline.getTime()).toBe(expected.getTime())
		expect(wrapper.vm.canSubmit).toBe(false)
	})

	it('posts an external request with the typed organisation, then tells the page', async () => {
		mockPost.mockResolvedValue({ data: { id: 'adv-1' } })
		const wrapper = await mountDialog(AdviceRequestDialog, { caseId: '@objectId', kind: 'external' })
		wrapper.vm.organisation = ' Welstandscommissie Zuiddrecht '
		wrapper.vm.subject = 'Verkeersveiligheid'
		wrapper.vm.question = 'Is het fietspad veilig genoeg?'
		wrapper.vm.deadline = new Date(2026, 9, 21)

		await wrapper.vm.submit()
		await flushPromises()

		expect(mockPost).toHaveBeenCalledWith('/apps/dossiq/api/cases/case-route/advice-requests', {
			kind: 'external',
			advisor: 'Welstandscommissie Zuiddrecht',
			subject: 'Verkeersveiligheid',
			deadline: '2026-10-21',
			question: 'Is het fietspad veilig genoeg?',
		})
		expect(mockEmit).toHaveBeenCalledWith('dossiq:advice:requested', { caseId: 'case-route', advice: { id: 'adv-1' } })
		expect(mockEmit).toHaveBeenCalledWith('cn:page:refresh', {})
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('finds internal advisers through the sharee search', async () => {
		mockGet.mockResolvedValueOnce({ data: {} })
		const wrapper = await mountDialog(AdviceRequestDialog, { caseId: 'case-1' })
		mockGet.mockResolvedValueOnce({ data: { ocs: { data: { exact: { users: [] }, users: [{ label: 'Lars de Koning', value: { shareType: 0, shareWith: 'lars' } }] } } } })

		await wrapper.vm.onSearch('Lars')

		expect(wrapper.vm.found).toEqual([{ id: 'lars', label: 'Lars de Koning' }])
		wrapper.vm.adviser = wrapper.vm.found[0]
		expect(wrapper.vm.adviserId).toBe('lars')
		expect(wrapper.vm.canSubmit).toBe(true)
	})

	it('keeps a refusal in the dialog in the server\'s words', async () => {
		mockPost.mockRejectedValue({ response: { data: { error: 'Not authorized' } } })
		const wrapper = await mountDialog(AdviceRequestDialog, { caseId: 'case-1', kind: 'external' })
		wrapper.vm.organisation = 'Brandweer'

		await wrapper.vm.submit()
		await flushPromises()

		expect(wrapper.vm.notice).toMatchObject({ variant: 'error', title: 'Advice not requested', text: 'Not authorized' })
		expect(wrapper.find('[data-testid="dialog-notice"]').exists()).toBe(true)
		expect(wrapper.emitted('close')).toBeUndefined()
	})
})

describe('AdviceResponseDialog', () => {
	beforeEach(() => vi.clearAllMocks())

	it('names the department and the reference, and lists the conditions its form asks', async () => {
		const wrapper = await mountDialog(AdviceResponseDialog, {
			adviceId: 'adv-15',
			advisingBody: 'Brandweer',
			reference: 'ADV-2026-0015',
			conditions: ['Vluchtwegen beoordeeld', '', 'Brandwerendheid beoordeeld'],
		})

		expect(wrapper.vm.subtitle).toBe('The form of the consulted department, here Brandweer, for ADV-2026-0015.')
		expect(wrapper.vm.checklist).toEqual(['Vluchtwegen beoordeeld', 'Brandwerendheid beoordeeld'])
		expect(wrapper.vm.conclusions.map((option) => option.value)).toEqual(['positive', 'positive-with-conditions', 'negative', 'clarification'])
	})

	it('reads the advice from the route when the action hands it an unresolved token', async () => {
		const wrapper = await mountDialog(AdviceResponseDialog, { adviceId: '@objectId' })
		expect(wrapper.vm.targetAdviceId).toBe('case-route')
		expect(wrapper.vm.targetCaseId).toBe('')
	})

	it('asks a conclusion and a summary before it sends', async () => {
		const wrapper = await mountDialog(AdviceResponseDialog, { adviceId: 'adv-15' })
		expect(wrapper.vm.canSubmit).toBe(false)
		wrapper.vm.conclusion = 'negative'
		expect(wrapper.vm.canSubmit).toBe(false)
		wrapper.vm.summary = 'De tweede vluchtweg ontbreekt.'
		expect(wrapper.vm.canSubmit).toBe(true)
	})

	it('marks the advice received with the conclusion, summary and checked conditions', async () => {
		mockPost.mockResolvedValue({ data: {} })
		const wrapper = await mountDialog(AdviceResponseDialog, { adviceId: 'adv-15', caseId: 'case-1', conditions: ['Vluchtwegen beoordeeld'] })
		wrapper.vm.conclusion = 'negative'
		wrapper.vm.summary = ' De tweede vluchtweg ontbreekt. '
		wrapper.vm.toggle('Vluchtwegen beoordeeld', true)

		await wrapper.vm.submit()
		await flushPromises()

		expect(mockPost).toHaveBeenCalledWith('/apps/dossiq/api/advice/adv-15/transition', {
			to: 'received',
			conclusion: 'negative',
			adviceText: 'De tweede vluchtweg ontbreekt.',
			conditionsChecked: ['Vluchtwegen beoordeeld'],
		})
		expect(mockEmit).toHaveBeenCalledWith('dossiq:advice:responded', { adviceId: 'adv-15', caseId: 'case-1', conclusion: 'negative' })
		expect(wrapper.emitted('close')).toHaveLength(1)
	})
})

describe('the advice dialogs in the registry', () => {
	it('is opened from the case page and from the advice request page', () => {
		const manifest = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../src/manifest.json'), 'utf8'))
		const action = (page, id) => manifest.pages.find((entry) => entry.id === page).config.headerActions.find((entry) => entry.id === id)

		expect(action('CaseDetail', 'advice-request')).toMatchObject({ type: 'open-modal', target: 'AdviceRequestDialog', props: { caseId: '@objectId' } })
		expect(action('AdviceDetail', 'advice-respond')).toMatchObject({
			type: 'open-modal',
			target: 'AdviceResponseDialog',
			visibleWhen: { field: 'status', op: 'eq', value: 'requested' },
		})
	})

	it('registers both as modals a manifest action opens', () => {
		const registry = fs.readFileSync(path.resolve(__dirname, '../../src/registry.js'), 'utf8')
		for (const name of ['AdviceRequestDialog', 'AdviceResponseDialog']) {
			const entry = registry.slice(registry.indexOf(`\t${name}: {`))
			expect(entry.slice(0, 120)).toContain("kind: 'modal'")
		}
	})
})
