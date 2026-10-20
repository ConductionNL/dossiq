// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The setup wizard's example-data step as board DqInstellen draws it: the
 * note about fictional data is an info card under the choices.
 *
 * @spec openspec/changes/setup-wizard-board/specs/setup-wizard-board/spec.md#requirement-the-example-data-note-is-an-info-card
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const manifest = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../src/manifest.json'), 'utf8'))

describe('the setup wizard on the board', () => {
	it('draws the example-data note as an info card', () => {
		const step = manifest.setup.steps.find((entry) => entry.id === 'demo-data')
		expect(step.note).toBe('Example data uses fictional people and organisations only.')
		expect(step.noteStyle).toBe('card')
	})
})
