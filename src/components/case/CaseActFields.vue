<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  The fields one lifecycle act asks for: the archive summary for archiving,
  a result template and a result for an ending act, a reason, a return date
  or a number of days. The lifecycle dialog draws them under the chosen act's
  card, or on their own when it opens on one act.

  @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
-->
<template>
	<div
		class="case-act-fields"
		:class="{ 'case-act-fields--paired': act.id === 'suspend' }"
		:data-testid="'case-act-fields-' + act.id">
	<template v-if="act.id === 'archive' && kase">
		<div class="case-acts__case" data-testid="case-act-archive-case">
			<FolderOutline :size="20" class="case-acts__case-icon" />
			<span class="case-acts__case-text">
				<strong>{{ kase.title }}</strong>
				<span class="case-acts__muted">{{ [kase.identifier, kase.initiatorDisplayName].filter(Boolean).join(' · ') }}</span>
			</span>
			<span v-if="kase.resultPublicLabel" class="case-acts__badge">{{ kase.resultPublicLabel }}</span>
		</div>

		<section class="case-acts__retention" data-testid="case-act-archive-retention">
			<h3 class="case-acts__section-title">
				{{ t('dossiq', 'Retention under the case type') }}
			</h3>
			<dl class="case-acts__facts">
				<div>
					<dt>{{ t('dossiq', 'Archive nomination') }}</dt>
					<dd>{{ kase.archiveNomination || t('dossiq', 'Not set') }}</dd>
				</div>
				<div>
					<dt>{{ t('dossiq', 'Destruction date') }}</dt>
					<dd>{{ kase.archiveActionDate || t('dossiq', 'Not set') }}</dd>
				</div>
				<div>
					<dt>{{ t('dossiq', 'Archive status afterwards') }}</dt>
					<dd>{{ kase.archiveStatus || t('dossiq', 'Not set') }}</dd>
				</div>
			</dl>
		</section>
	</template>

	<TemplatePicker
		v-if="inputs.result"
		kind="result"
		:caseType="caseType"
		:caseId="caseId"
		:label="t('dossiq', 'Result template')"
		@apply="$emit('applyTemplate', $event)" />

	<NcSelect
		v-if="inputs.reason && picksPauseReason"
		:modelValue="selectedPauseReason"
		:options="pauseReasonOptions"
		label="name"
		:inputLabel="t('dossiq', 'Reason')"
		:clearable="false"
		:disabled="busy"
		data-testid="case-act-pause-reason-picker"
		@update:modelValue="$emit('update:pauseReason', $event ? String($event.key) : '')" />

	<div v-if="inputs.reason && asksText" class="case-acts__field">
		<label class="case-acts__label" for="case-act-reason">{{ t('dossiq', 'Reason') }}</label>
		<NcTextArea
			id="case-act-reason"
			:modelValue="reason"
			data-testid="case-act-reason-input"
			:label="t('dossiq', 'Reason')"
			:labelOutside="true"
			resize="vertical"
			@update:modelValue="$emit('update:reason', $event)" />
		<span class="case-acts__muted">
			{{ t('dossiq', 'Goes into the history of the case.') }}
		</span>
	</div>

	<NcNoteCard
		v-if="act.id === 'archive'"
		type="info"
		:heading="t('dossiq', 'Read only afterwards')">
		{{ t('dossiq', 'Once archived, the documents, tasks and messages of this case can no longer be changed. The case stays findable until its archive action date.') }}
	</NcNoteCard>

	<!--
		A closing move or ending act picks from the case type's own
		result types, which the server publishes on the move and on
		`/acts`. There is no free field: a typed name is not an id, and
		a case type with no results needs none.
	-->
	<NcSelect
		v-if="inputs.result && resultOptions.length > 0"
		:modelValue="selectedResult"
			:options="resultOptions"
		label="name"
		:inputLabel="t('dossiq', 'Result')"
		:clearable="false"
		:disabled="busy"
		data-testid="case-act-result-picker"
		@update:modelValue="$emit('update:resultTypeId', $event ? String($event.id) : '')" />

	<NcTextField
		v-if="inputs.until"
		:modelValue="until"
			type="date"
		data-testid="case-act-until-input"
		:label="t('dossiq', 'The case comes back on')"
		@update:modelValue="$emit('update:until', $event)" />

	<NcTextField
		v-if="inputs.days"
		:modelValue="days"
			type="number"
		data-testid="case-act-days-input"
		:label="t('dossiq', 'Days the applicant is given')"
		:helperText="legalBasis"
		@update:modelValue="$emit('update:days', $event)" />

	<div v-if="inputs.days && comesBackOn" class="case-acts__field case-acts__field--wide" data-testid="case-act-comes-back">
		<span class="case-acts__label">{{ t('dossiq', 'The case comes back on') }}</span>
		<span data-testid="case-act-comes-back-date">{{ comesBackOn }}</span>
		<span class="case-acts__muted">
			{{ t('dossiq', 'You pick the case up earlier if the answer comes in earlier.') }}
		</span>
	</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import FolderOutline from 'vue-material-design-icons/FolderOutline.vue'
import TemplatePicker from '../TemplatePicker.vue'
import { formatDate } from '../../utils/caseHelpers.js'
import {
	asksReasonText,
	offersPauseReasons,
	OTHER_PAUSE_REASON,
	pickedPauseReason,
} from '../../utils/pauseReasonChoice.js'

