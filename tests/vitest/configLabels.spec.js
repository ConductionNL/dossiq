/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A manifest label written as `@config.<key>|<fallback>` reads the app
 * config (the portal channel named after the portal, "Mijn Zuiddrecht"),
 * and the fallback when the setting is empty.
 *
 * @spec openspec/changes/portal-channel-label/specs/portal-channel-label/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { withConfigLabels } from '../../src/utils/configLabels.js'

const simple = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../src/menu-layout.simple.json'), 'utf8')).pages.find((page) => page.id === 'CaseDetail')
const widget = (id) => simple.configAppend.widgets.find((entry) => entry.id === id)

describe('withConfigLabels', () => {
	it('puts the config value in, or the fallback when it is empty', () => {
		const manifest = { pages: [{ config: { map: { webformulier: '@config.portalName|Web form', phone: 'Phone' } } }] }
		expect(withConfigLabels(structuredClone(manifest), { portalName: 'Mijn Zuiddrecht' }).pages[0].config.map).toEqual({ webformulier: 'Mijn Zuiddrecht', phone: 'Phone' })
		expect(withConfigLabels(structuredClone(manifest), { portalName: '  ' }).pages[0].config.map.webformulier).toBe('Web form')
		expect(withConfigLabels(structuredClone(manifest), {}).pages[0].config.map.webformulier).toBe('Web form')
	})

	it('leaves other tokens and texts alone', () => {
		const manifest = { pages: [{ filter: { case: '@objectId', who: '@config.city' } }] }
		expect(withConfigLabels(structuredClone(manifest), { city: 'Zuiddrecht' }).pages[0].filter).toEqual({ case: '@objectId', who: '@config.city' })
	})
})

describe('the portal channel on the case page (DqZaakContact)', () => {
	it('is named after the portal in the chips and the contact-moments table', () => {
		expect(widget('case-resident-thread').content.channelMap.webformulier).toBe('@config.portalName|Web form')
		const column = widget('case-contact-moments-card').content.columns.find((entry) => entry.key === 'notificationChannel')
		expect(column.enumLabels.webformulier).toBe('@config.portalName|Web form')
	})
})
