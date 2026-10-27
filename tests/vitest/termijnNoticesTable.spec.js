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
 * @spec openspec/changes/notices-table-reads-its-schema/specs/notices-table-reads-its-schema/spec.md#requirement-the-notices-table-names-its-schema-where-the-table-reads-it
 */

import { describe, expect, it } from 'vitest'
import manifest from '../../src/manifest.json'

const page = manifest.pages.find((item) => item.id === 'TermijnDashboard')
const notices = page.config.widgets.find((item) => item.id === 'deadline-report-notices')

describe('the notices-of-default table', () => {
	it('names its register, schema and limit on the content', () => {
		expect(notices.type).toBe('table')
		expect(notices.content).toMatchObject({ register: 'dossiq', schema: 'noticeOfDefault', limit: 10 })
		expect(notices.content.source).toBeUndefined()
	})
})
