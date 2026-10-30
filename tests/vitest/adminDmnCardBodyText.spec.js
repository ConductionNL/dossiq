/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqBeheerGeavanceerd draws the "Beslistabellen (DMN)" card's text as
 * the card's body, 15px at 1.5 in the main ink under the title, not as the
 * 14px muted description the other cards carry.
 *
 * @spec openspec/changes/admin-advanced-tab/specs/admin-settings/spec.md#requirement-the-decision-tables-card-reads-its-text-as-body
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const admin = fs.readFileSync(path.resolve(__dirname, '../../src/views/settings/AdminRoot.vue'), 'utf8')

/**
 * @return {string} The section-dmn element, from its tag to its closing tag.
 */
function dmnSection() {
	const id = admin.indexOf('id="section-dmn"')
	const start = admin.lastIndexOf('<CnSettingsSection', id)
	const end = admin.indexOf('</CnSettingsSection>', id)
	return admin.slice(start, end)
}

describe('the Beslistabellen (DMN) card (DqBeheerGeavanceerd)', () => {
	it('carries its text in the body, not as the description', () => {
		const section = dmnSection()
		expect(section).not.toContain(':description=')
		expect(section).toMatch(/<p class="dq-settings-body-text">\s*\{\{\s*t\(\s*'dossiq',\s*'Decision tables live in the decision-tables register/)
	})

	it('draws that text 15px at 1.5 in the main ink', () => {
		const at = admin.indexOf('.dq-settings-body-text {')
		expect(at).toBeGreaterThan(-1)
		const body = admin.slice(at, admin.indexOf('}', at))
		expect(body).toContain('margin: 0;')
		expect(body).toContain('font-size: 15px;')
		expect(body).toContain('line-height: 1.5;')
		expect(body).toContain('color: var(--color-main-text);')
	})
})
