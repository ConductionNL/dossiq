// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The programme card hands the case title to the link dialog.
 *
 * DQ-BUGS: CaseProgrammeSection read a prop `object` while the host binds the
 * loaded case as `objectData`, so the dialog always got an empty title. The
 * card is the library facts widget now, whose empty action resolves
 * `@object.title` against the loaded case before it opens the dialog
 * (nextcloud-vue CnFactsWidget, `emptyActions`). What keeps the fix is that
 * the declaration names the title by that token, and the dialog takes it.
 *
 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-hangs-under-a-programme-by-reference-and-the-progress-figure-names-its-mode-req-plq-08
 */
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'))
const dialog = fs.readFileSync(path.join(ROOT, 'src', 'dialogs', 'LinkProgrammeDialog.vue'), 'utf8')

/**
 * Find a widget definition anywhere on a page, inside sections too.
 *
 * @param {object} page The page.
 * @param {string} id The widget id.
 * @return {object|undefined} The definition.
 */
function findWidget(page, id) {
	const all = []
	const visit = (widget) => {
		if (widget && typeof widget === 'object') {
			all.push(widget)
			for (const section of widget.content?.sections || []) {
				visit(section.widget)
			}
		}
	}
	page.config.widgets.forEach(visit)
	return all.find((widget) => widget.id === id)
}

describe('the programme card', () => {
	it('passes the case title to the link dialog', () => {
		const caseDetail = manifest.pages.find((page) => page.id === 'CaseDetail')
		const programme = findWidget(caseDetail, 'case-programme')
		const link = programme.content.emptyActions.find((action) => action.target === 'LinkProgrammeDialog')

		expect(link.props).toEqual({ caseId: '@objectId', caseTitle: '@object.title' })
		expect(dialog).toMatch(/caseTitle: \{/)
	})
})
