/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaakEmail draws the email tab as "E-mail 3": the mail linked to the
 * case, as the mail panel on that tab lists it.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const simple = read('src/menu-layout.simple.json').pages.find((page) => page.id === 'CaseDetail')
const tab = simple.configPatch.widgets['case-panels'].content.tabs.find((entry) => entry.label === 'Email')

describe('the case email tab', () => {
	it('counts the mail linked to the case', () => {
		expect(tab.widgetIds).toContain('case-email-panel')
		expect(tab.countFrom).toEqual({ endpoint: '/apps/openregister/api/objects/dossiq/case/@objectId/emails', field: 'total' })
	})
})
