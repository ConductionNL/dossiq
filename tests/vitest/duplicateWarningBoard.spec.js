/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The duplicate warning at intake as DqNieuweZaakDubbel draws it: a case type
 * that blocks a second case asks a coordinator for a reason (the policy was
 * read twice through the normaliser and every block became warn), each match
 * names its number, case type, requester and receipt date, and the warning
 * carries the board's eyebrow, matched words, reason hint and footnote.
 *
 * @spec openspec/changes/duplicate-warning-at-intake/specs/friendly-case-create-form/spec.md
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import nl from '../../l10n/nl.json'

const get = vi.fn()
const opened = vi.fn(() => Promise.resolve(null))
vi.mock('@nextcloud/axios', () => ({ default: { get: (...a) => get(...a), post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (u) => u }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, key) => key, translatePlural: (app, one, more, count) => (count === 1 ? one : more).replace('%n', count) }))
vi.mock('@conduction/nextcloud-vue', () => ({ candidateWithoutBlanks: (x) => x, duplicateAffordances: () => ({ show: true }), openDuplicateWarning: (props) => opened(props), useObjectStore: () => ({}) }))

const { askAboutDuplicates, duplicatePolicyFor } = await import('../../src/services/createCaseWithDuplicateCheck.js')
const { fetchMatchedCases } = await import('../../src/services/duplicateCheckApi.js')

describe('the duplicate warning against DqNieuweZaakDubbel', () => {
	beforeEach(() => {
		get.mockReset()
		opened.mockClear()
	})

	it('keeps a block a block, so a coordinator is asked why', async () => {
		get.mockResolvedValue({ data: { caseType: 'ct-2', duplicatePolicy: { policy: 'block', mayOverride: true } } })
		await expect(duplicatePolicyFor('ct-2')).resolves.toEqual({ policy: 'block', mayOverride: true })
	})

	it('reads the matched cases with their case type inline', async () => {
		get.mockResolvedValue({ data: { id: 'c-1' } })
		await fetchMatchedCases(['c-1'])
		expect(get.mock.calls[0][1]).toEqual({ params: { _extend: 'caseType' } })
	})

	it('names number, case type, requester and receipt, with the board words', async () => {
		await askAboutDuplicates({ matches: [{ uuid: 'c-1' }], cases: [], policy: 'block', mayOverride: true })
		const props = opened.mock.calls[0][0]
		expect(props.rowMeta[0]).toBe('{identifier} · {caseType.title} · {initiatorDisplayName} · received {receivedAt|date}')
		expect(nl.translations[props.rowMeta[0]]).toBe('{identifier} · {caseType.title} · {initiatorDisplayName} · ontvangen {receivedAt|date}')
		expect(props.rowMeta.at(-1)).toBe('{identifier}')
		expect(nl.translations[props.matchedText]).toBe('Gevonden op {fields} ({percentage}%)')
		expect(nl.translations[props.eyebrow]).toBe('Nieuwe zaak · mogelijke dubbele')
		expect(nl.translations[props.reasonLabel]).toBe('Waarom deze zaak toch aanmaken')
		expect(nl.translations[props.reasonHint]).toBe('Het zaaktype vraagt een reden. De reden komt op de nieuwe zaak te staan.')
		expect(nl.translations[props.footnote]).toMatch(/^Staat het zaaktype geen tweede zaak toe/)
	})
})
