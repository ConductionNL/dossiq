/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqVthInstellingen ends the Handhavingsstrategie card with a text link
 * under the facts, flush with them. The earlier matrix versions open from
 * such a link, not from a padded tertiary button standing in from the edge.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const admin = fs.readFileSync(path.resolve(__dirname, '../../src/views/settings/AdminRoot.vue'), 'utf8')

describe('the enforcement strategy card', () => {
	const card = admin.slice(admin.indexOf('id="section-enforcement-strategy"'), admin.indexOf('</CnSettingsSection>', admin.indexOf('id="section-enforcement-strategy"')))

	it('opens the earlier versions from a text link under the facts', () => {
		expect(card).toMatch(/<a\s[^>]*href="#section-enforcement-strategy"[^>]*role="button"[^>]*data-testid="enforcement-earlier-versions"/)
		expect(card).toContain(':aria-expanded="String(showEarlierMatrices)"')
		expect(card).toContain('@click.prevent="showEarlierMatrices = !showEarlierMatrices"')
		expect(card).not.toMatch(/<NcButton[\s\S]*?enforcement-earlier-versions/)
	})
})
