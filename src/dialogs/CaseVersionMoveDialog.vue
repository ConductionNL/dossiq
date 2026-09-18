<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Move one running case to another version of its case type.

  🔴 THE PREVIEW IS THE POINT, NOT THE PICKER. A case is pinned to the version
  it was filed under because its status is a row only that version holds and
  its term was computed from that version's deadline. Moving it is the
  deliberate exception, so the person doing it is shown what changes before the
  button does anything: which status the case lands in, which statuses and
  fields the other version adds and drops, and which of the dropped fields this
  case has actually answered. A confirm dialog with only a target and a reason
  would be a button that rewrites a case's vocabulary on trust.

  The refusal is shown in the same place, from the server. When the other
  version has no status by the name this case is sitting in, the server says so
  and names it, and the confirm button stays disabled. Guessing a landing
  status here would be a write nobody can read back.

  A reason is required. It goes on the case's own journal beside both version
  ids, because "the case type changed" with nothing next to it sends the next
  person digging through the store.

  @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
-->
<template>
	<NcDialog
		:name="t('dossiq', 'Move to another version')"
		size="normal"
		data-testid="case-version-move-dialog"
		@closing="$emit('close')">
		<div class="case-version-move">
			<p v-if="current.version" class="case-version-move__current">
				{{
					t('dossiq', 'This case runs on version {version}.', {
						version: current.version,
					})
				}}
			</p>

			<p
				v-if="!loading && targets.length === 0"
				data-testid="case-version-move-empty">
				{{
					t(
						'dossiq',
						'This case type has no other published version to move to.',
					)
				}}
			</p>

			<NcSelect
				v-if="targets.length > 0"
				v-model="target"
				data-testid="case-version-move-target"
				:inputLabel="t('dossiq', 'Version to move to')"
				:options="targetOptions"
				:reduce="option => option.id"
				label="label" />

			<div
				v-if="preview"
				class="case-version-move__preview"
				data-testid="case-version-move-preview">
				<p data-testid="case-version-move-status">
					{{ statusLine }}
				</p>

				<p v-if="preview.statuses.added.length > 0">
					{{
						t('dossiq', 'Statuses this version adds: {names}', {
							names: preview.statuses.added.join(', '),
						})
					}}
				</p>
				<p v-if="preview.statuses.removed.length > 0">
					{{
						t('dossiq', 'Statuses this version drops: {names}', {
							names: preview.statuses.removed.join(', '),
						})
					}}
				</p>
				<p v-if="preview.fields.added.length > 0">
					{{
						t('dossiq', 'Fields this version adds: {names}', {
							names: preview.fields.added.join(', '),
						})
					}}
				</p>
				<p v-if="preview.fields.removed.length > 0">
					{{
						t('dossiq', 'Fields this version drops: {names}', {
							names: preview.fields.removed.join(', '),
						})
					}}
				</p>
				<p
					v-if="preview.fields.answered.length > 0"
					class="case-version-move__warning"
					data-testid="case-version-move-answered">
					{{
						t(
							'dossiq',
							'This case has answers in fields the other version drops: {names}',
							{ names: preview.fields.answered.join(', ') },
						)
					}}
				</p>

				<p class="case-version-move__note">
					{{ preview.run.reason }}
				</p>

				<p
					v-for="refusal in preview.refusals"
					:key="refusal"
					class="case-version-move__error"
					data-testid="case-version-move-refusal"
					role="alert">
					{{ refusal }}
				</p>
			</div>

			<NcTextArea
				v-if="targets.length > 0"
				v-model="reason"
				data-testid="case-version-move-reason"
				:label="t('dossiq', 'Why it is moving')" />

			<p
				v-if="error"
				class="case-version-move__error"
				data-testid="case-version-move-error"
				role="alert">
				{{ error }}
			</p>
		</div>

		<template #actions>
			<NcButton data-testid="case-version-move-cancel" @click="$emit('close')">
				{{ t('dossiq', 'Cancel') }}
			</NcButton>
			<NcButton
				data-testid="case-version-move-confirm"
				variant="primary"
				:disabled="!canConfirm"
				@click="confirm">
				{{ t('dossiq', 'Move the case') }}
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
import NcTextArea from '@nextcloud/vue/components/NcTextArea'

const PAGE_REFRESH = 'cn:page:refresh'

