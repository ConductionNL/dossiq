/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaakDocumenten draws each document on two lines: the name, and under it
 * its type, size and date ("PDF · 1,2 MB · 14 januari 2026": the long date with
 * the year on a review case).
 */

import { describe, expect, it } from 'vitest'
const panels = require('./helpers/casePanels.js')

describe('the case documents rows', () => {
	it('carry the type, size and date under the name', () => {
		expect(panels.caseWidget('case-files').props.nameSecondary).toBe('{type} · {size} · {modified|date}')
	})
})

describe('the second line per case', () => {
	it('is type, size and date on a case that reviews its documents, sender and date otherwise', () => {
		expect(panels.caseWidget('case-files').props.secondLine).toEqual([
			{ when: '@context.caseType.documentReview.enabled', fields: ['type', 'size', 'modified|longdate:year'] },
			{ fields: ['senderName', 'modified|date'] },
		])
	})
})

describe('the case documents toolbar', () => {
	const props = panels.caseWidget('case-files').props

	it('asks for a file as a button, as DqZaakDocumenten draws it, with no New menu', () => {
		expect(props.newMenu).toBe(false)
		expect(props.newActions).toBeUndefined()
		expect(props.headerActions).toEqual([
			{ id: 'request-file', label: 'Request a file from a party', icon: 'EmailOutline', type: 'open-modal', target: 'FileRequestDialog' },
		])
	})

	it('keeps adding files and making folders', () => {
		expect(props.uploadButton).toBe(true)
		expect(props.folderButton).toBe(true)
	})
})

describe('the drop panel (DqZaakDocumentenSlepen)', () => {
	const props = panels.caseWidget('case-files').props

	it('says the files go to this case by its number, as documents to assess on a review case', () => {
		expect(props.dropDetailValues).toEqual({ case: '@object.identifier' })
		expect(props.dropDetail).toEqual([
			{ when: '@context.caseType.documentReview.enabled', text: '{count} files go to case {case}, as a document still to be assessed.' },
			{ text: '{count} files go to case {case}.' },
		])
	})
})
