<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Handing a case to another team inside the organisation.

  Three things and no more: which team, why, and whether this is a doorzending
  under Awb 2:3. The reason is required, because a case that turns up on
  another team's list with no explanation is a case that gets sent straight
  back.

  The doorzending checkbox is the one field nobody can default. Ticked, the
  applicant is told the case moved and to whom. Unticked, nothing is sent,
  because an internal move between two teams of the same bestuursorgaan is our
  arrangement and not their news. Defaulting it either way would either mail a
  citizen about a corridor or leave a statutory duty unperformed, so it starts
  off and the handler says.

  @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md
-->
<template>
	<CnDialog
		:name="t('dossiq', 'Hand this case to another team')"
		:eyebrow="eyebrow"
		:subtitle="t('dossiq', 'The case keeps its number, its history and its term. Only the team changes.')"
		width="form"
		data-testid="case-handover-dialog"
		@closing="$emit('close')">
		<div class="case-handover-dialog">
			<div class="case-handover-dialog__field">
				<label class="case-handover-dialog__label" for="case-handover-team">
					{{ t('dossiq', 'New team') }}
				</label>
				<CnSearchSelect
					:modelValue="teamOption"
					inputId="case-handover-team"
					:inputLabel="t('dossiq', 'New team')"
					:labelOutside="true"
					:options="teamOptions"
					:loading="searching"
					:filterable="false"
					:placeholder="t('dossiq', 'The group that handles it from here')"
					data-testid="case-handover-team"
					@update:modelValue="teamOption = $event"
					@search="searchTeams" />
				<span class="case-handover-dialog__hint">
					{{ currentTeam ? t('dossiq', 'Now with {team}. The new team picks its own handler.', { team: currentTeam }) : t('dossiq', 'The new team picks its own handler.') }}
				</span>
			</div>

			<div class="case-handover-dialog__field">
				<label class="case-handover-dialog__label" for="case-handover-reason">{{ t('dossiq', 'Why it is moving') }}</label>
				<NcTextArea
					id="case-handover-reason"
					v-model="reason"
					:label="t('dossiq', 'Why it is moving')"
					:labelOutside="true"
					resize="vertical"
					data-testid="case-handover-reason" />
				<span class="case-handover-dialog__hint">
					{{ t('dossiq', 'Required. The receiving team reads this before it accepts the case or sends it back.') }}
				</span>
			</div>

			<div class="case-handover-dialog__check" :class="{ 'case-handover-dialog__check--on': doorzending }">
				<NcCheckboxRadioSwitch
					:modelValue="doorzending"
					data-testid="case-handover-doorzending"
					@update:modelValue="doorzending = $event">
					<span class="case-handover-dialog__check-title">
						{{ t('dossiq', 'Tell the applicant, this is a doorzending (Awb 2:3)') }}
					</span>
				</NcCheckboxRadioSwitch>
				<span class="case-handover-dialog__check-note">
					{{ t('dossiq', 'The applicant then gets a message that the case moves to another team, and to which. Leave it off for a move inside the organisation.') }}
				</span>
			</div>

			<p
				v-if="error"
				class="case-handover-dialog__error"
				data-testid="case-handover-error"
				role="alert">
				{{ error }}
			</p>
		</div>

		<template #actions>
			<NcButton data-testid="case-handover-cancel" @click="$emit('close')">
				{{ t('dossiq', 'Cancel') }}
			</NcButton>
			<NcButton
				data-testid="case-handover-confirm"
				variant="primary"
				:disabled="!canConfirm"
				@click="confirm">
				<template #icon>
					<SwapHorizontal :size="20" />
				</template>
				{{ t('dossiq', 'Hand it over') }}
			</NcButton>
		</template>
	</CnDialog>
</template>

<script>
import { CnDialog, CnSearchSelect } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { emit } from '@nextcloud/event-bus'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import SwapHorizontal from 'vue-material-design-icons/SwapHorizontal.vue'
import { caseEyebrow, fetchCaseSummary } from '../services/caseSummary.js'
import { searchTeams } from '../services/teamSearch.js'

