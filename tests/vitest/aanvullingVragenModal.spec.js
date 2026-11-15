// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The handler asks the applicant for missing information from the case page
 * (handler-asks-the-applicant-for-missing-information, task 3.1).
 *
 * Pinned: the payload the form posts to the one endpoint that sends the
 * letter and suspends the term, the sentence that tells the handler what the
 * applicant receives, a refusal shown as the server's own words, and the
 * header action that offers the form only while the case can be asked.
 *
 * @spec openspec/changes/handler-asks-the-applicant-for-missing-information/specs/termijn-pause-extension/spec.md#requirement-the-handler-asks-for-missing-information-from-the-case-page-req-avr-05
 */

import { flushPromises, mount } from '@vue/test-utils'
import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'))
const registrySource = fs.readFileSync(path.join(ROOT, 'src', 'registry.js'), 'utf8')

const mockGet = vi.fn()
const mockPost = vi.fn()
vi.mock('@nextcloud/axios', () => ({
	default: { get: (...a) => mockGet(...a), post: (...a) => mockPost(...a) },
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (u) => u }))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, s, vars = {}) => s.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m)),
}))

/**
 * A stand-in for one @nextcloud/vue control.
 *
 * @param {string} name The component name.
 * @return {object} The stub.
 */
function control(name) {
	return defineComponent({
		name,
		props: ['modelValue', 'label', 'error', 'disabled', 'variant', 'type', 'size', 'name', 'options', 'inputLabel', 'max'],
		emits: ['update:modelValue', 'close', 'click'],
		render() {
			return h('div', { class: name, onClick: () => this.$emit('click') }, this.$slots.default?.() ?? [])
		},
	})
}

vi.mock('@nextcloud/vue', () => ({
	NcButton: control('NcButton'),
	NcDialog: control('NcDialog'),
	NcNoteCard: control('NcNoteCard'),
	NcSelect: control('NcSelect'),
	NcTextField: control('NcTextField'),
	NcTextArea: control('NcTextArea'),
}))

const { default: AanvullingVragenModal, missingItemsOf, maxSuspensionDaysOf, refusalSentenceOf } =
	await import('../../src/modals/AanvullingVragenModal.vue')

const PAUSE_REASONS = [
	{ key: 'aanvraag-onvolledig', name: 'Aanvraag onvolledig', legalBasis: 'Awb 4:5', defaultDays: 21 },
]

/**
 * Answer the two reads the form makes when it opens.
 *
 * @param {object} caseType The case type object.
 * @return {void}
 */
function answerReads(caseType = { id: 'ct-1', maxSuspensionDays: 28 }) {
	mockGet.mockImplementation((url) => {
		if (url === '/apps/dossiq/api/case/case-1/lifecycle') {
			return Promise.resolve({ data: { caseType: 'ct-1', suspended: false, pauseReasons: PAUSE_REASONS } })
		}
		if (url === '/apps/openregister/api/objects/dossiq/caseType/ct-1') {
			return Promise.resolve({ data: caseType })
		}
		return Promise.reject(new Error(`unexpected GET ${url}`))
	})
}

/**
 * Mount the form open on one case and let its reads settle.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountForm() {
	const wrapper = mount(AanvullingVragenModal, {
		props: { caseId: 'case-1', open: true },
		global: { mocks: { $route: { params: {} } } },
	})
	await flushPromises()
	return wrapper
}

/**
 * Fill a control by its test id.
 *
 * @param {object} wrapper The form.
 * @param {string} testId The control's test id.
 * @param {unknown} value The value.
 * @return {Promise<void>}
 */
async function fill(wrapper, testId, value) {
	wrapper.findComponent(`[data-testid="${testId}"]`).vm.$emit('update:modelValue', value)
	await flushPromises()
}

/**
 * Fill every field with a valid ask.
 *
 * @param {object} wrapper The form.
 * @return {Promise<void>}
 */
async function fillValid(wrapper) {
	await fill(wrapper, 'aanvulling-vragen-items', 'Bankafschrift\n\n  Loonstrook maart  \n')
	await fill(wrapper, 'aanvulling-vragen-recipient', ' aanvrager@example.org ')
	await fill(wrapper, 'aanvulling-vragen-days', '10')
	await fill(wrapper, 'aanvulling-vragen-rationale', 'Zonder deze stukken kan de aanvraag niet worden beoordeeld.')
}

/**
 * Press Send and let the post settle.
 *
 * @param {object} wrapper The form.
 * @return {Promise<void>}
 */
async function pressSend(wrapper) {
	await wrapper.find('[data-testid="aanvulling-vragen-send"]').trigger('click')
	await flushPromises()
}

