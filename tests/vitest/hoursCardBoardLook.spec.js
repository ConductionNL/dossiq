// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqZaak/DqZaakMeer "Uren registreren": humaniq's hours leaf in the board
 * look, the week line naming the case.
 *
 * @spec openspec/changes/hours-card-board-look/specs/case-page/spec.md
 */
import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { buildProfiledManifest } from '../../src/utils/structureProfile.js'

const ROOT = path.resolve(__dirname, '../..')
const readJson = (...parts) => JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))
const fragments = fs.readdirSync(path.join(ROOT, 'src', 'manifest.d')).filter((name) => name.endsWith('.json')).sort().map((name) => readJson('src', 'manifest.d', name))
const simple = buildProfiledManifest(buildManifest, readJson('src', 'manifest.json'), fragments, readJson('src', 'menu-layout.simple.json')).pages.find((page) => page.id === 'CaseDetail').config

describe('DqZaak Uren registreren', () => {
	it('places humaniq\'s hours leaf in the board look, naming the case', () => {
		const hours = simple.widgets.find((widget) => widget.id === 'case-kpis-hours')
		expect(hours).toMatchObject({ type: 'integration', integrationId: 'humaniq-hours', title: 'Record hours' })
		expect(hours.props).toEqual({ objectNoun: 'this case', compactHeader: true, sourceNote: true })
	})
})
