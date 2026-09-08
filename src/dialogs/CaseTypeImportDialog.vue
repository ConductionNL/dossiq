<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Import a case-type bundle exported from another instance.

  A dialog rather than a declarative action because the import takes a FILE.
  A header action's confirm gate is a plain confirm dialog with no fields, and
  `api-call` sends a JSON body — neither can carry a multipart upload, so a
  declaratively-declared Import would render a button that cannot do the one
  thing it is for.

  The collision strategy is asked for rather than assumed. Skip is the default
  because it is the only one of the three that cannot lose work: overwrite
  replaces rows the instance already has, and a person importing a bundle from
  a colleague rarely means to do that on the first try.

  @spec openspec/specs/workflow-import-export/spec.md
-->
<template>
	<NcDialog
		:name="t('dossiq', 'Import case type')"
		data-testid="case-type-import-dialog"
		@closing="$emit('close')">
		<div class="case-type-import">
			<p class="case-type-import__explainer">
				{{
					t(
						'dossiq',
						'Pick a bundle exported from another instance. Nothing is written until you confirm.',
					)
				}}
			</p>

			<input
				type="file"
				accept=".zip,application/zip"
				data-testid="case-type-import-file"
				:aria-label="t('dossiq', 'Bundle file')"
				@change="onPick" />

			<NcSelect
				v-model="strategy"
				data-testid="case-type-import-strategy"
				:options="strategies"
				:clearable="false"
				label="label"
				:inputLabel="t('dossiq', 'When the instance already has a row')" />

			<p
				v-if="error"
				class="case-type-import__error"
				data-testid="case-type-import-error"
				role="alert">
				{{ error }}
			</p>
		</div>

		<template #actions>
			<NcButton data-testid="case-type-import-cancel" @click="$emit('close')">
				{{ t('dossiq', 'Cancel') }}
			</NcButton>
			<NcButton
				data-testid="case-type-import-confirm"
				variant="primary"
				:disabled="!file || busy"
				@click="confirm">
				{{ t('dossiq', 'Import') }}
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
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { importStrategies, publishRefusalMessage } from '../utils/caseTypePublish.js'

const PAGE_REFRESH = 'cn:page:refresh'

export default {
	name: 'CaseTypeImportDialog',

	components: { NcButton, NcDialog, NcSelect },

	emits: ['close'],

	data() {
		return {
			file: null,
			strategy: null,
			busy: false,
			error: '',
		}
	},

	computed: {
		/**
		 * The three strategies the import endpoint accepts.
		 *
		 * @return {Array<object>} `{ id, label }` options.
		 */
		strategies() {
			return importStrategies({
				skip: t('dossiq', 'Keep what is already here'),
				merge: t('dossiq', 'Merge the bundle into it'),
				overwrite: t('dossiq', 'Replace it with the bundle'),
			})
		},

		/**
		 * The sentences a refusal can carry, already translated.
		 *
		 * Built here, in literal t() calls, and handed to the helper: a string
		 * that lives in the helper and passes through a translate callback is
		 * invisible to `tests/l10n/check-l10n.js`, which extracts by finding a
		 * literal inside a `t()` call for this app.
		 *
		 * @return {object} The messages.
		 */
		refusalMessages() {
			return {
				signIn: t('dossiq', 'Sign in again and retry.'),
				forbidden: t('dossiq', 'Your account may not do this.'),
				missing: t('dossiq', 'This case type no longer exists.'),
				generic: t(
					'dossiq',
					'That did not work. Try again, or ask an administrator.',
				),
			}
		},
	},

	created() {
		this.strategy = this.strategies[0]
	},

	methods: {
		t,

		/**
		 * Remember the picked file.
		 *
		 * @param {Event} event The change event.
		 * @return {void}
		 */
		onPick(event) {
			this.file = event?.target?.files?.[0] ?? null
			this.error = ''
		},

		/**
		 * Upload the bundle.
		 *
		 * @return {Promise<void>}
		 */
		async confirm() {
			if (!this.file) return
			this.busy = true
			try {
				const body = new FormData()
				body.append('package', this.file)
				body.append('strategy', this.strategy?.id ?? 'skip')

				await axios.post(
					generateUrl('/apps/dossiq/api/case-definitions/import'),
					body,
				)
				// The Case types index is what changed, and the page the person
				// is on lists case types.
				emit(PAGE_REFRESH)
				this.$emit('close')
			} catch (e) {
				// The server's own message names a path; the reason it refused
				// is what a person can act on.
				this.error =
					e?.response?.data?.error && e?.response?.status === 422
						? String(e.response.data.error)
						: publishRefusalMessage(e, this.refusalMessages)
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.case-type-import {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 0 12px 12px;
}

.case-type-import__error {
	color: var(--color-error-text, var(--color-error));
}
</style>
