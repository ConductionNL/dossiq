// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqZaak "Prioriteit": the priority as a header pill, where it came from
 * as label-left facts rows, the line on how it is set, and the override kept
 * on the card.
 *
 * @spec openspec/changes/priority-card-reads-like-the-board/specs/case-page/spec.md
 */
import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { buildProfiledManifest } from '../../src/utils/structureProfile.js'

const ROOT = path.resolve(__dirname, '../..')
const readJson = (...parts) => JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))

const fragments = fs
	.readdirSync(path.join(ROOT, 'src', 'manifest.d'))
	.filter((name) => name.endsWith('.json'))
	.sort()
	.map((name) => readJson('src', 'manifest.d', name))
const simple = buildProfiledManifest(
	buildManifest,
	readJson('src', 'manifest.json'),
	fragments,
	readJson('src', 'menu-layout.simple.json'),
).pages.find((page) => page.id === 'CaseDetail').config

const card = simple.widgets.find((widget) => widget.id === 'case-priority-card')
const nl = readJson('l10n', 'nl.json').translations

describe('DqZaak Prioriteit card', () => {
	it('is a facts card with the priority as its header pill', () => {
		expect(card.type).toBe('facts')
		expect(card.content.headerPill.path).toBe('priority')
		expect(card.content.headerPill.variantMap.high).toBe('warning')
	})

	it('reads derived, overridden and floor as rows, the override "No" when unset', () => {
		const rows = card.content.fields.filter((field) => field.label)
		expect(rows.map((row) => row.label)).toEqual(['Derived', 'Overridden', 'Floor'])
		const derived = rows[0].parts.flatMap((part) => (part.parts ? part.parts : [part])).map((part) => part.path)
		expect(derived).toEqual(['priorityDerived', 'impact', 'urgency'])
		expect(rows[1].emptyValue).toBe('No')
		expect(card.content.fields.at(-1)).toMatchObject({ as: 'line', text: 'The priority follows from the matrix. Override it with a reason.' })
	})

	it('keeps overriding on the card, saving only the override and its reason', () => {
		const action = card.content.actions.find((entry) => entry.id === 'priority-override')
		expect(action).toMatchObject({ type: 'open-form', endpoint: { method: 'PATCH', url: '/apps/openregister/api/objects/dossiq/case/@source.id' } })
		expect(Object.keys(action.formProperties)).toEqual(['priorityOverride', 'priorityOverrideReason'])
	})

	it('has the board words in Dutch', () => {
		for (const key of ['Derived', 'Overridden', 'Floor', 'No', 'from impact', 'and urgency', 'Override priority', 'The priority follows from the matrix. Override it with a reason.']) {
			expect(nl[key], key).toBeTruthy()
		}
		expect(nl.Derived).toBe('Afgeleid')
		expect(nl.Floor).toBe('Ondergrens')
	})
})
