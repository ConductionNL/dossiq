/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The features and roadmap page draws a planned feature as a neutral pill
 * ("Gepland, 2027"), as DqFunctionaliteiten does (nextcloud-vue
 * `board.statusVariants`); stable and beta keep their tones.
 *
 * @spec exclude board tone of the roadmap status pills (DqFunctionaliteiten), configuration only
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const manifest = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../src/manifest.json'), 'utf8'))

describe('the roadmap status tones', () => {
	it('draw a planned feature neutral', () => {
		const page = manifest.pages.find((entry) => entry.id === 'FeaturesRoadmap')
		expect(page.config.board.statusVariants).toEqual({ planned: 'default' })
	})
})
