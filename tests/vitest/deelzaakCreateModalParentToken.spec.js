// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DeelzaakCreateModal opened with an unresolved `@objectId` token: the case
 * page's `case-sub-cases` createAction declares `parentCase: "@objectId"` and
 * the list widget forwards it as the literal string, so the modal must take
 * the case on screen as the parent instead of reading a case called
 * `@objectId` (a 404 that ended in "No allowed sub-case types").
 *
 * @spec openspec/specs/deelzaak-support/spec.md#requirement-sub-case-creation-from-parent-case
 */

import { describe, expect, it } from 'vitest'
import { resolvedParentCase } from '../../src/modals/DeelzaakCreateModal.vue'

describe('resolvedParentCase', () => {
	it('keeps a real parent id', () => {
		expect(resolvedParentCase('parent-1', { params: { id: 'other' } })).toBe('parent-1')
	})

	it('takes the case on screen when the prop is an unresolved token', () => {
		expect(resolvedParentCase('@objectId', { params: { id: 'case-on-screen' } })).toBe('case-on-screen')
		expect(resolvedParentCase('@object.case', { params: { id: 'case-on-screen' } })).toBe('case-on-screen')
	})

	it('answers empty when there is neither', () => {
		expect(resolvedParentCase('@objectId', null)).toBe('')
		expect(resolvedParentCase('', { params: {} })).toBe('')
	})
})
