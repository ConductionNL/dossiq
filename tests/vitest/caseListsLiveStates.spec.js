// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The case list's live states: the board follows changes others make
 * (DqWerkbord), a bulk move's partial result stays above the list
 * (DqZaken), and the Woo team's open cases carry the label the board filters
 * on (DqZaken "Label: wijk-noord").
 *
 * @spec openspec/specs/realtime-updates-ui/spec.md
 */

import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const emit = vi.fn()
vi.mock('@nextcloud/event-bus', () => ({ emit: (...args) => emit(...args), subscribe: vi.fn(), unsubscribe: vi.fn() }))
vi.mock('../../src/dialogs/BulkTransitionDialog.vue', () => ({ default: {} }))
vi.mock('../../src/dialogs/ReassignSelectionDialog.vue', () => ({ default: {} }))
vi.mock('../../src/services/bulkJobApi.js', () => ({ countMatchingCases: vi.fn() }))

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const simpleCases = read('src/menu-layout.simple.json').pages.find((entry) => entry.id === 'Cases' && entry.config)
const routes = fs.readFileSync(path.join(ROOT, 'appinfo/routes.php'), 'utf8')

describe('the board follows changes others make (DqWerkbord)', () => {
	const realtime = simpleCases.config.realtime

	it('polls dossiq\'s change feed while the board view is open', () => {
		expect(realtime).toMatchObject({ url: '/apps/dossiq/api/cases/changes', views: ['board'] })
		expect(realtime.interval).toBeGreaterThanOrEqual(10000)
		expect(routes).toContain('/api/cases/changes')
	})

	it('names who changed it, in Dutch as the board writes it', () => {
		const nl = read('l10n/nl.json').translations
		expect(nl[realtime.titleTemplate]).toBe('Zojuist bijgewerkt door {actor}')
	})
})

describe('a bulk move\'s result stays above the list (DqZaken)', () => {
	beforeEach(() => emit.mockClear())

	it('raises the notice the job answered on the page notice channel', async () => {
		const { raiseJobNotice } = await import('../../src/utils/caseBulkActions.js')
		const notice = { variant: 'warning', title: '2 zaken naar In behandeling verplaatst.', text: '1 zaak is verplaatst zonder al zijn automatische acties.' }
		raiseJobNotice({ id: 'job-1', notice })
		expect(emit).toHaveBeenCalledWith('cn:page:notice', { notice })
	})

	it('reads the notice from the job outcome too, and raises nothing without one', async () => {
		const { noticeOfJob, raiseJobNotice } = await import('../../src/utils/caseBulkActions.js')
		expect(noticeOfJob({ outcome: { notice: { title: 'x' } } })).toEqual({ title: 'x' })
		expect(noticeOfJob({ notice: ['not', 'a', 'notice'] })).toBeNull()
		raiseJobNotice({ id: 'job-2' })
		raiseJobNotice(null)
		expect(emit).not.toHaveBeenCalled()
	})
})

describe('the Woo team\'s open cases carry the label wijk-noord (DqZaken)', () => {
	const cases = [
		...read('lib/Settings/example-sets/zuiddrecht.json').objects,
		...read('lib/Settings/example-sets/zuiddrecht.d/20-team-queue.json').objects,
	].filter((entry) => entry.schema === 'case' && (entry.data || entry).assignedGroup === 'zuiddrecht-woo-en-informatie')

	it('tags every one of them, so the filtered list keeps the rows the board draws', () => {
		expect(cases).toHaveLength(8)
		for (const entry of cases) {
			expect((entry.data || entry).tags).toContain('wijk-noord')
		}
	})
})
