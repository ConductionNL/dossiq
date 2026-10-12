// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * A share of nothing shows no figure on the deadline dashboard.
 *
 * The report answers `withinTermijnPercent` and `binnenTermijnPct` as null
 * when no term was decided (met or missed) yet. The formatter used to turn
 * that into `0.0 %`, which reads as every case overrun; it now shows a dash.
 *
 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/woo-request-intake/spec.md#requirement-the-term-behaves-as-opencatalogis-did-proven-on-its-fixtures-req-wto-003
 */
import { describe, expect, it } from 'vitest'
import { tdWidgetMixin } from '../../src/views/termijn/tdWidgetMixin.js'

const { percent } = tdWidgetMixin.methods

describe('tdWidgetMixin percent', () => {
	it('shows a dash when there is no figure', () => {
		expect(percent(null)).toBe('—')
		expect(percent(undefined)).toBe('—')
	})

	it('shows a real zero as zero', () => {
		expect(percent(0)).toBe('0.0 %')
	})

	it('shows a share with one decimal', () => {
		expect(percent(75)).toBe('75.0 %')
	})
})
