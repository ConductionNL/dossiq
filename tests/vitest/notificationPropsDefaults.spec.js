/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What the preference-screen shapers do with a call that leaves options out,
 * passes them as undefined or passes something that is not a list: the
 * defaults apply to a missing or undefined option only.
 *
 * @spec openspec/changes/notification-settings-on-the-shared-screen/specs/case-management/spec.md
 */
import { describe, expect, it } from 'vitest'
import {
	channelAxisFrom,
	channelsFrom,
	eventsFrom,
	propsFor,
	SINGLE_CHANNEL_ID,
	valuesFrom,
	writeFor,
} from '../../src/services/notificationPreferenceProps.js'

const single = (label) => [{ id: SINGLE_CHANNEL_ID, label, configured: true, unconfiguredReason: '' }]

describe('channelsFrom', () => {
	it('draws the one column without channels, for undefined, null, a non-list or an empty list', () => {
		expect(channelsFrom()).toEqual(single('Notifications'))
		expect(channelsFrom({ channels: undefined, singleChannelLabel: undefined })).toEqual(single('Notifications'))
		expect(channelsFrom({ channels: null, singleChannelLabel: 'Alerts' })).toEqual(single('Alerts'))
		expect(channelsFrom({ channels: 'mail', singleChannelLabel: null })).toEqual(single(null))
		expect(channelsFrom({ channels: [] })).toEqual(single('Notifications'))
	})

	it('reads each channel, labelled by its label, else its id', () => {
		expect(channelsFrom({ channels: [{ id: 'mail', configured: false, unconfiguredReason: 'No SMTP' }, null] })).toEqual([
			{ id: 'mail', label: 'mail', configured: false, unconfiguredReason: 'No SMTP' },
			{ id: '', label: '', configured: true, unconfiguredReason: '' },
		])
	})
})

describe('channelAxisFrom', () => {
	it('keeps each non-empty string id once, in order, and skips entries without a list', () => {
		expect(channelAxisFrom()).toEqual([])
		expect(channelAxisFrom([
			null,
			{ availableChannels: 'mail' },
			{ availableChannels: ['in-app', '', 3, 'mail'] },
			{ availableChannels: ['mail', 'in-app', 'sms'] },
		])).toEqual(['in-app', 'mail', 'sms'])
	})
})

describe('eventsFrom', () => {
	it('applies the label defaults to undefined options', () => {
		expect(eventsFrom()).toEqual([])
		expect(eventsFrom({ entries: [{ schema: 'case', notification: 'due', schemaTitle: 'Cases' }], label: undefined, groupLabel: undefined, channelIds: undefined })).toEqual([
			{ id: 'case-due', label: 'case-due', group: 'case', groupLabel: 'Cases', appDefault: false, immediate: false },
		])
	})
})

describe('valuesFrom', () => {
	it('reads with the one column when the options are left out or undefined', () => {
		const empty = { groupValues: {}, personalValues: {}, forcedValues: {}, refusals: {} }
		expect(valuesFrom()).toEqual(empty)
		expect(valuesFrom({ entries: [{ schema: 'a', notification: 'b', source: 'user-override', enabled: true }], channelId: undefined, channelIds: undefined }))
			.toEqual({ ...empty, personalValues: { 'a-b': { [SINGLE_CHANNEL_ID]: true } } })
	})
})

describe('propsFor', () => {
	const entries = [{ schema: 'a', notification: 'b', availableChannels: ['in-app', 'mail'], optInChannels: ['mail'], enabled: true, channels: ['in-app'] }]

	it('declares the axis channels when the channels are not a list or empty', () => {
		const ids = (props) => props.channels.map((column) => [column.id, column.label])
		expect(ids(propsFor({ entries, channels: null }))).toEqual([['in-app', 'in-app'], ['mail', 'mail']])
		expect(ids(propsFor({ entries, channels: [], channelLabel: (id) => id.toUpperCase() }))).toEqual([['in-app', 'IN-APP'], ['mail', 'MAIL']])
		expect(ids(propsFor({ entries, channelLabel: undefined }))).toEqual([['in-app', 'in-app'], ['mail', 'mail']])
	})

	it('keeps the given channels when there are some, and one column without an axis', () => {
		expect(propsFor({ entries, channels: [{ id: 'x', label: 'X' }] }).channels.map((column) => column.id)).toEqual(['x'])
		const flat = propsFor({ entries: [{ schema: 'a', notification: 'b', enabled: true }], singleChannelLabel: 'All' })
		expect(flat.channels).toEqual(single('All'))
		expect(flat.events[0].appDefault).toBe(true)
		expect(propsFor()).toEqual({ events: [], channels: single('Notifications'), groupValues: {}, personalValues: {}, forcedValues: {}, refusals: {} })
	})
})

describe('writeFor', () => {
	it('refuses an id it did not make', () => {
		expect(writeFor()).toBe(null)
		expect(writeFor({ eventId: '-x', value: true })).toBe(null)
	})

	it('writes the one column, with or without a scope', () => {
		expect(writeFor({ eventId: 'case-due-soon', value: 1 })).toEqual({ schema: 'case', notification: 'due-soon', enabled: false })
		expect(writeFor({ eventId: 'case-due', value: true, scope: undefined, channelId: undefined, current: ['mail'] })).toEqual({ schema: 'case', notification: 'due', enabled: true })
		expect(writeFor({ eventId: 'case-due', value: true, scope: 'woo' })).toEqual({ schema: 'case', notification: 'due', enabled: true, scope: 'domain:woo' })
	})

	it('switches one channel of several only with a current list', () => {
		expect(writeFor({ eventId: 'case-due', value: true, channelId: 'mail', current: null })).toEqual({ schema: 'case', notification: 'due', enabled: true })
		expect(writeFor({ eventId: 'case-due', value: false, channelId: 'mail', current: ['mail'] })).toEqual({ schema: 'case', notification: 'due', enabled: false, channels: [] })
		expect(writeFor({ eventId: 'case-due', value: true, channelId: 'mail', current: ['in-app'], scope: 'woo' })).toEqual({ schema: 'case', notification: 'due', enabled: true, channels: ['in-app', 'mail'], scope: 'domain:woo' })
	})
})
