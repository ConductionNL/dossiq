/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The boards draw a sort button and a column filter in the heads of the
 * settings tables (DqInstellingen: connections; DqVthInstellingen: intake
 * activities, fee rules) and of the case's contact moments (DqZaakContact).
 * The tables ask the library for them (nextcloud-vue table-widget-header-controls).
 *
 * @spec exclude board layout: DqInstellingen, DqVthInstellingen and DqZaakContact are the specification
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const admin = fs.readFileSync(path.join(ROOT, 'src', 'views', 'settings', 'AdminRoot.vue'), 'utf8')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'))

/**
 * The opening tag of the object table that reads this source.
 *
 * @param {string} source The source binding name.
 * @return {string} The tag.
 */
function tableTag(source) {
	const at = Math.max(admin.indexOf(`:source="${source}"`), admin.indexOf(`:endpointSource="${source}"`))
	const start = admin.lastIndexOf('<CnWidgetObjectTable', at)
	return admin.slice(start, admin.indexOf('/>', at))
}

describe('settings tables', () => {
	it.each(['connectionsSource', 'intakeActivitySource', 'feeScheduleSource'])('the %s table sorts and filters from its heads', (source) => {
		const tag = tableTag(source)
		expect(tag).toContain(':sortable="true"')
		expect(tag).toContain(':filterable="true"')
	})
})

describe('DqZaakContact', () => {
	it('lets the contact moments sort and filter from their heads', () => {
		const page = manifest.pages.find((p) => p.id === 'CaseDetail')
		const panel = page.config.widgets.find((w) => w.id === 'case-communication-panel')
		const list = panel.content.sections[0].widget
		expect(list.id).toBe('case-communication')
		expect(list.content.sortable).toBe(true)
		expect(list.content.filterable).toBe(true)
	})
})
