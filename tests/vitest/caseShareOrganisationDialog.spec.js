// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Sharing a case with, or handing it to, another organisation (board
 * DqZaakSamenwerken): the library's CnActionDialog configured on the case
 * page's action. One dialog, two acts that post to their own endpoint, every
 * partner organisation read from the server, and the field selection kept as
 * an option of the share, sent as the fields left out.
 *
 * @spec openspec/specs/federated-case-collaboration/spec.md
 */

// The aliased axios stub (vitest.config.js): the library's own dynamic import
// of '@nextcloud/axios' reaches it, where a vi.mock factory is not always used.
import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import CnActionDialog from '@conduction/nextcloud-vue/src/components/CnActionDialog/CnActionDialog.vue'
import { FEDERATION_ALLOWED_FIELDS } from '../../src/utils/federatedShareHelpers.js'

const ROOT = path.resolve(__dirname, '../..')

const { showSuccess } = vi.hoisted(() => ({ showSuccess: vi.fn() }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess, showError: vi.fn() }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url, generateOcsUrl: (url) => url, generateRemoteUrl: (url) => url, imagePath: (app, file) => file }))

const simple = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'menu-layout.simple.json'), 'utf8'))

/**
 * The share action on the simple case page.
 *
 * @return {object} The action.
 */
function shareAction() {
	const overlay = simple.pages.find((page) => page.id === 'CaseDetail')
	return overlay.configAppend.headerActions.find((entry) => entry.id === 'share-with-organisation')
}

const PARTNERS = { organisations: [
	{ id: 'org-1', name: 'Omgevingsdienst', domain: 'odzhz.nl', type: 'partner' },
	{ id: 'own', name: 'Gemeente', type: 'tenant' },
	{ id: 'org-2', name: 'Waterschap', type: 'partner' },
] }

