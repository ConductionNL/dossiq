/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The inbox draws "Acties" beside Downloaden, as board DqPostvak does. The
 * menu was declared with nothing in it (the page has no header actions), so
 * the library left it out; it now holds Refresh and the documentation.
 *
 * @spec openspec/specs/intake-from-a-channel/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const fragment = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../src/manifest.d/88-postvak.json'), 'utf8'))
const config = fragment.pages[0].config

describe('the inbox Actions menu', () => {
	it('holds Refresh and the documentation, so it is drawn', () => {
		const menu = config.headerButtons.find((button) => button.action === 'actions-menu')
		expect(menu.items).toEqual(['refresh', 'separator', 'documentation'])
		expect(config.documentationUrl).toBeTruthy()
	})
})
