<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  The case exactly as OpenRegister stored it, for an administrator who is
  working out why a surface shows what it shows.

  WHY NOT CnObjectMetadataModal, which the design named. Two reasons, and
  either one alone would be enough. It takes a REQUIRED `objectData` object,
  and an `open-modal` action forwards its props verbatim, so there is no way
  for the manifest to hand it the case. And it renders the `@self` block,
  which is the metadata around the record rather than the record: it answers
  who owns the case and when it was written, and never shows a single stored
  property. The requirement asks for the raw JSON, so this reads the object
  and prints it.

  It reads the case from the ROUTE, the way CaseCopyDialog does and for the
  same reason: a manifest `open-modal` carries no object context, and
  `@objectId` would arrive as that literal string.

  THIS IS A READING SURFACE AND NOT A PERMISSION. Hiding the Inspect entry
  from a handler is an affordance; the refusal is OpenRegister's, and it is
  the one that answers this fetch too. A handler who reached this dialog by
  hand sees the refusal, not the case.

  @spec openspec/changes/admin-inspect-entry/specs/case-management/spec.md
-->
<template>
	<NcDialog
		:name="t('dossiq', 'Raw data')"
		size="large"
		data-testid="case-raw-data-dialog"
		@closing="$emit('close')">
		<div class="case-raw-data">
			<p class="case-raw-data__explainer">
				{{
					t(
						'dossiq',
						'The case as Open Register stored it, with nothing left out and nothing renamed.',
					)
				}}
			</p>

			<NcLoadingIcon v-if="busy" :size="32" />

			<pre
				v-else-if="raw !== ''"
				class="case-raw-data__json"
				data-testid="case-raw-data-json">{{ raw }}</pre>

			<p
				v-if="error"
				class="case-raw-data__error"
				data-testid="case-raw-data-error"
				role="alert">
				{{ error }}
			</p>
		</div>

		<template #actions>
			<NcButton data-testid="case-raw-data-close" @click="$emit('close')">
				{{ t('dossiq', 'Close') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'

export default {
	name: 'CaseRawDataDialog',

	components: { NcButton, NcDialog, NcLoadingIcon },

	props: {
		/**
		 * The case to show. Absent when the manifest opened the dialog: an
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
			raw: '',
			error: '',
			busy: false,
		}
	},

	computed: {
		/**
		 * @return {string} The case this dialog reads.
		 * @spec openspec/changes/admin-inspect-entry/specs/case-management/spec.md
		 */
		targetCaseId() {
			return this.caseId || String(this.$route?.params?.id ?? '')
		},
	},

	/**
	 * Read the case as soon as the dialog is on screen.
	 *
	 * @return {Promise<void>} Nothing.
	 * @spec openspec/changes/admin-inspect-entry/specs/case-management/spec.md
	 */
	async mounted() {
		await this.load()
	},

	methods: {
		t,

		/**
		 * Fetch the stored object and render it as indented JSON.
		 *
		 * An unreadable case says so in words. A dialog that opened empty and
		 * silent is the failure this whole change exists to prevent: the
		 * reader is here because something is already not adding up.
		 *
		 * @return {Promise<void>} Nothing.
		 * @spec openspec/changes/admin-inspect-entry/specs/case-management/spec.md
		 */
		async load() {
			if (!this.targetCaseId) {
				this.error = t('dossiq', 'This dialog was opened without a case.')
				return
			}
			this.busy = true
			this.error = ''
			try {
				const { data } = await axios.get(
					generateUrl(
						`/apps/openregister/api/objects/dossiq/case/${encodeURIComponent(this.targetCaseId)}`,
					),
				)
				this.raw = JSON.stringify(data, null, 2)
			} catch (err) {
				this.raw = ''
				this.error =
					err?.response?.status === 403
						? t('dossiq', 'You may not read this case.')
						: t('dossiq', 'The case could not be read.')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.case-raw-data {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 0;
}

.case-raw-data__explainer {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.case-raw-data__json {
	background-color: var(--color-background-dark);
	border-radius: var(--border-radius);
	margin: 0;
	max-height: 60vh;
	overflow: auto;
	padding: 12px;
	white-space: pre-wrap;
	word-break: break-word;
}

.case-raw-data__error {
	color: var(--color-error);
	margin: 0;
}
</style>
