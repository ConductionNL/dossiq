/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Generate document's "What the template fills in" card as board
 * DqDocumentGenereren draws it: a bold title set tight, 8px above the facts,
 * on a ground lighter than the hover tone (r21: background 240,241,243 for
 * 245,246,248, title weight 600 for 700, 26px under the title for 8).
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const source = fs.readFileSync(path.resolve(__dirname, '../../src/dialogs/BeschikkingComposerDialog.vue'), 'utf8')
const style = source.slice(source.indexOf('<style'))

/**
 * The declarations of one rule in the dialog's style block.
 *
 * @param {string} selector The selector, exactly as written.
 * @return {string} The declarations.
 */
function rule(selector) {
	const at = style.indexOf(selector + ' {')
	expect(at, selector).toBeGreaterThan(-1)
	return style.slice(at, style.indexOf('}', at))
}

describe('the template fills card on board DqDocumentGenereren', () => {
	it('stacks title and facts 8px apart on a light ground with the large radius', () => {
		const card = rule('.generate-document__fills')
		expect(card).toContain('display: flex;')
		expect(card).toContain('flex-direction: column;')
		expect(card).toContain('gap: 8px;')
		expect(card).toContain('border-radius: var(--border-radius-large);')
		expect(card).toContain('background: color-mix(in srgb, var(--color-background-hover) 60%, var(--color-main-background));')
	})

	it('sets the title bold and tight, with no margin of its own', () => {
		const title = rule('.generate-document__fills-title')
		expect(title).toContain('margin: 0;')
		expect(title).toContain('font-weight: 700;')
		expect(title).toContain('line-height: 1.2;')
	})
})

describe('the template facts sit tight under the title (r25b: 22.8px for 8)', () => {
	it('takes away the padding core gives a dl, and sets the facts at the normal line height', () => {
		expect(rule('.generate-document__fills-grid')).toContain('padding: 0;')
		expect(rule('.generate-document__fills-grid dt,\n.generate-document__fills-grid dd')).toContain('line-height: normal;')
	})
})

describe('the note under the template facts (DqDocumentGenereren)', () => {
	it('carries the board\'s document-with-a-check icon, not the info circle', () => {
		const template = source.slice(0, source.indexOf('<script'))
		const note = template.slice(template.indexOf('<NcNoteCard type="info"'), template.indexOf('</NcNoteCard>'))
		expect(note).toContain('<FileDocumentCheckOutline')
		expect(source).toContain("import FileDocumentCheckOutline from 'vue-material-design-icons/FileDocumentCheckOutline.vue'")
	})
})

describe('the generate dialog keeps the board\'s rhythm (r34: the note 4.1px high)', () => {
	it('stands the templates, the fills card and the note 18px apart', () => {
		expect(rule('.generate-document__form')).toContain('gap: 18px;')
	})

	it('sets each fact 3px under its label in the medium weight', () => {
		// The dd's own rule, not the tail of the shared `dt,\n...dd` rule.
		const at = style.indexOf('}\n\n.generate-document__fills-grid dd {')
		expect(at).toBeGreaterThan(-1)
		const value = style.slice(at + 1, style.indexOf('}', at + 1))
		expect(value).toContain('margin: 3px 0 0;')
		expect(value).toContain('font-weight: 500;')
	})
})
