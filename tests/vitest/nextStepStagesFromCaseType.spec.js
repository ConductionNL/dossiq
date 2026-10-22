/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * next-step-per-stage 1.4: the What now? card reads a case type's own stage
 * texts (`caseType.nextStep`) through nextcloud-vue's `stagesFrom`, and keeps
 * the generic texts for a case type without them. caseType declares the
 * property in a fragment whose version is the one the merge keeps.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const caseDetail = read('src/menu-layout.simple.json').pages.find((page) => page.id === 'CaseDetail')
const fragments = fs.readdirSync(path.join(ROOT, 'lib/Settings/register.d')).filter((name) => name.endsWith('.json')).sort()

describe('the What now? card per case type', () => {
	it('reads the case type\'s stages and keeps the generic ones', () => {
		expect(caseDetail.config.nextStep.stagesFrom).toBe('@context.caseType.nextStep')
		expect(Object.keys(caseDetail.config.nextStep.stages)).toEqual(['intake', 'pending-info', 'in-progress', 'review', 'closed'])
	})

	it('declares caseType.nextStep, and the merge keeps the version that declares it', () => {
		let version = null
		let declares = false
		for (const name of fragments) {
			const caseType = read(`lib/Settings/register.d/${name}`).components?.schemas?.caseType
			if (caseType?.version) {
				version = caseType.version
				declares = Boolean(caseType.properties?.nextStep)
			}
		}
		expect(version).toBe('1.30.0')
		expect(declares).toBe(true)
	})
})
