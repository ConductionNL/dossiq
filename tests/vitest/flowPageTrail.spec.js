/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqFlow's second row reads "Instellingen / Flows / <flow>", each level its
 * own link, and the notice names the case type the flow is the way of.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const flowPage = read('src/manifest.json').pages.find((page) => page.id === 'FlowDetail')
const nl = read('l10n/nl.json').translations

describe('the flow page path and notice (DqFlow)', () => {
	it('reads Instellingen / Flows, each its own link', () => {
		expect(flowPage.config.breadcrumb).toEqual([
			{ label: 'Settings', href: '/index.php/settings/admin/dossiq' },
			{ label: 'Flows' },
		])
		expect(flowPage.config.breadcrumb.map((crumb) => nl[crumb.label])).toEqual(['Instellingen', 'Flows'])
	})

	it('names the case type in the notice', () => {
		expect(flowPage.config.notice).toContain('{name}')
		expect(nl[flowPage.config.notice]).toBe('Deze flow is de werkwijze van het zaaktype {name}. De zaak voert de stappen uit; oude webhookstappen lopen nu via een externe aanroep van Integriq.')
	})
})
