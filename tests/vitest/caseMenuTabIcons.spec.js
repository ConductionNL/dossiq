// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqZaakMeer: every case tab in the Acties menu carries an icon dossiq
 * registers, so the menu draws it in front of the label.
 *
 * @spec openspec/changes/case-menu-tabs-carry-icons/specs/case-page/spec.md
 */
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const layout = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'menu-layout.simple.json'), 'utf8'))
const icons = fs.readFileSync(path.join(ROOT, 'src', 'icons.js'), 'utf8')

function caseTabs(node) {
	if (Array.isArray(node)) {
		return node.map(caseTabs).find(Boolean) || null
	}
	if (node && typeof node === 'object') {
		if (node.ariaLabel === 'Case details' && Array.isArray(node.tabs)) {
			return node.tabs
		}
		return Object.values(node).map(caseTabs).find(Boolean) || null
	}
	return null
}

describe('case tabs in the Acties menu', () => {
	const overflow = caseTabs(layout).filter((tab) => tab.overflow === true)

	it('each declare an icon', () => {
		expect(overflow.length).toBeGreaterThan(0)
		for (const tab of overflow) {
			expect(tab.icon, tab.label).toMatch(/^[A-Z]\w+$/)
		}
	})

	it('only use icons dossiq registers', () => {
		for (const tab of overflow) {
			expect(icons, `${tab.label}: ${tab.icon}`).toMatch(new RegExp(`\\b${tab.icon}\\b`))
		}
	})
})
