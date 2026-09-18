// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * dossiq's notification settings render the shared screen, not a second one.
 *
 * THE DEFECT THIS PINS DOWN. dossiq kept its own list of switches beside
 * `CnNotificationPreferences`. It could say three layers — the shipped
 * default, a team default and your own — and had nowhere to put a channel an
 * administrator has FORCED or one the platform REFUSES for this recipient. So
 * a handler could switch a notice off, keep receiving it, and read a page that
 * said "you set this" while an administrator's forced row was what actually
 * decided. These assert that the forced row and its reason reach the screen,
 * and that a change goes back to the platform as the right notification.
 *
 * @spec openspec/changes/notification-settings-on-the-shared-screen/specs/case-management/spec.md
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const SharedScreenStub = defineComponent({
	name: 'CnNotificationPreferences',
	props: ['events', 'channels', 'groupValues', 'personalValues', 'forcedValues', 'refusals'],
	emits: ['change'],
	render() {
		return h('div', { class: 'shared-screen' }, 'shared screen')
	},
})

vi.mock('@conduction/nextcloud-vue', () => ({
	CnNotificationPreferences: SharedScreenStub,
}))

const fetchPreferences = vi.fn()
const savePreference = vi.fn()
const saveGroupDefault = vi.fn()

vi.mock('../../src/services/notificationRoutingApi.js', () => ({
	fetchPreferences: (...args) => fetchPreferences(...args),
	savePreference: (...args) => savePreference(...args),
	saveGroupDefault: (...args) => saveGroupDefault(...args),
	clearPreference: vi.fn(),
}))

const { default: NotificationRoutingSettings } = await import(
	'../../src/views/settings/NotificationRoutingSettings.vue'
)

const ENTRIES = [
	{
		schema: 'case',
		schemaTitle: 'Cases',
		notification: 'caseAssigned',
		enabled: true,
		source: 'user-override',
		forced: {
			value: true,
			by: 'Team leads',
			reason: 'An assignment has to reach the handler',
		},
	},
	{
		schema: 'workDigest',
		schemaTitle: 'Work digest',
		notification: 'workDigestReady',
		enabled: false,
		source: 'app-default',
	},
]

/**
 * Mount the settings screen with the reads stubbed.
 *
 * @param {object} props Extra props.
 * @return {Promise<object>} The wrapper, after the first read.
 */
async function mountSettings(props = {}) {
	const wrapper = mount(NotificationRoutingSettings, {
		props,
		global: {
			mocks: { t: (app, text) => text },
			stubs: {
				NcSelect: true,
				NcTextField: true,
				NcButton: true,
				NcLoadingIcon: true,
				NcEmptyContent: true,
			},
		},
	})
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	vi.clearAllMocks()
	fetchPreferences.mockResolvedValue(ENTRIES)
	savePreference.mockResolvedValue({})
	saveGroupDefault.mockResolvedValue({})
})

describe('the shared screen is what renders', () => {
	it('mounts CnNotificationPreferences rather than a list of its own', async () => {
		const wrapper = await mountSettings()

		expect(wrapper.findComponent(SharedScreenStub).exists()).toBe(true)
	})

	it('hands it a row per notification, grouped by the schema', async () => {
		const wrapper = await mountSettings()

		const events = wrapper.findComponent(SharedScreenStub).props('events')
		expect(events.map((event) => event.id))
			.toEqual(['case-caseAssigned', 'workDigest-workDigestReady'])
		expect(events[0].groupLabel).toBe('Cases')
	})

	it('hands it the forced row, with who forced it and why', async () => {
		// THE POINT OF THE CHANGE. Without this a handler reads "you set this"
		// while an administrator's forced row is what actually decided.
		const wrapper = await mountSettings()

		const forced = wrapper.findComponent(SharedScreenStub).props('forcedValues')
		expect(forced['case-caseAssigned'].notification).toEqual({
			value: true,
			by: 'Team leads',
			reason: 'An assignment has to reach the handler',
		})
	})

	it('forces nothing the platform did not force, which is the control', async () => {
		// Without this, handing every row a forced entry would pass the test
		// above and lock the whole screen.
		const wrapper = await mountSettings()

		expect(wrapper.findComponent(SharedScreenStub).props('forcedValues')['workDigest-workDigestReady'])
			.toBeUndefined()
	})

	it('puts an overridden value on the person\'s own layer', async () => {
		const wrapper = await mountSettings()

		expect(wrapper.findComponent(SharedScreenStub).props('personalValues')['case-caseAssigned'])
			.toEqual({ notification: true })
	})

	it('says nothing is routed rather than rendering an empty table', async () => {
		fetchPreferences.mockResolvedValue([])
		const wrapper = await mountSettings()

		expect(wrapper.findComponent(SharedScreenStub).exists()).toBe(false)
	})
})

describe('a change goes back to the platform', () => {
	it('writes the notification the row stands for', async () => {
		const wrapper = await mountSettings()

		wrapper.findComponent(SharedScreenStub).vm.$emit('change', {
			eventId: 'workDigest-workDigestReady',
			channelId: 'notification',
			scope: '',
			value: true,
		})
		await flushPromises()

		expect(savePreference).toHaveBeenCalledWith({
			schema: 'workDigest',
			notification: 'workDigestReady',
			enabled: true,
			scope: null,
		})
	})

	it('re-reads afterwards, so the layer that decided is the platform\'s answer', async () => {
		// A screen that kept the click's value would show a setting the
		// platform may have overruled a moment later.
		const wrapper = await mountSettings()
		expect(fetchPreferences).toHaveBeenCalledTimes(1)

		wrapper.findComponent(SharedScreenStub).vm.$emit('change', {
			eventId: 'workDigest-workDigestReady',
			channelId: 'notification',
			value: false,
		})
		await flushPromises()

		expect(fetchPreferences).toHaveBeenCalledTimes(2)
	})

	it('says a refused write was refused, rather than leaving the switch where the click put it', async () => {
		savePreference.mockRejectedValue({ response: { status: 403 } })
		const wrapper = await mountSettings()

		wrapper.findComponent(SharedScreenStub).vm.$emit('change', {
			eventId: 'workDigest-workDigestReady',
			channelId: 'notification',
			value: true,
		})
		await flushPromises()

		expect(wrapper.find('[data-testid="notification-routing-message"]').text())
			.toBe('An administrator decides this one, so it is not yours to change.')
	})

	it('says nothing when the write worked, which is the control', async () => {
		const wrapper = await mountSettings()

		wrapper.findComponent(SharedScreenStub).vm.$emit('change', {
			eventId: 'workDigest-workDigestReady',
			channelId: 'notification',
			value: true,
		})
		await flushPromises()

		expect(wrapper.find('[data-testid="notification-routing-message"]').exists()).toBe(false)
	})

	it('writes nothing for an id it did not make', async () => {
		// A write to the wrong notification is silent and permanent.
		const wrapper = await mountSettings()

		wrapper.findComponent(SharedScreenStub).vm.$emit('change', {
			eventId: 'nodash',
			channelId: 'notification',
			value: true,
		})
		await flushPromises()

		expect(savePreference).not.toHaveBeenCalled()
	})
})
