/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the pure deelzaak (sub-case) presentation helpers in
 * src/utils/deelzaakHelpers.js: the case-list sub-case count badge (T10) and
 * what a refused delete says (REQ-CM-35). These pin the exact user-facing
 * copy and the no-badge threshold that the formatter relies on.
 *
 * `@nextcloud/l10n` is aliased to the deterministic stub (English source
 * string + {placeholder} substitution), so the asserted output is the
 * interpolated English source.
 *
 * NOTE: these assertions used to read "N deelzaken". Commit cf3ee93b9
 * ("i18n(l10n): author Dutch-source UI literals in English + NL l10n")
 * correctly re-authored the source literal as `'{count} sub-cases'` and put
 * the Dutch in `l10n/nl.json` — but this spec was never updated, because
 * the whole Vitest suite had been un-runnable (and un-run in CI) since the
 * Vue 3 migration. The English source is what the stub returns and what
 * these tests are documented to assert, so the expectations are corrected
 * here rather than the (correct) production literal being reverted.
 *
 * @spec openspec/changes/deelzaak-support/tasks.md#T10
 * @spec openspec/changes/case-delete-guard/specs/case-management/spec.md
 */

import { describe, expect, it } from 'vitest'
import {
	hasSubCaseBadge,
	refusalMessage,
	subCaseCountBadge,
} from '../../src/utils/deelzaakHelpers.js'

describe('subCaseCountBadge', () => {
	it('renders "N sub-cases" for a positive count', () => {
		expect(subCaseCountBadge(2)).toBe('2 sub-cases')
		expect(subCaseCountBadge(1)).toBe('1 sub-cases')
		expect(subCaseCountBadge(25)).toBe('25 sub-cases')
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
		expect(subCaseCountBadge('3')).toBe('3 sub-cases')
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

describe('refusalMessage', () => {
	it('shows the sentence a stopped delete guard sent back', () => {
		const err = {
			response: {
				data: {
					error: 'Object deletion rejected by hook',
					errors: {
						message:
							'You cannot delete this case yet. This case still has sub-cases. Resolve this first, then delete the case.',
						error: 'case.held',
						blockedBy: ['has-subcases'],
					},
				},
			},
		}
		expect(refusalMessage(err)).toMatch(/still has sub-cases/)
	})

	it('shows the sentence a dossiq door sent back at the top level', () => {
		const err = {
			response: {
				data: {
					message:
						'You cannot delete this case yet. A legal hold is on this case. Resolve this first, then delete the case.',
					error: 'case.held',
					blockedBy: ['legal-hold'],
				},
			},
		}
		expect(refusalMessage(err)).toMatch(/A legal hold is on this case/)
	})

	it('falls back to the generic line only when no sentence came back', () => {
		expect(refusalMessage(new Error('Network Error'))).toBe(
			'The case could not be deleted. Please try again.',
		)
		expect(
			refusalMessage({ response: { data: { errors: { message: '  ' } } } }),
		).toBe('The case could not be deleted. Please try again.')
	})
})
