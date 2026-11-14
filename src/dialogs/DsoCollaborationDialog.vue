<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	Start a collaboration: the case page header action on a case that came in
	through the DSO. Posts to the case's DSO collaboration route, which checks
	the caller may change the case, starts the collaboration request with the
	other competent authority and records it on the case history. A refusal
	keeps the dialog open with its sentence.

	@spec openspec/changes/vth-cases-run-end-to-end
-->
<template>
	<CnDialog
		:name="t('dossiq', 'Start a collaboration')"
		data-testid="dso-collaborate-dialog"
		@closing="$emit('close')">
		<div v-if="sent === null" class="dso-dialog">
			<NcTextField
				v-model="authority"
				data-testid="dso-collaborate-authority"
				:label="t('dossiq', 'Competent authority')" />
			<NcTextArea
				v-model="rationale"
				data-testid="dso-collaborate-rationale"
				:label="t('dossiq', 'What the collaboration is about')" />
			<p
				v-if="error"
				class="dso-dialog__error"
				role="alert"
				data-testid="dso-collaborate-error">
				{{ error }}
			</p>
		</div>
		<p
			v-else
			class="dso-dialog__done"
			role="status"
			data-testid="dso-collaborate-sent">
			{{ t('dossiq', '{authority} was asked to collaborate on this case. The case history records it.', { authority: sent }) }}
		</p>

		<template #actions>
			<template v-if="sent === null">
				<NcButton data-testid="dso-collaborate-cancel" @click="$emit('close')">
					{{ t('dossiq', 'Cancel') }}
				</NcButton>
				<NcButton
					variant="primary"
					data-testid="dso-collaborate-confirm"
					:disabled="!canSend"
					@click="send">
					{{ t('dossiq', 'Start collaboration') }}
				</NcButton>
			</template>
			<NcButton
				v-else
				variant="primary"
				data-testid="dso-collaborate-close"
				@click="$emit('close')">
				{{ t('dossiq', 'Close') }}
			</NcButton>
		</template>
	</CnDialog>
</template>

<script>
import { CnDialog } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { dsoErrorOf, startCollaboration } from '../services/dsoCaseApi.js'

export default {
	name: 'DsoCollaborationDialog',

	components: {
		CnDialog,
		NcButton,
		NcTextArea,
		NcTextField,
	},

	props: {
		/**
		 * The case. Arrives as the unresolved `@objectId` token from an
		 * `open-modal` action, so the route answers instead.
		 */
		caseId: {
			type: String,
			default: '',
		},
	},

	emits: ['close', 'done'],

	data() {
		return {
			authority: '',
			rationale: '',
			busy: false,
			error: '',
			sent: null,
		}
	},

	computed: {
		/** @spec openspec/changes/vth-cases-run-end-to-end */
		targetCaseId() {
			const given = String(this.caseId || '')
			if (given !== '' && given.startsWith('@') === false) {
				return given
			}
			return String(this.$route?.params?.id ?? '')
		},

		/** @spec openspec/changes/vth-cases-run-end-to-end */
		canSend() {
			return this.busy === false
				&& this.targetCaseId !== ''
				&& this.authority.trim() !== ''
				&& this.rationale.trim() !== ''
		},
	},

	methods: {
		t,

		/**
		 * Start the collaboration through the DSO route and show that it was asked.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/vth-cases-run-end-to-end
		 */
		async send() {
			if (!this.canSend) {
				return
			}
			this.busy = true
			this.error = ''
			try {
				const authority = this.authority.trim()
				await startCollaboration(this.targetCaseId, authority, this.rationale.trim())
				this.sent = authority
				this.$emit('done', { authority })
			} catch (error) {
				this.error = dsoErrorOf(error)
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.dso-dialog {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 3);
}

.dso-dialog__error {
	color: var(--color-error-text);
}
</style>
