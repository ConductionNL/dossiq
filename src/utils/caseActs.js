// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * "What may I do right now", in two halves.
 *
 * A case's acts come from two places and mean two different things. The
 * phase's acts are the moves the case can make from where it is, answered by
 * OpenRegister's `available-actions` through dossiq's lifecycle provider. The
 * always-available acts are declared on the case type and are allowed whatever
 * phase the case is in: withdraw it, add a document, ask a colleague.
 *
 * 🔴 THEY ARE RENDERED TOGETHER AND MARKED APART. One list is what a handler
 * asks for; two separate menus are two places to look and one of them gets
 * forgotten. But an always-available act is NOT a phase act with a flag: it
 * never enters the phase strip, the progress figure or a term, and the mark is
 * what keeps a surface from treating it as one.
 *
 * The functions here are pure so the node-environment vitest suite can
 * exercise them without a DOM, the same split `caseTaskPaneHelpers.js` uses.
 *
 * @spec openspec/changes/task-as-a-first-class-record/specs/process-step-configuration/spec.md
 */

/**
 * One act of the current phase, in the shape the acts list renders.
 *
 * The lifecycle provider answers `{action, to, label, description, blocked}`.
 * A blocked act is kept and disabled rather than dropped: an act that vanishes
 * tells the reader the system cannot do it, where one shown with its reason
 * tells them what has to happen first.
 *
 * @param {object} action The provider's action.
 * @return {object} The act.
 * @spec openspec/changes/task-as-a-first-class-record/specs/process-step-configuration/spec.md
 */
export function phaseAct(action) {
	const id = String(action?.action ?? '').trim()
	return {
		id,
		label: String(action?.label ?? action?.description ?? id).trim(),
		description: String(action?.description ?? '').trim(),
		alwaysAvailable: false,
		available: action?.blocked !== true,
		reason: String(action?.reason ?? '').trim(),
	}
}

/**
 * One always-available act, as dossiq's own endpoint answers it.
 *
 * @param {object} act The declared act with its verdict.
 * @return {object} The act.
 * @spec openspec/changes/task-as-a-first-class-record/specs/process-step-configuration/spec.md
 */
export function declaredAct(act) {
	const id = String(act?.id ?? '').trim()
	return {
		id,
		label: String(act?.label ?? id).trim(),
		description: String(act?.description ?? '').trim(),
		alwaysAvailable: true,
		available: act?.available !== false,
		reason: String(act?.reason ?? '').trim(),
	}
}

/**
 * Both halves in one list, the phase's first and the always-available after.
 *
 * The order is the reading order a handler expects: what this phase is asking
 * of me, then what I can always do. An act with no id is dropped, because a
 * button that names nothing fails when it is pressed.
 *
 * @param {object[]} phase The provider's actions for the current phase.
 * @param {object[]} always The case type's declared always-available acts.
 * @return {object[]} The acts, marked.
 * @spec openspec/changes/task-as-a-first-class-record/specs/process-step-configuration/spec.md
 */
export function actsFor(phase = [], always = []) {
	const rows = [
		...(Array.isArray(phase) ? phase : []).map(phaseAct),
		...(Array.isArray(always) ? always : []).map(declaredAct),
	]
	return rows.filter((act) => act.id !== '')
}

/**
 * The acts that belong in the phase strip and the progress figure.
 *
 * Exactly the phase's own, and this function exists so that stays true by
 * construction rather than by every caller remembering. An always-available
 * act in the strip would show a stage the case never leaves; in the progress
 * figure it would keep the bar from ever reaching the end.
 *
 * @param {object[]} acts The whole list.
 * @return {object[]} The phase's acts.
 * @spec openspec/changes/task-as-a-first-class-record/specs/process-step-configuration/spec.md
 */
export function phaseActsOnly(acts = []) {
	return (Array.isArray(acts) ? acts : []).filter((act) => act?.alwaysAvailable !== true)
}