export default {
	name: 'CaseActFields',

	components: {
		FolderOutline,
		NcNoteCard,
		NcSelect,
		NcTextArea,
		NcTextField,
		TemplatePicker,
	},

	props: {
		/** The chosen act, a menu entry. */
		act: { type: Object, required: true },
		/** Which fields the act asks for (`inputsFor`). */
		inputs: { type: Object, required: true },
		/** The case, read for the archive summary; null until read. */
		kase: { type: Object, default: null },
		/** The case type, scoping the result templates. */
		caseType: { type: String, default: '' },
		/** The case id. */
		caseId: { type: String, default: '' },
		/** The result types the act may close with. */
		resultOptions: { type: Array, default: () => [] },
		/** Whether the act is posting. */
		busy: { type: Boolean, default: false },
		/** The reason typed so far. */
		reason: { type: String, default: '' },
		/** The chosen result type id. */
		resultTypeId: { type: String, default: '' },
		/** The return date. */
		until: { type: String, default: '' },
		/** The days the applicant is given. */
		days: { type: String, default: '' },
		/** The pause reasons the case type declares: `{ key, name, legalBasis }`. */
		pauseReasons: { type: Array, default: () => [] },
		/** The picked pause reason key, OTHER_PAUSE_REASON, or ''. */
		pauseReason: { type: String, default: '' },
	},

	emits: ['update:reason', 'update:resultTypeId', 'update:until', 'update:days', 'update:pauseReason', 'applyTemplate'],

	computed: {
		/**
		 * Whether suspend offers the case type's pause reasons.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/suspend-picks-a-pause-reason/specs/termijn-pause-extension/spec.md
		 */
		picksPauseReason() {
			return offersPauseReasons(this.act, this.pauseReasons)
		},

		/**
		 * The picker's entries: the declared reasons, then Other for free text.
		 *
		 * @return {Array<{key: string, name: string}>}
		 * @spec openspec/changes/suspend-picks-a-pause-reason/specs/termijn-pause-extension/spec.md
		 */
		pauseReasonOptions() {
			return [
				...this.pauseReasons.map((reason) => ({ key: reason.key, name: reason.name || reason.key })),
				{ key: OTHER_PAUSE_REASON, name: t('dossiq', 'Other') },
			]
		},

		/**
		 * The picker's current entry.
		 *
		 * @return {object|null}
		 * @spec openspec/changes/suspend-picks-a-pause-reason/specs/termijn-pause-extension/spec.md
		 */
		selectedPauseReason() {
			return this.pauseReasonOptions.find((option) => option.key === this.pauseReason) ?? null
		},

		/**
		 * Whether the free-text reason shows.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/suspend-picks-a-pause-reason/specs/termijn-pause-extension/spec.md
		 */
		asksText() {
			return asksReasonText(this.act, this.pauseReasons, this.pauseReason)
		},

		/**
		 * The ground the picked reason rests on, as the case type states it; '' without one.
		 *
		 * @return {string}
		 * @spec openspec/changes/suspend-picks-a-pause-reason/specs/termijn-pause-extension/spec.md
		 */
		legalBasis() {
			return String(pickedPauseReason(this.pauseReasons, this.pauseReason)?.legalBasis ?? '')
		},

		/**
		 * The day a suspended case comes back: today plus the days the
		 * applicant is given. '' without days.
		 *
		 * @return {string} The date, or ''.
		 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
		 */
		comesBackOn() {
			const days = Number.parseInt(this.days, 10)
			if (!Number.isFinite(days) || days < 1) {
				return ''
			}
			const date = new Date()
			date.setDate(date.getDate() + days)

			return formatDate(date.toISOString())
		},

		/**
		 * The chosen result as its option.
		 *
		 * @return {object|null} The option, or null.
		 * @spec openspec/specs/status-transition-engine/spec.md
		 */
		selectedResult() {
			return this.resultOptions.find((option) => option.id === this.resultTypeId) ?? null
		},
	},

	methods: {
		t,
	},
}
</script>

<style scoped>
.case-act-fields {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

/* Suspend sets the reason and the days side by side, as board DqVolgendeStap. */
.case-act-fields--paired {
	display: grid;
	grid-template-columns: repeat(2, minmax(0, 1fr));
	align-items: start;
}

.case-acts__field--wide {
	grid-column: 1 / -1;
}

.case-acts__case {
	display: flex;
	align-items: center;
	gap: 14px;
	padding: 14px 16px;
	border: 1px solid var(--color-border);
	border-radius: 8px;
}

.case-acts__case-icon {
	color: var(--color-primary-element);
}

.case-acts__case-text {
	display: flex;
	flex: 1 1 auto;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
}

.case-acts__badge {
	padding: 3px 10px;
	border-radius: 12px;
	background: var(--color-success-light, var(--color-background-dark));
	color: var(--color-success-text);
	font-size: 13px;
	font-weight: 600;
}

.case-acts__muted {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.case-acts__section-title {
	margin: 0 0 8px;
	font-size: 14px;
	font-weight: 600;
}

.case-acts__facts {
	display: grid;
	grid-template-columns: repeat(2, minmax(0, 1fr));
	gap: 0 24px;
	margin: 0;
}

.case-acts__facts > div {
	padding: 10px 0;
	border-bottom: 1px solid var(--color-border);
}

.case-acts__facts dt {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.case-acts__facts dd {
	margin: 2px 0 0;
	font-size: 15px;
	font-weight: 600;
}

.case-acts__label {
	font-size: 14px;
	font-weight: 600;
}

.case-acts__field {
	display: flex;
	flex-direction: column;
	gap: 6px;
}
</style>
