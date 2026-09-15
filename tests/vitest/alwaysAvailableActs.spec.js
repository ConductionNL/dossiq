// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * "What may I do right now", in two halves (REQ-TASK-041).
 *
 * The case type declares the acts allowed in EVERY phase, beside the acts of
 * the current one. `lifecycle-acts-on-the-case` landed the one lifecycle menu
 * while this change was in flight, and it already reads
 * `/api/case/{id}/acts`, so the always-available half is answered there and
 * folded into that menu rather than shipping a second list answering the same
 * question. These tests sit on the menu builder for that reason.
 *
 * 🔴 WHAT IS WORTH PINNING IS NOT THAT BOTH HALVES APPEAR. It is that an
 * always-available act carries a KIND of its own, so a surface can mark it,
 * and that nothing downstream treats it as a transition. Modelled as a phase
 * it would enter the phase strip, the progress figure and the term
 * calculation, and all three would be wrong: a stage the case never leaves, a
 * bar that never fills, and a clock running on something that is not work.
 *
 * @spec openspec/changes/task-as-a-first-class-record/specs/process-step-configuration/spec.md
 */
import { describe, expect, it } from 'vitest'
import { buildActsMenu } from '../../src/utils/caseActsMenu.js'

/** Two moves of the current phase, as `/available-transitions` answers them. */
const TRANSITIONS = [
	{ id: 't1', label: 'Plan de hoorzitting', allowed: true },
	{ id: 't2', label: 'Neem een beslissing', allowed: true },
]

/** Three acts the case type allows in every phase. */
const ALWAYS = [
	{ id: 'withdraw', label: 'Trek de zaak in', available: true },
	{ id: 'add-document', label: 'Voeg een document toe', available: true },
	{ id: 'escalate', label: 'Escaleer', available: false, reason: 'Onvoldoende rechten' },
]

describe('always-available acts in the one menu', () => {
	it('offers both halves in one list, the phase first', () => {
		const menu = buildActsMenu({
			transitions: TRANSITIONS,
			state: null,
			acts: { alwaysAvailable: ALWAYS },
		})

		const ids = menu.map((entry) => entry.id)
		expect(ids.slice(0, 2)).toEqual(['t1', 't2'])
		expect(ids).toContain('withdraw')
		expect(ids).toContain('add-document')
		expect(ids).toContain('escalate')
	})

	it('marks the always-available half, and only that half', () => {
		const menu = buildActsMenu({
			transitions: TRANSITIONS,
			state: null,
			acts: { alwaysAvailable: ALWAYS },
		})

		expect(menu.filter((entry) => entry.kind === 'always')).toHaveLength(3)
		// The phase's own moves keep their own kind: marking both would make
		// the mark say nothing.
		expect(menu.find((entry) => entry.id === 't1').kind).not.toBe('always')
	})

	it('shows an act the guard refuses, disabled, with the guard sentence', () => {
		const menu = buildActsMenu({ transitions: [], state: null, acts: { alwaysAvailable: ALWAYS } })
		const escalate = menu.find((entry) => entry.id === 'escalate')

		// Shown, not hidden: an act that vanishes tells the reader the system
		// cannot do it, where one shown with its reason tells them who to ask.
		expect(escalate).toBeDefined()
		expect(escalate.disabled).toBe(true)
		expect(escalate.reason).toBe('Onvoldoende rechten')
	})

	it('adds no entry when the case type declares none', () => {
		const menu = buildActsMenu({ transitions: TRANSITIONS, state: null, acts: {} })

		expect(menu.filter((entry) => entry.kind === 'always')).toEqual([])
	})

	it('drops an act that names nothing, because a button with no id fails when pressed', () => {
		const menu = buildActsMenu({
			transitions: [],
			state: null,
			acts: { alwaysAvailable: [{ label: 'Naamloos' }] },
		})

		expect(menu.filter((entry) => entry.kind === 'always')).toEqual([])
	})

	it('adds no always-available entry when the acts read failed entirely', () => {
		// The other halves of the menu still stand: one endpoint that is down
		// must not empty the menu, because an empty menu is a legitimate
		// answer for a case in a terminal status and the two would be
		// indistinguishable.
		const menu = buildActsMenu({ transitions: [], state: null, acts: null })

		expect(menu.filter((entry) => entry.kind === 'always')).toEqual([])
	})
})
