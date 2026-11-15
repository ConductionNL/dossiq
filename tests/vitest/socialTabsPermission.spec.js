/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * privacy-and-social-domain-groups 1.5: the case page's Indication and Case
 * meeting tabs show only to a holder of the `sociaal-domein` role
 * permission, as the social-domain pages do.
 *
 * @spec openspec/specs/admin-data-access/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const simple = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/menu-layout.simple.json'), 'utf8')).pages.find((page) => page.id === 'CaseDetail')
const tabs = simple.configPatch.widgets['case-panels'].content.tabs

describe('the social-domain tabs on the case page', () => {
	it.each(['case-social-indications', 'case-social-meetings'])('%s needs sociaal-domein', (widgetId) => {
		expect(tabs.find((tab) => tab.widgetId === widgetId).permission).toBe('sociaal-domein')
	})
})
