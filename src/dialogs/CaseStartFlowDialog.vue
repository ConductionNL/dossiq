<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Start a sub-process for this case.

  The list is the case type's, not the instance's: a handler picks from what
  their case type allows, and nothing else. The run is posted straight to
  OpenRegister's own run endpoint with this case as the subject, so the run
  lands in the Flow runs widget beside it without dossiq holding a copy of
  anything (ADR-022).

  The subject is `{uuid, register, schema}` because that is the shape
  FlowRunRow reads: it stores subject_uuid, subject_register and
  subject_schema off exactly those three keys, and the case page's Flow runs
  widget filters on the first of them.

  It reads the case from the ROUTE, because an open-modal action forwards its
  props verbatim and `@objectId` would arrive as that literal string.

  @spec openspec/specs/workflow-definition-engine/spec.md
-->
<template>
	<NcDialog
		:name="t('dossiq', 'Start a sub-process')"
		data-testid="case-start-flow-dialog"
		@closing="$emit('close')">
		<div class="case-start-flow">
			<NcLoadingIcon v-if="loading" :size="24" />

			<p
				v-else-if="flows.length === 0"
				class="case-start-flow__empty"
				data-testid="case-start-flow-empty">
				{{
					t(
						'dossiq',
						'This case type allows no sub-process to be started by hand.',
					)
				}}
			</p>

			<ul
				v-else
				class="case-start-flow__list"
				data-testid="case-start-flow-list">
				<li
					v-for="flow in flows"
					:key="flow.id"
					class="case-start-flow__row">
					<NcCheckboxRadioSwitch
						type="radio"
						name="case-start-flow"
						:value="flow.id"
						:modelValue="chosen"
						@update:modelValue="chosen = $event">
						{{ flow.title }}
					</NcCheckboxRadioSwitch>
					<span v-if="flow.description" class="case-start-flow__hint">
						{{ flow.description }}
					</span>
				</li>
			</ul>

			<p
				v-if="error"
				class="case-start-flow__error"
				data-testid="case-start-flow-error"
				role="alert">
				{{ error }}
			</p>
		</div>

		<template #actions>
			<NcButton data-testid="case-start-flow-cancel" @click="$emit('close')">
				{{ t('dossiq', 'Cancel') }}
			</NcButton>
			<NcButton
				data-testid="case-start-flow-confirm"
				variant="primary"
				:disabled="!canRun"
				@click="run">
				{{ t('dossiq', 'Run') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { emit } from '@nextcloud/event-bus'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { caseActionRefusal } from '../utils/caseActionsHelpers.js'

const PAGE_REFRESH = 'cn:page:refresh'

export default {
	name: 'CaseStartFlowDialog',

	components: { NcButton, NcCheckboxRadioSwitch, NcDialog, NcLoadingIcon },

	props: {
		/**
		 * The case to start a flow for. Absent when the manifest opened the
		 * dialog: an `open-modal` action carries no object context, so the
		 * route answers.
		 */
		caseId: {
			type: String,
			default: '',
		},
	},

	emits: ['close'],

	data() {
		return {
			flows: [],
			chosen: '',
			loading: true,
			busy: false,
			error: '',
		}
	},

	computed: {
		/** @return {string} The case this dialog acts on. */
		targetCaseId() {
			return this.caseId || String(this.$route?.params?.id ?? '')
		},

		/** @return {boolean} Whether a flow may be run. */
		canRun() {
			return this.busy === false && this.chosen !== ''
		},
	},

	/**
	 * Read the flows this case's type allows.
	 *
	 * @return {Promise<void>} Nothing.
	 * @spec openspec/specs/workflow-definition-engine/spec.md
	 */
	async mounted() {
		await this.load()
	},

	methods: {
		t,

		/**
		 * Load the startable flows.
		 *
		 * @return {Promise<void>} Nothing.
		 * @spec openspec/specs/workflow-definition-engine/spec.md
		 */
		async load() {
			if (!this.targetCaseId) {
				this.loading = false
				return
			}
			try {
				const { data } = await axios.get(
					generateUrl(
						`/apps/dossiq/api/case/${encodeURIComponent(this.targetCaseId)}/startable-flows`,
					),
				)
				this.flows = Array.isArray(data?.results) ? data.results : []
				if (this.flows.length === 1) {
					this.chosen = this.flows[0].id
				}
			} catch (err) {
				this.error = caseActionRefusal(err?.response?.data ?? {}, (s) =>
					t('dossiq', s),
				)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Post the run to OpenRegister with this case as its subject.
		 *
		 * @return {Promise<void>} Nothing.
		 * @spec openspec/specs/workflow-definition-engine/spec.md
		 */
		async run() {
			if (!this.canRun) {
				return
			}
			this.busy = true
			this.error = ''
			try {
				await axios.post(
					generateUrl(
						`/apps/openregister/api/flows/${encodeURIComponent(this.chosen)}/run`,
					),
					{
						subject: {
							uuid: this.targetCaseId,
							register: 'dossiq',
							schema: 'case',
						},
					},
				)
				// The Flow runs widget on this page polls, but a run started by
				// hand should show up the moment it is queued rather than up to
				// fifteen seconds later.
				emit(PAGE_REFRESH)
				this.$emit('close')
			} catch (err) {
				this.error = caseActionRefusal(err?.response?.data ?? {}, (s) =>
					t('dossiq', s),
				)
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.case-start-flow {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 0;
}

.case-start-flow__list {
	display: flex;
	flex-direction: column;
	gap: 8px;
	list-style: none;
	margin: 0;
	padding: 0;
}

.case-start-flow__hint,
.case-start-flow__empty {
	color: var(--color-text-maxcontrast);
	display: block;
	margin: 0 0 0 32px;
}

.case-start-flow__empty {
	margin-left: 0;
}

.case-start-flow__error {
	color: var(--color-error);
	margin: 0;
}
</style>
