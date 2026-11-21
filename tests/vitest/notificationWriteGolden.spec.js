/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation tests for `writeFor`
 * (src/services/notificationPreferenceProps.js): the write one switch sends,
 * for one column and for one channel of several, with and without a scope.
 *
 * @spec openspec/changes/notification-settings-on-the-shared-screen/specs/case-management/spec.md
 */

import { describe, expect, it } from 'vitest'
import { writeFor } from '../../src/services/notificationPreferenceProps.js'

describe('writeFor golden', () => {
	it.each([undefined, null, '', 'nodash', '-leading', 42])('writes nothing for the id %j', (eventId) => {
		expect(writeFor({ eventId, value: true })).toBeNull()
	})

	it('writes nothing without options', () => {
		expect(writeFor()).toBeNull()
	})

	it('splits the id at its first dash and keeps the switch', () => {
		const write = writeFor({ eventId: 'case-hand-off', value: 'yes' })
		expect(write).toEqual({ schema: 'case', notification: 'hand-off', enabled: false })
	})

	it('scopes the write to a domain', () => {
		const write = writeFor({ eventId: 'case-x', value: true, scope: 'vth' })
		expect(write).toEqual({ schema: 'case', notification: 'x', enabled: true, scope: 'domain:vth' })
		expect(Object.keys(write)).toEqual(['schema', 'notification', 'enabled', 'scope'])
	})

	it('writes the whole channel set with one channel switched', () => {
		const on = writeFor({ eventId: 'case-x', value: true, channelId: 'push', current: ['mail', 'push'], scope: 'a' })
		expect(on).toEqual({ schema: 'case', notification: 'x', enabled: true, channels: ['mail', 'push'], scope: 'domain:a' })
		expect(Object.keys(on)).toEqual(['schema', 'notification', 'enabled', 'channels', 'scope'])

		expect(writeFor({ eventId: 'case-x', value: false, channelId: 'push', current: ['push'] }))
			.toEqual({ schema: 'case', notification: 'x', enabled: false, channels: [] })
		expect(writeFor({ eventId: 'case-x', value: false, channelId: 'push', current: ['mail'] }))
			.toEqual({ schema: 'case', notification: 'x', enabled: true, channels: ['mail'] })
	})

	it('keeps a single switch for the single column or without the current set', () => {
		expect(writeFor({ eventId: 'case-x', value: true, channelId: 'notification', current: ['mail'] }))
			.toEqual({ schema: 'case', notification: 'x', enabled: true })
		expect(writeFor({ eventId: 'case-x', value: true, channelId: 'push', current: 'mail' }))
			.toEqual({ schema: 'case', notification: 'x', enabled: true })
	})
})
