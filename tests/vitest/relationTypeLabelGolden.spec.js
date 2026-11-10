/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation tests for `relationTypeLabel`
 * (src/utils/caseRelationHelpers.js): every known kind of case relation, and
 * an unknown one handed back unchanged.
 */

import { describe, expect, it } from 'vitest'
import { relationTypeLabel } from '../../src/utils/caseRelationHelpers.js'

describe('relationTypeLabel golden', () => {
	it.each([
		['vervolg', 'Follow-up'],
		['subject', 'Subject'],
		['bijdrage', 'Contribution'],
		['samenhang', 'Related'],
	])('names %s', (kind, label) => {
		expect(relationTypeLabel(kind)).toBe(label)
	})

	it.each(['onbekend', '', null, undefined, 'toString', 3])('hands %j back unchanged', (kind) => {
		expect(relationTypeLabel(kind)).toBe(kind)
	})
})
