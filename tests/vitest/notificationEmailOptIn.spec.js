/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * E-mail is an opt-in channel on dossiq's notifications (Ruben, 25 October
 * 2026): off until a person switches it on. The personal settings draw one
 * column per channel the platform declares, as board DqPersoonlijkeInstellingen
 * does ("In Nextcloud", "Per e-mail").
 *
 * @spec openspec/specs/case-management/spec.md#requirement-a-person-can-switch-e-mail-on-per-notification
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import {
	channelAxisFrom,
	channelsOnFor,
	propsFor,
	writeFor,
} from '../../src/services/notificationPreferenceProps.js'

const ROOT = path.resolve(__dirname, '../..')

const ENTRY = {
	schema: 'case',
	notification: 'caseAssigned',
	enabled: true,
	channels: ['nc-notification'],
	availableChannels: ['nc-notification', 'email'],
	optInChannels: ['email'],
	source: 'schema-default',
}

describe('the e-mail opt-in channel', () => {
	it('draws one column per declared channel, named as the board does', () => {
		const props = propsFor({ entries: [ENTRY], channelLabel: (id) => ({ 'nc-notification': 'In Nextcloud', email: 'Per e-mail' })[id] })
		expect(props.channels.map((c) => [c.id, c.label])).toEqual([['nc-notification', 'In Nextcloud'], ['email', 'Per e-mail']])
	})

	it('has e-mail off by default and Nextcloud on', () => {
		const props = propsFor({ entries: [ENTRY] })
		expect(props.events[0].appDefault).toEqual({ 'nc-notification': true, email: false })
		expect(props.personalValues).toEqual({})
	})

	it('shows a person who switched e-mail on in both columns', () => {
		const mine = { ...ENTRY, channels: ['nc-notification', 'email'], source: 'user-override' }
		expect(propsFor({ entries: [mine] }).personalValues['case-caseAssigned']).toEqual({ 'nc-notification': true, email: true })
	})

	it('writes the whole channel set when one cell changes', () => {
		const on = writeFor({ eventId: 'case-caseAssigned', value: true, channelId: 'email', current: channelsOnFor(ENTRY) })
		expect(on).toEqual({ schema: 'case', notification: 'caseAssigned', enabled: true, channels: ['nc-notification', 'email'] })
		const off = writeFor({ eventId: 'case-caseAssigned', value: false, channelId: 'nc-notification', current: ['nc-notification'] })
		expect(off).toEqual({ schema: 'case', notification: 'caseAssigned', enabled: false, channels: [] })
	})

	it('keeps one column when the platform names no channels, which is the control', () => {
		const bare = { schema: 'case', notification: 'caseAssigned', enabled: true, source: 'schema-default' }
		expect(channelAxisFrom([bare])).toEqual([])
		expect(propsFor({ entries: [bare], singleChannelLabel: 'Notifications' }).channels).toHaveLength(1)
	})

	it('declares e-mail as opt-in on every per-person notification', () => {
		for (const file of ['dossiq_register.json', 'dossiq_mock_register.json']) {
			const text = fs.readFileSync(path.join(ROOT, 'lib', 'Settings', file), 'utf8')
			const specs = []
			JSON.parse(text, (key, value) => {
				if (key === 'x-openregister-notifications' && value && typeof value === 'object') {
					specs.push(...Object.values(value).filter((spec) => Array.isArray(spec?.channels)))
				}
				return value
			})
			expect(specs.length, file).toBeGreaterThan(0)
			for (const spec of specs) {
				expect(spec.optInChannels, file).toEqual(['email'])
				expect(spec.channels, file).not.toContain('email')
			}
		}
	})
})
