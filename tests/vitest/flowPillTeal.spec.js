/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case type flow page draws its "Case type flow" pill teal, as DqFlow
 * does (nextcloud-vue `pillVariant`).
 *
 * @spec exclude board tone of the flow page pill (DqFlow), configuration only
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const manifest = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../src/manifest.json'), 'utf8'))

describe('the case type flow pill', () => {
	it('is teal', () => {
		const page = manifest.pages.find((entry) => entry.route === '/flows/:id')
		expect(page.config.pill).toBe('Case type flow')
		expect(page.config.pillVariant).toBe('teal')
	})
})
