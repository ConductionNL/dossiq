/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqBeheerGeavanceerd: the path note says what the Geavanceerd tab holds, as
 * the board draws it, so it fits on the path's line beside the header buttons.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const read = (...parts) => fs.readFileSync(path.resolve(__dirname, '../..', ...parts), 'utf8')
const SOURCE = 'applies to the whole organisation; settings you set once or with a specialist'

describe('the Geavanceerd tab path note', () => {
	it('names the advanced tab\'s own note', () => {
		const root = read('src', 'views', 'settings', 'AdminRoot.vue')
		const note = root.slice(root.indexOf('breadcrumbNote() {'))
		expect(note.slice(0, note.indexOf('setupSteps()'))).toMatch(/activeTab === 'advanced'[\s\S]*?'applies to the whole organisation; settings you set once or with a specialist'/)
	})

	it('reads it in Dutch as the board does', () => {
		for (const lang of ['nl', 'nl_NL']) {
			const file = path.resolve(__dirname, '../../l10n', `${lang}.json`)
			if (!fs.existsSync(file)) {
				continue
			}
			expect(JSON.parse(fs.readFileSync(file, 'utf8')).translations[SOURCE]).toBe('geldt voor de hele organisatie; instellingen die u eenmalig of met een specialist zet')
		}
	})
})

describe('the admin cards\' notes', () => {
	it('asks the shell for the boards\' plain notes', () => {
		const root = read('src', 'views', 'settings', 'AdminRoot.vue')
		const shell = root.slice(root.indexOf('<CnAdminSettingsShell'), root.indexOf('>', root.indexOf('@tabChange')))
		expect(shell).toMatch(/\n\t\tplainNotes\n/)
	})
})
