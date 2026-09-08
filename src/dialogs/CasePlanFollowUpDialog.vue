<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Plan a follow-up case for a later date.

  Three fields, because three are what a scheduled case needs: which type it
  is, when it should exist, and what it is called. Everything else the case
  inherits from its type when it is created.

  The earliest date is TOMORROW. A schedule trigger fires on a cron minute, so
  a follow-up planned for today would fire either in a few hours or not at all
  depending on the clock, which is two behaviours from one gesture.

  It reads the case from the ROUTE, because an open-modal action forwards its
  props verbatim and `@objectId` would arrive as that literal string.

  @spec openspec/specs/workflow-definition-engine/spec.md
-->
<template>
	<NcDialog
		:name="t('dossiq', 'Plan a follow-up case')"
		data-testid="case-plan-dialog"
		@closing="$emit('close')">
		<div class="case-plan">
			<p class="case-plan__explainer">
				{{
					t(
						'dossiq',
						'The case is created on the date you pick, related to this one. Until then it shows on the Related cases tab as planned.',
					)
				}}
			</p>

			<NcSelect
				v-model="caseType"
				data-testid="case-plan-type"
				:inputLabel="t('dossiq', 'Case type')"
				:options="caseTypes"
				label="label"
				:clearable="false"
				:loading="loadingTypes" />

			<NcDateTimePicker
				v-model="date"
				data-testid="case-plan-date"
				type="date"
				:min="earliest"
				:label="t('dossiq', 'Date')" />

			<NcTextField
				v-model="title"
				data-testid="case-plan-title"
				:label="t('dossiq', 'Title of the follow-up')" />

			<p
				v-if="error"
				class="case-plan__error"
				data-testid="case-plan-error"
				role="alert">
				{{ error }}
			</p>
		</div>

		<template #actions>
			<NcButton data-testid="case-plan-cancel" @click="$emit('close')">
				{{ t('dossiq', 'Cancel') }}
			</NcButton>
			<NcButton
				data-testid="case-plan-confirm"
				variant="primary"
				:disabled="!canConfirm"
				@click="confirm">
				{{ t('dossiq', 'Plan follow-up') }}
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
import NcDateTimePicker from '@nextcloud/vue/components/NcDateTimePicker'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import {
	caseActionRefusal,
	earliestFollowUpDate,
	isPlanComplete,
} from '../utils/caseActionsHelpers.js'

const PAGE_REFRESH = 'cn:page:refresh'

export default {
	name: 'CasePlanFollowUpDialog',

	components: {
		NcButton,
		NcDateTimePicker,
		NcDialog,
		NcSelect,
		NcTextField,
	},

	props: {
		/**
		 * The case to plan a follow-up for. Absent when the manifest opened
		 * the dialog: an `open-modal` action carries no object context, so the
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
			caseTypes: [],
			caseType: null,
			date: null,
			title: '',
			loadingTypes: true,
			busy: false,
			error: '',
			earliest: earliestFollowUpDate(),
		}
	},

	computed: {
		/** @return {string} The case this dialog acts on. */
		targetCaseId() {
			return this.caseId || String(this.$route?.params?.id ?? '')
		},

		/** @return {string} The chosen date as YYYY-MM-DD, or the empty string. */
		isoDate() {
			if (!this.date) {
				return ''
			}
			const picked = new Date(this.date)
			if (Number.isNaN(picked.getTime())) {
				return ''
			}
			return picked.toISOString().slice(0, 10)
		},

		/** @return {boolean} Whether the plan may be sent. */
		canConfirm() {
			return (
				this.busy === false
				&& isPlanComplete(
					{
						caseType: this.caseType?.id ?? '',
						date: this.isoDate,
						title: this.title,
					},
					this.earliest,
				)
			)
		},
	},

	/**
	 * Load the case types a follow-up may be.
	 *
	 * @return {Promise<void>} Nothing.
	 * @spec openspec/specs/workflow-definition-engine/spec.md
	 */
	async mounted() {
		await this.loadCaseTypes()
	},

	methods: {
		t,

		/**
		 * Load the published case types.
		 *
		 * @return {Promise<void>} Nothing.
		 * @spec openspec/specs/workflow-definition-engine/spec.md
		 */
		async loadCaseTypes() {
			try {
				const { data } = await axios.get(
					generateUrl(
						'/apps/openregister/api/objects/dossiq/caseType?_limit=200',
					),
				)
				const rows = Array.isArray(data?.results) ? data.results : []
				this.caseTypes = rows
					.filter((row) => row?.isDraft !== true)
					.map((row) => ({
						id: String(row.id ?? ''),
						label: String(row.title ?? row.id ?? ''),
					}))
					.filter((row) => row.id !== '')
			} catch {
				// An unreadable list leaves the picker empty, which the disabled
				// confirm button already communicates. Blocking with an error
				// would be louder than the failure warrants.
				this.caseTypes = []
			} finally {
				this.loadingTypes = false
			}
		},

		/**
		 * Post the plan.
		 *
		 * @return {Promise<void>} Nothing.
		 * @spec openspec/specs/workflow-definition-engine/spec.md
		 */
		async confirm() {
			if (!this.canConfirm || !this.targetCaseId) {
				return
			}
			this.busy = true
			this.error = ''
			try {
				await axios.post(
					generateUrl(
						`/apps/dossiq/api/case/${encodeURIComponent(this.targetCaseId)}/plan`,
					),
					{
						caseType: this.caseType.id,
						date: this.isoDate,
						title: this.title.trim(),
					},
				)
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
.case-plan {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 0;
}

.case-plan__explainer {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.case-plan__error {
	color: var(--color-error);
	margin: 0;
}
</style>
