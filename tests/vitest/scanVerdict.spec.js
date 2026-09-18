// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Not scanned and clean must not read the same, ever.
 *
 * The Scan column exists to tell a handler whether anybody has checked the
 * attachment they are about to open. That only works if the absence of an
 * answer looks different from a cleared file, which is exactly what an
 * optional platform app makes easy to get wrong: most instances have no
 * scanner, the endpoint answers nothing, and a formatter that renders an
 * empty cell there would read as reassurance.
 *
 * So every shape that is not an explicit verdict is pinned to Not scanned
 * here, and the manifest column is pinned to this formatter, because a
 * formatter nothing references is a formatter nothing renders.
 *
 * @spec openspec/changes/scan-verdict-on-the-row/specs/document-zaakdossier/spec.md
 */
import { beforeAll, describe, expect, it } from 'vitest'
import manifest from '../../src/manifest.json'
import {
	scanVerdictLabel,
	STATE_CLEAN,
	STATE_INFECTED,
	STATE_NOT_SCANNED,
	stateOf,
} from '../../src/services/scanVerdict.js'

beforeAll(() => {
	globalThis.t = (app, text, vars = {}) =>
		String(text).replace(/\{(\w+)\}/g, (match, key) =>
			key in vars ? String(vars[key]) : match,
		)
})

/**
 * The Files tab widget on the case page.
 *
 * @return {object} The widget.
 */
function filesWidget() {
	for (const page of manifest.pages) {
		for (const widget of page.config?.widgets || []) {
			if (widget.id === 'case-files') {
				return widget
			}
		}
	}
	throw new Error('the case Files widget is gone')
}

describe('the column is declared where the browser will read it', () => {
	it('adds Scan beside Sender and Recipients', () => {
		const columns = filesWidget().props.columns
		const scan = columns.find((column) => column.id === 'scan')
		expect(scan).toBeTruthy()
		expect(scan.label).toBe('Scan')
		expect(scan.property).toBe('scanVerdict')
	})

	it('names a formatter, or the cell renders a raw object', () => {
		const scan = filesWidget().props.columns.find(
			(column) => column.id === 'scan',
		)
		expect(scan.formatter).toBe('scanVerdict')
	})
})

describe('a verdict is read, never inferred', () => {
	it('reads a cleared file as clean, with the time of the check', () => {
		const label = scanVerdictLabel({
			state: STATE_CLEAN,
			scannedAt: '2026-09-15T09:00:00+00:00',
		})
		expect(label).toContain('Clean')
		expect(label).not.toBe('Clean')
	})

	it('reads a cleared file with no recorded time as clean, plainly', () => {
		expect(
			scanVerdictLabel({ state: STATE_CLEAN, scannedAt: null }),
		).toBe('Clean')
	})

	it('reads a flagged file as infected', () => {
		expect(scanVerdictLabel({ state: STATE_INFECTED })).toBe('Infected')
	})

	it('reads an absent scanner as not scanned', () => {
		expect(
			scanVerdictLabel({
				state: STATE_NOT_SCANNED,
				scannedAt: null,
				scannerPresent: false,
			}),
		).toBe('Not scanned')
	})

	it('reads nothing at all as not scanned, never as clean', () => {
		expect(scanVerdictLabel(null)).toBe('Not scanned')
		expect(scanVerdictLabel(undefined)).toBe('Not scanned')
		expect(scanVerdictLabel({})).toBe('Not scanned')
		expect(scanVerdictLabel('')).toBe('Not scanned')
	})

	it('reads a state it does not know as not scanned', () => {
		expect(stateOf({ state: 'quarantined' })).toBe(STATE_NOT_SCANNED)
		expect(scanVerdictLabel({ state: 'quarantined' })).toBe('Not scanned')
	})

	it('takes a bare state as well as a verdict object', () => {
		expect(stateOf(STATE_INFECTED)).toBe(STATE_INFECTED)
		expect(scanVerdictLabel(STATE_INFECTED)).toBe('Infected')
	})

	it('drops a recorded time that is not one', () => {
		expect(
			scanVerdictLabel({ state: STATE_CLEAN, scannedAt: 'whenever' }),
		).toBe('Clean')
	})
})