describe('AanvullingVragenModal', () => {
	beforeEach(() => {
		mockGet.mockReset()
		mockPost.mockReset()
		answerReads()
	})

	it('tells the handler what the applicant receives and that the term is suspended, before sending', async () => {
		const wrapper = await mountForm()

		expect(wrapper.find('[data-testid="aanvulling-vragen-notice"]').text()).toBe(
			'The summary and the list of missing items go to the applicant. The term is suspended once the letter has gone out.',
		)
		expect(mockPost).not.toHaveBeenCalled()
	})

	it('posts the missing items one per line, the recipient, the term, the pause reason and the rationale', async () => {
		mockPost.mockResolvedValue({ data: { sent: true, suspended: true, request: { id: 'avr-1' } } })
		const wrapper = await mountForm()
		await fillValid(wrapper)
		await fill(wrapper, 'aanvulling-vragen-reason', PAUSE_REASONS[0])

		await pressSend(wrapper)

		expect(mockPost).toHaveBeenCalledTimes(1)
		expect(mockPost).toHaveBeenCalledWith('/apps/dossiq/api/cases/case-1/information-request', {
			items: ['Bankafschrift', 'Loonstrook maart'],
			recipient: 'aanvrager@example.org',
			durationDays: 10,
			pauseReason: 'aanvraag-onvolledig',
			rationale: 'Zonder deze stukken kan de aanvraag niet worden beoordeeld.',
		})
		expect(wrapper.emitted('sent')).toEqual([[{ sent: true, suspended: true, request: { id: 'avr-1' } }]])
		expect(wrapper.emitted('close')).toHaveLength(1)
		expect(wrapper.find('[data-testid="aanvulling-vragen-error"]').exists()).toBe(false)
	})

	it('takes a pause reason\'s default term while the handler has not changed the term', async () => {
		const wrapper = await mountForm()

		await fill(wrapper, 'aanvulling-vragen-reason', PAUSE_REASONS[0])

		expect(wrapper.findComponent('[data-testid="aanvulling-vragen-days"]').props('modelValue')).toBe('21')
	})

	it('shows a refusal as the server\'s own sentence, and neither closes nor reports a send', async () => {
		const sentence = 'This case is already waiting on the applicant. Record the answer to the open request, or withdraw it, before asking again.'
		mockPost.mockRejectedValue({
			response: { status: 409, data: { message: sentence, error: 'aanvullingsverzoek-already-open' } },
		})
		const wrapper = await mountForm()
		await fillValid(wrapper)

		await pressSend(wrapper)

		expect(wrapper.find('[data-testid="aanvulling-vragen-error"]').text()).toBe(sentence)
		expect(wrapper.emitted('sent')).toBeUndefined()
		expect(wrapper.emitted('close')).toBeUndefined()
	})

	it('refuses an ask without a missing item before anything is sent', async () => {
		const wrapper = await mountForm()
		await fillValid(wrapper)
		await fill(wrapper, 'aanvulling-vragen-items', '  \n ')

		await pressSend(wrapper)

		expect(mockPost).not.toHaveBeenCalled()
		expect(wrapper.find('[data-testid="aanvulling-vragen-error"]').text()).toBe(
			'Name at least one thing the applicant has to send.',
		)
		expect(wrapper.findComponent('[data-testid="aanvulling-vragen-items"]').props('error')).toBe(true)
	})

	it('refuses a term beyond the case type\'s declared suspension limit before anything is sent', async () => {
		const wrapper = await mountForm()
		await fillValid(wrapper)
		await fill(wrapper, 'aanvulling-vragen-days', '29')

		await pressSend(wrapper)

		expect(mockPost).not.toHaveBeenCalled()
		expect(wrapper.find('[data-testid="aanvulling-vragen-error"]').text()).toBe(
			'This case type allows a suspension of at most 28 days.',
		)
		expect(wrapper.findComponent('[data-testid="aanvulling-vragen-days"]').props('label')).toBe(
			'Term for the applicant in days (at most 28)',
		)
	})

	it('leaves the term unbounded when the case type declares no limit, and the server decides', async () => {
		answerReads({ id: 'ct-1' })
		mockPost.mockResolvedValue({ data: { sent: true } })
		const wrapper = await mountForm()
		await fillValid(wrapper)
		await fill(wrapper, 'aanvulling-vragen-days', '90')

		await pressSend(wrapper)

		expect(mockPost.mock.calls[0][1].durationDays).toBe(90)
	})
})

describe('the helpers', () => {
	it('keeps only the lines that say something, trimmed', () => {
		expect(missingItemsOf(' a \n\n b\n   ')).toEqual(['a', 'b'])
		expect(missingItemsOf(undefined)).toEqual([])
	})

	it('reads the declared suspension limit, 0 when none is declared', () => {
		expect(maxSuspensionDaysOf({ maxSuspensionDays: 42 })).toBe(42)
		expect(maxSuspensionDaysOf({ maxSuspensionDays: '35' })).toBe(35)
		expect(maxSuspensionDaysOf({ maxSuspensionDays: 0 })).toBe(0)
		expect(maxSuspensionDaysOf(null)).toBe(0)
	})

	it('prefers the server\'s message, then its error, then a general sentence', () => {
		expect(refusalSentenceOf({ message: 'No running term.', error: 'no-running-term-to-suspend' })).toBe('No running term.')
		expect(refusalSentenceOf({ error: 'Not authorized' })).toBe('Not authorized')
		expect(refusalSentenceOf({})).toBe('The request could not be made.')
	})
})

describe('the case page offers the ask', () => {
	const caseDetail = manifest.pages.find((page) => page.id === 'CaseDetail')
	const action = caseDetail.config.headerActions.find((a) => a.id === 'request-information')

	it('opens the registered modal with the case', () => {
		expect(action).toMatchObject({
			type: 'open-modal',
			target: 'AanvullingVragenModal',
			props: { caseId: '@objectId', open: true },
		})
		expect(registrySource).toMatch(/AanvullingVragenModal: \{\s*kind: 'modal',\s*component: AanvullingVragenModal,/)
	})

	it('only while the case is open, its term is not suspended and no request is open', () => {
		expect(action.visibleWhen.all).toEqual([
			{ field: '@self.archived', op: 'empty' },
			{ field: 'isFinalStatus', op: 'neq', value: true },
			{ endpoint: '/apps/dossiq/api/case/@objectId/lifecycle', field: 'suspended', op: 'eq', value: false },
			{ endpoint: '/apps/dossiq/api/cases/@objectId/aanvullingsverzoeken', field: 'waiting', op: 'eq', value: false },
		])
	})
})