export default {
	name: 'CaseVersionMoveDialog',

	components: {
		NcButton,
		NcDialog,
		NcSelect,
		NcTextArea,
	},

	props: {
		/**
		 * The case to move. Absent when the manifest opened the dialog: an
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
			current: {},
			targets: [],
			target: '',
			preview: null,
			reason: '',
			error: '',
			loading: true,
			busy: false,
		}
	},

	computed: {
		/**
		 * The case this dialog moves.
		 *
		 * @return {string} The id.
		 *
		 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
		 */
		targetCaseId() {
			return this.caseId || String(this.$route?.params?.id ?? '')
		},

		/**
		 * The versions to choose from, as the picker reads them.
		 *
		 * @return {Array<object>} The options.
		 *
		 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
		 */
		targetOptions() {
			return this.targets.map(version => ({
				id: version.id,
				label: t('dossiq', 'Version {version}', {
					version: version.version,
				}),
			}))
		},

		/**
		 * Where the case lands, or that it has nowhere to land.
		 *
		 * @return {string} The sentence.
		 *
		 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
		 */
		statusLine() {
			if (!this.preview?.status?.mapped) {
				return t('dossiq', 'This case has nowhere to land.')
			}
			return t('dossiq', 'The case stays in {status}.', {
				status: this.preview.status.to,
			})
		},

		/**
		 * Whether the move may be started.
		 *
		 * The server's own `canMove` and nothing derived beside it: a dialog
		 * that worked out for itself whether a status maps would be a second
		 * answer to the question the server already answered.
		 *
		 * @return {boolean} True when it may.
		 *
		 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
		 */
		canConfirm() {
			return (
				this.busy === false
				&& Boolean(this.target)
				&& this.preview?.canMove === true
				&& this.reason.trim().length > 0
			)
		},
	},

	watch: {
		target: 'loadPreview',
	},

	async mounted() {
		await this.loadOptions()
	},

	methods: {
		t,

		/**
		 * The versions this case could move to.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
		 */
		async loadOptions() {
			this.loading = true
			try {
				const { data } = await axios.get(this.endpoint())
				this.current = data?.current ?? {}
				this.targets = Array.isArray(data?.targets) ? data.targets : []
			} catch (e) {
				this.error = this.refusalOf(e)
			} finally {
				this.loading = false
			}
		},

		/**
		 * What moving onto the chosen version would change.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
		 */
		async loadPreview() {
			this.preview = null
			if (!this.target) {
				return
			}
			try {
				const { data } = await axios.get(this.endpoint(), {
					params: { target: this.target },
				})
				this.preview = data?.preview ?? null
			} catch (e) {
				this.error = this.refusalOf(e)
			}
		},

		/**
		 * Move the case, and keep the dialog open on a refusal.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
		 */
		async confirm() {
			if (!this.canConfirm) {
				return
			}
			this.busy = true
			this.error = ''
			try {
				await axios.post(this.endpoint(), {
					target: this.target,
					reason: this.reason.trim(),
				})
				// Tell the whole page: the header, the stages widget and the
				// attributes panel all read the case type.
				emit(PAGE_REFRESH, {})
				this.$emit('close')
			} catch (e) {
				this.error = this.refusalOf(e)
			} finally {
				this.busy = false
			}
		},

		/**
		 * The endpoint for this case.
		 *
		 * @return {string} The url.
		 *
		 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
		 */
		endpoint() {
			return generateUrl(
				`/apps/dossiq/api/case/${encodeURIComponent(this.targetCaseId)}/version-move`,
			)
		},

		/**
		 * The refusal sentence the server wrote, or a fallback.
		 *
		 * Shown verbatim from `message`, which is the sentence its author wrote
		 * at the throw site (ADR-050). Replacing it with a generic line here is
		 * how "that version has no status called Ontvangen" becomes "could not
		 * complete the request".
		 *
		 * @param {object} e The axios error.
		 * @return {string} The sentence.
		 *
		 * @spec openspec/changes/case-type-version-chain/specs/zaaktype-versioning/spec.md
		 */
		refusalOf(e) {
			return (
				e?.response?.data?.message
				|| t('dossiq', 'The case was not moved.')
			)
		},
	},
}
</script>

<style scoped>
.case-version-move {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 16px 16px;
}

.case-version-move__preview {
	display: flex;
	flex-direction: column;
	gap: 4px;
	border-inline-start: 4px solid var(--color-border);
	padding-inline-start: 12px;
}

.case-version-move__note {
	color: var(--color-text-maxcontrast);
}

.case-version-move__warning {
	color: var(--color-warning-text, var(--color-warning));
}

.case-version-move__error {
	color: var(--color-error-text, var(--color-error));
}
</style>
