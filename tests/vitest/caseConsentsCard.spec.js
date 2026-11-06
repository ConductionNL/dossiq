/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board DqSociaalDomein Toestemmingen: the case overview lists every consent
 * on a social domain case and can record one.
 *
 * @spec openspec/changes/social-domain-consent-and-safeguards/proposal.md
 */

import { describe, expect, it } from 'vitest'
import layout from '../../src/menu-layout.simple.json'

const text = JSON.stringify(layout)
const card = JSON.parse(`{${text.match(/"id":"case-consents-card".*?"emptyText":"No consent recorded"\}\}/)[0]}`)

describe('the consents card', () => {
	it('sits under the family plan on the overview', () => {
		expect(text).toContain('"goal-plan","case-consents-card"')
	})

	it('reads the consents of this case, newest first', () => {
		expect(card.content).toMatchObject({ schema: 'toestemming', filter: { caseId: '@objectId' }, sort: { field: 'grantedDate', dir: 'desc' } })
	})

	it('shows a withdrawn consent as withdrawn', () => {
		expect(card.content.pill.cases[0]).toMatchObject({ when: { field: 'withdrawn', value: true }, label: 'Withdrawn' })
	})

	it('records a consent on this case', () => {
		expect(card.headerActions[0].action).toMatchObject({ type: 'open-form', schema: 'toestemming', props: { caseId: '@objectId' } })
	})
})
