/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The setup wizard opens by saying what Dossiq is, then offers the example
 * data, then asks for the organisation (ADR-111 rule 4). The welcome copy has
 * its Dutch keys, so a Dutch administrator reads Dutch from the first step.
 *
 * @spec openspec/specs/first-time-setup/spec.md#requirement-the-wizard-opens-with-a-welcome-then-the-example-data
 */
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'))
const nl = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n', 'nl.json'), 'utf8')).translations
const steps = manifest.setup.steps

describe('the setup wizard order', () => {
	it('opens with the welcome, then the example data, then the organisation', () => {
		expect(steps.map((step) => step.id)).toEqual([
			'welcome',
			'demo-data',
			'organisation-details',
			'house-style',
			'case-types',
			'done',
		])
	})

	it('welcomes with an info step that asks nothing', () => {
		const welcome = steps[0]
		expect(welcome.type).toBe('info')
		expect(welcome.required).not.toBe(true)
	})

	it('has Dutch keys for the welcome and the organisation copy', () => {
		for (const step of [steps[0], steps.find((s) => s.id === 'organisation-details')]) {
			expect(nl[step.title], step.title).toBeTruthy()
			expect(nl[step.body], step.body).toBeTruthy()
		}
	})
})
