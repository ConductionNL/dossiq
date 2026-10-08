<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Plan a follow-up case for a later date, once or as a series.

  Three fields are what a scheduled case needs: which type it is, when it
  should exist, and what it is called. Everything else the case inherits from
  its type when it is created.

  Two more turn it into a series. Repeat says how often the case comes back, and
  Ends says when it stops coming back. Both are pickers, never a cron
  expression: five cron fields are a language, and a yearly permit check typed
  as one is a flow that fires every day in January.

  The end fields only appear once a repeat is chosen, because "ends after 3"
  means nothing on a case that happens once.

  The earliest date is TOMORROW. A schedule trigger fires on a cron minute, so
  a follow-up planned for today would fire either in a few hours or not at all
  depending on the clock, which is two behaviours from one gesture.

  It reads the case from the ROUTE, because an open-modal action forwards its
  props verbatim and `@objectId` would arrive as that literal string.

  @spec openspec/changes/planned-case-series/specs/workflow-definition-engine/spec.md
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
			<p class="case-plan__explainer">
				{{
					t(
						'dossiq',
						'Pick a repeat to plan a series, for example a yearly permit check. A series that lands on the 31st moves to the last day of a shorter month.',
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

			<NcSelect
				v-model="recurrence"
				data-testid="case-plan-recurrence"
				:inputLabel="t('dossiq', 'Repeat')"
				:options="recurrences"
				label="label"
				:clearable="false" />

			<template v-if="repeats">
				<NcSelect
					v-model="end"
					data-testid="case-plan-end"
					:inputLabel="t('dossiq', 'Ends')"
					:options="ends"
					label="label"
					:clearable="false" />

				<NcDateTimePicker
					v-if="end && end.id === 'until'"
					v-model="until"
					data-testid="case-plan-until"
					type="date"
					:min="isoDate || earliest"
					:label="t('dossiq', 'End date')" />

				<NcTextField
					v-if="end && end.id === 'count'"
					v-model="count"
					data-testid="case-plan-count"
					type="number"
					min="1"
					:label="t('dossiq', 'Number of cases')" />
			</template>

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
	endOptions,
	isPlanComplete,
	recurrenceOptions,
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
			recurrences: recurrenceOptions((s) => t('dossiq', s)),
			recurrence: recurrenceOptions((s) => t('dossiq', s))[0],
			ends: endOptions((s) => t('dossiq', s)),
			end: endOptions((s) => t('dossiq', s))[0],
			until: null,
			count: '3',
			loadingTypes: true,
			busy: false,
			error: '',
			earliest: earliestFollowUpDate(),
		}
	},

	computed: {
		/**
		 * @return {string} The case this dialog acts on.
		 * @spec openspec/specs/workflow-definition-engine/spec.md
		 */
		targetCaseId() {
			return this.caseId || String(this.$route?.params?.id ?? '')
		},

		/**
		 * @return {string} The chosen date as YYYY-MM-DD, or the empty string.
		 * @spec openspec/specs/workflow-definition-engine/spec.md
		 */
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

		/**
		 * @return {boolean} Whether a repeat was chosen.
		 * @spec openspec/changes/planned-case-series/specs/workflow-definition-engine/spec.md
		 */
		repeats() {
			return (this.recurrence?.id ?? 'none') !== 'none'
		},

		/**
		 * @return {string} The chosen end date as YYYY-MM-DD, or the empty string.
		 * @spec openspec/changes/planned-case-series/specs/workflow-definition-engine/spec.md
		 */
		isoUntil() {
			if (!this.until) {
				return ''
			}
			const picked = new Date(this.until)
			if (Number.isNaN(picked.getTime())) {
				return ''
			}
			return picked.toISOString().slice(0, 10)
		},

		/**
		 * @return {object} The plan as the server reads it.
		 * @spec openspec/changes/planned-case-series/specs/workflow-definition-engine/spec.md
		 */
		plan() {
			const end = this.repeats ? (this.end?.id ?? 'open') : 'open'
			return {
				caseType: this.caseType?.id ?? '',
				date: this.isoDate,
				title: this.title,
				recurrence: this.recurrence?.id ?? 'none',
				end,
				until: end === 'until' ? this.isoUntil : '',
				count: end === 'count' ? Number(this.count) : 0,
			}
		},

		/**
		 * @return {boolean} Whether the plan may be sent.
		 * @spec openspec/changes/planned-case-series/specs/workflow-definition-engine/spec.md
		 */
		canConfirm() {
			return this.busy === false && isPlanComplete(this.plan, this.earliest)
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
		 * @spec openspec/changes/planned-case-series/specs/workflow-definition-engine/spec.md
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
						caseType: this.plan.caseType,
						date: this.plan.date,
						title: this.title.trim(),
						recurrence: this.plan.recurrence,
						until: this.plan.until,
						count: this.plan.count,
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
