/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the pure deelzaak (sub-case) presentation helpers in
 * src/utils/deelzaakHelpers.js — the case-list sub-case count badge (T10)
 * and the parent-deletion orphan warning (T11). These pin the exact
 * user-facing copy and the no-badge / no-warning thresholds that the
 * formatter and the orphan-warning modal rely on.
 *
 * `@nextcloud/l10n` is aliased to the deterministic stub (English source
 * string + {placeholder} substitution), so the asserted output is the
 * interpolated English source.
 *
 * @spec openspec/changes/deelzaak-support/tasks.md#T10
 * @spec openspec/changes/deelzaak-support/tasks.md#T11
 */

import { describe, it, expect } from 'vitest'
import {
	subCaseCountBadge,
	hasSubCaseBadge,
	orphanWarningMessage,
	requiresOrphanWarning,
} from '../../src/utils/deelzaakHelpers.js'

describe('subCaseCountBadge', () => {
	it('renders "N deelzaken" for a positive count', () => {
		expect(subCaseCountBadge(2)).toBe('2 deelzaken')
		expect(subCaseCountBadge(1)).toBe('1 deelzaken')
		expect(subCaseCountBadge(25)).toBe('25 deelzaken')
	})

	it('renders no badge (empty string) for zero or negative counts', () => {
		expect(subCaseCountBadge(0)).toBe('')
		expect(subCaseCountBadge(-1)).toBe('')
	})

	it('renders no badge for non-numeric / undefined counts', () => {
		expect(subCaseCountBadge(undefined)).toBe('')
		expect(subCaseCountBadge(null)).toBe('')
		expect(subCaseCountBadge('not a number')).toBe('')
		expect(subCaseCountBadge(NaN)).toBe('')
	})

	it('coerces numeric strings', () => {
		expect(subCaseCountBadge('3')).toBe('3 deelzaken')
	})
})

describe('hasSubCaseBadge', () => {
	it('is true only when the count is a positive number', () => {
		expect(hasSubCaseBadge(1)).toBe(true)
		expect(hasSubCaseBadge(99)).toBe(true)
	})

	it('is false for zero, negative, or non-numeric counts', () => {
		expect(hasSubCaseBadge(0)).toBe(false)
		expect(hasSubCaseBadge(-2)).toBe(false)
		expect(hasSubCaseBadge(undefined)).toBe(false)
		expect(hasSubCaseBadge(NaN)).toBe(false)
	})
})

describe('orphanWarningMessage', () => {
	it('states the sub-case count and the unlink consequence', () => {
		const msg = orphanWarningMessage(2)
		expect(msg).toContain('2 sub-cases')
		expect(msg).toMatch(/unlink/i)
		expect(msg).toMatch(/continue\?$/)
	})

	it('clamps negative / non-numeric counts to zero', () => {
		expect(orphanWarningMessage(-5)).toContain('0 sub-cases')
		expect(orphanWarningMessage(undefined)).toContain('0 sub-cases')
	})
})

describe('requiresOrphanWarning', () => {
	it('requires the warning only when one or more sub-cases exist', () => {
		expect(requiresOrphanWarning(1)).toBe(true)
		expect(requiresOrphanWarning(10)).toBe(true)
	})

	it('does not require the warning when there are no sub-cases', () => {
		expect(requiresOrphanWarning(0)).toBe(false)
		expect(requiresOrphanWarning(undefined)).toBe(false)
		expect(requiresOrphanWarning(NaN)).toBe(false)
	})
})
