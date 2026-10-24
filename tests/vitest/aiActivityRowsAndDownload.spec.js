/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaakAssistent "Wat de assistent op deze zaak deed": hermiq's records
 * about the case, read through its subject route, downloadable for the AI
 * oversight role only, and no Add (the list owns no records).
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const simple = read('src/menu-layout.simple.json').pages.find((page) => page.id === 'CaseDetail')
const list = simple.configAppend.widgets.find((widget) => widget.id === 'case-ai-activity')
const nl = read('l10n/nl.json').translations

describe('the AI oversight card', () => {
	it('reads the records about this case from hermiq', () => {
		expect(list.content.endpointSource.url).toBe('/apps/hermiq/api/ai-oversight/subject')
		expect(list.content.endpointSource.params.subjectId).toBe('@objectId')
	})

	it('offers no Add', () => {
		expect(list.content.allowCreate).toBe(false)
	})

	it('downloads the records for the AI oversight role only', () => {
		expect(list.content.export).toEqual({
			label: 'Download',
			url: '/apps/hermiq/api/ai-oversight/subject/export',
			params: { originApp: 'dossiq', subjectId: '@objectId' },
			group: 'ai-oversight',
		})
		expect(nl[list.content.export.label]).toBe('Downloaden')
		expect(nl[list.content.footnote]).toBe('Downloaden mag alleen wie de rol AI-toezicht heeft.')
	})
})
