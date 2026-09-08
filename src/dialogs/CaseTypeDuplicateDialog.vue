<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Duplicate a case type, and land on the copy.

  The landing is why this is a dialog and not a declarative `api-call`. That
  action posts, toasts and refreshes the page you are already on, so a person
  who asked for a copy would be left looking at the original with no clue
  where the copy went — and a case type list of twenty is not a place you find
  a new row by scrolling. This posts, reads the new id out of the answer, and
  routes there.

  It confirms first because a duplicate is cheap to make and tedious to undo:
  the copy carries every status, result, role and attribute of the original.

  @spec openspec/specs/workflow-import-export/spec.md
-->
<template>
	<NcDialog
		:name="t('dossiq', 'Duplicate case type')"
		data-testid="case-type-duplicate-dialog"
		@closing="$emit('close')">
		<div class="case-type-duplicate">
			<p>
				{{
					t(
						'dossiq',
						'A copy is made as a draft, with the same statuses, results, roles and attributes. You will land on it.',
					)
				}}
			</p>

			<p
				v-if="error"
				class="case-type-duplicate__error"
				data-testid="case-type-duplicate-error"
				role="alert">
				{{ error }}
			</p>
		</div>

		<template #actions>
			<NcButton
				data-testid="case-type-duplicate-cancel"
				@click="$emit('close')">
				{{ t('dossiq', 'Cancel') }}
			</NcButton>
			<NcButton
				data-testid="case-type-duplicate-confirm"
				variant="primary"
				:disabled="busy"
				@click="confirm">
				{{ t('dossiq', 'Duplicate') }}
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
import { copiedCaseTypeId, publishRefusalMessage } from '../utils/caseTypePublish.js'

export default {
	name: 'CaseTypeDuplicateDialog',

	components: { NcButton, NcDialog },

	props: {
		/**
		 * The case type to copy. Falls back to the route: an `open-modal`
		 * header action forwards its props verbatim, so a manifest `@objectId`
		 * would arrive as that literal string.
		 */
		caseTypeId: {
			type: String,
			default: '',
		},
	},

	emits: ['close'],

	data() {
		return { busy: false, error: '' }
	},

	computed: {
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

		/**
		 * The case type this dialog copies.
		 *
		 * @return {string} The id.
		 */
		id() {
			return this.caseTypeId || String(this.$route?.params?.id ?? '')
		},
	},

	methods: {
		t,

		/**
		 * Make the copy and go to it.
		 *
		 * @return {Promise<void>}
		 */
		async confirm() {
			this.busy = true
			try {
				const { data } = await axios.post(
					generateUrl(
						`/apps/dossiq/api/case-definitions/${encodeURIComponent(this.id)}/copy`,
					),
				)
				const copyId = copiedCaseTypeId(data)
				this.$emit('close')
				if (copyId) {
					this.$router.push({
						name: 'CaseTypeDetail',
						params: { id: copyId },
					})
					return
				}
				// A copy that was made but cannot be located is still a copy:
				// say nothing false, and leave the person where they were.
				this.error = t(
					'dossiq',
					'The copy was made but could not be opened.',
				)
			} catch (e) {
				this.error = publishRefusalMessage(e, this.refusalMessages)
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.case-type-duplicate {
	padding: 0 12px 12px;
}

.case-type-duplicate__error {
	color: var(--color-error-text, var(--color-error));
}
</style>
