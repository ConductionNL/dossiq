// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * "What may I do right now", in two halves (REQ-TASK-041).
 *
 * The thing worth pinning is not that both halves appear. It is that the
 * always-available half is MARKED, so a surface can render them together, and
 * that it is kept out of the phase strip and the progress figure BY
 * CONSTRUCTION rather than by every caller remembering to filter. A flag that
 * every reader has to honour is a flag one reader eventually forgets, and the
 * failure is silent: a phase strip with a stage the case never leaves, and a
 * progress bar that never reaches the end.
 *
 * @spec openspec/changes/task-as-a-first-class-record/specs/process-step-configuration/spec.md
 */
import { describe, expect, it } from 'vitest'
import { actsFor, phaseActsOnly } from '../../src/utils/caseActs.js'

/** Two acts of the current phase, as the lifecycle provider answers them. */
const PHASE = [
	{ action: 'to-hoorzitting', label: 'Plan de hoorzitting', description: 'Nodig de belanghebbende uit' },
	{ action: 'to-beslissing', label: 'Neem een beslissing' },
]

/** Three acts the case type allows in every phase. */
const ALWAYS = [
	{ id: 'withdraw', label: 'Trek in', available: true },
	{ id: 'add-document', label: 'Voeg een document toe', available: true },
	{ id: 'escalate', label: 'Escaleer', available: false, reason: 'Onvoldoende rechten' },
]

describe('always-available acts', () => {
	it('offers both halves as one list, and marks the always-available ones', () => {
		const acts = actsFor(PHASE, ALWAYS)

		expect(acts).toHaveLength(5)
		// The phase's acts come first: what this phase is asking of me, then
		// what I can always do.
		expect(acts.slice(0, 2).map((act) => act.id)).toEqual([
			'to-hoorzitting',
			'to-beslissing',
		])
		expect(acts.filter((act) => act.alwaysAvailable)).toHaveLength(3)
		expect(acts.filter((act) => !act.alwaysAvailable)).toHaveLength(2)
	})

	it('keeps the always-available acts out of the phase strip and the progress figure', () => {
		const strip = phaseActsOnly(actsFor(PHASE, ALWAYS))

		expect(strip.map((act) => act.id)).toEqual(['to-hoorzitting', 'to-beslissing'])
		// The one that matters: not "three of five are marked" but "the strip
		// contains none of them". An always-available act in the strip is a
		// stage the case never leaves.
		expect(strip.some((act) => act.alwaysAvailable)).toBe(false)
	})

	it('shows an act the guard refuses, disabled, with the guard sentence', () => {
		const escalate = actsFor([], ALWAYS).find((act) => act.id === 'escalate')

		// Shown, not hidden: an act that vanishes tells the reader the system
		// cannot do it, where one shown with its reason tells them who to ask.
		expect(escalate).toBeDefined()
		expect(escalate.available).toBe(false)
		expect(escalate.reason).toBe('Onvoldoende rechten')
	})

	it('shows a blocked phase act the same way', () => {
		const blocked = actsFor(
			[{ action: 'to-archief', label: 'Archiveer', blocked: true, reason: 'Er is nog geen besluit' }],
			[],
		)

		expect(blocked[0].available).toBe(false)
		expect(blocked[0].reason).toBe('Er is nog geen besluit')
	})

	it('drops an act that names nothing, because a button with no id fails when pressed', () => {
		expect(actsFor([{ label: 'Naamloos' }], [{ label: 'Ook naamloos' }])).toEqual([])
	})

	it('answers an empty list rather than throwing when a half is missing', () => {
		expect(actsFor()).toEqual([])
		expect(actsFor(null, undefined)).toEqual([])
		expect(phaseActsOnly()).toEqual([])
	})

	it('falls back to the description when an act carries no label', () => {
		const acts = actsFor([{ action: 'to-x', description: 'Ga verder' }], [])

		expect(acts[0].label).toBe('Ga verder')
	})
})