const stubs = {
	CnDialog: { name: 'CnDialog', props: ['eyebrow', 'name'], template: '<div><p class="eyebrow">{{ eyebrow }}</p><slot /><slot name="actions" /></div>' },
	CnChoiceCards: { name: 'CnChoiceCards', props: ['options', 'modelValue', 'layout', 'columns', 'label', 'hideLabel'], emits: ['update:modelValue'], template: '<div class="choice-cards" />' },
	NcButton: { name: 'NcButton', props: ['disabled'], emits: ['click'], template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>' },
	NcCheckboxRadioSwitch: { name: 'NcCheckboxRadioSwitch', props: ['modelValue'], emits: ['update:modelValue'], template: '<label class="check"><slot /></label>' },
	NcNoteCard: { name: 'NcNoteCard', template: '<div class="note"><slot /></div>' },
	NcSelect: { name: 'NcSelect', props: ['modelValue', 'options', 'clearable', 'inputLabel', 'placeholder'], emits: ['update:modelValue', 'search'], template: '<div class="select" />' },
	NcTextField: { name: 'NcTextField', props: ['modelValue', 'label'], emits: ['update:modelValue'], template: '<input class="text">' },
	NcTextArea: true,
	CnFormField: { name: 'CnFormField', props: ['text', 'optional'], template: '<label class="form-field">{{ text }}</label>' },
	CnIcon: true,
}

/**
 * Mount the configured dialog on case-1.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountDialog() {
	const wrapper = mount(CnActionDialog, { props: { ...shareAction().props, objectId: 'case-1' }, global: { stubs } })
	// The library reads through a dynamic import of axios, which can take
	// longer than one flush: wait until the case and the partners are read.
	await vi.waitFor(() => expect(axios.get).toHaveBeenCalledTimes(2))
	await flushPromises()
	return wrapper
}

/**
 * The control a field draws.
 *
 * @param {object} wrapper The wrapper.
 * @param {string} field The field name.
 * @param {string} name The stub's name.
 * @return {object} The control.
 */
function control(wrapper, field, name) {
	return wrapper.find(`[data-testid="cn-action-dialog-field-${field}"]`).findComponent({ name })
}

/**
 * Fill the dialog as a handler would.
 *
 * @param {object} wrapper The wrapper.
 * @param {{mode?: string, message?: string, limit?: boolean, untick?: Array<string>}} input What to set.
 * @return {Promise<void>}
 */
async function fill(wrapper, input) {
	if (input.mode) {
		wrapper.findComponent({ name: 'CnChoiceCards' }).vm.$emit('update:modelValue', input.mode)
	}
	control(wrapper, 'organisation', 'NcSelect').vm.$emit('update:modelValue', { id: 'org-1' })
	await flushPromises()
	if (input.message) {
		control(wrapper, 'message', 'NcTextField').vm.$emit('update:modelValue', input.message)
	}
	if (input.limit) {
		control(wrapper, 'limit', 'NcCheckboxRadioSwitch').vm.$emit('update:modelValue', true)
		await flushPromises()
	}
	await untick(wrapper, input.untick || [])
}

/**
 * Untick case fields in the field selection.
 *
 * @param {object} wrapper The wrapper.
 * @param {Array<string>} labels The labels to untick.
 * @return {Promise<void>}
 */
async function untick(wrapper, labels) {
	for (const label of labels) {
		const box = wrapper.find('[data-testid="cn-action-dialog-field-shared"]').findAllComponents({ name: 'NcCheckboxRadioSwitch' }).find((c) => c.text() === label)
		box.vm.$emit('update:modelValue', false)
		await flushPromises()
	}
}

/**
 * Press the confirm button.
 *
 * @param {object} wrapper The wrapper.
 * @return {Promise<void>}
 */
async function confirm(wrapper) {
	await wrapper.find('[data-testid="cn-action-dialog-confirm"]').trigger('click')
	await flushPromises()
}

beforeEach(() => {
	axios.get.mockReset()
	axios.post.mockReset()
	showSuccess.mockReset()
	axios.get.mockImplementation((url) => Promise.resolve({
		data: String(url).includes('organisations/search') ? PARTNERS : { id: 'case-1', title: 'Verlichting fietspad Lindelaan', identifier: '2026-0082' },
	}))
	axios.post.mockResolvedValue({ data: { success: true } })
})

describe('the share action on the case page', () => {
	it('opens the library action dialog from Meer, with no local dialog beside it', () => {
		expect(shareAction()).toMatchObject({ type: 'open-modal', target: 'CnActionDialog', group: 'Case', props: { objectId: '@objectId' } })
		expect(fs.readFileSync(path.join(ROOT, 'src', 'registry.js'), 'utf8')).not.toContain('CaseShareOrganisationDialog')
		expect(fs.existsSync(path.join(ROOT, 'src', 'dialogs', 'CaseShareOrganisationDialog.vue'))).toBe(false)
	})

	it('offers the case fields a share may limit, as the server allows them', () => {
		const shared = shareAction().props.config.fields.find((field) => field.field === 'shared')
		expect(shared.options.map((option) => option.value)).toEqual(FEDERATION_ALLOWED_FIELDS)
	})
})

describe('CaseShareOrganisationDialog', () => {
	// @spec openspec/specs/share-dialog-lists-every-partner/spec.md#requirement-the-share-dialog-offers-every-partner-organisation
	it('offers partner organisations only, named with their domain, and heads with the case', async () => {
		const wrapper = await mountDialog()
		expect(control(wrapper, 'organisation', 'NcSelect').props('options').map((o) => o.label)).toEqual(['Omgevingsdienst (odzhz.nl)', 'Waterschap'])
		expect(axios.get).toHaveBeenCalledWith(expect.stringMatching(/\/apps\/openregister\/api\/organisations\/search$/), { params: { _limit: 100 } })
		expect(wrapper.find('.eyebrow').text()).toBe('Verlichting fietspad Lindelaan, 2026-0082')
	})

	it('shares through a partner share with permission, message and the fields left out', async () => {
		const wrapper = await mountDialog()
		await fill(wrapper, { message: 'Graag jullie advies.', limit: true, untick: ['Description', 'Case type', 'Priority', 'Deadline', 'Requested date'] })
		await confirm(wrapper)
		expect(axios.post).toHaveBeenCalledTimes(1)
		expect(axios.post).toHaveBeenCalledWith('/apps/dossiq/api/shares', {
			caseId: 'case-1',
			shareType: 'partner',
			partnerId: 'org-1',
			permissionLevel: 'bekijken_bijdragen',
			message: 'Graag jullie advies.',
			fieldExclusions: FEDERATION_ALLOWED_FIELDS.filter((field) => !['title', 'status'].includes(field)),
		})
	})

	it('excludes nothing when fields are not limited (the server reads the empty value as no exclusions)', async () => {
		const wrapper = await mountDialog()
		await fill(wrapper, {})
		await confirm(wrapper)
		const [, body] = axios.post.mock.calls[0]
		expect(body.fieldExclusions).toBe('')
		expect(body.message).toBe('')
	})

	it('hands over through a case transfer with the message as the reason, and nothing else', async () => {
		const wrapper = await mountDialog()
		await fill(wrapper, { mode: 'handover', message: 'Hoort bij jullie' })
		expect(wrapper.find('[data-testid="cn-action-dialog-field-permission"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="cn-action-dialog-field-limit"]').exists()).toBe(false)
		await confirm(wrapper)
		expect(axios.post).toHaveBeenCalledWith('/apps/dossiq/api/transfers', { caseId: 'case-1', targetOrganization: 'org-1', reason: 'Hoort bij jullie' })
	})

	it('cannot share without an organisation, or with every field taken away', async () => {
		const wrapper = await mountDialog()
		const button = () => wrapper.find('[data-testid="cn-action-dialog-confirm"]')
		expect(button().attributes('disabled')).toBeDefined()
		await fill(wrapper, { limit: true, untick: shareAction().props.config.fields.find((f) => f.field === 'shared').options.map((o) => o.label) })
		expect(button().attributes('disabled')).toBeDefined()
	})

	// @spec openspec/specs/share-org-not-clearable/spec.md#requirement-the-organisation-choice-has-no-clear-button
	it('offers no clear button on the organisation, as on the act and the permission', async () => {
		const selects = (await mountDialog()).findAllComponents({ name: 'NcSelect' })
		expect(selects.length).toBe(2)
		expect(selects.every((select) => select.props('clearable') === false)).toBe(true)
	})

	it('says the share in a toast naming the organisation, and closes', async () => {
		const wrapper = await mountDialog()
		await fill(wrapper, {})
		await confirm(wrapper)
		expect(showSuccess).toHaveBeenCalledWith('The case is shared with Omgevingsdienst.')
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('says the handover in a toast naming the organisation', async () => {
		const wrapper = await mountDialog()
		await fill(wrapper, { mode: 'handover' })
		await confirm(wrapper)
		expect(showSuccess).toHaveBeenCalledWith('The handover to Omgevingsdienst is requested.')
	})

	it('shows the server\'s refusal, else that the case could not be shared, and stays open', async () => {
		const wrapper = await mountDialog()
		await fill(wrapper, {})
		axios.post.mockRejectedValueOnce({ response: { data: { success: false, error: 'No consent for this partner.' } } })
		await confirm(wrapper)
		expect(wrapper.find('[data-testid="cn-action-dialog-refusal"]').text()).toBe('No consent for this partner.')
		axios.post.mockRejectedValueOnce({ response: { data: {} } })
		await confirm(wrapper)
		expect(wrapper.find('[data-testid="cn-action-dialog-refusal"]').text()).toBe('The case could not be shared.')
		expect(wrapper.emitted('close')).toBeUndefined()
	})
})

describe('CaseShareOrganisationDialog on the board (DqZaakSamenwerken)', () => {
	it('offers Share and Hand over as option rows side by side, the legend for assistive tech only', async () => {
		const cards = (await mountDialog()).findComponent({ name: 'CnChoiceCards' })
		expect(cards.props('options').map((option) => [option.value, option.label, option.description])).toEqual([
			['share', 'Share', 'You stay the owner; they work along'],
			['handover', 'Hand over', 'They become the owner; you can still read along'],
		])
		expect(cards.props('modelValue')).toBe('share')
		expect(cards.props('layout')).toBe('rows')
		expect(cards.props('columns')).toBe(2)
		expect(cards.props('label')).toBe('What you do')
		expect(cards.props('hideLabel')).toBe(true)
	})

	it('writes "(optional)" beside the message, draws the field limit as a plain checkbox, and the parts 14px apart', async () => {
		const wrapper = await mountDialog()
		const label = wrapper.find('[data-testid="cn-action-dialog-field-message"]').findComponent({ name: 'CnFormField' })
		expect([label.props('text'), label.props('optional')]).toEqual(['Message', true])
		expect(wrapper.find('[data-testid="cn-action-dialog-field-limit"] .cn-action-dialog__checkbox--plain').exists()).toBe(true)
		expect(wrapper.find('.cn-action-dialog').attributes('style')).toContain('gap: 14px')
	})

	it('names the share button by the act', async () => {
		const wrapper = await mountDialog()
		expect(wrapper.find('[data-testid="cn-action-dialog-confirm"]').text()).toBe('Share')
		await fill(wrapper, { mode: 'handover' })
		expect(wrapper.find('[data-testid="cn-action-dialog-confirm"]').text()).toBe('Hand over')
	})
})
