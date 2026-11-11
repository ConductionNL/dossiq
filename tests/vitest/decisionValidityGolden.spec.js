// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Characterisation tests for `getDecisionValidity` (src/utils/decisionHelpers.js),
 * with the clock frozen: every validity window and its wording.
 *
 * @spec openspec/specs/roles-decisions/spec.md
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { formatDecisionDate, getDecisionValidity } from '../../src/utils/decisionHelpers.js'

describe('decision validity golden', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		vi.setSystemTime(new Date(2026, 10, 10, 15, 30))
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it('is not yet effective before its effective date', () => {
		expect(getDecisionValidity({ effectiveDate: '2026-11-11', expiryDate: '2027-01-01' })).toEqual({
			status: 'not_effective',
			label: 'Not yet effective',
			style: 'validity--pending',
			remaining: `Effective from ${formatDecisionDate('2026-11-11')}`,
		})
	})

	it.each([['2026-11-09'], ['2026-11-01']])('is expired after its expiry date (%s)', (expiryDate) => {
		expect(getDecisionValidity({ effectiveDate: '2026-11-10', expiryDate })).toEqual({
			status: 'expired',
			label: 'Expired',
			style: 'validity--expired',
			remaining: null,
		})
	})

	it.each([['2026-11-10', 0], ['2026-11-20', 10], ['2026-12-10', 30]])('expires soon up to 30 days ahead (%s)', (expiryDate, days) => {
		expect(getDecisionValidity({ expiryDate })).toEqual({
			status: 'expiring_soon',
			label: `Expires in ${days} days`,
			style: 'validity--warning',
			remaining: `Expires ${formatDecisionDate(expiryDate)}`,
		})
	})

	it('is active until a later expiry date', () => {
		expect(getDecisionValidity({ effectiveDate: '2026-01-01', expiryDate: '2026-12-11' })).toEqual({
			status: 'active',
			label: 'Active',
			style: 'validity--active',
			remaining: `Valid until ${formatDecisionDate('2026-12-11')}`,
		})
	})

	it('is active from its effective date without an expiry date', () => {
		expect(getDecisionValidity({ effectiveDate: '2026-11-10' })).toEqual({
			status: 'active',
			label: 'Active',
			style: 'validity--active',
			remaining: `From ${formatDecisionDate('2026-11-10')}`,
		})
	})

	it.each([[{}], [{ effectiveDate: '', expiryDate: null }]])('is unknown without dates (%j)', (decision) => {
		expect(getDecisionValidity(decision)).toEqual({ status: 'unknown', label: '', style: '', remaining: null })
	})
})
