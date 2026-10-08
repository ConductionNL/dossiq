// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Reading a lens value.
//
// A lens is a property OpenRegister resolves while it renders a record: it
// holds the path to a field on a linked record, never a copy of that field's
// value. The shape that arrives is therefore not always the field. Where the
// reader may not open the linked record, OpenRegister answers
// `{ "@withheld": true, "reason": "access" }` instead.
//
// THE MARKER IS AN OBJECT, WHICH IS WHY IT NEEDS A READER. It is truthy, so
// `value ? show(value) : dash()` shows it; it stringifies to
// `[object Object]`, so a template that interpolates it prints that. Both
// failures are silent and both are wrong in the same direction: they turn
// "you may not see this" into something else. Every surface that renders a
// lens goes through here.
//
// @spec openspec/changes/case-objects-hinge-on-the-object/specs/case-management/spec.md

/**
 * Whether a lens value is the withheld marker rather than a value.
 *
 * @param {unknown} value The resolved lens value.
 * @return {boolean} True when the reader may not see the underlying value.
 *
 * @spec openspec/changes/case-objects-hinge-on-the-object/specs/case-management/spec.md
 */
export function isWithheld(value) {
	return (
		typeof value === 'object'
		&& value !== null
		&& !Array.isArray(value)
		&& value['@withheld'] === true
	)
}

/**
 * Why a lens value is withheld.
 *
 * @param {unknown} value The resolved lens value.
 * @return {string} The reason OpenRegister gave, or the empty string.
 *
 * @spec openspec/changes/case-objects-hinge-on-the-object/specs/case-management/spec.md
 */
export function withheldReason(value) {
	if (!isWithheld(value)) return ''
	const reason = value.reason
	return typeof reason === 'string' ? reason : ''
}

/**
 * The lens properties of a record, dropped for a write.
 *
 * A lens is stored nowhere, so OpenRegister refuses a write that names one
 * with a 400 that names the property. A client that reads a record and puts
 * it back must drop them first, which is what this does.
 *
 * @param {object} record The record as it was read.
 * @param {Array<string>} lensNames The lens property names of its schema.
 * @return {object} A copy of the record with the lens properties removed.
 *
 * @spec openspec/changes/case-objects-hinge-on-the-object/specs/case-management/spec.md
 */
export function withoutLenses(record, lensNames) {
	const out = { ...(record || {}) }
	for (const name of Array.isArray(lensNames) ? lensNames : []) {
		delete out[name]
	}
	return out
}

/**
 * The lens property names the `caseObject` schema declares.
 *
 * Kept beside the reader so a surface writing a caseObject back has one place
 * to ask. Mirrors `x-openregister-lenses` in `lib/Settings/dossiq_register.json`
 * and is asserted against it by `tests/vitest/caseObjectHinge.spec.js`, so the
 * two cannot drift apart unnoticed.
 *
 * @type {Array<string>}
 */
export const CASE_OBJECT_LENSES = ['objectTitle', 'objectStatus']
