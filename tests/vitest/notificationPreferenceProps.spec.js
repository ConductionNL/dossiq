/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The platform's answer, shaped for the shared preferences screen.
 *
 * Every case here guards a value that would look right on screen and not be in
 * force. A layer this file guessed at. A channel column that exists only here,
 * so a handler's click lands somewhere else. A forced row read as "on" when an
 * administrator forced it OFF. And an id split at the wrong dash, which writes
 * to a notification nobody has.
 *
 * @spec openspec/changes/notification-settings-on-the-shared-screen/specs/case-management/spec.md
 */
import { describe, expect, it } from 'vitest'
import {
	channelsFrom,
	eventsFrom,
	keyFor,
	propsFor,
	SINGLE_CHANNEL_ID,
	valuesFrom,
	writeFor,
} from '../../src/services/notificationPreferenceProps.js'

const ENTRIES = [
	{
		schema: 'case',
		schemaTitle: 'Cases',
		notification: 'caseAssigned',
		enabled: true,
		source: 'user-override',
	},
	{
		schema: 'case',
		schemaTitle: 'Cases',
		notification: 'caseHandoffIntake',
		enabled: false,
		source: 'group-default',
	},
	{
		schema: 'workDigest',
		schemaTitle: 'Work digest',
		notification: 'workDigestReady',
		enabled: true,
		source: 'app-default',
	},
]

describe('the id of a row', () => {
	it('carries the schema as well as the key', () => {
		// The same key can exist on two schemas. A collision would merge two
		// notifications into one switch, and half of them would stop being
		// settable at all.
		expect(keyFor({ schema: 'case', notification: 'caseAssigned' })).toBe(
			'case-caseAssigned',
		)
		expect(
			keyFor({ schema: 'substitution', notification: 'caseAssigned' }),
		).not.toBe(keyFor({ schema: 'case', notification: 'caseAssigned' }))
	})
})

describe('the columns', () => {
	it("uses the platform's channels when it has them", () => {
		expect(channelsFrom({ channels: [{ id: 'mail', label: 'Mail' }] })).toEqual([
			{ id: 'mail', label: 'Mail', configured: true, unconfiguredReason: '' },
		])
	})

	it('carries a channel the instance has not configured, with the reason', () => {
		expect(
			channelsFrom({
				channels: [
					{
						id: 'sms',
						label: 'SMS',
						configured: false,
						unconfiguredReason: 'No gateway',
					},
				],
			})[0],
		).toEqual({
			id: 'sms',
			label: 'SMS',
			configured: false,
			unconfiguredReason: 'No gateway',
		})
	})

	it('renders one column, named, when the platform has no channel axis', () => {
		// dossiq's preferences are one switch per notification today.
		// Fabricating a mail and a push column would tell a handler they can
		// choose between them when the platform stores no such choice, and
		// their click would be collapsed onto the single value that exists.
		const columns = channelsFrom({ singleChannelLabel: 'Notifications' })

		expect(columns.length).toBe(1)
		expect(columns[0]).toEqual({
			id: SINGLE_CHANNEL_ID,
			label: 'Notifications',
			configured: true,
			unconfiguredReason: '',
		})
	})
})

describe('the rows', () => {
	it('groups them by the schema they belong to', () => {
		const events = eventsFrom({
			entries: ENTRIES,
			label: (entry) => entry.notification,
		})

		expect(events[0]).toEqual({
			id: 'case-caseAssigned',
			label: 'caseAssigned',
			group: 'case',
			groupLabel: 'Cases',
			appDefault: false,
			immediate: false,
		})
	})

	it('takes the shipped default from the effective value when nothing overrode it', () => {
		const events = eventsFrom({ entries: ENTRIES })

		expect(
			events.find((event) => event.id === 'workDigest-workDigestReady')
				.appDefault,
		).toBe(true)
	})

	it('does not guess a shipped default that was overridden', () => {
		// The platform returns the EFFECTIVE value and the source. When
		// somebody overrode it, the default underneath is not in the answer,
		// and writing the effective value there would put a made-up value on
		// a layer the screen displays.
		const events = eventsFrom({ entries: ENTRIES })

		expect(
			events.find((event) => event.id === 'case-caseAssigned').appDefault,
		).toBe(false)
	})

	it('takes the shipped default from the platform when it says so, which is the control', () => {
		// Without this, "never guess" could be implemented as "always false"
		// and nobody would notice.
		const events = eventsFrom({
			entries: [
				{
					schema: 'case',
					notification: 'x',
					enabled: false,
					source: 'user-override',
					appDefault: true,
				},
			],
		})

		expect(events[0].appDefault).toBe(true)
	})
})

