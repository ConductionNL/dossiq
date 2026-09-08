<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Copy this case into a new case of the same type.

  The dialog asks two things and nothing else: what the copy is called, and
  whether the source's documents come along. Everything else is a domain rule
  the service owns, because which fields carry over is not a choice a handler
  should be making one case at a time.

  WHY NOT CnCopyDialog, which the design named. The library's copy dialog
  offers three naming PATTERNS over a fixed name and carries no slots at all,
  so there is nowhere to put the Include documents checkbox and no way to type
  a title that is not one of the three patterns. Verified against the installed
  2.41.0 source. A `copy` header-action type over CnCopyDialog taking a field
  list is the blocked task that deletes this file.

  It reads the case from the ROUTE when the manifest opened it: an
  `open-modal` action forwards its props verbatim, so `@objectId` arrives as
  that literal string rather than as a uuid.

  @spec openspec/specs/case-management/spec.md
-->
<template>
	<NcDialog
		:name="t('dossiq', 'Copy this case')"
		data-testid="case-copy-dialog"
		@closing="$emit('close')">
		<div class="case-copy-dialog">
			<p class="case-copy-dialog__explainer">
				{{
					t(
						'dossiq',
						'The copy keeps the case type, the requester and the filled properties, and starts in the first status of its type. It gets its own case number.',
					)
				}}
			</p>

			<NcTextField
				v-model="title"
				data-testid="case-copy-title"
				:label="t('dossiq', 'Title of the copy')" />

			<NcCheckboxRadioSwitch
				v-model="withDocuments"
				data-testid="case-copy-documents"
				type="checkbox">
				{{ t('dossiq', 'Include documents') }}
			</NcCheckboxRadioSwitch>

			<p class="case-copy-dialog__hint">
				{{
					t(
						'dossiq',
						'Documents are linked to the copy, never duplicated. The file stays where it is.',
					)
				}}
			</p>

			<p
				v-if="error"
				class="case-copy-dialog__error"
				data-testid="case-copy-error"
				role="alert">
				{{ error }}
			</p>
		</div>

		<template #actions>
			<NcButton data-testid="case-copy-cancel" @click="$emit('close')">
				{{ t('dossiq', 'Cancel') }}
			</NcButton>
			<NcButton
				data-testid="case-copy-confirm"
				variant="primary"
				:disabled="!canConfirm"
				@click="confirm">
				{{ t('dossiq', 'Copy case') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { caseActionRefusal, proposedCopyTitle } from '../utils/caseActionsHelpers.js'

export default {
	name: 'CaseCopyDialog',

	components: { NcButton, NcCheckboxRadioSwitch, NcDialog, NcTextField },

	props: {
		/**
		 * The case to copy. Absent when the manifest opened the dialog: an
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
			title: '',
			withDocuments: false,
			error: '',
			busy: false,
		}
	},

	computed: {
		/**
		 * @return {string} The case this dialog acts on.
		 * @spec openspec/specs/case-management/spec.md
		 */
		targetCaseId() {
			return this.caseId || String(this.$route?.params?.id ?? '')
		},

		/**
		 * @return {boolean} Whether the copy may be sent.
		 * @spec openspec/specs/case-management/spec.md
		 */
		canConfirm() {
			return this.busy === false && this.title.trim().length > 0
		},
	},

	/**
	 * Propose a title from the case the page is showing.
	 *
	 * @return {Promise<void>} Nothing.
	 * @spec openspec/specs/case-management/spec.md
	 */
	async mounted() {
		await this.proposeTitle()
	},

	methods: {
		t,

		/**
		 * Read the source case and propose "Copy of <title>".
		 *
		 * @return {Promise<void>} Nothing.
		 * @spec openspec/specs/case-management/spec.md
		 */
		async proposeTitle() {
			if (!this.targetCaseId) {
				return
			}
			try {
				const { data } = await axios.get(
					generateUrl(
						`/apps/openregister/api/objects/dossiq/case/${encodeURIComponent(this.targetCaseId)}`,
					),
				)
				this.title = proposedCopyTitle(data?.title ?? '')
			} catch {
				// An unreadable source proposes nothing rather than blocking:
				// the POST is the authority, and it guards the case itself.
				this.title = proposedCopyTitle('')
			}
		},

		/**
		 * Post the copy and open it.
		 *
		 * @return {Promise<void>} Nothing.
		 * @spec openspec/specs/case-management/spec.md
		 */
		async confirm() {
			if (!this.canConfirm || !this.targetCaseId) {
				return
			}
			this.busy = true
			this.error = ''
			try {
				const { data } = await axios.post(
					generateUrl(
						`/apps/dossiq/api/case/${encodeURIComponent(this.targetCaseId)}/copy`,
					),
					{ title: this.title.trim(), documents: this.withDocuments },
				)
				const newId = String(data?.id ?? '')
				this.$emit('close')
				if (newId !== '') {
					this.$router?.push({ name: 'CaseDetail', params: { id: newId } })
				}
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
.case-copy-dialog {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 0;
}

.case-copy-dialog__explainer,
.case-copy-dialog__hint {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.case-copy-dialog__error {
	color: var(--color-error);
	margin: 0;
}
</style>
