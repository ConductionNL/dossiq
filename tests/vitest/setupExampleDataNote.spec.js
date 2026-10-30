/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqInstellen draws the example data step's note ("Voorbeeldgegevens gebruiken
 * alleen fictieve personen en organisaties.") as an info notice under the two
 * choices, not as a line of grey text.
 *
 * @spec openspec/specs/first-time-setup/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const manifest = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../src/manifest.json'), 'utf8'))

describe('the example data step', () => {
	it('shows its note as an info notice', () => {
		const step = manifest.setup.steps.find((entry) => entry.id === 'demo-data')
		expect(step.note).toBe('Example data uses fictional people and organisations only.')
		expect(step.noteStyle).toBe('card')
	})
})
