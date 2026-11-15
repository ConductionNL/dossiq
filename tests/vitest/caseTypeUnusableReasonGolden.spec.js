// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Characterisation tests for `getCaseTypeUnusableReason`
 * (src/utils/caseValidation.js), with the clock frozen: every reason and the
 * order the checks run in.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { getCaseTypeUnusableReason, isCaseTypeUsable } from '../../src/utils/caseValidation.js'

const DRAFT = 'Cannot create a case with a draft case type. The case type must be published first.'
const REPLACED = 'This version of the case type has been replaced. Cases already running on it continue. New cases go on the current version.'

describe('case type unusable reason golden', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		vi.setSystemTime(new Date(2026, 10, 10, 15, 0))
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it.each([
		[null, 'Case type not found'],
		[undefined, 'Case type not found'],
		[{ isDraft: true, validUntil: '2020-01-01' }, DRAFT],
		[{ isDraft: 'true' }, DRAFT],
		[{ isDraft: 'false', validFrom: '2027-01-01T00:00:00Z' }, 'Cannot create a case with a case type that is not yet valid. The case type is valid from 2027-01-01.'],
		[{ validFrom: '2026-11-11' }, 'Cannot create a case with a case type that is not yet valid. The case type is valid from 2026-11-11.'],
		[{ validFrom: '2026-11-10', validUntil: '2026-11-10' }, null],
		[{ validUntil: '2026-11-09T12:00:00' }, 'Cannot create a case with an expired case type. The case type was valid until 2026-11-09.'],
		[{ validFrom: '2026-12-01', validUntil: '2020-01-01' }, 'Cannot create a case with a case type that is not yet valid. The case type is valid from 2026-12-01.'],
		[{}, null],
	])('reads %j as %j', (caseType, reason) => {
		expect(getCaseTypeUnusableReason(caseType)).toBe(reason)
	})

	it('says a replaced version is replaced, before its dates', () => {
		expect(getCaseTypeUnusableReason({ supersededBy: 'ct-2', validFrom: '2027-01-01' })).toBe(REPLACED)
		expect(getCaseTypeUnusableReason({ supersededBy: { id: 'ct-2' } })).toBe(REPLACED)
		expect(getCaseTypeUnusableReason({ isDraft: true, supersededBy: 'ct-2' })).toBe(DRAFT)
	})

	it.each([
		[null, false],
		[{ isDraft: true }, false],
		[{ isDraft: 'true' }, false],
		[{ supersededBy: 'ct-2' }, false],
		[{ validFrom: '2026-11-11' }, false],
		[{ validFrom: '2026-11-10', validUntil: '2026-11-10T23:00:00' }, true],
		[{ validUntil: '2026-11-09' }, false],
		[{ isDraft: 'false' }, true],
		[{}, true],
	])('reads %j as usable: %j', (caseType, usable) => {
		expect(isCaseTypeUsable(caseType)).toBe(usable)
	})
})
