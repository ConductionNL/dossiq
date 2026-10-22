/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The connections table on the Integraties tab as DqInstellingen draws it:
 * Koppeling, Bron, Status, Laatste contact.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const admin = fs.readFileSync(path.join(ROOT, 'src/views/settings/AdminRoot.vue'), 'utf8')
const nl = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n/nl.json'), 'utf8')).translations
const columns = admin.slice(admin.indexOf('connectionColumns() {'), admin.indexOf('connectionColumns() {') + 2500)

describe('the connections table', () => {
	it('names the connection, then its source, its status and when integriq last checked it', () => {
		const keys = [...columns.matchAll(/^\t{5}key: '([^']+)'|\{ key: '([^']+)'/gm)].map((m) => m[1] || m[2])
		expect(keys.slice(0, 4)).toEqual(['title', 'source', 'status', 'checkedAt'])
		expect(columns).toContain("label: this.t('dossiq', 'Link')")
		expect(nl.Link).toBe('Koppeling')
	})

	it('shows the source by its name, read from integriq', () => {
		expect(columns).toMatch(/key: 'source',\s+label: this\.t\('dossiq', 'Source'\),\s+resolve: \{ register: 'integriq', schema: 'source', labelField: 'name' \},/)
		expect(nl.Source).toBe('Bron')
	})

	it('says when integriq last checked the connection, as a day and a time', () => {
		expect(columns).toMatch(/key: 'checkedAt',\s+label: this\.t\('dossiq', 'Last contact'\),\s+formatter: 'relativeDate',\s+formatterOptions: \{ time: true \},/)
		expect(columns).not.toContain("'@self.updated'")
	})
})
