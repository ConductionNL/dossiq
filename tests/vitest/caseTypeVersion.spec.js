/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Which version of a case type a new case is filed on.
 *
 * A superseded version is published, valid today, and still the wrong answer:
 * its cases run on, new ones belong on its successor. The two versions carry
 * the same title, so a picker offering both gives the person choosing nothing
 * to tell them apart, and nothing anywhere would report the mistake. That is
 * why the rule lives in one function that every offering surface asks, and why
 * these assertions pin the superseded case rather than only the draft one.
 *
 * @spec openspec/specs/zaaktype-versioning/spec.md
 */
import { beforeAll, describe, expect, it } from 'vitest'
import {
	getCaseTypeUnusableReason,
	isCaseTypeUsable,
	isCurrentCaseTypeVersion,
} from '../../src/utils/caseValidation.js'

beforeAll(() => {
	// The module calls the global `t` Nextcloud provides at runtime.
	globalThis.t = (app, text) => text
})

/** A published case type nothing has replaced. */
const current = { id: 'ct-2', title: 'Vergunning', isDraft: false, version: 2 }

describe('isCurrentCaseTypeVersion', () => {
	it('says yes while nothing has replaced it', () => {
		expect(isCurrentCaseTypeVersion(current)).toBe(true)
	})

	it('says yes for a row saved before the property existed', () => {
		expect(isCurrentCaseTypeVersion({ id: 'ct-1' })).toBe(true)
	})

	it.each([
		['an empty string', ''],
		['null', null],
		['undefined', undefined],
	])('treats %s as nothing having replaced it', (_label, value) => {
		expect(isCurrentCaseTypeVersion({ ...current, supersededBy: value })).toBe(
			true,
		)
	})

	it('says no once a successor is named', () => {
		expect(isCurrentCaseTypeVersion({ ...current, supersededBy: 'ct-3' })).toBe(
			false,
		)
	})

	it('reads a successor stored as an expanded object', () => {
		// OpenRegister answers a $ref either as a bare id or as the expanded
		// object, depending on the read. Reading only the string form would
		// call an expanded reference "no successor" and keep offering a version
		// that has been replaced.
		expect(
			isCurrentCaseTypeVersion({ ...current, supersededBy: { id: 'ct-3' } }),
		).toBe(false)
		expect(
			isCurrentCaseTypeVersion({ ...current, supersededBy: { uuid: 'ct-3' } }),
		).toBe(false)
	})

	it('says no for nothing at all', () => {
		expect(isCurrentCaseTypeVersion(null)).toBe(false)
	})
})

describe('isCaseTypeUsable', () => {
	it('accepts the current published version', () => {
		expect(isCaseTypeUsable(current)).toBe(true)
	})

	it('refuses a version that has been replaced, even though it is published', () => {
		expect(isCaseTypeUsable({ ...current, supersededBy: 'ct-3' })).toBe(false)
	})

	it('still refuses a draft', () => {
		expect(isCaseTypeUsable({ ...current, isDraft: true })).toBe(false)
	})

	it('still honours the validity window', () => {
		expect(isCaseTypeUsable({ ...current, validUntil: '2020-01-01' })).toBe(
			false,
		)
	})
})

describe('getCaseTypeUnusableReason', () => {
	it('explains a replaced version as a version, not as a draft or an expiry', () => {
		const reason = getCaseTypeUnusableReason({
			...current,
			supersededBy: 'ct-3',
		})
		expect(reason).toMatch(/replaced/)
		expect(reason).not.toMatch(/draft/)
	})

	it('says nothing is wrong with the current version', () => {
		expect(getCaseTypeUnusableReason(current)).toBeNull()
	})
})
