/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/case-routing-note/specs/kcc-routing/spec.md#requirement-the-team-queue-says-why-a-case-stands-there
 */
import fs from 'fs'
import path from 'path'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, params) => String(text).replace(/\{(\w+)\}/g, (_, key) => String((params || {})[key] ?? '')),
}))

import { routingNoteLabel } from '../../src/services/routingNote.js'

const manifest = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../src/manifest.json'), 'utf8'))

describe('a case routing note in words', () => {
	it('words a refused handover', () => {
		expect(routingNoteLabel({ kind: 'handover-refused', team: 'Belastingen', reason: 'niet voor ons' })).toBe('Assigning to team Belastingen refused: niet voor ons')
	})

	it('words a split with a failed destination', () => {
		expect(routingNoteLabel({ kind: 'intake-split', submission: 'web-1', siblings: ['2026-4873'], failed: [{ destination: 'Belastingen', reason: 'onbekend' }] }))
			.toBe('From submission web-1, together with 2026-4873. Destination Belastingen failed: onbekend.')
	})

	it('reads nothing without a note or for an unknown kind', () => {
		expect(routingNoteLabel(null)).toBe('')
		expect(routingNoteLabel({ kind: 'other' })).toBe('')
	})

	it('is drawn on the team queue row', () => {
		const queue = JSON.stringify(manifest)
		expect(queue).toContain('"noteTemplate":"{routingNote|routingNote}"')
		expect(queue).toContain('"noteToneField":"routingNote.level"')
	})
})
