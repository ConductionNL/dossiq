/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The line under a held conversation, as DqZaakContact "Live gesprek" draws
 * it: "4 okt, 10:30 tot 10:42 · Pieter Jansen en Sanne de Vries · 12 minuten".
 *
 * @spec openspec/specs/case-management/spec.md
 */

import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/l10n', () => ({
	getLanguage: () => 'nl',
	translate: (_app, s, vars = {}) => s.replace(/\{(\w+)\}/g, (_m, k) => String(vars[k] ?? '')),
	translatePlural: (_app, one, other, n) => (n === 1 ? one : other).replace('%n', String(n)),
}))

const { conversationEnded, conversationSummary, conversationWhen } =
	await import('../../src/utils/conversationRecordLine.js')

const now = new Date('2026-10-08T12:00:00')

describe('the line under a held conversation', () => {
	const record = {
		startedAt: '2026-10-04T10:30:00',
		endedAt: '2026-10-04T10:42:00',
		participants: ['Pieter Jansen', 'Sanne de Vries'],
		durationSeconds: 720,
	}

	it('writes the day, the start and the end time', () => {
		expect(conversationWhen(record, { now, locale: 'nl' })).toBe('4 okt, 10:30 to 10:42')
	})

	it('takes the end from the duration when the record has no end', () => {
		const { endedAt, ...noEnd } = record
		expect(conversationWhen(noEnd, { now, locale: 'nl' })).toBe('4 okt, 10:30 to 10:42')
	})

	it('writes only the start while the conversation runs', () => {
		expect(conversationWhen({ startedAt: record.startedAt }, { now, locale: 'nl' })).toBe('4 okt, 10:30')
	})

	it('adds the year outside the current one', () => {
		expect(conversationWhen({ startedAt: '2025-03-12T09:00:00' }, { now, locale: 'nl' })).toBe('12 mrt 2025, 09:00')
	})

	it('joins the moment, the people as a list and the minutes', () => {
		expect(conversationSummary(record, { now, locale: 'nl' }))
			.toBe('4 okt, 10:30 to 10:42 · Pieter Jansen en Sanne de Vries · 12 minutes')
	})

	it('leaves out what the record does not hold', () => {
		expect(conversationSummary({ participants: ['Pieter Jansen'] }, { now, locale: 'nl' })).toBe('Pieter Jansen')
		expect(conversationSummary({}, { now, locale: 'nl' })).toBe('')
	})

	it('says a conversation ended once it has an end or a duration', () => {
		expect(conversationEnded(record)).toBe(true)
		expect(conversationEnded({ durationSeconds: 60 })).toBe(true)
		expect(conversationEnded({ startedAt: record.startedAt })).toBe(false)
	})
})
