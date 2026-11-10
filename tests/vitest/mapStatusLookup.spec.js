/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation tests for `statusColor` and `statusIcon`
 * (src/services/mapFormatters.js): every known case status, the fallback
 * for unknown or missing statuses, and strict matching on the status value.
 */

import { describe, expect, it } from 'vitest'
import { statusColor, statusIcon } from '../../src/services/mapFormatters.js'

describe('statusColor', () => {
	it.each([
		['blocked', 'var(--color-status-error)'],
		['in_progress', 'var(--color-status-warning)'],
		['open', 'var(--color-status-info)'],
		['closed', 'var(--color-text-maxcontrast)'],
	])('maps %s to its colour token', (status, expected) => {
		expect(statusColor(status)).toBe(expected)
	})

	it.each([undefined, null, '', 'unknown', 'toString', 'constructor', ['open'], 'OPEN'])(
		'falls back to the max-contrast colour for %s',
		(status) => {
			expect(statusColor(status)).toBe('var(--color-text-maxcontrast)')
		},
	)
})

describe('statusIcon', () => {
	it.each([
		['blocked', 'alert-circle'],
		['in_progress', 'progress-clock'],
		['open', 'map-marker'],
		['closed', 'check-circle'],
	])('maps %s to its icon', (status, expected) => {
		expect(statusIcon(status)).toBe(expected)
	})

	it.each([undefined, null, '', 'unknown', 'toString', 'constructor', ['closed'], 'CLOSED'])(
		'falls back to map-marker for %s',
		(status) => {
			expect(statusIcon(status)).toBe('map-marker')
		},
	)
})
