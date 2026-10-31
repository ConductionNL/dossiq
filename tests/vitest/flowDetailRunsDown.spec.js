/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqFlow draws a case type flow top to bottom at its own size, with the step
 * palette beside it. The flow page asks the library flow board for that.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const manifest = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../src/manifest.json'), 'utf8'))
const flowPage = manifest.pages.find((page) => page.id === 'FlowDetail')

describe('the flow page (DqFlow)', () => {
	it('runs the steps top to bottom beside the palette column', () => {
		expect(flowPage.type).toBe('flow')
		expect(flowPage.config.palette).toBe('column')
		expect(flowPage.config.flowDirection).toBe('down')
	})
})
