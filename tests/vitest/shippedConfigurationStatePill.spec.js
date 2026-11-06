/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * "Wat dossiq meelevert" draws a row's state as the board's pill:
 * "Ongewijzigd" on a light success fill, "Door u gewijzigd" on a warning fill.
 *
 * @spec openspec/specs/case-type-seed-data/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const view = fs.readFileSync(path.join(ROOT, 'src/views/settings/ShippedConfiguration.vue'), 'utf8')
const nl = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n/nl.json'), 'utf8')).translations

describe('the shipped configuration state', () => {
	it('is a pill per state', () => {
		expect(view).toContain(`<span class="cn-status-badge" :class="'cn-status-badge--' + stateTone(row)">`)
		expect(view).toContain("{ shipped: 'success', changed: 'warning', removed: 'warning' }")
		expect(view).not.toContain('color-mix')
	})

	it('reads as the board words it', () => {
		expect(nl['Shipped, unchanged']).toBe('Ongewijzigd')
		expect(nl['Shipped, changed here']).toBe('Door u gewijzigd')
	})
})

describe('the background service account card', () => {
	it('draws its "Dit account gebruiken" as a secondary button, as the board does', () => {
		const root = fs.readFileSync(path.join(ROOT, 'src/views/settings/AdminRoot.vue'), 'utf8')
		const at = root.indexOf('id="section-background-service-account"')
		expect(root.slice(at, root.indexOf('/>', at))).toContain('saveVariant="secondary"')
	})
})
