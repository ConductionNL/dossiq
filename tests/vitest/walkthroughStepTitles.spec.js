/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Every tour step has a title, in English and Dutch.
 *
 * Step 2 of 7 of the getting-started tour had no title, so the popover opened
 * on a bare "2 / 7" and a screen reader announced "Step 2 of 7:" with nothing
 * after the colon (cloud check, 8 October 2026). Steps 3 and 6 had the same
 * gap.
 *
 * @spec openspec/changes/notification-labels-and-tour-titles/specs/notification-labels/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
function readJson(...parts) {
	return JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))
}

const manifest = readJson('src', 'manifest.json')
const en = readJson('l10n', 'en.json').translations
const nl = readJson('l10n', 'nl.json').translations

const steps = (manifest.walkthrough?.tours ?? []).flatMap((tour) =>
	tour.steps.map((step, index) => ({ tour: tour.id, number: index + 1, ...step })),
)

describe('tour step titles', () => {
	it('finds the steps to check, which is the control', () => {
		expect(steps.length).toBeGreaterThan(5)
	})

	it.each(
		steps.map((step) => [`${step.tour} step ${step.number} (${step.id})`, step]),
	)('%s has a title', (_, step) => {
		expect(typeof step.title === 'string' && step.title.trim() !== '').toBe(true)
	})

	it.each(steps.filter((step) => step.title).map((step) => [step.title]))(
		'"%s" has an English and a Dutch entry',
		(title) => {
			expect(en[title]).toBe(title)
			expect(nl[title]).toBeTruthy()
			expect(nl[title]).not.toBe(title)
		},
	)
})
