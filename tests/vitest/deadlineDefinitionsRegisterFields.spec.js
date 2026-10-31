// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The AWB term definitions read the register's own fields: `legalBasis`,
 * `standardDurationWeeks` (or `standardDurationDays`) and `caseType`.
 * Reading `basis` and `case_type` drew "(geen grondslag)" and "—" for all 23.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */

import { describe, expect, it } from 'vitest'

describe('the AWB definitions read the register fields', () => {
	it('shows legalBasis, standardDurationWeeks in weeks and the caseType slug', async () => {
		const { default: tab } = await import('../../src/views/settings/tabs/DeadlineDefinitionsTab.vue')
		const def = { caseType: 'omgevingsvergunning-kleinbouw', legalBasis: 'Ow 16.64 lid 1', standardDurationDays: 56, standardDurationWeeks: 8 }
		expect(tab.methods.basisOf(def)).toBe('Ow 16.64 lid 1')
		expect(tab.methods.caseTypeOf(def)).toBe('omgevingsvergunning-kleinbouw')
		expect(tab.methods.formatDuur(def)).toMatch(/^8 (weeks|weken)$/)
		expect(tab.methods.formatDuur({ standardDurationDays: 10 })).toMatch(/^10 (days|dagen)$/)
		expect(tab.methods.formatDuur({})).toBe('—')
	}, 60000)
})
