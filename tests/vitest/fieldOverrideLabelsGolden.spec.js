/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation tests for `translateFieldOverrideLabels`
 * (src/utils/translateLensLabels.js): every override map in the manifest is
 * found, only string labels are translated, and shared nodes are walked once.
 *
 * @spec openspec/specs/frontend-locale-completeness/spec.md
 */

import { describe, expect, it } from 'vitest'
import { translateFieldOverrideLabels } from '../../src/utils/translateLensLabels.js'

/**
 * A translate function that records and marks what it translated.
 *
 * @param {Array<string>} seen Where the keys are recorded.
 * @return {(text: string) => string} The translate function.
 */
function recorder(seen) {
	return (text) => {
		seen.push(text)
		return '[' + text + ']'
	}
}

describe('translateFieldOverrideLabels golden', () => {
	it('translates the labels of every override map, in walk order', () => {
		const keep = { width: 2 }
		const manifest = {
			pages: [
				{ config: { fieldOverrides: { requester: { label: 'Requester', hidden: false }, notes: keep, odd: { label: 3 }, gone: null } } },
				{ headerActions: [{ form: { overrides: { title: { label: 'Title' } } } }] },
			],
			nav: { primary: { overrides: [{ label: 'not a map' }] } },
			widgets: [{ content: { overrides: { amount: { label: 'Amount' } }, extra: { fieldOverrides: 'x' } } }],
		}
		const seen = []
		const out = translateFieldOverrideLabels(manifest, recorder(seen))
		expect(out).toBe(manifest)
		expect(seen).toEqual(['Requester', 'Title', 'Amount'])
		expect(manifest.pages[0].config.fieldOverrides).toEqual({
			requester: { label: '[Requester]', hidden: false },
			notes: { width: 2 },
			odd: { label: 3 },
			gone: null,
		})
		expect(manifest.pages[0].config.fieldOverrides.notes).toBe(keep)
		expect(manifest.pages[1].headerActions[0].form.overrides.title).toEqual({ label: '[Title]' })
		expect(manifest.nav.primary.overrides).toEqual([{ label: 'not a map' }])
		expect(manifest.widgets[0].content.overrides.amount).toEqual({ label: '[Amount]' })
	})

	it('walks a shared node once, and tolerates cycles', () => {
		const shared = { overrides: { a: { label: 'A' } } }
		const manifest = { one: shared, two: shared }
		manifest.self = manifest
		const seen = []
		translateFieldOverrideLabels(manifest, recorder(seen))
		expect(seen).toEqual(['A'])
		expect(shared.overrides.a.label).toBe('[A]')
	})

	it('translates an override map nested inside another one again on the way down', () => {
		const inner = { b: { label: 'B' } }
		const manifest = { overrides: { a: { label: 'A', overrides: inner } } }
		const seen = []
		translateFieldOverrideLabels(manifest, recorder(seen))
		expect(seen).toEqual(['A', 'B'])
		expect(manifest.overrides.a).toEqual({ label: '[A]', overrides: { b: { label: '[B]' } } })
	})

	it.each([null, undefined, 'manifest', 4])('hands %j back', (manifest) => {
		expect(translateFieldOverrideLabels(manifest, recorder([]))).toBe(manifest)
	})
})
