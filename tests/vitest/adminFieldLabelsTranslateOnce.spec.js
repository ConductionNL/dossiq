/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * CnSettingsFieldsSection translates a field label through cnTranslate, so a
 * label the fields already translated is translated twice. A Dutch label that
 * is itself an English key then changes: "Folder" -> "Map" -> "Kaart"
 * (DqBeheerinstellingen, Zaak-e-mail). Every label the fields translate must
 * survive that; the folder label is handed over as its key.
 *
 * @spec openspec/specs/admin-settings/spec.md#requirement-an-admin-field-label-reads-the-same-after-the-library-translates-it
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import * as adminFields from '../../src/views/settings/adminFields.js'

const ROOT = path.resolve(__dirname, '../..')
const nl = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n/nl.json'), 'utf8')).translations

/**
 * Dutch translate, as dossiq's catalogue answers it.
 *
 * @param {string} app The app id.
 * @param {string} text The key.
 * @return {string} The Dutch text.
 */
function t(app, text) {
	return typeof nl[text] === 'string' ? nl[text] : text
}

const labels = Object.entries(adminFields)
	.filter(([, build]) => typeof build === 'function')
	.flatMap(([name, build]) => {
		let fields
		try {
			fields = build(t)
		} catch {
			return []
		}
		return Array.isArray(fields) ? fields.filter((field) => field && typeof field.label === 'string').map((field) => [name, field.key, field.label]) : []
	})

describe('admin field labels', () => {
	it('the shared mailbox folder reads Map once the section translates it', () => {
		const folder = labels.find(([, key]) => key === 'email_imap_folder')
		expect(t('dossiq', folder[2])).toBe('Map')
	})

	it.each(labels.filter(([, key]) => key !== 'email_imap_folder'))('%s %s reads the same after the library translates it', (name, key, label) => {
		expect(t('dossiq', label)).toBe(label)
	})
})
