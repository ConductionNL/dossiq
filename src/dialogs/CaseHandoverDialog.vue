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
	<NcDialog
		:name="t('dossiq', 'Hand this case to another team')"
		data-testid="case-handover-dialog"
		@closing="$emit('close')">
		<div class="case-handover-dialog">
			<p class="case-handover-dialog__explainer">
				{{
					t(
						'dossiq',
						'The case keeps its number, its history and its term. Only the team changes.',
					)
				}}
			</p>

			<NcTextField
				v-model="team"
				data-testid="case-handover-team"
				:label="t('dossiq', 'Team')"
				:placeholder="t('dossiq', 'The group that handles it from here')" />

			<NcTextArea
				v-model="reason"
				data-testid="case-handover-reason"
				:label="t('dossiq', 'Why it is moving')" />

			<NcCheckboxRadioSwitch
				:modelValue="doorzending"
				data-testid="case-handover-doorzending"
				@update:modelValue="doorzending = $event">
				{{
					t(
						'dossiq',
						'Tell the applicant, this is a doorzending (Awb 2:3)',
					)
				}}
			</NcCheckboxRadioSwitch>

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
				{{ t('dossiq', 'Hand it over') }}
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
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'

const PAGE_REFRESH = 'cn:page:refresh'

export default {
	name: 'CaseHandoverDialog',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcTextArea,
		NcTextField,
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
			team: '',
			reason: '',
			doorzending: false,
			error: '',
			busy: false,
		}
	},

	computed: {
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

	methods: {
		t,

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
	gap: 12px;
	padding: 8px 16px 16px;
}

.case-handover-dialog__error {
	color: var(--color-error-text, var(--color-error));
}
</style>
