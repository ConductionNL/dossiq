/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The notices-of-default table reads its register and schema where the table
 * widget (CnObjectListWidget) looks for them: on the content itself. Nested
 * under `source` the widget saw no schema, fetched nothing and drew its empty
 * state over three stored notices (DqTermijnen "Ingebrekestellingen en
 * dwangsom", r33).
 *
 * @spec openspec/specs/notices-table-reads-its-schema/spec.md#requirement-the-notices-table-names-its-schema-where-the-table-reads-it
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import manifest from '../../src/manifest.json'

const page = manifest.pages.find((item) => item.id === 'TermijnDashboard')
const notices = page.config.widgets.find((item) => item.id === 'deadline-report-notices')

describe('the notices-of-default table', () => {
	it('reads its rows from the notices endpoint, not from a register list (notices-of-default-by-case)', () => {
		expect(notices.type).toBe('object-table')
		expect(notices.content.endpointSource.url).toBe('/apps/dossiq/api/deadlines/notices')
		expect(notices.content.limit).toBe(10)
		expect(notices.content.register).toBeUndefined()
		expect(notices.content.schema).toBeUndefined()
		expect(notices.content.source).toBeUndefined()
	})

	it('sorts and filters from its column heads and carries the board\'s footnote (DqTermijnen r35)', () => {
		expect(notices.content.sortable).toBe(true)
		expect(notices.content.filterable).toBe(true)
		expect(notices.content.footnote).toBe('A notice of default only counts once the decision term has passed (Awb 4:17). A fixed amount goes to the financial administration as a payment signal; the result comes back on the case.')
		const nl = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../l10n/nl.json'), 'utf8')).translations
		expect(nl[notices.content.footnote]).toBe('Een ingebrekestelling telt pas als de beslistermijn voorbij is (Awb 4:17). Een vastgesteld bedrag gaat als betaalsignaal naar de financiële administratie; het resultaat komt terug op de zaak.')
	})

	it('draws the case as a plain link, as DqTermijnen (r36: 15/600 title ink, board 14/400 link)', () => {
		// A borderless object table keeps the board title-column look (DqDashboard's case list);
		// a framed one in a card shows its first column plain. The dashboard card draws no frame either way.
		expect(notices.content.borderless).toBeUndefined()
	})
})