describe('the layers', () => {
	it("puts an overridden value on the person's own layer", () => {
		const { personalValues } = valuesFrom({ entries: ENTRIES })

		expect(personalValues['case-caseAssigned']).toEqual({
			[SINGLE_CHANNEL_ID]: true,
		})
	})

	it("puts a team default on the group layer, and not on the person's", () => {
		const { groupValues, personalValues } = valuesFrom({ entries: ENTRIES })

		expect(groupValues['case-caseHandoffIntake']).toEqual({
			[SINGLE_CHANNEL_ID]: false,
		})
		expect(personalValues['case-caseHandoffIntake']).toBeUndefined()
	})

	it('leaves both layers empty for a value nobody changed', () => {
		// It has to fall through to the app default. A false on either layer
		// would render as somebody's choice and lock the row's explanation to
		// the wrong sentence.
		const { groupValues, personalValues } = valuesFrom({ entries: ENTRIES })

		expect(groupValues['workDigest-workDigestReady']).toBeUndefined()
		expect(personalValues['workDigest-workDigestReady']).toBeUndefined()
	})

	it('carries a forced row with who forced it and why', () => {
		const { forcedValues } = valuesFrom({
			entries: [
				{
					schema: 'case',
					notification: 'caseAssigned',
					enabled: true,
					source: 'user-override',
					forced: {
						value: true,
						by: 'Team leads',
						reason: 'Assignments must reach the handler',
					},
				},
			],
		})

		expect(forcedValues['case-caseAssigned'][SINGLE_CHANNEL_ID]).toEqual({
			value: true,
			by: 'Team leads',
			reason: 'Assignments must reach the handler',
		})
	})

	it('carries a channel forced OFF as off', () => {
		// The detail that would cause a real incident: reading a forced row as
		// "always on" switches on a channel an administrator forbade.
		const { forcedValues } = valuesFrom({
			entries: [
				{
					schema: 'case',
					notification: 'caseAssigned',
					enabled: true,
					source: 'user-override',
					forced: {
						value: false,
						by: 'Security',
						reason: 'This kind never leaves the organisation',
					},
				},
			],
		})

		expect(forcedValues['case-caseAssigned'][SINGLE_CHANNEL_ID].value).toBe(
			false,
		)
	})

	it('carries a refusal with its reason, separately from an absence', () => {
		// A refusal is a rule working. Rendering it as "not available" would
		// read as a configuration gap somebody should go and fix.
		const { refusals } = valuesFrom({
			entries: [
				{
					schema: 'case',
					notification: 'caseAssigned',
					refused: { reason: 'This kind never leaves the organisation' },
				},
			],
		})

		expect(refusals['case-caseAssigned'][SINGLE_CHANNEL_ID]).toEqual({
			reason: 'This kind never leaves the organisation',
		})
	})

	it('forces and refuses nothing when the platform said nothing, which is the control', () => {
		const { forcedValues, refusals } = valuesFrom({ entries: ENTRIES })

		expect(forcedValues).toEqual({})
		expect(refusals).toEqual({})
	})
})

describe('everything from one read', () => {
	it('keys the values in the column it actually rendered', () => {
		// A mismatch here renders every cell as unset while the values sit in
		// the object under a key no column has.
		const props = propsFor({
			entries: ENTRIES,
			channels: [{ id: 'mail', label: 'Mail' }],
		})

		expect(props.channels[0].id).toBe('mail')
		expect(props.personalValues['case-caseAssigned']).toEqual({ mail: true })
	})
})

describe('the write behind a change', () => {
	it('splits the id back into the schema and the key', () => {
		expect(writeFor({ eventId: 'case-caseAssigned', value: true })).toEqual({
			schema: 'case',
			notification: 'caseAssigned',
			enabled: true,
		})
	})

	it('keeps a key that contains a dash intact', () => {
		// Splitting on the LAST dash would move half the key into the schema
		// and write to a notification nobody has.
		expect(
			writeFor({ eventId: 'case-case-handoff-intake', value: false }),
		).toEqual({
			schema: 'case',
			notification: 'case-handoff-intake',
			enabled: false,
		})
	})

	it('pins the write to the domain being shown', () => {
		expect(
			writeFor({ eventId: 'case-caseAssigned', scope: 'zaken', value: true })
				.scope,
		).toBe('domain:zaken')
	})

	it('writes nothing global as scoped, which is the control', () => {
		expect(
			writeFor({ eventId: 'case-caseAssigned', value: true }).scope,
		).toBeUndefined()
	})

	it('refuses an id it did not make', () => {
		// A write to the wrong notification is silent and permanent.
		expect(writeFor({ eventId: 'nodash', value: true })).toBe(null)
		expect(writeFor({ eventId: '-leading', value: true })).toBe(null)
	})
})
