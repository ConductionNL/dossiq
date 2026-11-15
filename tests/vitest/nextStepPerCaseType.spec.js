/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * next-step-per-stage 1.4: a case type keeps its own "Wat nu?" texts per
 * stage. The case page reads them from the case type through
 * nextStep.stagesFrom; a case type without them keeps the page's stages.
 *
 * @spec openspec/specs/case-page/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (...parts) => JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))
const simple = read('src', 'menu-layout.simple.json').pages.find((page) => page.id === 'CaseDetail').config
const fragment = read('lib', 'Settings', 'register.d', '99-case-type-next-step.json')

describe('the "Wat nu?" stages per case type', () => {
	it('are read from the case type, the page\'s stages staying for a case type without them', () => {
		expect(simple.nextStep.stagesFrom).toBe('@context.caseType.nextStep')
		expect(Object.keys(simple.nextStep.stages).length).toBeGreaterThan(0)
	})

	it('are declared on the case type as a stages map', () => {
		const property = fragment.components.schemas.caseType.properties.nextStep
		expect(property.type).toBe('object')
		expect(property.properties.stages.type).toBe('object')
	})

	it('takes a case type version of its own, which the fragment merger keeps when it is the highest', () => {
		// The version register (one number per change) and RegisterFragmentMerger keeping the highest
		// x.y.z replace the old rule that the newest fragment sorts last.
		const versions = fs.readdirSync(path.join(ROOT, 'lib', 'Settings', 'register.d'))
			.filter((name) => name.endsWith('.json') && name !== '99-case-type-next-step.json')
			.map((name) => read('lib', 'Settings', 'register.d', name).components?.schemas?.caseType?.version)
			.filter(Boolean)
		expect(versions).not.toContain(fragment.components.schemas.caseType.version)
	})
})
