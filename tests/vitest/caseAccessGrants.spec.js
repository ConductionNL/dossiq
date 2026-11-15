// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Granting and withdrawing a user's or group's access to one case from the
 * sharing tab (REQ-CRS-43): the grants OpenRegister lists, the grant the form
 * posts, the withdraw call, and what the owner-only refusal shows.
 *
 * @spec openspec/changes/capability-rows-say-the-whole-capability/specs/case-access-control/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), delete: vi.fn() }))
const toasts = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/dialogs', () => toasts)
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))
vi.mock('@nextcloud/vue', async () => {
	const { h } = await import('vue')
	return {
		NcButton: {
			name: 'NcButton',
			props: ['type', 'disabled'],
			emits: ['click'],
			render() {
				return h('button', { type: this.type || 'button', disabled: this.disabled, onClick: () => this.$emit('click') }, this.$slots.default?.())
			},
		},
		NcCheckboxRadioSwitch: {
			name: 'NcCheckboxRadioSwitch',
			props: ['modelValue'],
			emits: ['update:modelValue'],
			render() {
				return h('input', { type: 'checkbox', 'data-testid': 'can-edit', checked: this.modelValue, onChange: (e) => this.$emit('update:modelValue', e.target.checked) })
			},
		},
		NcNoteCard: { name: 'NcNoteCard', render() { return h('div', { 'data-note': '' }, this.$slots.default?.()) } },
		NcSelect: {
			name: 'NcSelect',
			props: ['modelValue', 'options', 'inputLabel'],
			emits: ['update:modelValue'],
			render() {
				return h('select', { 'data-testid': 'grant-type', 'aria-label': this.inputLabel, value: this.modelValue, onChange: (e) => this.$emit('update:modelValue', e.target.value) }, this.options.map((o) => h('option', { value: o.id }, o.label)))
			},
		},
		NcTextField: {
			name: 'NcTextField',
			props: ['modelValue', 'label'],
			emits: ['update:modelValue'],
			render() {
				return h('input', { 'aria-label': this.label, value: this.modelValue, onInput: (e) => this.$emit('update:modelValue', e.target.value) })
			},
		},
	}
})

const { caseGrantsEndpoint, grantPermissions, personalGrants } = await import('../../src/utils/caseAccessGrants.js')
const { default: CaseAccessGrants } = await import('../../src/views/cases/components/CaseAccessGrants.vue')

const SHARES = '/index.php/apps/openregister/api/objects/dossiq/case/case-1/shares'

/**
 * Mount the grants for case-1 and let the list load.
 *
 * @return {Promise<object>} The wrapper.
 */
async function grants() {
	const wrapper = mount(CaseAccessGrants, { props: { objectId: 'case-1' } })
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	for (const fn of [...Object.values(http), ...Object.values(toasts)]) {
		fn.mockReset()
	}
})

describe('case grant rules', () => {
	it('addresses a case\'s grants and one grant by its provider id', () => {
		expect(caseGrantsEndpoint('case 1')).toBe('/apps/openregister/api/objects/dossiq/case/case%201/shares')
		expect(caseGrantsEndpoint('case-1', 'ocinternal:7')).toBe('/apps/openregister/api/objects/dossiq/case/case-1/shares/ocinternal%3A7')
	})

	it('asks for reading, or reading and editing', () => {
		expect(grantPermissions(false)).toBe(1)
		expect(grantPermissions(true)).toBe(3)
	})

	it('keeps the user and group grants, each saying whether it edits', () => {
		expect(personalGrants([
			{ id: 'ocinternal:1', type: 'user', sharedWith: 'jan', permissions: 1 },
			{ id: 'ocinternal:2', type: 'group', sharedWith: 'vergunningen', permissions: 31 },
			{ id: 'ocFederatedSharing:3', type: 'remote', sharedWith: 'a@b', permissions: 1 },
			{ type: 'user', sharedWith: 'no-id' },
			null,
		])).toEqual([
			{ id: 'ocinternal:1', type: 'user', sharedWith: 'jan', canEdit: false },
			{ id: 'ocinternal:2', type: 'group', sharedWith: 'vergunningen', canEdit: true },
		])
		expect(personalGrants(undefined)).toEqual([])
	})
})

