/**
 * The registration form buildiq serves: how dossiq asks for it, what a create
 * writes from it, and that everything stays as before when nothing answers.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/competitor-parity-2026-09/tasks.md#15
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const get = vi.fn()
const saved = []

vi.mock('@nextcloud/axios', () => ({ default: { get: (...args) => get(...args) } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text, translatePlural: (app, one, more) => more }))
vi.mock('@conduction/nextcloud-vue', () => ({
	candidateWithoutBlanks: (candidate) => candidate,
	duplicateAffordances: () => ({ show: false }),
	openDuplicateWarning: async () => null,
	useObjectStore: () => ({ saveObject: async (type, candidate) => { saved.push([type, candidate]); return { id: 'saved' } } }),
}))
vi.mock('../../src/services/duplicateCheckApi.js', () => ({
	checkForDuplicates: async () => ({ matches: [], checked: true }),
	fetchMatchedCases: async () => [],
}))
vi.mock('../../src/services/intakeTriageApi.js', () => ({
	fetchIntakeRequirements: async () => ({ duplicatePolicy: 'warn', mayOverrideDuplicates: true }),
}))
vi.mock('../../src/services/initiatorSearch.js', () => ({ initiatorChoice: async () => ({}) }))

const { DESK_ASK, INTAKE_CHANNELS, fetchServedRegistrationForm, withServedForm } = await import('../../src/services/servedRegistrationForm.js')
const { createCaseWithDuplicateCheck } = await import('../../src/services/createCaseWithDuplicateCheck.js')

const served = {
	id: 'c1d2',
	name: 'Aanvraag bouwvergunning (balie)',
	channel: 'balie',
	fields: [{ key: 'applicantName', section: 'applicant', required: true }],
	presets: [{ key: 'intakeChannel', value: 'balie' }, { key: 'confidentiality', value: 'intern' }],
}

beforeEach(() => {
	get.mockReset()
	saved.length = 0
})

describe('fetchServedRegistrationForm', () => {
	it('asks the leaf on the case type as the desk and answers the first form', async () => {
		get.mockResolvedValue({ data: { items: [served, { id: 'other' }], total: 2, drafts: [] } })

		expect(await fetchServedRegistrationForm('ct-1')).toEqual(served)
		expect(get).toHaveBeenCalledWith(
			'/apps/openregister/api/objects/dossiq/caseType/ct-1/integrations/buildiq-registration-form',
			{ params: { audience: 'employee', channel: 'balie' } },
		)
		expect(DESK_ASK).toEqual({ audience: 'employee', channel: 'balie' })
	})

	it('answers null when nothing is served, the leaf is absent, or no case type is chosen', async () => {
		get.mockResolvedValueOnce({ data: { items: [], total: 0 } })
		expect(await fetchServedRegistrationForm('ct-1')).toBeNull()

		get.mockRejectedValueOnce(Object.assign(new Error('404'), { response: { status: 404 } }))
		expect(await fetchServedRegistrationForm('ct-1')).toBeNull()

		expect(await fetchServedRegistrationForm('')).toBeNull()
		expect(await fetchServedRegistrationForm(undefined)).toBeNull()
		expect(get).toHaveBeenCalledTimes(2)
	})
})

describe('withServedForm', () => {
	it('writes the hidden presets and the served channel where the handler left the field empty', () => {
		expect(withServedForm({ title: 'Dakkapel', caseType: 'ct-1', confidentiality: '' }, served)).toEqual({
			title: 'Dakkapel',
			caseType: 'ct-1',
			confidentiality: 'intern',
			intakeChannel: 'balie',
		})
	})

	it('never replaces what the handler answered', () => {
		expect(withServedForm({ intakeChannel: 'phone', confidentiality: 'vertrouwelijk' }, served)).toEqual({
			intakeChannel: 'phone',
			confidentiality: 'vertrouwelijk',
		})
	})

	it('writes the channel without presets, and leaves the case alone with no form', () => {
		expect(withServedForm({ title: 'A' }, { channel: 'website' })).toEqual({ title: 'A', intakeChannel: 'website' })
		const candidate = { title: 'A' }
		expect(withServedForm(candidate, null)).toBe(candidate)
		expect(withServedForm({ title: 'A' }, { presets: [{ key: '', value: 'x' }, { key: 'note', value: '' }] })).toEqual({ title: 'A' })
	})

	it('replaces the schema default with the served channel, and never writes a channel outside the enum', () => {
		expect(withServedForm({ intakeChannel: 'manual' }, served).intakeChannel).toBe('balie')
		expect(withServedForm({ title: 'A' }, { channel: 'desk', presets: [{ key: 'intakeChannel', value: 'desk' }] })).toEqual({ title: 'A' })
		expect(withServedForm({ intakeChannel: 'manual' }, { channel: 'desk' })).toEqual({ intakeChannel: 'manual' })
	})

	it('keeps the channel list in step with the case schema', () => {
		const register = JSON.parse(readFileSync(resolve(__dirname, '../../lib/Settings/dossiq_register.json'), 'utf8'))
		expect([...INTAKE_CHANNELS]).toEqual(register.components.schemas.case.properties.intakeChannel.enum)
		expect(register.components.schemas.case.properties.intakeChannel.default).toBe('manual')
	})

	it('does not write a preset into the visible fields it was removed from', () => {
		expect(served.fields.some((field) => served.presets.some((preset) => preset.key === field.key))).toBe(false)
	})
})

describe('createCaseWithDuplicateCheck with a served form', () => {
	it('saves the case carrying the served channel and presets', async () => {
		get.mockResolvedValue({ data: { items: [served] } })

		await createCaseWithDuplicateCheck({ title: 'Dakkapel', caseType: 'ct-1' }, { type: 'case' })

		expect(saved).toEqual([['case', { title: 'Dakkapel', caseType: 'ct-1', intakeChannel: 'balie', confidentiality: 'intern' }]])
	})

	it('saves the manifest form unchanged when buildiq answers nothing', async () => {
		get.mockRejectedValue(new Error('Integration not found'))

		await createCaseWithDuplicateCheck({ title: 'Dakkapel', caseType: 'ct-1' }, { type: 'case' })

		expect(saved).toEqual([['case', { title: 'Dakkapel', caseType: 'ct-1' }]])
	})
})
