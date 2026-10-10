/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Sibling leaves are PLACED on the case page, not rebuilt here.
 *
 * planninq's `planninq-projects` (dossiq#3190; planninq change
 * `integration-case-bridge`, leaf id in planninq
 * lib/Listener/RegisterProjectsLeafListener.php::LEAF_ID) lists the projects
 * whose `caseReference` is the host object's uuid, so it needs the host's
 * `register`, `schema` and `objectId`, which the host forwards to every leaf
 * it mounts.
 *
 * filinq's `filinq-merge-to-pdf` (dossiq#3185; filinq change
 * `merge-documents-to-pdf`, leaf id in filinq
 * lib/EventListener/RegisterMergeToPdfLeafListener.php::LEAF_ID on
 * ConductionNL/filinq#1251) takes the same three props.
 *
 * A leaf whose app is absent is never registered, so on an install without
 * the sibling the surface is missing rather than empty. That is why no
 * placement declares `requiredApp` (see hoursLeafManifest.spec.js).
 *
 * @spec openspec/specs/case-linked-projects/spec.md
 * @spec openspec/specs/case-documents-merge-via-filinq-leaf/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)
const caseDetail = manifest.pages.find((page) => page.id === 'CaseDetail')

/**
 * Every widget on CaseDetail, including the ones nested in tabs and sections.
 *
 * @return {Array<object>} The widgets.
 */
function allWidgets() {
	const found = []
	const walk = (node) => {
		if (Array.isArray(node)) {
			node.forEach(walk)
			return
		}
		if (node === null || typeof node !== 'object') return
		if (typeof node.type === 'string' && typeof node.id === 'string')
			found.push(node)
		Object.values(node).forEach(walk)
	}
	walk(caseDetail.config.widgets)
	return found
}

/**
 * Assert one leaf is placed in the grid, once, ungated.
 *
 * @param {string} integrationId The leaf id.
 * @return {object} The widget.
 */
function placedInTheGrid(integrationId) {
	const widget = caseDetail.config.widgets.find(
		(w) => w.integrationId === integrationId,
	)
	expect(
		widget,
		`${integrationId} is a top-level widget of CaseDetail`,
	).toBeDefined()
	expect(widget.type).toBe('integration')
	expect(widget.requiredApp).toBeUndefined()
	const cell = caseDetail.config.layout.find((l) => l.widgetId === widget.id)
	expect(cell, 'a grid widget renders only with a layout cell').toBeDefined()
	expect(
		allWidgets().filter((w) => w.integrationId === integrationId),
	).toHaveLength(1)
	return widget
}

describe('sibling leaves on the case page', () => {
	it('mounts leaves on the case, so they scope on register dossiq and schema case', () => {
		expect(caseDetail.config.register).toBe('dossiq')
		expect(caseDetail.config.schema).toBe('case')
	})

	// @spec openspec/specs/case-linked-projects/spec.md#a-handler-starts-a-project-from-a-case
	it('places planninq-projects on the case page, in the grid', () => {
		expect(placedInTheGrid('planninq-projects').title).toBe('Projects')
	})

	// @spec openspec/specs/case-documents-merge-via-filinq-leaf/spec.md#a-handler-merges-a-cases-documents-into-one-pdf
	it('places filinq-merge-to-pdf on the case page, in the grid', () => {
		expect(placedInTheGrid('filinq-merge-to-pdf').title).toBe(
			'Merge into one PDF',
		)
	})
})
