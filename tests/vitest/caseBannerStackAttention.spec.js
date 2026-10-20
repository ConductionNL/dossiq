// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The banner stack hands the loaded case to the attention strip.
 *
 * The markers and the risk assessment are read off the case record, not
 * fetched, so a strip that only receives `objectId` draws neither.
 *
 * @spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import CaseBannerStack from '../../src/components/case/CaseBannerStack.vue'

vi.mock('@nextcloud/dialogs', () => ({
	showSuccess: vi.fn(),
	showError: vi.fn(),
}))

const stubs = {
	CaseArchivedStrip: true,
	CaseUnreadPanel: true,
	CaseStatusDeclarationPanel: true,
	NcButton: { template: '<button v-bind="$attrs"><slot /></button>' },
	NcTextField: true,
}

describe('CaseBannerStack', () => {
	it('draws the markers and the risk assessment the case carries', async () => {
		axios.get.mockResolvedValue({ data: { raised: false, history: [] } })

		const wrapper = mount(CaseBannerStack, {
			props: {
				objectId: 'case-7',
				objectData: {
					id: 'case-7',
					attentionMarkers: [
						{ marker: 'virus-scan-failed', tab: 'documents', reason: 'Scan failed' },
					],
					riskAssessment: { level: 'high', ground: 'Threat received' },
				},
			},
			global: { stubs },
		})
		await flushPromises()

		expect(wrapper.find('[data-testid="case-marker-virus-scan-failed"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="case-attention-risk"]').exists()).toBe(true)
	})
})
