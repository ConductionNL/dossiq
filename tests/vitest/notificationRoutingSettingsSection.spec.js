// @vitest-environment jsdom
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * The notification-routing section: it loads the preferences for the chosen
 * domain, labels events and channels, writes one preference change (403 and
 * other errors each get their own message), and sets a team default.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */
import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const api = {
	fetchPreferences: vi.fn(),
	savePreference: vi.fn(),
	saveGroupDefault: vi.fn(),
}

vi.mock('../../src/services/notificationRoutingApi.js', () => api)
vi.mock('../../src/utils/notificationLabels.js', () => ({
	notificationLabels: () => ({ 'case.caseClosed': 'A case is closed', shared: 'Shared label' }),
}))
vi.mock('../../src/services/notificationPreferenceProps.js', () => ({
	propsFor: (args) => ({ rows: args.entries.length }),
	keyFor: (entry) => `${entry.schema}.${entry.notification}`,
	channelsOnFor: (entry) => entry.channels || [],
	writeFor: ({ eventId, value, channelId, scope }) =>
		value === null
			? null
			: { schema: eventId.split('.')[0], notification: eventId.split('.')[1], enabled: value, channels: channelId ? [channelId] : null, scope },
}))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text, vars) => (vars ? text.replace(/\{(\w+)\}/g, (m, k) => vars[k] ?? m) : text) }))
vi.mock('@conduction/nextcloud-vue', () => ({ CnNotificationMatrix: { name: 'CnNotificationMatrix', render: () => null } }))
vi.mock('@nextcloud/vue', () => ({
	NcButton: { name: 'NcButton', render: () => null },
	NcEmptyContent: { name: 'NcEmptyContent', render: () => null },
	NcLoadingIcon: { name: 'NcLoadingIcon', render: () => null },
	NcSelect: { name: 'NcSelect', render: () => null },
	NcTextField: { name: 'NcTextField', render: () => null },
}))

const { default: NotificationRoutingSettings } = await import('../../src/views/settings/NotificationRoutingSettings.vue')

const httpError = (status) => Object.assign(new Error('x'), { response: { status } })

async function mounted(props = {}) {
	const wrapper = shallowMount(NotificationRoutingSettings, { propsData: props })
	await flushPromises()
	return wrapper
}

describe('the notification-routing section', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		api.fetchPreferences.mockResolvedValue({
			entries: [{ schema: 'case', notification: 'caseAssigned', channels: ['email'] }],
			channels: ['email', 'nc-notification'],
		})
	})

	it('loads the preferences for the whole workload on mount', async () => {
		const wrapper = await mounted()

		expect(api.fetchPreferences).toHaveBeenCalledWith(null)
		expect(wrapper.vm.entries).toHaveLength(1)
		expect(wrapper.vm.channels).toEqual(['email', 'nc-notification'])
		expect(wrapper.vm.loading).toBe(false)
		expect(wrapper.vm.selectedDomain.id).toBe('')
		expect(wrapper.vm.preferenceProps.rows).toBe(1)
	})

	it('reloads scoped to the chosen domain', async () => {
		const wrapper = await mounted()
		await wrapper.vm.chooseDomain({ id: 'zaken' })
		expect(api.fetchPreferences).toHaveBeenLastCalledWith('domain:zaken')
		await wrapper.vm.chooseDomain(null)
		expect(api.fetchPreferences).toHaveBeenLastCalledWith(null)
		expect(wrapper.vm.selectedDomain.id).toBe('')
	})

	it('labels a known event and channel, and falls back for the rest', async () => {
		const wrapper = await mounted()
		expect(wrapper.vm.labelFor({ notification: 'caseAssigned' })).toBe('A case is assigned to me')
		expect(wrapper.vm.labelFor({ schema: 'case', notification: 'caseClosed' })).toBe('A case is closed')
		expect(wrapper.vm.labelFor({ schema: 'x', schemaTitle: 'Thing', notification: 'odd' })).toBe('Thing: odd')
		expect(wrapper.vm.channelLabel('email')).toBe('By e-mail')
		expect(wrapper.vm.channelLabel('carrier-pigeon')).toBe('carrier-pigeon')
		expect(wrapper.vm.entryOptions[0].notificationLabel).toBe('A case is assigned to me')
	})

	it('writes a preference change and reloads; a no-op write is skipped', async () => {
		api.savePreference.mockResolvedValue({})
		const wrapper = await mounted()
		api.fetchPreferences.mockClear()

		await wrapper.vm.onChange({ eventId: 'case.caseAssigned', channelId: 'email', value: true })
		expect(api.savePreference).toHaveBeenCalledWith({ schema: 'case', notification: 'caseAssigned', enabled: true, channels: ['email'], scope: null })
		expect(wrapper.vm.message).toBe('')
		expect(api.fetchPreferences).toHaveBeenCalledTimes(1)

		api.savePreference.mockClear()
		await wrapper.vm.onChange({ eventId: 'case.caseAssigned', value: null })
		expect(api.savePreference).not.toHaveBeenCalled()
	})

	it('explains a refused write by its status', async () => {
		const wrapper = await mounted()
		api.savePreference.mockRejectedValueOnce(httpError(403))
		await wrapper.vm.onChange({ eventId: 'case.caseAssigned', channelId: 'email', value: true })
		expect(wrapper.vm.message).toBe('An administrator decides this one, so it is not yours to change.')

		api.savePreference.mockRejectedValueOnce(httpError(500))
		await wrapper.vm.onChange({ eventId: 'case.caseAssigned', channelId: 'email', value: true })
		expect(wrapper.vm.message).toBe('That setting was not saved.')
	})

	it('sets a team default only when a group and an entry are chosen', async () => {
		const wrapper = await mounted()
		expect(wrapper.vm.canWriteGroupDefault).toBe(false)
		wrapper.vm.group = ' team-a '
		wrapper.vm.selectedGroupEntry = { schema: 'case', notification: 'caseAssigned' }
		wrapper.vm.domain = 'zaken'
		expect(wrapper.vm.canWriteGroupDefault).toBe(true)

		api.saveGroupDefault.mockResolvedValue({})
		await wrapper.vm.setGroupDefault(true)
		expect(api.saveGroupDefault).toHaveBeenCalledWith({ group: 'team-a', schema: 'case', notification: 'caseAssigned', enabled: true, scope: 'domain:zaken' })
		expect(wrapper.vm.groupMessage).toBe('The team default is set.')
	})

	it('explains a refused team default by its status', async () => {
		const wrapper = await mounted()
		wrapper.vm.group = 'team-a'
		wrapper.vm.selectedGroupEntry = { schema: 'case', notification: 'caseAssigned' }

		api.saveGroupDefault.mockRejectedValueOnce(httpError(403))
		await wrapper.vm.setGroupDefault(false)
		expect(wrapper.vm.groupMessage).toBe('You do not administer that group, so its default is not yours to set.')

		api.saveGroupDefault.mockRejectedValueOnce(httpError(500))
		await wrapper.vm.setGroupDefault(false)
		expect(wrapper.vm.groupMessage).toBe('The team default was not set.')
	})
})