const PAGE_REFRESH = 'cn:page:refresh'

export default {
	name: 'CaseHandoverDialog',

	components: {
		CnDialog,
		CnSearchSelect,
		NcButton,
		NcCheckboxRadioSwitch,
		NcTextArea,
		SwapHorizontal,
	},

	props: {
		/**
		 * The case to hand on. Absent when the manifest opened the dialog: an
		 * `open-modal` action carries no object context, so the route answers.
		 */
		caseId: {
			type: String,
			default: '',
		},
	},

	emits: ['close'],

	data() {
		return {
			kase: null,
			teamOption: null,
			teamOptions: [],
			searching: false,
			reason: '',
			doorzending: false,
			error: '',
			busy: false,
		}
	},

	computed: {
		/**
		 * @return {string} The group id the case moves to, or a typed name.
		 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md
		 */
		team() {
			const option = this.teamOption
			if (option === null || option === undefined) {
				return ''
			}
			return String(typeof option === 'object' ? (option.id ?? option.label ?? '') : option)
		},

		/**
		 * @return {string} The context line above the title: the act and the case number.
		 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md
		 */
		eyebrow() {
			return caseEyebrow(t('dossiq', 'Hand over'), this.kase)
		},

		/**
		 * @return {string} The team the case is with now, by its public name.
		 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md
		 */
		currentTeam() {
			return String(this.kase?.assignedGroupPublicName || this.kase?.assignedGroup || '')
		},

		/** @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md */
		targetCaseId() {
			return this.caseId || String(this.$route?.params?.id ?? '')
		},

		/** @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md */
		canConfirm() {
			return (
				this.busy === false
				&& this.team.trim().length > 0
				&& this.reason.trim().length > 0
			)
		},
	},

	async mounted() {
		this.kase = await fetchCaseSummary(this.targetCaseId)
	},

	methods: {
		t,

		/**
		 * Look up the groups whose name matches what was typed.
		 *
		 * @param {string} query What was typed.
		 * @return {Promise<void>}
		 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md
		 */
		async searchTeams(query) {
			this.searching = true
			try {
				this.teamOptions = (await searchTeams(query)).map((team) => ({ ...team, icon: 'AccountGroupOutline' }))
			} catch {
				this.teamOptions = []
			} finally {
				this.searching = false
			}
		},

		/**
		 * Hand the case on, and keep the dialog open on a refusal.
		 *
		 * The refusal is shown verbatim from `message`, which is the sentence
		 * its author wrote at the throw site (ADR-050). Replacing it with a
		 * generic line here is how "that team could not be found" became "could
		 * not complete the request" everywhere else in this app.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md
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
						`/apps/dossiq/api/case/${encodeURIComponent(this.targetCaseId)}/handover`,
					),
					{
						team: this.team.trim(),
						reason: this.reason.trim(),
						doorzending: this.doorzending,
					},
				)
				// Tell the whole page: the Actions menu opens this with no parent
				// widget listening, and the seats section and the header both need
				// to re-read the case.
				emit(PAGE_REFRESH, {})
				this.$emit('close')
			} catch (error) {
				this.error =
					error?.response?.data?.message
					|| t('dossiq', 'The case was not handed on.')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.case-handover-dialog {
	display: flex;
	flex-direction: column;
	gap: 18px;
}

.case-handover-dialog__field {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.case-handover-dialog__label {
	font-size: 14px;
	font-weight: 600;
}

.case-handover-dialog__hint,
.case-handover-dialog__check-note {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.case-handover-dialog__check {
	display: flex;
	flex-direction: column;
	gap: 2px;
	padding: 12px 14px;
	border: 1px solid var(--color-border-dark);
	border-radius: 8px;
}

.case-handover-dialog__check--on {
	border: 2px solid var(--color-primary-element);
	background: var(--color-primary-element-light);
}

.case-handover-dialog__check-title {
	font-size: 15px;
	font-weight: 600;
}

.case-handover-dialog__check-note {
	padding-inline-start: 44px;
}

.case-handover-dialog__error {
	margin: 0;
	color: var(--color-error-text, var(--color-error));
}
</style>
