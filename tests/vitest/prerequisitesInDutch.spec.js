/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vereisten read in the reader's language: every reason lib/Prerequisites.php
 * gives for an extension or an app is a catalogue key with a Dutch text, and
 * the block renders it through the translation.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const php = fs.readFileSync(path.join(ROOT, 'lib/Prerequisites.php'), 'utf8')
const tab = fs.readFileSync(path.join(ROOT, 'src/views/settings/tabs/PrerequisitesTab.vue'), 'utf8')
const nl = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n/nl.json'), 'utf8')).translations

const block = (name) => php.slice(php.indexOf(`const ${name} = [`), php.indexOf('];', php.indexOf(`const ${name} = [`)))
const reasons = ['EXTENSIONS', 'APPS_REQUIRED', 'APPS_OPTIONAL'].flatMap((name) => [...block(name).matchAll(/=> '([^']+)'/g)].map((m) => m[1]))

describe('Vereisten in Dutch', () => {
	it('has a Dutch text for every reason', () => {
		expect(reasons.length).toBeGreaterThan(10)
		for (const reason of reasons) {
			expect(typeof nl[reason]).toBe('string')
			expect(nl[reason]).not.toBe(reason)
		}
	})

	it('renders the reasons through the translation', () => {
		expect(tab).toContain("{{ t('dossiq', row.why) }}")
		expect(tab.match(/\{\{ t\('dossiq', row\.unlocks\) \}\}/g)).toHaveLength(2)
		expect(tab).not.toContain('{{ row.why }}')
	})
})
