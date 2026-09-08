<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Publish a draft case type: the validation findings first, then the change
  note.

  IN THAT ORDER, and that is the whole reason this is a dialog rather than a
  declarative `api-call`. A person about to be refused should be told before
  being made to write a note they will lose. So the dialog reads
  `/publish/validate` on open and, when it comes back with findings, shows
  them and offers no note field and no Publish button — there is nothing to
  publish yet.

  The change note is required when there IS something to publish: a version
  whose note is blank tells the next reader nothing about what changed, which
  is the only question a version list is ever asked.

  @spec openspec/specs/zaaktype-versioning/spec.md
-->
<template>
	<NcDialog
		:name="t('dossiq', 'Publish case type')"
		data-testid="case-type-publish-dialog"
		@closing="$emit('close')">
		<div class="case-type-publish">
			<NcLoadingIcon v-if="loading" :size="24" />

			<template v-else-if="findings.length > 0">
				<p class="case-type-publish__explainer">
					{{
						t(
							'dossiq',
							'This case type is not ready to publish. Fix the following first.',
						)
					}}
				</p>
				<ul
					class="case-type-publish__findings"
					data-testid="case-type-publish-findings">
					<li v-for="finding in findings" :key="finding">
						{{ finding }}
					</li>
				</ul>
			</template>

			<template v-else>
				<p class="case-type-publish__explainer">
					{{
						t(
							'dossiq',
							'Say what changed in this version. The note is kept with it.',
						)
					}}
				</p>
				<NcTextArea
					v-model="changeNote"
					data-testid="case-type-change-note"
					:label="t('dossiq', 'Change note')" />
			</template>

			<p
				v-if="error"
				class="case-type-publish__error"
				data-testid="case-type-publish-error"
				role="alert">
				{{ error }}
			</p>
		</div>

		<template #actions>
			<NcButton data-testid="case-type-publish-cancel" @click="$emit('close')">
				{{ t('dossiq', 'Cancel') }}
			</NcButton>
			<NcButton
				v-if="!loading && findings.length === 0"
				data-testid="case-type-publish-confirm"
				variant="primary"
				:disabled="!canPublish"
				@click="publish">
				{{ t('dossiq', 'Publish') }}
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
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import { publishRefusalMessage } from '../utils/caseTypePublish.js'

const PAGE_REFRESH = 'cn:page:refresh'

export default {
	name: 'CaseTypePublishDialog',

	components: { NcButton, NcDialog, NcLoadingIcon, NcTextArea },

	props: {
		/**
		 * The case type to publish. Falls back to the route, because an
		 * `open-modal` header action forwards its props VERBATIM: a
		 * `@objectId` token in the manifest would arrive as that literal
		 * string, and the dialog would publish a case type called "@objectId".
		 */
		caseTypeId: {
			type: String,
			default: '',
		},
	},

	emits: ['close'],

	data() {
		return {
			loading: true,
			findings: [],
			changeNote: '',
			error: '',
		}
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
		 * The case type this dialog acts on.
		 *
		 * @return {string} The id.
		 */
		id() {
			return this.caseTypeId || String(this.$route?.params?.id ?? '')
		},

		/**
		 * Whether the Publish button does anything yet.
		 *
		 * @return {boolean} True when there is a note and no finding.
		 */
		canPublish() {
			return this.findings.length === 0 && this.changeNote.trim() !== ''
		},
	},

	/**
	 * Ask what stands in the way before asking for anything.
	 *
	 * @return {Promise<void>}
	 */
	async mounted() {
		await this.loadFindings()
	},

	methods: {
		t,

		/**
		 * Read the validation findings.
		 *
		 * @return {Promise<void>}
		 */
		async loadFindings() {
			this.loading = true
			try {
				const { data } = await axios.get(
					generateUrl(
						`/apps/dossiq/api/case-types/${encodeURIComponent(this.id)}/publish/validate`,
					),
				)
				this.findings = Array.isArray(data?.findings) ? data.findings : []
				this.error = ''
			} catch (e) {
				// A validation that cannot run is not a validation that passed:
				// the dialog says so and offers no Publish button.
				this.findings = []
				this.error = publishRefusalMessage(e, this.refusalMessages)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Publish, or show what the server refused on.
		 *
		 * @return {Promise<void>}
		 */
		async publish() {
			try {
				await axios.post(
					generateUrl(
						`/apps/dossiq/api/case-types/${encodeURIComponent(this.id)}/publish`,
					),
					{ changeNote: this.changeNote },
				)
				// The page shows the draft flag and the version list, and both
				// just changed.
				emit(PAGE_REFRESH)
				this.$emit('close')
			} catch (e) {
				// The server validates again, and it is the one that decides:
				// a case type can be edited between the two calls.
				const findings = e?.response?.data?.findings
				if (Array.isArray(findings) && findings.length > 0) {
					this.findings = findings
					return
				}
				this.error = publishRefusalMessage(e, this.refusalMessages)
			}
		},
	},
}
</script>

<style scoped>
.case-type-publish {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 0 12px 12px;
}

.case-type-publish__findings {
	margin: 0;
	padding-left: 20px;
}

.case-type-publish__error {
	color: var(--color-error-text, var(--color-error));
}
</style>
