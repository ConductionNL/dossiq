// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The three cross-organisation dialogs of a case: sharing it with a
 * partner, asking for a transfer, and the federated activity thread. None
 * of them calls the server; each hands the page one payload and the page
 * does the rest.
 *
 * @spec openspec/specs/federated-case-collaboration/spec.md
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import CaseTransferDialog from '../../src/dialogs/CaseTransferDialog.vue'
import CreateShareDialog from '../../src/dialogs/CreateShareDialog.vue'
import FederatedActivityPanel from '../../src/dialogs/FederatedActivityPanel.vue'

const STUBS = {
	NcDialog: { template: '<div><slot /><slot name="actions" /></div>' },
	NcButton: { emits: ['click'], props: ['disabled'], template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>' },
	NcSelect: { template: '<div />' },
	NcDateTimePicker: { template: '<div />' },
	NcLoadingIcon: { template: '<span class="loading" />' },
}

/**
 * Mount a dialog with the library's controls stubbed.
 *
 * @param {object} component The dialog.
 * @param {object} props Its props.
 * @return {object} The wrapper.
 */
function mountDialog(component, props) {
	return mount(component, { props, global: { stubs: STUBS } })
}

/**
 * The button with the given text.
 *
 * @param {object} wrapper The dialog.
 * @param {string} text The button text.
 * @return {object} The button wrapper.
 */
function button(wrapper, text) {
	return wrapper.findAll('button').find((b) => b.text() === text)
}

describe('CreateShareDialog', () => {
	it('shares view-only by default, without a partner', async () => {
		const wrapper = mountDialog(CreateShareDialog, { caseId: 'case-1', open: true })

		await button(wrapper, 'Create share').trigger('click')

		expect(wrapper.emitted('created')).toEqual([[{ caseId: 'case-1', shareType: 'partner', permissionLevel: 'bekijken', partnerId: undefined }]])
		expect(wrapper.vm.saving).toBe(false)
	})

	it('shares with the chosen partner and permission level', async () => {
		const wrapper = mountDialog(CreateShareDialog, { caseId: 'case-1', partners: [{ id: 'p-9', label: 'Gemeente Zuiddrecht' }] })
		wrapper.vm.form.partnerId = { id: 'p-9', label: 'Gemeente Zuiddrecht' }
		wrapper.vm.form.permissionLevel = wrapper.vm.permissionOptions[2]

		await wrapper.vm.createShare()

		expect(wrapper.emitted('created')[0][0]).toEqual({ caseId: 'case-1', shareType: 'partner', permissionLevel: 'bekijken_bijdragen', partnerId: 'p-9' })
	})

	it('falls back to view-only when the permission is cleared', async () => {
		const wrapper = mountDialog(CreateShareDialog, { caseId: 'case-1' })
		wrapper.vm.form.permissionLevel = null

		await wrapper.vm.createShare()

		expect(wrapper.emitted('created')[0][0].permissionLevel).toBe('bekijken')
	})

	it('offers three permission levels and closes on cancel', async () => {
		const wrapper = mountDialog(CreateShareDialog, { caseId: 'case-1' })

		expect(wrapper.vm.permissionOptions.map((o) => o.value)).toEqual(['bekijken', 'bekijken_reageren', 'bekijken_bijdragen'])
		await button(wrapper, 'Cancel').trigger('click')
		expect(wrapper.emitted('update:open')).toEqual([[false]])
		expect(wrapper.emitted('created')).toBeUndefined()
	})
})

describe('CaseTransferDialog', () => {
	it('cannot be submitted without a target organisation and a reason', () => {
		const wrapper = mountDialog(CaseTransferDialog, { caseId: 'case-1' })

		expect(wrapper.vm.isValid).toBeFalsy()
		wrapper.vm.form.targetOrganization = { id: 'org-2' }
		wrapper.vm.form.reason = '   '
		expect(wrapper.vm.isValid).toBe(false)
		wrapper.vm.form.reason = 'Bevoegd gezag wijzigt'
		expect(wrapper.vm.isValid).toBe(true)
	})

	it('submits the target id, the reason, the date as YYYY-MM-DD and the trimmed cloud id', async () => {
		const wrapper = mountDialog(CaseTransferDialog, { caseId: 'case-1' })
		wrapper.vm.form.targetOrganization = { id: 'org-2', label: 'Omgevingsdienst' }
		wrapper.vm.form.reason = 'Bevoegd gezag wijzigt'
		wrapper.vm.form.requestedDate = new Date(Date.UTC(2026, 10, 20, 12))
		wrapper.vm.form.remoteCloudId = '  admin@od.example  '

		await wrapper.vm.submitTransfer()

		expect(wrapper.emitted('submitted')).toEqual([[{
			caseId: 'case-1',
			targetOrganization: 'org-2',
			reason: 'Bevoegd gezag wijzigt',
			requestedDate: '2026-11-20',
			remoteCloudId: 'admin@od.example',
		}]])
		expect(wrapper.vm.saving).toBe(false)
	})

	it('sends no cloud id when it is blank and today when no date is set', async () => {
		const wrapper = mountDialog(CaseTransferDialog, { caseId: 'case-1' })
		wrapper.vm.form.targetOrganization = { id: 'org-2' }
		wrapper.vm.form.reason = 'x'
		wrapper.vm.form.requestedDate = null
		wrapper.vm.form.remoteCloudId = '   '
		const before = new Date().toISOString().slice(0, 10)

		await wrapper.vm.submitTransfer()

		const payload = wrapper.emitted('submitted')[0][0]
		expect(payload.remoteCloudId).toBeNull()
		expect([before, new Date().toISOString().slice(0, 10)]).toContain(payload.requestedDate)
	})

	it('closes on cancel without submitting', async () => {
		const wrapper = mountDialog(CaseTransferDialog, { caseId: 'case-1' })

		await button(wrapper, 'Cancel').trigger('click')

		expect(wrapper.emitted('update:open')).toEqual([[false]])
		expect(wrapper.emitted('submitted')).toBeUndefined()
	})
})

describe('FederatedActivityPanel', () => {
	it('shows a loading state, then an empty state', async () => {
		const wrapper = mountDialog(FederatedActivityPanel, { federatedShareId: 'fs-1', loading: true })
		expect(wrapper.find('.loading').exists()).toBe(true)
		expect(wrapper.text()).toContain('Loading activity...')

		await wrapper.setProps({ loading: false })
		expect(wrapper.text()).toContain('No activity yet.')
	})

	it('lists entries with a remote or local badge', () => {
		const wrapper = mountDialog(FederatedActivityPanel, {
			federatedShareId: 'fs-1',
			entries: [
				{ actorType: 'remote', actor: 'od-admin', message: 'Stukken ontvangen' },
				{ actorType: 'local', actor: 'anna', message: 'Graag uw advies' },
			],
		})

		const items = wrapper.findAll('.federated-activity-panel__entry')
		expect(items).toHaveLength(2)
		expect(items[0].text()).toContain('Remote')
		expect(items[0].text()).toContain('Stukken ontvangen')
		expect(items[0].find('.federated-activity-panel__actor-badge--remote').exists()).toBe(true)
		expect(items[1].text()).toContain('Local')
	})

	it('keeps Post disabled until a message is typed', async () => {
		const wrapper = mountDialog(FederatedActivityPanel, { federatedShareId: 'fs-1' })
		expect(button(wrapper, 'Post').attributes('disabled')).toBeDefined()

		await wrapper.find('textarea').setValue('  ')
		expect(button(wrapper, 'Post').attributes('disabled')).toBeDefined()

		await wrapper.find('textarea').setValue('Akkoord')
		expect(button(wrapper, 'Post').attributes('disabled')).toBeUndefined()
	})

	it('posts the trimmed message for the share and clears the field', async () => {
		const wrapper = mountDialog(FederatedActivityPanel, { federatedShareId: 'fs-1' })
		await wrapper.find('textarea').setValue('  Akkoord met overdracht  ')

		await button(wrapper, 'Post').trigger('click')

		expect(wrapper.emitted('post')).toEqual([[{ federatedShareId: 'fs-1', message: 'Akkoord met overdracht' }]])
		expect(wrapper.vm.message).toBe('')
		expect(wrapper.vm.posting).toBe(false)
	})

	it('closes on Close', async () => {
		const wrapper = mountDialog(FederatedActivityPanel, { federatedShareId: 'fs-1' })

		await button(wrapper, 'Close').trigger('click')

		expect(wrapper.emitted('update:open')).toEqual([[false]])
	})
})
