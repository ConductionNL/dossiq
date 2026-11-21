/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation tests for IntakeRequirementsModal's `labelFor`
 * (src/modals/IntakeRequirementsModal.vue): every intake field's translated
 * label, and an unknown field shown as its name.
 */

import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcModal: {}, NcSelect: {}, NcTextField: {} }))

const { methods } = (await import('../../src/modals/IntakeRequirementsModal.vue')).default

describe('intake field labels', () => {
	it.each([
		['communicationChannel', 'Communication channel'],
		['confidentiality', 'Confidentiality'],
		['classification', 'Classification'],
		['sensitivity', 'Sensitivity'],
		['actionFacet', 'Action facet'],
		['insightLevel', 'Insight level'],
		['assignedGroup', 'Team'],
		['assignee', 'Assignee'],
	])('labels %s', (field, label) => {
		expect(methods.labelFor(field)).toBe(label)
	})

	it.each(['unknown', '', 'toString', undefined])('shows %j as itself', (field) => {
		expect(methods.labelFor(field)).toBe(field)
	})
})
