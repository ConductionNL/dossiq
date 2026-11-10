/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation golden for `normaliseDeclaration`
 * (src/utils/intakeRequirements.js): a full declaration, an empty one, and
 * the strict readings of every flag (who may be assigned, whether the
 * classification is an access rule, the duplicate policy).
 */

import { describe, expect, it } from 'vitest'
import { normaliseDeclaration } from '../../src/utils/intakeRequirements.js'

const EMPTY = {
	caseType: '',
	requiredBeforeCreation: [],
	requiredBeforeComplete: [],
	scheme: '',
	classificationIsAccessRule: false,
	facets: [],
	classificationValues: [],
	schemeResolves: true,
	allowedGroups: [],
	allowedUsers: [],
	narrowsNothing: true,
	refusalDestination: { department: '', role: '' },
	canRefuse: false,
	duplicatePolicy: 'warn',
	mayOverrideDuplicates: false,
	unreadable: false,
}

describe('normaliseDeclaration golden', () => {
	it.each([undefined, null, {}])('defaults everything for %j', (declaration) => {
		const out = normaliseDeclaration(declaration)
		expect(out).toEqual(EMPTY)
		expect(Object.keys(out)).toEqual(Object.keys(EMPTY))
	})

	it('reads a full declaration and copies its lists', () => {
		const source = {
			caseType: 'ct-1',
			intakeRequirements: { requiredBeforeCreation: ['a'], requiredBeforeComplete: ['b'] },
			classification: { scheme: 'bio', classificationIsAccessRule: true, facets: ['f'] },
			classificationValues: ['v'],
			schemeResolves: false,
			assigneeNarrowing: { allowedGroups: ['g'], allowedUsers: ['u'] },
			narrowsNothing: false,
			refusalDestination: { department: 'd', role: 'r' },
			canRefuse: true,
			duplicatePolicy: { policy: 'block', mayOverride: true },
			unreadable: true,
		}
		const out = normaliseDeclaration(source)
		expect(out).toEqual({
			caseType: 'ct-1',
			requiredBeforeCreation: ['a'],
			requiredBeforeComplete: ['b'],
			scheme: 'bio',
			classificationIsAccessRule: true,
			facets: ['f'],
			classificationValues: ['v'],
			schemeResolves: false,
			allowedGroups: ['g'],
			allowedUsers: ['u'],
			narrowsNothing: false,
			refusalDestination: { department: 'd', role: 'r' },
			canRefuse: true,
			duplicatePolicy: 'block',
			mayOverrideDuplicates: true,
			unreadable: true,
		})
		expect(out.allowedGroups).not.toBe(source.assigneeNarrowing.allowedGroups)
		expect(out.facets).not.toBe(source.classification.facets)
		expect(out.refusalDestination).toBe(source.refusalDestination)
	})

	it('reads every flag strictly', () => {
		expect(normaliseDeclaration({
			classification: { classificationIsAccessRule: 'true' },
			schemeResolves: 0,
			narrowsNothing: null,
			canRefuse: 'yes',
			duplicatePolicy: { policy: 'BLOCK', mayOverride: 1 },
			unreadable: 1,
		})).toEqual({ ...EMPTY, schemeResolves: true, narrowsNothing: true })
	})
})
