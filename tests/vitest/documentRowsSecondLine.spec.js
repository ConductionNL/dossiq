/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaakDocumenten draws each document on two lines: the name, and under it
 * its type, size and date ("PDF · 1,2 MB · 14 januari 2026").
 */

import { describe, expect, it } from 'vitest'
const panels = require('./helpers/casePanels.js')

describe('the case documents rows', () => {
	it('carry the type, size and date under the name', () => {
		expect(panels.caseWidget('case-files').props.nameSecondary).toBe('{type} · {size} · {modified|date}')
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
