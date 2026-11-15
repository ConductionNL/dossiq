// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The urgency pill of an Assigned to me row reads the tier as a word, in
 * English and Dutch, as board DqAanMijToegewezen draws it.
 *
 * @spec openspec/specs/my-work/spec.md
 */
import fs from 'fs'
import path from 'path'
import { describe, expect, it, vi } from 'vitest'

vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({}) }))
vi.mock('../../src/store/modules/deelzaak.js', () => ({ useDeelzaakStore: () => ({}) }))

const { default: formatters } = await import('../../src/services/formatters.js')

const nl = JSON.parse(
	fs.readFileSync(path.resolve(__dirname, '../../l10n/nl.json'), 'utf8'),
).translations

describe('deadlineTierLabel', () => {
	it.each([
		['overdue', 'Late', 'Te laat'],
		['critical', 'Critical', 'Kritiek'],
		['warning', 'Soon', 'Bijna'],
		['normal', 'Normal', 'Normaal'],
	])('reads %s as %s (%s)', (tier, en, du) => {
		expect(formatters.deadlineTierLabel(tier)).toBe(en)
		expect(nl[en]).toBe(du)
	})

	it('reads an unknown tier as nothing', () => {
		expect(formatters.deadlineTierLabel('later')).toBe('')
		expect(formatters.deadlineTierLabel(undefined)).toBe('')
	})
})
