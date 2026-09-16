<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Start the next version of a case type, and land on the draft.

  A dialog and not a declarative `api-call`, for the reason Duplicate is one:
  the action posts, toasts and refreshes the page you are already on, so a
  person who asked for a new version would be left looking at the old one with
  no clue where the draft went. This posts, reads the new id out of the answer,
  and routes there.

  It says what a version is before it makes one, because the gesture beside it
  is Duplicate and the two are easy to mix up. A duplicate is a second case
  type. A version is this case type later on: same name, same identifier, and
  the cases running today stay where they are.

  @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
-->
<template>
	<NcDialog
		:name="t('dossiq', 'Start a new version')"
		data-testid="case-type-new-version-dialog"
		@closing="$emit('close')">
		<div class="case-type-new-version">
			<p>
				{{
					t(
						'dossiq',
						'The new version keeps the name and the identifier of this one. It copies the statuses, results, attributes and the workflow.',
					)
				}}
			</p>
			<p>
				{{
					t(
						'dossiq',
						'Cases already running stay on the version they were filed under. The draft starts taking new cases once you publish it.',
					)
				}}
			</p>

			<p
				v-if="error"
				class="case-type-new-version__error"
				data-testid="case-type-new-version-error"
				role="alert">
				{{ error }}
			</p>
		</div>

		<template #actions>
			<NcButton
				data-testid="case-type-new-version-cancel"
				@click="$emit('close')">
				{{ t('dossiq', 'Cancel') }}
			</NcButton>
			<NcButton
				data-testid="case-type-new-version-confirm"
				variant="primary"
				:disabled="busy"
				@click="confirm">
				{{ t('dossiq', 'Start the draft') }}
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
	name: 'CaseTypeNewVersionDialog',

	components: { NcButton, NcDialog },

	props: {
		/**
		 * The case type to version. Falls back to the route: an `open-modal`
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
		 * Written as literal t() calls here rather than inside the helper,
		 * because `tests/l10n/check-l10n.js` extracts by finding a literal in a
		 * t() call for this app and cannot see a string passing through a
		 * callback.
		 *
		 * @return {object} The messages.
		 *
		 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
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
		 * The case type this dialog versions.
		 *
		 * @return {string} The id.
		 *
		 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
		 */
		id() {
			return this.caseTypeId || String(this.$route?.params?.id ?? '')
		},
	},

	methods: {
		t,

		/**
		 * Make the next version and go to it.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
		 */
		async confirm() {
			this.busy = true
			this.error = ''
			try {
				const { data } = await axios.post(
					generateUrl(
						`/apps/dossiq/api/case-definitions/${encodeURIComponent(this.id)}/new-version`,
					),
				)
				const draftId = copiedCaseTypeId(data)
				this.$emit('close')
				if (draftId) {
					this.$router.push({
						name: 'CaseTypeDetail',
						params: { id: draftId },
					})
					return
				}
				// A draft that was made but cannot be located is still a draft:
				// say nothing false, and leave the person where they were.
				this.error = t(
					'dossiq',
					'The draft was made but could not be opened.',
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
.case-type-new-version {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 0 12px 12px;
}

.case-type-new-version__error {
	color: var(--color-error-text, var(--color-error));
}
</style>
