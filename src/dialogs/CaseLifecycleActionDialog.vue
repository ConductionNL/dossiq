<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  One dialog for the four gestures that change a case without changing its
  status: Suspend, Resume, Extend term and Reopen.

  Each asks for a reason, because each is an act with a statutory basis that
  someone will have to justify later — opschorting under Awb 4:5, verlenging
  under Awb 4:14, and a reopening that puts a legally closed case back into
  handling. The reason is required, and the button says so by staying
  disabled rather than by failing afterwards.

  The dialog reads `/lifecycle` first, so a gesture the case does not allow
  says why up front instead of after a refused POST. That read is what makes
  the Actions menu honest at all: an `open-modal` action's `visibleWhen` can
  only see the case RECORD, and `suspensionAllowed` sits on the case TYPE, so
  the menu offers Suspend on any open case and this dialog is where the case
  type gets its say.

  It is opened both from the page's Actions menu (manifest `open-modal`) and
  from the transitions widget's Resume button, which is the one gesture a
  suspended case needs in front of the handler rather than behind a menu.

  @spec openspec/specs/status-transition-engine/spec.md
-->
<template>
	<NcDialog
		:name="title"
		data-testid="case-lifecycle-dialog"
		@closing="$emit('close')">
		<div class="case-lifecycle-dialog">
			<p class="case-lifecycle-dialog__explainer">
				{{ explainer }}
			</p>

			<NcTextArea
				v-model="reason"
				data-testid="case-lifecycle-reason"
				:label="t('dossiq', 'Reason')" />

			<NcTextField
				v-if="action === 'suspend'"
				v-model="days"
				data-testid="case-lifecycle-days"
				type="number"
				:label="t('dossiq', 'Days the applicant is given')" />

			<p
				v-if="error"
				class="case-lifecycle-dialog__error"
				data-testid="case-lifecycle-error"
				role="alert">
				{{ error }}
			</p>
		</div>

		<template #actions>
			<NcButton data-testid="case-lifecycle-cancel" @click="$emit('close')">
				{{ t('dossiq', 'Cancel') }}
			</NcButton>
			<NcButton
				data-testid="case-lifecycle-confirm"
				variant="primary"
				:disabled="!canConfirm"
				@click="confirm">
				{{ t('dossiq', 'Confirm') }}
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
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import {
	lifecycleRefusalCode,
	refusalMessage,
} from '../utils/caseLifecycleHelpers.js'

const PAGE_REFRESH = 'cn:page:refresh'

export default {
	name: 'CaseLifecycleActionDialog',

	components: { NcButton, NcDialog, NcTextArea, NcTextField },

	props: {
		/**
		 * The case to act on. Absent when the manifest opened the dialog: an
		 * `open-modal` action carries no object context, so the route answers.
		 */
		caseId: {
			type: String,
			default: '',
		},

		/** One of suspend, resume, extend, reopen. */
		action: {
			type: String,
			required: true,
		},
	},

	emits: ['close'],

	data() {
		return {
			reason: '',
			days: '14',
			error: '',
			busy: false,
			refused: false,
		}
	},

	computed: {
		/** @spec openspec/specs/status-transition-engine/spec.md */
		targetCaseId() {
			return this.caseId || String(this.$route?.params?.id ?? '')
		},

		/** @spec openspec/specs/status-transition-engine/spec.md */
		title() {
			switch (this.action) {
				case 'suspend':
					return t('dossiq', 'Suspend this case')
				case 'resume':
					return t('dossiq', 'Resume this case')
				case 'extend':
					return t('dossiq', 'Extend the term')
				default:
					return t('dossiq', 'Reopen this case')
			}
		},

		/** @spec openspec/specs/status-transition-engine/spec.md */
		explainer() {
			switch (this.action) {
				case 'suspend':
					return t(
						'dossiq',
						'The processing term stops while the case is suspended (Awb 4:5).',
					)
				case 'resume':
					return t('dossiq', 'The processing term starts running again.')
				case 'extend':
					return t(
						'dossiq',
						'The deadline moves by the period this case type states (Awb 4:14).',
					)
				default:
					return t(
						'dossiq',
						'The case returns to the first status of its case type.',
					)
			}
		},

		/** @spec openspec/specs/status-transition-engine/spec.md */
		canConfirm() {
			return (
				this.busy === false
				&& this.refused === false
				&& this.reason.trim().length > 0
			)
		},
	},

	/**
	 * Ask the case what it allows before the handler types anything.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	async mounted() {
		await this.checkAllowed()
	},

	methods: {
		t,

		/**
		 * Ask the case whether it allows this gesture, before anything is typed.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/status-transition-engine/spec.md
		 */
		async checkAllowed() {
			if (!this.targetCaseId) {
				return
			}
			let state
			try {
				const { data } = await axios.get(
					generateUrl(
						`/apps/dossiq/api/case/${encodeURIComponent(this.targetCaseId)}/lifecycle`,
					),
				)
				state = data ?? null
			} catch {
				// An unreadable state refuses nothing: the POST is the authority
				// either way, and blocking here would hide a gesture the case
				// does allow.
				return
			}
			const code = lifecycleRefusalCode(this.action, state)
			if (code !== '') {
				this.refused = true
				this.error = refusalMessage({ code }, (s) => t('dossiq', s))
			}
		},

		/**
		 * Post the gesture, and keep the dialog open on a refusal.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/status-transition-engine/spec.md
		 */
		async confirm() {
			if (!this.canConfirm || !this.targetCaseId) {
				return
			}
			this.busy = true
			this.error = ''
			const payload = { reason: this.reason }
			if (this.action === 'suspend') {
				payload.days = Number(this.days) || 0
			}
			try {
				await axios.post(
					generateUrl(
						`/apps/dossiq/api/case/${encodeURIComponent(this.targetCaseId)}/${this.action}`,
					),
					payload,
				)
				// Tell the whole page, not just whoever opened this dialog: the
				// Actions menu opens it with no parent widget listening, and the
				// strip and the stepper both need to re-read the case.
				emit(PAGE_REFRESH, {})
				this.$emit('close')
			} catch (error) {
				this.error = refusalMessage(error?.response?.data ?? {}, (s) =>
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
.case-lifecycle-dialog {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 16px 16px;
}

.case-lifecycle-dialog__error {
	color: var(--color-error-text, var(--color-error));
}
</style>
