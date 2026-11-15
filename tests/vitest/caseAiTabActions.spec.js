/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqZaakAssistent: on the AI-toezicht tab the case page draws no "what now"
 * card and one quick action, Bericht. The other quick actions stay in the
 * Actions menu there.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const config = read('src/menu-layout.simple.json').pages.find((page) => page.id === 'CaseDetail').config
const AI_TAB = 'ai-oversight'

/**
 * @param {string|object} entry A quick action entry.
 * @return {boolean} Whether it shows beside the AI tab.
 */
const onAiTab = (entry) => typeof entry === 'string' || !(entry.notTabs || []).includes(AI_TAB)

describe('the case page on the AI-toezicht tab', () => {
	it('keeps only the message quick action', () => {
		const shown = config.quickActions.filter(onAiTab).map((entry) => (typeof entry === 'string' ? entry : entry.id))
		expect(shown).toEqual(['send-digital-post'])
	})

	it('keeps all three quick actions on the other tabs', () => {
		expect(config.quickActions.map((entry) => (typeof entry === 'string' ? entry : entry.id)))
			.toEqual(['send-digital-post', 'generate-document', 'log-contact'])
	})

	it('stands the "what now" card aside', () => {
		expect(config.nextStep.notTabs).toEqual([AI_TAB])
	})
})
