/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqCatalogus: each card is as tall as what it holds, and the Store card
 * closes up under the object types instead of leaving a band.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const config = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.d/64-catalogue.json'), 'utf8')).pages[0].config

describe('the catalogue cards fit their content (DqCatalogus)', () => {
	it('sizes every card to its content', () => {
		config.layout.forEach((item) => expect(item.sizeToContent).toBe(true))
	})

	it('packs the cards upward', () => {
		expect(config.gridFloat).toBe(false)
	})
})
