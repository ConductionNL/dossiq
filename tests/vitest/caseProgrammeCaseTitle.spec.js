// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The programme section hands the case title to the link dialog.
 *
 * CnDetailWidgetHost binds the loaded case as `objectData`; the title the
 * work item in pipelinq carries is read from there.
 *
 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-hangs-under-a-programme-by-reference-and-the-progress-figure-names-its-mode-req-plq-08
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import CaseProgrammeSection from '../../src/components/case/CaseProgrammeSection.vue'

const LinkProgrammeDialog = {
	name: 'LinkProgrammeDialog',
	props: ['caseId', 'caseTitle'],
	template: '<div class="link-dialog" />',
}

const stubs = {
	NcButton: { template: '<button v-bind="$attrs" @click="$emit(\'click\')"><slot /></button>' },
	NcEmptyContent: true,
	NcLoadingIcon: true,
	LinkProgrammeDialog,
}

describe('CaseProgrammeSection', () => {
	it('gives the link dialog the title of the case the host bound', async () => {
		axios.get.mockResolvedValue({ data: { available: true, programme: null } })

		const wrapper = mount(CaseProgrammeSection, {
			props: {
				objectId: 'case-1',
				objectData: { id: 'case-1', title: 'Omgevingsvergunning Lindelaan 4' },
			},
			global: { stubs },
		})
		await flushPromises()

		await wrapper.find('[data-testid="case-programme-link"]').trigger('click')

		expect(wrapper.findComponent(LinkProgrammeDialog).props('caseTitle')).toBe(
			'Omgevingsvergunning Lindelaan 4',
		)
	})
})
