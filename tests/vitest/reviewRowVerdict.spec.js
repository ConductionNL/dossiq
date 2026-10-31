// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaakDocumenten: on a case type that reviews its documents each row shows
 * its verdict as a pill and a "Beoordelen" button, primary while the document
 * is open, which opens the assessment dialog with that document chosen.
 * DqDocumentenBeheer (any other case) shows neither.
 *
 * @spec openspec/specs/files-review-row-verdict/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it, vi } from 'vitest'

vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({ collections: {} }) }))
vi.mock('../../src/store/modules/deelzaak.js', () => ({ useDeelzaakStore: () => ({ subCaseCounts: {} }) }))

const panels = require('./helpers/casePanels.js')
const { default: formatters } = await import('../../src/services/formatters.js')
const nl = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../l10n/nl.json'), 'utf8')).translations

const REVIEW = '@context.caseType.documentReview.enabled'
const props = panels.caseWidget('case-files').props

describe('the verdict per document row', () => {
	it('is a badge column on a review case only, in the verdict tones', () => {
		const column = props.columns.find((entry) => entry.key === 'reviewVerdict')
		expect(column).toMatchObject({ source: 'row', widget: 'badge', formatter: 'optionLabel', when: REVIEW })
		expect(column.colorMap).toMatchObject({ openbaar: 'success', deels_openbaar: 'warning', niet_openbaar: 'error' })
		for (const label of Object.values(column.formatterOptions.labels)) {
			expect(nl[label], label).toBeTruthy()
		}
	})

	it('names a verdict through the optionLabel formatter, the code when it has no label', () => {
		const options = { labels: { open: 'Still to be assessed' } }
		expect(formatters.optionLabel('open', {}, {}, options)).toBe('Still to be assessed')
		expect(formatters.optionLabel('other', {}, {}, options)).toBe('other')
		expect(formatters.optionLabel('', {}, {}, options)).toBe('')
	})
})

describe('the Beoordelen button on a document row', () => {
	it('opens the assessment dialog with that document, primary while it is open, on a review case only', () => {
		const button = props.rowButton
		expect(button).toMatchObject({ type: 'open-modal', target: 'DocumentAssessDialog', when: REVIEW, rowProps: { documentId: 'id' } })
		expect(button.primaryWhen).toEqual({ key: 'reviewVerdict', values: ['open'] })
		expect(nl[button.label]).toBe('Beoordelen')
	})
})
