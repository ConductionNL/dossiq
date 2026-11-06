/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaken, DqZakenSorteren and DqFilterInMenu: the bulk band reads "Met de
 * geselecteerde zaken". The case list names the plural (`bulkNoun`), which
 * dossiq's catalogue translates.
 *
 * @spec openspec/specs/case-bulk-status-transition/spec.md
 */

import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { buildProfiledManifest } from '../../src/utils/structureProfile.js'

const ROOT = path.resolve(__dirname, '../..')
const readJson = (...parts) => JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))

const manifest = readJson('src', 'manifest.json')
const fragments = fs.readdirSync(path.join(ROOT, 'src', 'manifest.d'))
	.filter((name) => name.endsWith('.json'))
	.sort()
	.map((name) => readJson('src', 'manifest.d', name))

describe('the bulk band of the case list in the simple profile', () => {
	const built = buildProfiledManifest(buildManifest, manifest, fragments, readJson('src', 'menu-layout.simple.json'))
	const cases = built.pages.find((page) => page.id === 'Cases')

	it('names the cases', () => {
		expect(cases.config.bulkNoun).toBe('cases')
	})

	it('reads "zaken" in Dutch', () => {
		expect(readJson('l10n', 'nl.json').translations.cases).toBe('zaken')
	})
})
