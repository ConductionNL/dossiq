/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Whether OpenRegister's case layer could hold a plan for a case.
 *
 * OpenRegister is the only runtime, so the one decision left is whether to ask
 * it. A wrong "no" hides a plan; a wrong "yes" costs one 404. The rule
 * therefore asks whenever it is not sure.
 *
 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-001-case-semantics-are-consumed-from-openregister
 */
import { describe, expect, it } from 'vitest'
import * as source from '../../src/services/casePlanSource.js'

describe('mayHoldOpenRegisterPlan', () => {
	it('asks for a caseType the case layer drives', () => {
		expect(
			source.mayHoldOpenRegisterPlan({ caseType: { handlingModel: 'cmmn' } }),
		).toBe(true)
	})

	it('does not ask for a BPMN caseType', () => {
		expect(
			source.mayHoldOpenRegisterPlan({ caseType: { handlingModel: 'bpmn' } }),
		).toBe(false)
	})

	it('reads an absent handlingModel as BPMN, as the projection does', () => {
		expect(source.mayHoldOpenRegisterPlan({ caseType: { id: 'ct' } })).toBe(
			false,
		)
	})

	it('asks when the caseType could not be read, so an outage stays visible', () => {
		expect(source.mayHoldOpenRegisterPlan({ caseType: null })).toBe(true)
		expect(source.mayHoldOpenRegisterPlan()).toBe(true)
	})
})

describe('the retired engine left nothing behind', () => {
	it('offers no second source, no blob reader and no read preference', () => {
		expect(Object.keys(source)).toEqual(['mayHoldOpenRegisterPlan'])
	})
})
