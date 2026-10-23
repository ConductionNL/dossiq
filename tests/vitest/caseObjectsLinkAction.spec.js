/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaakGerelateerd: the Objecten card offers "Object koppelen", the case
 * page's own Link object form, instead of the list's generic Add.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const simple = read('src/menu-layout.simple.json').pages.find((page) => page.id === 'CaseDetail')
const card = simple.configAppend.widgets.find((widget) => widget.id === 'case-objects-card').content
const link = read('src/manifest.json').pages.find((page) => page.id === 'CaseDetail').config.headerActions.find((action) => action.id === 'link-object')

describe('the Objecten card', () => {
	it('links an object with the case page\'s own form', () => {
		expect(card.createAction).toMatchObject({
			type: 'open-form',
			label: 'Link object',
			icon: 'Plus',
			register: link.register,
			schema: link.schema,
			includeFields: link.includeFields,
			props: { case: '@objectId' },
		})
	})

	it('does not offer it on an archived case', () => {
		expect(card.createAction.visibleWhen).toEqual(link.visibleWhen)
	})
})
