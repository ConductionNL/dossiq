/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the email-template editor's unresolved-variable detection
 * (case-email-integration T11), which the subject line is checked with.
 *
 * @spec openspec/changes/case-email-integration/specs/case-email-integration/spec.md
 */

import { describe, expect, it } from 'vitest'
import { collectUnresolved } from '../../src/utils/emailTemplatePreview.js'

const KNOWN = ['zaakNummer', 'contactNaam', 'handler', 'startDate']

describe('collectUnresolved', () => {
	it('returns no unresolved names when all placeholders are known', () => {
		const text = 'Zaak {{zaakNummer}} voor {{contactNaam}}'
		expect(collectUnresolved(text, KNOWN)).toEqual([])
	})

	it('returns unknown placeholder names', () => {
		const text =
			'Hallo {{contactNaam}}, veld {{nonExistentField}} en {{onbekend}}'
		const result = collectUnresolved(text, KNOWN)
		expect(result).toContain('onbekend')
		expect(result).toContain('nonExistentField')
		expect(result).not.toContain('contactNaam')
	})

	it('de-duplicates repeated unresolved names', () => {
		const text = '{{foo}} en nog eens {{foo}}'
		expect(collectUnresolved(text, KNOWN)).toEqual(['foo'])
	})

	it('handles empty / nullish input safely', () => {
		expect(collectUnresolved('', KNOWN)).toEqual([])
		expect(collectUnresolved(null, KNOWN)).toEqual([])
	})
})
