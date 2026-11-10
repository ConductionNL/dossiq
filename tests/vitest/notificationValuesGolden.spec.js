/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation golden for `valuesFrom`
 * (src/services/notificationPreferenceProps.js): the group, personal, forced
 * and refused values for one column and for several, from every kind of row.
 *
 * @spec openspec/changes/notification-settings-on-the-shared-screen/specs/case-management/spec.md
 */

import { describe, expect, it } from 'vitest'
import { valuesFrom } from '../../src/services/notificationPreferenceProps.js'

const ENTRIES = [
	{ schema: 'case', notification: 'assigned', source: 'user-override', enabled: true, channels: ['mail'] },
	{ schema: 'case', notification: 'closed', source: 'group-default', enabled: false, availableChannels: ['mail', 'push'] },
	{ schema: 'task', notification: 'due', source: 'app-default', enabled: true, availableChannels: ['mail', 'push'], optInChannels: ['push'] },
	{ schema: 'task', notification: 'late', source: 'group-default', enabled: true, availableChannels: ['push'], forced: { value: false, by: 'admin', reason: 'policy' } },
	{ schema: 'task', notification: 'gone', forced: { value: true }, refused: { reason: 'not allowed' } },
	{ notification: 'bare', forced: null, refused: null },
	{ schema: 'x', notification: 'r', refused: {} },
]

describe('valuesFrom golden', () => {
	it('keys one column by the single channel id', () => {
		expect(valuesFrom({ entries: ENTRIES })).toEqual({
			groupValues: {
				'case-closed': { notification: false },
				'task-late': { notification: true },
			},
			personalValues: {
				'case-assigned': { notification: true },
			},
			forcedValues: {
				'task-late': { notification: { value: false, by: 'admin', reason: 'policy' } },
				'task-gone': { notification: { value: true, by: '', reason: '' } },
			},
			refusals: {
				'task-gone': { notification: { reason: 'not allowed' } },
				'x-r': { notification: { reason: '' } },
			},
		})
	})

	it('keys one column by the given channel id', () => {
		const out = valuesFrom({ entries: ENTRIES.slice(0, 1), channelId: 'bell' })
		expect(out.personalValues).toEqual({ 'case-assigned': { bell: true } })
	})

	it('keys every column when the platform names them', () => {
		const out = valuesFrom({ entries: ENTRIES, channelIds: ['mail', 'push'] })
		expect(out).toEqual({
			groupValues: {
				'case-closed': { mail: false, push: false },
				'task-late': { mail: false, push: true },
			},
			personalValues: {
				'case-assigned': { mail: true, push: false },
			},
			forcedValues: {
				'task-late': {
					mail: { value: false, by: 'admin', reason: 'policy' },
					push: { value: false, by: 'admin', reason: 'policy' },
				},
				'task-gone': {
					mail: { value: true, by: '', reason: '' },
					push: { value: true, by: '', reason: '' },
				},
			},
			refusals: {
				'task-gone': { mail: { reason: 'not allowed' }, push: { reason: 'not allowed' } },
				'x-r': { mail: { reason: '' }, push: { reason: '' } },
			},
		})
		expect(out.forcedValues['task-late'].mail).not.toBe(out.forcedValues['task-late'].push)
	})

	it('answers empty values for no entries', () => {
		expect(valuesFrom()).toEqual({ groupValues: {}, personalValues: {}, forcedValues: {}, refusals: {} })
	})
})
