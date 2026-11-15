/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqBeheerinstellingen and DqBeheerGeavanceerd set a card's description 6px
 * under its title (the content 16px under it); the library's board look
 * defaults to 4px, so the admin page hands the shell its gap.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const admin = fs.readFileSync(path.resolve(__dirname, '../../src/views/settings/AdminRoot.vue'), 'utf8')

describe('the admin settings cards as the boards space them', () => {
	it('hands the settings shell a 6px gap between a card title and its description', () => {
		const shell = admin.slice(admin.indexOf('<CnAdminSettingsShell'), admin.indexOf('>', admin.indexOf('<CnAdminSettingsShell')))
		expect(shell).toContain(':descGap="6"')
	})
})
