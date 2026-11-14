/*
 * SPDX-FileCopyrightText: 2026 Dossiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Which fixture residue a run may remove, and which belongs to somebody else.
 *
 * 🔴 THE SWEEP USED TO DELETE EVERY `E2EZAAK-` OBJECT ON THE INSTANCE. That is
 * right on a throwaway CI instance and wrong on a shared developer one, where
 * the family prefix is shared by every session: one run's global setup deleted
 * another session's case types, statuses and workflow templates WHILE that
 * session's spec was using them, and the victim read it as a broken page (a
 * case type with no statuses, a Statuses tab that said "No status types
 * defined"). Measured on the shared dev instance 2026-09-11: seven objects of
 * a live run removed between its own beforeAll and its first assertion.
 *
 * So the residue sweep now removes only what it can PROVE is stale: a row
 * whose own timestamps say it was last touched longer ago than any suite could
 * still be running. A row it cannot date is left alone, because "I could not
 * read its age" is not "it is old".
 *
 * The age is the rule rather than an opt-in flag on its own for one reason:
 * leftovers from a crashed run still have to be collectable without anybody
 * remembering to pass anything, and a crashed run's rows become collectable by
 * the clock. The opt-in is kept as well, for an instance you own
 * (`DOSSIQ_E2E_SWEEP_ALL_RESIDUE=1`), because on a rig nobody shares the
 * cheapest correct answer is still "remove all of it".
 *
 * These functions are pure so they can be unit-tested without an instance; the
 * network walk that uses them lives in `fixtures.ts`.
 */

/** How old residue must be before a sweep may remove it, in minutes. */
export const DEFAULT_RESIDUE_MIN_AGE_MINUTES = 120

/**
 * Whether this run is allowed to remove residue it cannot date.
 *
 * @param env The environment to read (defaults to `process.env`).
 * @return True when the caller has declared the instance its own.
 */
export function sweepsAllResidue(env: NodeJS.ProcessEnv = process.env): boolean {
	const flag = String(env.DOSSIQ_E2E_SWEEP_ALL_RESIDUE ?? '')
		.trim()
		.toLowerCase()

	return flag === '1' || flag === 'true' || flag === 'yes'
}

/**
 * How old a row must be before the residue sweep may remove it.
 *
 * Zero means "remove everything", which is what the opt-in flag and a run's
 * own teardown both want. Anything unparsable falls back to the default rather
 * than to zero: a typo must not turn the guard off.
 *
 * @param env The environment to read (defaults to `process.env`).
 * @return The minimum age in milliseconds.
 */
export function residueMinAgeMs(env: NodeJS.ProcessEnv = process.env): number {
	if (sweepsAllResidue(env)) return 0

	const raw = String(env.DOSSIQ_E2E_RESIDUE_MIN_AGE_MINUTES ?? '').trim()
	const minutes = raw === '' ? NaN : Number(raw)
	if (Number.isFinite(minutes) && minutes >= 0) {
		return minutes * 60_000
	}

	return DEFAULT_RESIDUE_MIN_AGE_MINUTES * 60_000
}

/**
 * The moment a row was last touched, as milliseconds since the epoch.
 *
 * `updated` first: a row created hours ago and written a minute ago belongs to
 * a run that is still going. OpenRegister answers both on `@self`, and leaves
 * them null on a trashed row, which is why null is a real answer here.
 *
 * @param row An OpenRegister object as the API returns it.
 * @return The timestamp, or null when the row carries none that parses.
 */
export function rowTouchedAt(row: unknown): number | null {
	const self = ((row ?? {}) as Record<string, any>)['@self'] ?? {}
	const candidates = [
		self.updated,
		self.created,
		(row as Record<string, any>)?.updated,
		(row as Record<string, any>)?.created,
	]

	for (const candidate of candidates) {
		if (typeof candidate !== 'string' || candidate.trim() === '') continue
		const parsed = Date.parse(candidate)
		if (Number.isNaN(parsed) === false) return parsed
	}

	return null
}

/**
 * Whether a sweep bounded by `minAgeMs` may remove this row.
 *
 * @param row      The row.
 * @param minAgeMs How old it must be. Zero removes everything.
 * @param now      The current time in ms (injectable for the unit tests).
 * @return True when the row is old enough to be nobody's live fixture.
 */
export function isStaleResidue(
	row: unknown,
	minAgeMs: number,
	now: number = Date.now(),
): boolean {
	if (minAgeMs <= 0) return true

	const touched = rowTouchedAt(row)
	// Undatable is NOT old: a row the API gives no timestamp for could belong
	// to a run that started a second ago.
	if (touched === null) return false

	return now - touched >= minAgeMs
}
