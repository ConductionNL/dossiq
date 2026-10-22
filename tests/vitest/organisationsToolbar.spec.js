/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqOrganisaties draws the quick filter chips on the toolbar row, beside the
 * Filter button. The page asks the library for that placement.
 *
 * @spec exclude board layout: DqOrganisaties is the specification
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'))
const page = manifest.pages.find((entry) => entry.id === 'Organisations')

describe('DqOrganisaties', () => {
	it('puts the quick filter chips on the toolbar row', () => {
		expect(page.config.quickFilterPlacement).toBe('toolbar')
	})
})
