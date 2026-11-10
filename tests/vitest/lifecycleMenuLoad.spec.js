// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation tests for the lifecycle menu's `load`
 * (src/dialogs/CaseLifecycleMenuDialog.vue): the three reads, how fulfilled,
 * empty and refused answers land on the menu, and the preset act.
 *
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
 */

import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { buildActsMenu } from '../../src/utils/caseActsMenu.js'

vi.mock('@nextcloud/dialogs', () => ({ showWarning: vi.fn(), showSuccess: vi.fn(), showError: vi.fn() }))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))

const { methods } = (await import('../../src/dialogs/CaseLifecycleMenuDialog.vue')).default

const LIFECYCLE = {
	canSuspend: true,
	caseType: 'ct-1',
	pauseReasons: [{ key: 'k', name: 'Wacht' }],
	requesterReminder: { due: '2026-12-01' },
}

/**
 * A stand-in for the component instance `load` runs on.
 *
 * @param {string} act The preset act.
 * @return {object} The instance.
 */
function instance(act = '') {
	return {
		targetCaseId: 'case 1',
		act,
		actDialogs: {},
		loading: true,
		entries: null,
		choose: vi.fn(),
		withOwnDialogs: methods.withOwnDialogs,
	}
}

/**
 * Answer the three menu reads, by endpoint.
 *
 * @param {object} answers Per endpoint suffix, a value or an Error.
 */
function answer(answers) {
	axios.get.mockImplementation((url) => {
		const key = Object.keys(answers).find((suffix) => url.endsWith(suffix))
		const value = key === undefined ? { data: null } : answers[key]
		return value instanceof Error ? Promise.reject(value) : Promise.resolve(value)
	})
}

describe('lifecycle menu load', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('stops loading without a case and reads nothing', async () => {
		const vm = { ...instance(), targetCaseId: '' }
		await methods.load.call(vm)
		expect(vm.loading).toBe(false)
		expect(axios.get).not.toHaveBeenCalled()
	})

	it('reads the three endpoints in order and fills the menu', async () => {
		const transitions = [{ id: 't1', name: 'Naar besluit', toStatusName: 'Besluit', guardsPassed: true }]
		const acts = { acts: [] }
		answer({
			'/available-transitions': { data: { transitions } },
			'/lifecycle': { data: LIFECYCLE },
			'/acts': { data: acts },
		})
		const vm = instance()
		await methods.load.call(vm)
		expect(axios.get.mock.calls.slice(0, 3).map((call) => call[0])).toEqual([
			'/index.php/apps/dossiq/api/case/case%201/available-transitions',
			'/index.php/apps/dossiq/api/case/case%201/lifecycle',
			'/index.php/apps/dossiq/api/case/case%201/acts',
		])
		expect(vm.entries).toEqual(buildActsMenu({ transitions, state: LIFECYCLE, acts }))
		expect(vm.caseType).toBe('ct-1')
		expect(vm.pauseReasons).toBe(LIFECYCLE.pauseReasons)
		expect(vm.reminder).toBe(LIFECYCLE.requesterReminder)
		expect(vm.awaiting).toEqual([])
		expect(vm.loading).toBe(false)
		expect(vm.choose).not.toHaveBeenCalled()
	})

	it('falls back on every refused read', async () => {
		answer({
			'/available-transitions': new Error('down'),
			'/lifecycle': new Error('down'),
			'/acts': new Error('down'),
		})
		const vm = instance()
		await methods.load.call(vm)
		expect(vm.entries).toEqual(buildActsMenu({ transitions: [], state: null, acts: null }))
		expect(vm.caseType).toBe('')
		expect(vm.pauseReasons).toEqual([])
		expect(vm.reminder).toBeNull()
		expect(vm.loading).toBe(false)
	})

	it('treats empty answers like missing data', async () => {
		answer({
			'/available-transitions': {},
			'/lifecycle': { data: { pauseReasons: 'not a list' } },
			'/acts': {},
		})
		const vm = instance()
		await methods.load.call(vm)
		expect(vm.entries).toEqual(buildActsMenu({ transitions: [], state: { pauseReasons: 'not a list' }, acts: null }))
		expect(vm.caseType).toBe('')
		expect(vm.pauseReasons).toEqual([])
		expect(vm.reminder).toBeNull()
	})

	it('opens the preset act when it is on offer and enabled', async () => {
		answer({ '/lifecycle': { data: LIFECYCLE }, '/acts': { data: { acts: [] } } })
		const vm = instance('suspend')
		await methods.load.call(vm)
		const suspend = vm.entries.find((entry) => entry.id === 'suspend')
		expect(suspend).toBeDefined()
		expect(vm.choose).toHaveBeenCalledWith(suspend)
	})

	it('does not open a preset act that is not on offer', async () => {
		answer({ '/lifecycle': { data: LIFECYCLE }, '/acts': { data: { acts: [] } } })
		const vm = instance('promote-to-nothing')
		await methods.load.call(vm)
		expect(vm.choose).not.toHaveBeenCalled()
	})
})
