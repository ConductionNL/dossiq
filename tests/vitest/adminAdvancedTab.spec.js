/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The admin page's Geavanceerd tab (coordinator decision 24 Oct, board
 * DqBeheerGeavanceerd): the settings set once or by a specialist leave System
 * and Integrations, which then end as their boards draw them, and keep every
 * feature one tab further.
 *
 * @spec openspec/specs/admin-settings/spec.md#requirement-the-specialist-settings-sit-on-their-own-tab
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const admin = fs.readFileSync(path.join(ROOT, 'src/views/settings/AdminRoot.vue'), 'utf8')
const connections = JSON.parse(fs.readFileSync(path.join(ROOT, 'lib/Settings/connections.json'), 'utf8'))
const nl = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n/nl.json'), 'utf8')).translations

/**
 * @param {string} tab The tab id.
 * @return {Array<string>} The section ids in that tab's template, in order.
 */
function sectionsOf(tab) {
	const at = admin.indexOf(`<template #tab-${tab}>`)
	const end = admin.indexOf('\n\t\t</template>', at)
	return [...admin.slice(at, end).matchAll(/\n\t\t\t\tid="(section-[\w-]+)"/g)].map((m) => m[1])
}

const ADVANCED = [
	'section-zgw', 'section-kcc', 'section-dmn', 'section-financial', 'section-stuf', 'section-store',
	'section-first-run', 'section-prerequisites', 'section-shipped-configuration', 'section-queue-urgency',
	'section-archival', 'section-woo-reports',
]

describe('the Geavanceerd admin tab', () => {
	it('is the last tab, read "Geavanceerd"', () => {
		expect(admin).toMatch(/\{ id: 'substitution', label: this\.t\('dossiq', 'Substitution'\) \},\n(?:\t+\/\/[^\n]*\n)*\t+\{ id: 'advanced', label: this\.t\('dossiq', 'Advanced'\) \},\n\t+\]\)/)
		expect(nl.Advanced).toBe('Geavanceerd')
	})

	it('holds the specialist sections, which System and Integrations no longer carry', () => {
		const advanced = sectionsOf('advanced')
		for (const id of ADVANCED) {
			expect(advanced).toContain(id)
		}
		expect(sectionsOf('system')).toEqual(['section-configuration', 'section-menu-structure', 'section-background-service-account', 'section-mailbox', 'section-ai', 'section-consultation', 'section-search-index'])
		expect(sectionsOf('integrations')).toEqual(['section-connections', 'section-kadaster'])
	})

	it('sends a connection row to its card on the tab it moved to', () => {
		const urls = connections.connections.map((c) => c.settingsUrl).filter(Boolean)
		for (const id of ['zgw', 'stuf', 'kcc', 'dmn', 'store', 'financial']) {
			expect(urls).toContain(`/settings/admin/dossiq?tab=advanced#section-${id}`)
		}
	})
})

describe('no stub ships on the admin page', () => {
	/**
	 * @spec openspec/changes/admin-dead-settings-cards-removed/specs/admin-settings/spec.md#requirement-no-admin-card-promises-what-it-does-not-do
	 */
	it('has no "this panel will host" placeholder and no mandate settings card nothing reads', () => {
		const fields = fs.readFileSync(path.join(ROOT, 'src/views/settings/adminFields.js'), 'utf8')
		expect(fields).not.toMatch(/This panel will host/)
		expect(admin).not.toContain('id="section-mandate-settings"')
	})
})
