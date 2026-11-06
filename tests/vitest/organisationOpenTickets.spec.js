/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqOrganisaties draws "Open tickets" per organisation and DqOrganisatie a
 * tickets card. The tickets are pipelinq's, read through dossiq by the master
 * entity the organisation shares with its pipelinq client; both hide when
 * pipelinq is absent.
 *
 * @spec openspec/specs/pipelinq-consumption/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'))
const routes = fs.readFileSync(path.join(ROOT, 'appinfo', 'routes.php'), 'utf8')
const page = (id) => manifest.pages.find((entry) => entry.id === id)

describe('DqOrganisaties Open tickets', () => {
	const columns = page('Organisations').config.columns
	const column = columns.find((c) => c && c.key === 'openTickets')

	it('sits after Open cases and reads the count from dossiq by master entity', () => {
		expect(columns.findIndex((c) => c && c.key === 'openTickets')).toBe(columns.findIndex((c) => c && c.key === 'openCases') + 1)
		expect(column.aggregate).toMatchObject({ endpoint: '/apps/dossiq/api/parties/open-tickets', key: 'masterEntityRef', requiresApp: 'pipelinq', zeroLabel: 'None' })
		expect(routes).toContain("'url' => '/api/parties/open-tickets',")
	})
})

describe.each(['OrganisationDetail', 'ContactDetail'])('%s tickets card (DqOrganisatie, DqContact)', (pageId) => {
	const config = page(pageId).config
	const widget = config.widgets.find((w) => w.id === 'contact-open-tickets')

	it('lists the open tickets from dossiq, only when pipelinq is installed', () => {
		expect(widget.type).toBe('record-list')
		expect(widget.visibleWhen).toEqual({ appInstalled: 'pipelinq' })
		expect(widget.content.requiresApp).toBe('pipelinq')
		expect(widget.content.endpointSource).toMatchObject({ url: '/apps/dossiq/api/parties/open-tickets/list', params: { key: '@object.masterEntityRef' }, responsePath: 'results' })
		expect(routes).toContain("'url' => '/api/parties/open-tickets/list',")
	})

	it('is part of the Overview tab', () => {
		const tabs = config.widgets.find((w) => w.id === 'contact-panels').content.tabs
		expect(tabs[0].widgetIds).toContain('contact-open-tickets')
	})
})
