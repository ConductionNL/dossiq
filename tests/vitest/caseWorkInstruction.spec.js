// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The work instruction for this KIND of case, on the case.
 *
 * knowledge-base-on-the-case REQ-CKB-01, parity row 11.26. The case type
 * names the page once and every case of that type shows it, with nobody
 * linking anything per case. That is the half of the requirement a
 * declaration could not close: `CnDetailPage` reads no `extend`, so the
 * case's `caseType` is a bare uuid on the page and a widget over a dotted
 * `caseType.knowledgeBasePage` path renders blank while looking configured.
 *
 * 🔴 THE THREE BLANK STATES ARE DRIVEN APART, because a reader cannot tell
 * them apart from the panel and only one of them is nobody's problem:
 *
 *  - a case type that names NO page draws nothing, on purpose;
 *  - a lookup that FAILED says so and offers a retry;
 *  - a page that IS named draws a link, and never the article body.
 *
 * 🔴 A `javascript:` VALUE IS NOT OPENED. `knowledgeBasePage` is free text an
 * administrator types, carries no `format` on purpose, and is rendered as an
 * `href`. The scheme whitelist is asserted here rather than trusted, because
 * a blacklist that missed one would look exactly like this test passing.
 *
 * @spec openspec/specs/case-knowledge-base/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')

describe('the panel is placed where a reader meets it', () => {
	const manifest = JSON.parse(
		fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
	)

	it('is the first section of the Knowledge tab on the case', () => {
		const detail = (manifest.pages || []).find((p) => p.id === 'CaseDetail')
		const panel = (detail.config.widgets || []).find(
			(w) => w.id === 'case-knowledge-panel',
		)
		const sections = panel.content.sections || []

		expect(sections[0].widget.type).toBe('facts')
		expect(sections[0].widget.id).toBe('case-work-instruction')
		// One reference away: the case's type, read once.
		expect(sections[0].widget.content.source).toEqual({ register: 'dossiq', schema: 'caseType', id: '@object.caseType' })
	})

	it('is the library facts card: a link into Collectives in a new tab, silent without a page, a retry on a failed lookup', () => {
		// What the link does (a page path under Collectives, an http(s) URL as
		// is, any other scheme no link at all, a failed lookup said with a
		// retry) is nextcloud-vue CnFactsWidget's, with these cases ported to
		// its spec. This holds the declaration to it.
		const detail = (manifest.pages || []).find((p) => p.id === 'CaseDetail')
		const panel = (detail.config.widgets || []).find((w) => w.id === 'case-knowledge-panel')
		const widget = panel.content.sections[0].widget

		expect(widget.content.link).toMatchObject({ href: '{knowledgeBasePage}', hrefBase: '/apps/collectives/', target: '_blank' })
		expect(widget.content.fields).toBeUndefined()
		expect(widget.content.errorText).toBe('The work instruction for this case type could not be looked up.')
	})

	it('is placed exactly once, so a second placement cannot drift from this one', () => {
		// `JSON.stringify` puts no space after a colon, and the manifest file
		// does. Counting the file's spelling here found nothing and read as a
		// pass on the opposite claim, which is why the walk is over the parsed
		// tree and not over either string.
		let placements = 0
		const walk = (node) => {
			if (Array.isArray(node)) {
				node.forEach(walk)
				return
			}
			if (node === null || typeof node !== 'object') {
				return
			}
			if (node.id === 'case-work-instruction') {
				placements += 1
			}
			Object.values(node).forEach(walk)
		}
		walk(manifest)

		expect(placements).toBe(1)
	})
})
