/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case list's side panel counts the list under its title ("48 zaken, 8 op
 * deze pagina", DqFilterBeheren and the other filter boards) instead of the
 * case schema's English description (nextcloud-vue index-sidebar-count-subtitle).
 *
 * @spec openspec/specs/case-management/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const cases = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8')).pages.find((page) => page.id === 'Cases')
const nl = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n', 'nl.json'), 'utf8')).translations

describe('the case list side panel', () => {
	it('counts the cases under its title', () => {
		expect(cases.config.sidebar.subtitle).toEqual({
			singular: '{total} case, {shown} on this page',
			plural: '{total} cases, {shown} on this page',
		})
	})

	it('reads the board\'s Dutch', () => {
		expect(nl['_{total} case, {shown} on this page_::_{total} cases, {shown} on this page_']).toEqual([
			'{total} zaak, {shown} op deze pagina',
			'{total} zaken, {shown} op deze pagina',
		])
	})
})
