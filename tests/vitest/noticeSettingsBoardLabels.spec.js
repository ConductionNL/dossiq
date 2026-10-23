// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Notices section of the personal settings dialog, as board
 * DqPersoonlijkeInstellingen draws it: the domain picker is labelled
 * "Meldingsdomein" and always holds a domain (no clear button, r23 measured
 * "Selectie wissen" extra on live), and the matrix groups read in the
 * reader's language: live showed the register's English "Work digest".
 *
 * @spec openspec/changes/notification-settings-on-the-shared-screen/specs/case-management/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const Matrix = defineComponent({
	name: 'CnNotificationMatrix',
	props: ['events', 'channels', 'groupValues', 'personalValues', 'forcedValues', 'refusals'],
	render() {
		return h('div')
	},
})
const Select = defineComponent({
	name: 'NcSelect',
	props: ['modelValue', 'options', 'inputLabel', 'ariaLabelCombobox', 'clearable'],
	render() {
		return h('div')
	},
})

vi.mock('@conduction/nextcloud-vue', () => ({ CnNotificationMatrix: Matrix }))
vi.mock('@nextcloud/l10n', async (importOriginal) => ({
	...(await importOriginal()),
	translate: (app, text) => `«${text}»`,
}))
const fetchPreferences = vi.fn()
vi.mock('../../src/services/notificationRoutingApi.js', () => ({
	fetchPreferences: (...args) => fetchPreferences(...args),
	savePreference: vi.fn(),
	saveGroupDefault: vi.fn(),
	clearPreference: vi.fn(),
}))

const { default: NotificationRoutingSettings } = await import('../../src/views/settings/NotificationRoutingSettings.vue')

const ENTRIES = [
	{ schema: 'case', schemaTitle: 'Cases', notification: 'caseAssigned', enabled: true, source: 'app-default' },
	{ schema: 'workDigest', schemaTitle: 'Work digest', notification: 'workDigestReady', enabled: true, source: 'app-default' },
]

/**
 * Mount the section with the reads stubbed.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountSection() {
	const wrapper = mount(NotificationRoutingSettings, {
		global: { stubs: { NcSelect: Select, NcTextField: true, NcButton: true, NcLoadingIcon: true, NcEmptyContent: true } },
	})
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	fetchPreferences.mockResolvedValue(ENTRIES)
})

describe('the Notices section on board DqPersoonlijkeInstellingen', () => {
	it('labels the domain picker "Meldingsdomein" and offers no clear button', async () => {
		const picker = (await mountSection()).findAllComponents(Select)[0]
		expect(picker.props('inputLabel')).toBe('«Notification domain»')
		expect(picker.props('clearable')).toBe(false)
	})

	it('translates the matrix groups', async () => {
		const events = (await mountSection()).findComponent(Matrix).props('events')
		expect(events.map((event) => event.groupLabel)).toEqual(['«Cases»', '«Work digest»'])
	})
})
