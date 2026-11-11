/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation tests for `formatDuration` (src/utils/durationHelpers.js):
 * every unit in its one and many form, combinations, and the values it
 * hands back unchanged.
 */

import { describe, expect, it } from 'vitest'
import { formatDuration } from '../../src/utils/durationHelpers.js'

describe('formatDuration golden', () => {
	it.each([
		['P1Y', '1 year'],
		['P3Y', '3 years'],
		['P1M', '1 month'],
		['P2M', '2 months'],
		['P1W', '1 week'],
		['P6W', '6 weeks'],
		['P1D', '1 day'],
		['P42D', '42 days'],
		['P1Y2M3W4D', '1 year, 2 months, 3 weeks, 4 days'],
		['P2Y1D', '2 years, 1 day'],
	])('reads %s as %s', (iso, words) => {
		expect(formatDuration(iso)).toBe(words)
	})

	it.each([
		['P0D', 'P0D'],
		['not a duration', 'not a duration'],
		['', ''],
		[null, ''],
		[undefined, ''],
	])('hands %j back as %j', (iso, out) => {
		expect(formatDuration(iso)).toBe(out)
	})
})
