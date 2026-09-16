/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Starting a case from a saved template, and recording which one.
 */

import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it } from 'vitest'
import {
	listCaseTemplates,
	listContentTemplates,
	startFromTemplate,
} from '../../src/services/templateApi.js'

const BASE = '/index.php/apps/dossiq'

describe('starting a case from a template', () => {
	beforeEach(() => {
		axios.get.mockReset()
		axios.post.mockReset()
	})

	it('asks for the templates of the case type the handler is on', async () => {
		axios.get.mockResolvedValue({ data: { items: [], total: 0 } })

		await listCaseTemplates('ct-vth')

		expect(axios.get).toHaveBeenCalledWith(`${BASE}/api/case-templates`, {
			params: { caseType: 'ct-vth' },
		})
	})

	it('asks for every template when no case type is named', async () => {
		axios.get.mockResolvedValue({ data: { items: [], total: 0 } })

		await listCaseTemplates()

		expect(axios.get).toHaveBeenCalledWith(`${BASE}/api/case-templates`, {
			params: { caseType: '' },
		})
	})

	it('starts the case and hands back the template it came from', async () => {
		axios.post.mockResolvedValue({
			data: { ok: true, case: { id: 'case-9', startedFromTemplate: 'tpl-1' } },
		})

		const result = await startFromTemplate('tpl-1', { title: 'Sloopmelding Kerkstraat 4' })

		expect(axios.post).toHaveBeenCalledWith(`${BASE}/api/case-templates/tpl-1/start`, {
			overrides: { title: 'Sloopmelding Kerkstraat 4' },
		})
		expect(result.case.startedFromTemplate).toBe('tpl-1')
	})

	it('sends an empty override block rather than nothing when the handler changed nothing', async () => {
		axios.post.mockResolvedValue({ data: { ok: true, case: {} } })

		await startFromTemplate('tpl-1')

		expect(axios.post).toHaveBeenCalledWith(`${BASE}/api/case-templates/tpl-1/start`, {
			overrides: {},
		})
	})

	it('asks the library for one kind, scoped to the case being worked on', async () => {
		axios.get.mockResolvedValue({ data: { items: [], total: 0 } })

		await listContentTemplates('task', { caseType: 'ct-bezwaar', case: 'case-1' })

		expect(axios.get).toHaveBeenCalledWith(`${BASE}/api/content-templates/task`, {
			params: { caseType: 'ct-bezwaar', case: 'case-1' },
		})
	})
})