describe('the case access grants', () => {
	it('lists who was given access, by name or group', async () => {
		http.get.mockResolvedValue({ data: { results: [
			{ id: 'ocinternal:1', type: 'user', sharedWith: 'jan', permissions: 1 },
			{ id: 'ocinternal:2', type: 'group', sharedWith: 'vergunningen', permissions: 3 },
		] } })

		const wrapper = await grants()

		expect(http.get).toHaveBeenCalledWith(SHARES)
		const items = wrapper.findAll('.case-access-grants__item')
		expect(items.map((item) => item.find('.case-access-grants__who').text())).toEqual(['jan', 'vergunningen'])
		expect(items.map((item) => item.find('.case-access-grants__meta').text())).toEqual(['Person · can read', 'Group · can edit'])
	})

	it('says when nobody was given access', async () => {
		http.get.mockResolvedValue({ data: { results: [] } })

		const wrapper = await grants()

		expect(wrapper.find('.case-access-grants__note').text()).toBe('Nobody was given access to this case by name or group.')
	})

	it('tells somebody who may not manage the grants so, and offers no form', async () => {
		http.get.mockRejectedValue({ response: { status: 403 } })

		const wrapper = await grants()

		expect(wrapper.find('[data-testid="case-access-grants-forbidden"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="case-access-grant-form"]').exists()).toBe(false)
	})

	it('says when the grants could not be loaded', async () => {
		http.get.mockRejectedValue(new Error('down'))

		const wrapper = await grants()

		expect(wrapper.find('[role="alert"]').text()).toBe('The access to this case could not be loaded.')
		expect(wrapper.find('[data-testid="case-access-grant-form"]').exists()).toBe(true)
	})

	it('grants a group edit access to this case and reloads the list', async () => {
		http.get.mockResolvedValueOnce({ data: { results: [] } })
			.mockResolvedValueOnce({ data: { results: [{ id: 'ocinternal:9', type: 'group', sharedWith: 'vergunningen', permissions: 3 }] } })
		http.post.mockResolvedValue({ data: {} })

		const wrapper = await grants()
		expect(wrapper.find('[data-testid="case-access-grant-submit"]').attributes('disabled')).toBeDefined()

		await wrapper.find('[data-testid="grant-type"]').setValue('group')
		await wrapper.find('[data-testid="case-access-grant-name"]').setValue('  vergunningen ')
		wrapper.findComponent({ name: 'NcCheckboxRadioSwitch' }).vm.$emit('update:modelValue', true)
		await flushPromises()
		await wrapper.find('[data-testid="case-access-grant-form"]').trigger('submit')
		await flushPromises()

		expect(http.post).toHaveBeenCalledWith(SHARES, { type: 'group', shareWith: 'vergunningen', permissions: 3 })
		expect(toasts.showSuccess).toHaveBeenCalledWith('Access given')
		expect(wrapper.find('[data-testid="case-access-grant-name"]').element.value).toBe('')
		expect(wrapper.findAll('.case-access-grants__item')).toHaveLength(1)
	})

	it('shows OpenRegister\'s refusal when a grant cannot be made', async () => {
		http.get.mockResolvedValue({ data: { results: [] } })
		http.post.mockRejectedValue({ response: { data: { message: 'No such user' } } })

		const wrapper = await grants()
		await wrapper.find('[data-testid="case-access-grant-name"]').setValue('nobody')
		await wrapper.find('[data-testid="case-access-grant-form"]').trigger('submit')
		await flushPromises()

		expect(http.post).toHaveBeenCalledWith(SHARES, { type: 'user', shareWith: 'nobody', permissions: 1 })
		expect(toasts.showError).toHaveBeenCalledWith('No such user')
	})

	it('withdraws a grant by its id and reloads the list', async () => {
		http.get.mockResolvedValueOnce({ data: { results: [{ id: 'ocinternal:1', type: 'user', sharedWith: 'jan', permissions: 1 }] } })
			.mockResolvedValueOnce({ data: { results: [] } })
		http.delete.mockResolvedValue({ data: {} })

		const wrapper = await grants()
		await wrapper.find('[data-testid="case-access-grant-withdraw-ocinternal:1"]').trigger('click')
		await flushPromises()

		expect(http.delete).toHaveBeenCalledWith(SHARES + '/ocinternal%3A1')
		expect(toasts.showSuccess).toHaveBeenCalledWith('Access withdrawn')
		expect(wrapper.findAll('.case-access-grants__item')).toHaveLength(0)
	})

	it('keeps the grant and says so when it cannot be withdrawn', async () => {
		http.get.mockResolvedValue({ data: { results: [{ id: 'ocinternal:1', type: 'user', sharedWith: 'jan', permissions: 1 }] } })
		http.delete.mockRejectedValue(new Error('down'))

		const wrapper = await grants()
		await wrapper.find('[data-testid="case-access-grant-withdraw-ocinternal:1"]').trigger('click')
		await flushPromises()

		expect(toasts.showError).toHaveBeenCalledWith('The access could not be withdrawn.')
		expect(wrapper.findAll('.case-access-grants__item')).toHaveLength(1)
	})
})
