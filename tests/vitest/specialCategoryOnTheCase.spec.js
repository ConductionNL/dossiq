/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The special categories of personal data a case processes: asked on the
 * create form, said on the case page. The intake refusal and the
 * processing-log row are server side and tested there.
 *
 * @spec openspec/changes/social-domain-consent-and-safeguards/specs/dossiq-sociaal-domein-avg-consent/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'))
const caseSchema = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'lib', 'Settings', 'register.d', '99-case-type-social-safeguards.json'), 'utf8'),
).components.schemas.case.properties.gdprClassification

/**
 * One page's config.
 *
 * @param {string} id The page id.
 * @return {object} The config.
 */
function config(id) {
	return manifest.pages.find((entry) => entry.id === id).config
}

describe('the special categories on the create form', () => {
	const cases = config('Cases')

	it('are a field of the form, labelled for every category the case schema knows', () => {
		expect(cases.includeFields).toContain('gdprClassification')
		const labels = cases.fieldOverrides.gdprClassification.enumLabels
		expect(Object.keys(labels).sort()).toEqual([...caseSchema.items.enum].sort())
	})
})

describe('the special categories on the case page', () => {
	const detail = config('CaseDetail')
	const banner = detail.widgets.find((entry) => entry.id === 'case-special-categories')

	it('are a strip of the banner stack, shown only while the case names one', () => {
		const stack = detail.widgets.find((entry) => entry.id === 'case-banner-stack')
		expect(stack.content.sections).toContainEqual({ widgetId: 'case-special-categories' })
		expect(banner.type).toBe('banner')
		expect(banner.content.visibleWhen).toEqual({ field: 'gdprClassification', op: 'notEmpty' })
		expect(banner.content.lead).toContain('{subject.gdprClassification|values}')
	})
})
