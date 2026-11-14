/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The team queue's rows read as DqTeamwachtrij writes them: "2026-0076 ·
 * Dagblad Drechtsteden · Ontvangen · prioriteit hoog", the status by its name
 * and the priority as a word, where live printed the stored "normal". Needs
 * nextcloud-vue `{field|formatter}` in a row's subtitleTemplate.
 *
 * @spec openspec/specs/case-lists/spec.md
 */
import fs from 'fs'
import path from 'path'
import { describe, expect, it, vi } from 'vitest'

vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({}) }))
vi.mock('../../src/store/modules/deelzaak.js', () => ({ useDeelzaakStore: () => ({}) }))

const { default: formatters } = await import('../../src/services/formatters.js')

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8'))
const nl = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n/nl.json'), 'utf8')).translations
const queue = manifest.pages.find((page) => page.id === 'Cases').config.defaultSavedViews.find((view) => view.slug === 'team-queue')

describe('the team queue row line', () => {
	it('names the status and the priority in words', () => {
		expect(queue.overrides.listConfig.subtitleTemplate).toBe('{identifier} · {initiatorDisplayName} · {status|statusTypeName} · {priority|priorityPhrase}')
	})

	it('says the priority as a phrase, and nothing for an unknown value', () => {
		expect(formatters.priorityPhrase('high')).toBe('priority high')
		expect(formatters.priorityPhrase('normal')).toBe('priority normal')
		expect(formatters.priorityPhrase('')).toBe('')
		expect(formatters.priorityPhrase('whatever')).toBe('')
	})

	it('reads the phrases in Dutch', () => {
		expect(nl['priority low']).toBe('prioriteit laag')
		expect(nl['priority normal']).toBe('prioriteit normaal')
		expect(nl['priority high']).toBe('prioriteit hoog')
		expect(nl['priority urgent']).toBe('prioriteit urgent')
	})
})
