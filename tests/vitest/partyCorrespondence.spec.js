// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * A party's own correspondence on the People tab, and the two columns the
 * Files tab declares for it.
 *
 * 🔴 A PARTY NOBODY CORRESPONDED WITH GETS NO LINE, not "0 documents". An
 * empty count on every row is noise on the tab whose job is to say who is on
 * the case, and it is the shape a naive `${sent.length} sent` produces on
 * every party of every case in the fleet.
 *
 * @spec openspec/changes/document-correspondents/specs/document-zaakdossier/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

describe("a party's documents on the People tab", () => {
	// Counting what a party sent and received, once per tab, with no line for
	// a party nobody corresponded with and none when the read fails, is the
	// library parties widget's now (nextcloud-vue CnObjectPartiesWidget and
	// objectParties.documentsOfParty, with these cases ported to their specs).
	// dossiq's half: the Roles section reads the case dossier for it.
	it('reads the dossier of the case for the correspondence line', () => {
		const manifest = JSON.parse(
			fs.readFileSync(
				path.resolve(__dirname, '../../src/manifest.json'),
				'utf8',
			),
		)
		const people = manifest.pages
			.find((page) => page.id === 'CaseDetail')
			.config.widgets.find((widget) => widget.id === 'case-people-panel')
		const roles = people.content.sections
			.map((section) => section.widget)
			.find((widget) => widget.id === 'case-party-roles')

		expect(roles.content.correspondence).toEqual({
			endpoint: '/apps/dossiq/api/cases/@objectId/dossier',
			path: 'informatieobjecten',
		})
	})
})

describe('the Files tab declares its columns', () => {
	const manifest = JSON.parse(
		fs.readFileSync(path.resolve(__dirname, '../../src/manifest.json'), 'utf8'),
	)

	/**
	 * The Files tab of the case page.
	 *
	 * @return {object} The widget entry.
	 */
	function filesTab() {
		const panels = require('./helpers/casePanels.js')
		return panels.caseWidget('case-files')
	}

	it('binds each column to a property something answers', () => {
		const props = filesTab().props

		// 🔴 The property, not the label. A column bound to a key nothing
		// answers renders blank forever and raises nothing anywhere. The table
		// shows the DqDocumentenBeheer columns: scanVerdict, which the dossier
		// listing carries per row, and vertrouwelijkheidaanduiding, the row's
		// own confidentiality; reviewVerdict, which the listing puts on each row
		// of a case under review. Direction and keywords are the informatieobject's
		// own fields, which the listing carries whole; they start hidden, as do
		// documentType and partyName, which DocumentListingLabels adds to each row.
		// The name column is the library's own, relabelled, and binds no property.
		expect(
			props.columns
				.filter(
					(column) => typeof column === 'object' && column.key !== 'name',
				)
				.map((column) => column.property),
		).toEqual([
			'scanVerdict',
			'vertrouwelijkheidaanduiding',
			'reviewVerdict',
			'documentType',
			'direction',
			'partyName',
			'keywords',
		])

		// The listing's senderName and recipientNames, and the approval marker
		// (#3004, where a document sits in decidiq's route), moved into the
		// selected file's Document details card rather than off the page.
		const details = props.sideCards.find((card) => card.id === 'details')
		expect(details.fields.map((field) => field.key)).toEqual(
			expect.arrayContaining([
				'senderName',
				'recipientNames',
				'approvalMarker',
			]),
		)
	})

	it('labels them in sentence case with no em-dash', () => {
		const props = filesTab().props
		const labels = [
			...props.columns
				.filter((column) => typeof column === 'object')
				.map((column) => column.label),
			...props.sideCards.flatMap((card) =>
				card.fields.map((field) => field.label),
			),
		]

		expect(labels).toEqual(
			expect.arrayContaining([
				'Document',
				'Scan result',
				'Visible to',
				'Sender',
				'Recipients',
				'Approval',
			]),
		)
		for (const label of labels) {
			expect(label).not.toMatch(/—|--/)
		}
	})

	it('is the manifest on disk and not a copy of it', () => {
		expect(manifest.pages.some((page) => page.id === 'CaseDetail')).toBe(true)
	})
})
