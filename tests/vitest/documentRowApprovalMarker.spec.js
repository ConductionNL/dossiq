/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Files tab says which documents are waiting on somebody.
 *
 * 🔴 NO MARKER AND "APPROVED" ARE DIFFERENT ROWS. A document nobody routed is
 * not a document three people agreed to, and folding them together loses the
 * one a handler was looking for. `cleared` alone cannot tell them apart:
 * decidiq answers `cleared: true` for a document with no routes at all, which
 * is why `routed` exists beside it.
 *
 * 🔴 THE MARKER IS A READ AND NEVER A STORED COPY. A copy on the document
 * would be written once and would disagree with the route the first time
 * somebody approved from decidiq's own page, and the row would go on saying
 * "step two of three" after the route had finished. So the marker is a
 * FORMATTER over a value the page fetched, and nothing in the register holds
 * it: the last test asserts no dossiq schema carries one.
 *
 * @spec openspec/specs/besluitvorming-leaf/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import {
	approvalMarkerLabel,
	isInApprovalRoute,
} from '../../src/services/approvalMarker.js'

const ROOT = path.resolve(__dirname, '../..')

/**
 * Read a JSON file from the repository.
 *
 * @param {...string} parts Path parts under the repository root.
 * @return {object} The parsed document.
 */
function readJson(...parts) {
	return JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))
}

describe('a document in a route is marked, and one that is not is not', () => {
	it('shows nothing for a document nobody routed', () => {
		expect(approvalMarkerLabel(undefined)).toBe('')
		expect(approvalMarkerLabel({ routed: false, cleared: true })).toBe('')
		expect(isInApprovalRoute(undefined)).toBe(false)
	})

	it('shows the step a route is waiting on', () => {
		const label = approvalMarkerLabel({
			routed: true,
			cleared: false,
			stepCount: 3,
			waitingOn: [{ stage: 2, stageName: 'Juridisch', actor: 'jurist' }],
		})

		expect(label).toContain('2')
		expect(label).toContain('3')
	})

	it('shows a route that finished as approved, not as nothing', () => {
		expect(
			approvalMarkerLabel({ routed: true, cleared: true, waitingOn: [] }),
			'a completed route reads the same as a document nobody reviewed',
		).toBe('Approved')
	})

	it('still marks a route whose step numbers are missing', () => {
		expect(
			approvalMarkerLabel({ routed: true, cleared: false, waitingOn: [] }),
		).toBe('In approval')
	})
})

describe('the column is declared where the rows are', () => {
	const manifest = readJson('src', 'manifest.json')
	const detail = (manifest.pages || []).find((p) => p.id === 'CaseDetail')
	const files = (detail.config.widgets || []).find((w) => w.id === 'case-files')
	const columns = (files.props || files.content || {}).columns || []

	it('declares an Approval column over the marker, with its formatter', () => {
		const column = columns.find((c) => c.id === 'approval-chain')

		expect(column, 'the Files tab declares no approval column').toBeTruthy()
		expect(column.property).toBe('approvalMarker')
		expect(column.formatter).toBe('approvalChain')
	})

	it('names a formatter that is registered, or the cell renders the raw value', () => {
		const formatters = fs.readFileSync(
			path.join(ROOT, 'src', 'services', 'formatters.js'),
			'utf8',
		)

		expect(formatters).toContain('approvalChain:')
	})
})

describe('dossiq stores no copy of the route state', () => {
	it('declares no approval marker property on any schema', () => {
		const documents = [readJson('lib', 'Settings', 'dossiq_register.json')]
		const dir = path.join(ROOT, 'lib', 'Settings', 'register.d')
		fs.readdirSync(dir)
			.filter((f) => f.endsWith('.json'))
			.forEach((f) =>
				documents.push(readJson('lib', 'Settings', 'register.d', f)),
			)

		const offenders = []
		for (const doc of documents) {
			const schemas = (doc.components && doc.components.schemas) || {}
			for (const [name, schema] of Object.entries(schemas)) {
				for (const property of Object.keys(schema.properties || {})) {
					if (
						/^(approvalMarker|approvalStep|approvalState|approvalChainStep)$/.test(
							property,
						)
					) {
						offenders.push(`${name}.${property}`)
					}
				}
			}
		}

		expect(
			offenders,
			'a schema holds a copy of decidiq route state, which disagrees with the route the first time somebody approves from decidiq own page',
		).toEqual([])
	})

	it('reads the markers from an endpoint that exists', () => {
		const routes = fs.readFileSync(
			path.join(ROOT, 'appinfo', 'routes.php'),
			'utf8',
		)

		expect(routes).toContain("'name' => 'zaakdossier#approvalMarkers'")
		expect(routes).toContain('/api/informatieobjecten/approval-markers')

		const controller = fs.readFileSync(
			path.join(ROOT, 'lib', 'Controller', 'ZaakdossierController.php'),
			'utf8',
		)
		expect(
			controller,
			'a route pointing at a method that does not exist is a ReflectionException, not a 404',
		).toContain('public function approvalMarkers()')
	})
})
