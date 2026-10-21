/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What dossiq needs, and what this instance has: the server's reading
 * (`Prerequisites::check()`) as CnReadinessChecklist items. The checklist
 * draws each state as a word (present, missing) and names the unmet blocking
 * items; this pins what each row says and which rows block.
 *
 * @spec openspec/changes/declared-prerequisites/specs/admin-settings/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { prerequisiteGroups, prerequisiteItems } from '../../src/utils/prerequisiteItems.js'

const t = (app, text) => text
const adminRoot = fs.readFileSync(path.resolve(__dirname, '../../src/views/settings/AdminRoot.vue'), 'utf8')

/**
 * A server reading, in the shape `Prerequisites::check()` answers with.
 *
 * @param {object} overrides Blocks to replace.
 * @return {object} The reading.
 */
function reading(overrides = {}) {
	return {
		php: { required: '8.3', running: '8.3.6', present: true },
		nextcloud: { min: '32', max: '34', running: '33', present: true },
		extensions: [
			{ name: 'json', why: 'Reads every payload.', present: true },
			{ name: 'zip', why: 'Packs an export.', present: false },
		],
		apps: {
			required: [{ id: 'openregister', unlocks: 'Stores every case.', present: true }],
			optional: [
				{ id: 'humaniq', unlocks: 'Books the hours.', present: false },
				{ id: 'docudesk', name: 'filinq', unlocks: 'Documents.', present: true },
			],
		},
		...overrides,
	}
}

/**
 * @param {object|null} state A reading.
 * @return {Record<string, object>} The items by id.
 */
const items = (state) => Object.fromEntries(prerequisiteItems(state, t).map((item) => [item.id, item]))

describe('The Prerequisites block', () => {
	it('says missing about an extension the server reported absent, and why it is needed', () => {
		const rows = items(reading())
		expect(rows['ext-zip']).toMatchObject({ met: false, explanation: 'Packs an export.', group: 'extensions' })
		expect(rows['ext-json'].met).toBe(true)
	})

	it('lists an absent optional app with what it would have added, and never blocks on it', () => {
		const rows = items(reading())
		expect(rows['app-humaniq']).toMatchObject({ met: false, explanation: 'Books the hours.', blocking: false, group: 'optional' })
	})

	it('blocks only on a required app', () => {
		const rows = items(reading({ apps: { required: [{ id: 'openregister', unlocks: 'x', present: false }], optional: [] } }))
		expect(rows['app-openregister']).toMatchObject({ met: false, blocking: true, group: 'required' })
	})

	it('names the running PHP and Nextcloud beside what is required', () => {
		const rows = items(reading())
		expect(rows.php.label).toContain('8.3')
		expect(rows.php.explanation).toContain('8.3.6')
		expect(rows.nextcloud.label).toBe('Nextcloud 32 to 34')
		expect(rows.nextcloud.explanation).toContain('33')
		expect(items(reading({ nextcloud: { min: '32', max: '34', running: '31', present: false } })).nextcloud.met).toBe(false)
	})

	it('shows the name the server gives, not the id it looked up', () => {
		expect(items(reading())['app-docudesk'].label).toBe('filinq')
	})

	it('gives an empty reading the platform rows unmet rather than throwing', () => {
		expect(Object.keys(items(null))).toEqual(['php', 'nextcloud'])
		expect(items(null).php.met).toBe(false)
	})

	it('is drawn in four groups, the states said present and missing, the blocking ones named', () => {
		expect(prerequisiteGroups(t).map((group) => group.id)).toEqual(['platform', 'extensions', 'required', 'optional'])
		expect(adminRoot).toContain('data-testid="prerequisites-block"')
		expect(adminRoot).toContain(":statusLabels=\"{ met: t('dossiq', 'present'), unmet: t('dossiq', 'missing') }\"")
		expect(adminRoot).toContain(":blockingText=\"t('dossiq', 'Dossiq cannot run until this is installed:')\"")
		expect(adminRoot).toContain("loadState('dossiq', 'prerequisites', {})")
	})
})
