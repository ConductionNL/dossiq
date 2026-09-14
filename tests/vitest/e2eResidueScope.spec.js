// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The e2e residue sweep removes only what no running suite can still own.
 *
 * `tests/e2e/global-setup.ts` used to delete every `E2EZAAK-` object on the
 * instance. On the shared developer instance that is every session's fixtures,
 * and one run's setup deleted another session's case type while its spec was
 * using it. The rule that replaced it lives in `tests/e2e/helpers/residue.ts`
 * and is pinned here, because the only other way to see it fail is to lose
 * somebody's data.
 */
import { describe, expect, it } from 'vitest'
import {
	DEFAULT_RESIDUE_MIN_AGE_MINUTES,
	isStaleResidue,
	residueMinAgeMs,
	rowTouchedAt,
	sweepsAllResidue,
} from '../e2e/helpers/residue.ts'

const NOW = Date.parse('2026-09-11T12:00:00Z')
const HOUR = 60 * 60_000

/**
 * A row as OpenRegister returns it, last touched `ageMs` ago.
 *
 * @param {number} ageMs How long ago it was touched.
 * @return {object} The row.
 */
function rowAged(ageMs) {
	const stamp = new Date(NOW - ageMs).toISOString()
	return { id: 'x', '@self': { created: stamp, updated: stamp } }
}

describe('the residue age rule', () => {
	it('leaves a fixture another run seeded a minute ago alone', () => {
		expect(isStaleResidue(rowAged(60_000), 2 * HOUR, NOW)).toBe(false)
	})

	it('removes a crashed run’s leftover once it is older than the bound', () => {
		expect(isStaleResidue(rowAged(3 * HOUR), 2 * HOUR, NOW)).toBe(true)
	})

	it('dates a row by its LAST write, so an old row a live run just wrote survives', () => {
		const row = {
			'@self': {
				created: new Date(NOW - 5 * HOUR).toISOString(),
				updated: new Date(NOW - 60_000).toISOString(),
			},
		}
		expect(isStaleResidue(row, 2 * HOUR, NOW)).toBe(false)
	})

	it('treats a row it cannot date as live, not as old', () => {
		// A trashed row: OpenRegister answers `created` and `updated` as null.
		const trashed = { id: 'x', '@self': { created: null, updated: null } }
		expect(rowTouchedAt(trashed)).toBeNull()
		expect(isStaleResidue(trashed, 2 * HOUR, NOW)).toBe(false)
	})

	it('removes everything at a bound of zero, which a run’s own teardown uses', () => {
		expect(isStaleResidue(rowAged(0), 0, NOW)).toBe(true)
		expect(isStaleResidue({}, 0, NOW)).toBe(true)
	})
})

describe('the residue bound', () => {
	it('defaults to two hours, past the suite’s 38 minute globalTimeout', () => {
		expect(DEFAULT_RESIDUE_MIN_AGE_MINUTES).toBe(120)
		expect(residueMinAgeMs({})).toBe(120 * 60_000)
	})

	it('can be set in minutes', () => {
		expect(residueMinAgeMs({ DOSSIQ_E2E_RESIDUE_MIN_AGE_MINUTES: '30' })).toBe(
			30 * 60_000,
		)
	})

	it('falls back to the default on a value it cannot read, never to zero', () => {
		for (const value of ['', 'soon', '-5']) {
			expect(
				residueMinAgeMs({ DOSSIQ_E2E_RESIDUE_MIN_AGE_MINUTES: value }),
			).toBe(120 * 60_000)
		}
	})

	it('drops the bound only when the instance is declared the caller’s own', () => {
		expect(sweepsAllResidue({})).toBe(false)
		expect(sweepsAllResidue({ DOSSIQ_E2E_SWEEP_ALL_RESIDUE: '1' })).toBe(true)
		expect(residueMinAgeMs({ DOSSIQ_E2E_SWEEP_ALL_RESIDUE: 'true' })).toBe(0)
	})
})
