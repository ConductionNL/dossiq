/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DqTeamwachtrij draws how long a case has waited in red from five days on
 * ("6 dagen", "5 dagen" red; "4 dagen" and less in the main ink), in the list
 * view's leading cell and the table's Waiting column alike.
 *
 * @spec openspec/specs/case-lists/spec.md
 */

import { describe, expect, it } from 'vitest'
import manifest from '../../src/manifest.json'

const queue = manifest.pages.find((page) => page.id === 'Cases' && page.route === '/cases').config.defaultSavedViews.find((view) => view.slug === 'team-queue').overrides
const RED_FROM_FIVE = [{ op: 'gte', value: 5, variant: 'error' }]

describe('the team queue waiting time', () => {
	it('turns red from five days in the list view', () => {
		expect(queue.listConfig.leading.widgetProps.variantWhen).toEqual(RED_FROM_FIVE)
	})

	it('turns red from five days in the table', () => {
		expect(queue.columns.find((column) => column.key === 'startDate').widgetProps.variantWhen).toEqual(RED_FROM_FIVE)
	})
})
