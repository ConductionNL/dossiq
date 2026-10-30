/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Cases list in the simple profile says "{shown} van {total} lopende
 * zaken" (open cases), so it opens on the open cases: isFinalStatus false as a
 * removable default filter ("Status: lopend" on the Active line, board DqZaken).
 * On :8080 (REFRESH-3) /apps/dossiq/cases listed closed cases (Aanleg fietspad
 * Lindelaan, Melding kapot speeltoestel Oosthavenplein, both isFinalStatus
 * true) under that line, because its default lens "All" filters drafts and
 * hidden statuses only.
 *
 * @spec openspec/specs/case-search-and-lists/spec.md#requirement-the-open-cases-list-opens-on-open-cases
 */
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const simple = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'menu-layout.simple.json'), 'utf8'))
const cases = simple.pages.find((page) => page.id === 'Cases').config

describe('the Cases list that counts open cases', () => {
	it('says open cases', () => {
		expect(cases.countText).toMatch(/open cases/)
		expect(Object.values(cases.countTextByView || {}).every((text) => /open cases/.test(text))).toBe(true)
	})

	it('opens on the open cases, as a filter the reader can remove', () => {
		expect(cases.defaultFilters).toMatchObject({ isFinalStatus: false })
	})

	it('keeps open cases only under a link that names its own filters (Ruben, 29 October)', () => {
		expect(cases.heldDefaultFilters).toEqual(['isFinalStatus'])
	})

	it('opens on the reader\'s teams, as the line says ("in uw teams")', () => {
		expect(cases.countText).toMatch(/in your teams/)
		expect(cases.defaultFilters.assignedGroup).toBe('@myTeam')
	})
})
