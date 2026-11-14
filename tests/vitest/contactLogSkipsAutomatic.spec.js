/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaakContact: the contact log and the Contact tab's count list the contacts
 * a person recorded ("4 vastgelegd"). A message the system sent by itself, such
 * as the receipt, carries `automatic: true` and stays in the thread and the
 * history, not in the log.
 *
 * @spec openspec/changes/contact-log-skips-automatic/specs/contact-log-skips-automatic/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const layout = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/menu-layout.simple.json'), 'utf8'))
const register = JSON.parse(fs.readFileSync(path.join(ROOT, 'lib/Settings/register.d/40-kcc-werkplek.json'), 'utf8'))
const casePage = layout.pages.find((page) => page.configAppend && (page.configAppend.widgets || []).some((w) => w.id === 'case-contact-moments-card'))
const widget = (id) => casePage.configAppend.widgets.find((w) => w.id === id)

describe('automatic contact moments stay out of the contact log', () => {
	it('gives the contact moment an automatic marker', () => {
		const schema = register.components.schemas.contactmoment
		expect(schema.version).toBe('1.9.0')
		expect(schema.properties.automatic.type).toBe('boolean')
		expect(schema.properties.automatic.default).toBeUndefined()
	})

	it('lists only the moments without the marker in the log', () => {
		expect(widget('case-contact-moments-card').content.filter).toEqual({ case: '@objectId', automatic: { isnull: true } })
	})

	it('counts the same moments on the Contact tab', () => {
		const tab = casePage.configPatch.widgets['case-panels'].content.tabs.find((t) => t.label === 'Contact')
		expect(tab.countFrom.filter).toEqual({ case: '@objectId', automatic: { isnull: true } })
	})

	it('keeps every moment, the receipt too, in the conversation', () => {
		expect(widget('case-resident-thread').content.source.filter).toEqual({ case: '@objectId' })
	})
})
