<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Rebind one running case to another case type.

  🔴 NOTHING HERE IS GUESSED, AND THAT IS THE DIFFERENCE FROM THE VERSION MOVE.
  Two versions of one case type share a status NAME, so a move can work out
  where a case lands. Two different case types share nothing: "In behandeling"
  on a Kapvergunning and "In behandeling" on an Omgevingsvergunning are
  unrelated rows that happen to read alike. So the landing status is ASKED for,
  and the properties the target requires in that status are asked for too, one
  field at a time, by name, from the server.

  The dialog derives no verdict of its own. `canRebind`, the missing property
  names and every refusal sentence come from the server, so what this shows and
  what the write does cannot disagree.

  Once a target is picked, the dialog shows what happens to the answers on the
  case (CaseRebindImpact): what is removed, what carries over where, and what
  the new type needs now. Every move and answer asks the server again, so the
  groups on screen are always the ones the rebind will apply. Removed values
  must be confirmed, and the POST names them, so a case that changed after the
  preview is refused rather than rebound blind.

  A reason is required, and it goes on the case's journal beside both case type
  ids, with every removed value. The number, the folder, the documents and the roles do not change: this
  is a change of blueprint, not a new case.

  @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md
  @spec openspec/changes/case-type-rebind-property-impact/specs/zaaktype-versioning/spec.md
-->
<template>
	<NcDialog
		:name="t('dossiq', 'Change type or version')"
		size="normal"
		data-testid="case-rebind-dialog"
		@closing="$emit('close')">
		<div class="case-rebind">
			<p v-if="current.title" class="case-rebind__current">
				{{
					t('dossiq', 'This case runs as {type}, in {status}.', {
						type: current.title,
						status: current.status || t('dossiq', 'no status'),
					})
				}}
			</p>

			<p
				v-if="!loading && targets.length === 0"
				data-testid="case-rebind-empty">
				{{
					t(
						'dossiq',
						'There is no other published case type to move this case to.',
					)
				}}
			</p>

			<NcSelect
				v-if="targets.length > 0"
				v-model="target"
				data-testid="case-rebind-target"
				:inputLabel="t('dossiq', 'Case type to move to')"
				:options="targetOptions"
				:reduce="(option) => option.id"
				label="label" />

			<NcSelect
				v-if="statusOptions.length > 0"
				v-model="status"
				data-testid="case-rebind-status"
				:inputLabel="t('dossiq', 'Status it lands in')"
				:options="statusOptions"
				:reduce="(option) => option.id"
				label="label" />

			<div
				v-if="preview"
				class="case-rebind__preview"
				data-testid="case-rebind-preview">
				<p v-if="!preview.results.carried" class="case-rebind__warning">
					{{ preview.results.note }}
				</p>

				<CaseRebindImpact
					v-if="preview.impact"
					v-model:remap="remap"
					v-model:answers="answers"
					v-model:dropConfirmed="dropConfirmed"
					:impact="preview.impact"
					:targetTitle="preview.to.title"
					:statusChosen="Boolean(status)" />

				<p class="case-rebind__note">
					{{ preview.run.reason }}
				</p>
			</div>

			<NcTextArea
				v-if="targets.length > 0"
				v-model="reason"
				data-testid="case-rebind-reason"
				:label="t('dossiq', 'Why it is moving')" />

			<p
				v-if="error"
				class="case-rebind__error"
				data-testid="case-rebind-error"
				role="alert">
				{{ error }}
			</p>
		</div>

		<template #actions>
			<NcButton data-testid="case-rebind-cancel" @click="$emit('close')">
				{{ t('dossiq', 'Cancel') }}
			</NcButton>
			<NcButton
				data-testid="case-rebind-confirm"
				variant="primary"
				:disabled="!canConfirm"
				@click="confirm">
				{{ t('dossiq', 'Change the type') }}
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
import CaseRebindImpact from '../components/case/CaseRebindImpact.vue'

const PAGE_REFRESH = 'cn:page:refresh'

/** How long a typed answer settles before the server is asked again. */
const PREVIEW_DEBOUNCE_MS = 300

export default {
	name: 'CaseRebindDialog',

	components: {
		CaseRebindImpact,
		NcButton,
		NcDialog,
		NcSelect,
		NcTextArea,
	},

	props: {
		/**
		 * The case to rebind. Absent when the manifest opened the dialog: an
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
			status: '',
			preview: null,
			answers: {},
			remap: {},
			dropConfirmed: false,
			previewing: false,
			previewSeq: 0,
			reason: '',
			error: '',
			loading: true,
			busy: false,
		}
	},

	computed: {
		/**
		 * The case this dialog rebinds.
		 *
		 * @return {string} The id.
		 *
		 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md
		 */
		targetCaseId() {
			return this.caseId || String(this.$route?.params?.id ?? '')
		},

		/**
		 * The case types to choose from, as the picker reads them.
		 *
		 * A version of the case's own type is labelled as one, because a
		 * coordinator picking between "Omgevingsvergunning" and
		 * "Omgevingsvergunning" with nothing to tell them apart is picking
		 * blind.
		 *
		 * @return {Array<object>} The options.
		 *
		 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md
		 */
		targetOptions() {
			return this.targets.map((type) => ({
				id: type.id,
				label: type.sameChain
					? t('dossiq', '{title}, version {version}', {
							title: type.title,
							version: type.version,
						})
					: type.title,
			}))
		},

		/**
		 * The statuses of the chosen case type.
		 *
		 * @return {Array<object>} The options.
		 *
		 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md
		 */
		statusOptions() {
			return (this.preview?.statuses ?? []).map((entry) => ({
				id: entry.id,
				label: entry.name,
			}))
		},

		/**
		 * Whether the rebind may be started.
		 *
		 * The server's own `canRebind` and nothing derived beside it, so this
		 * dialog can never enable a button the write would refuse.
		 *
		 * @return {boolean} True when it may.
		 *
		 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md
		 * @spec openspec/changes/case-type-rebind-property-impact/specs/zaaktype-versioning/spec.md
		 */
		canConfirm() {
			return (
				this.busy === false
				&& this.previewing === false
				&& Boolean(this.target)
				&& Boolean(this.status)
				&& this.preview?.canRebind === true
				&& (this.droppedNames.length === 0 || this.dropConfirmed)
				&& this.reason.trim().length > 0
			)
		},

		/**
		 * The answers the server says this rebind removes.
		 *
		 * @return {Array<string>} Their names.
		 *
		 * @spec openspec/changes/case-type-rebind-property-impact/specs/zaaktype-versioning/spec.md
		 */
		droppedNames() {
			return (this.preview?.impact?.dropped ?? []).map((row) => row.name)
		},
	},

	watch: {
		target: 'onTargetChange',
		status: 'loadPreview',
		remap: { handler: 'schedulePreview', deep: true },
		answers: { handler: 'schedulePreview', deep: true },
		/**
		 * A different list of removed answers needs a fresh confirmation.
		 *
		 * @param {Array<string>} now The names now.
		 * @param {Array<string>} before The names before.
		 *
		 * @spec openspec/changes/case-type-rebind-property-impact/specs/zaaktype-versioning/spec.md
		 */
		droppedNames(now, before) {
			if (now.join('\n') !== (before ?? []).join('\n')) {
				this.dropConfirmed = false
			}
		},
	},

	beforeUnmount() {
		clearTimeout(this.previewTimer)
	},

	async mounted() {
		await this.loadOptions()
	},

	methods: {
		t,

		/**
		 * The case types this case could be rebound to.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md
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
		 * A new target clears the mapping that belonged to the old one.
		 *
		 * Keeping the status would keep a row of the case type the coordinator
		 * just moved away from, and the server refuses exactly that. Clearing
		 * it here means they are asked again rather than refused.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md
		 * @spec openspec/changes/case-type-rebind-property-impact/specs/zaaktype-versioning/spec.md
		 */
		async onTargetChange() {
			this.status = ''
			this.answers = {}
			this.remap = {}
			this.dropConfirmed = false
			this.preview = null
			await this.loadPreview()
		},

		/**
		 * Ask the server again once a move or an answer settles.
		 *
		 * @spec openspec/changes/case-type-rebind-property-impact/specs/zaaktype-versioning/spec.md
		 */
		schedulePreview() {
			clearTimeout(this.previewTimer)
			this.previewing = true
			this.previewTimer = setTimeout(
				() => this.loadPreview(),
				PREVIEW_DEBOUNCE_MS,
			)
		},

		/**
		 * What rebinding onto the chosen type, in the chosen status, asks for.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md
		 * @spec openspec/changes/case-type-rebind-property-impact/specs/zaaktype-versioning/spec.md
		 */
		async loadPreview() {
			clearTimeout(this.previewTimer)
			if (!this.target) {
				this.preview = null
				this.previewing = false
				return
			}
			// Only the newest answer counts: a slow reply to an older question
			// must not overwrite the groups for the current one.
			const seq = ++this.previewSeq
			this.previewing = true
			try {
				const { data } = await axios.get(this.endpoint(), {
					params: {
						target: this.target,
						status: this.status,
						remap: this.remap,
						properties: this.answers,
					},
				})
				if (seq === this.previewSeq) {
					this.preview = data?.preview ?? null
					this.error = ''
				}
			} catch (e) {
				if (seq === this.previewSeq) {
					this.error = this.refusalOf(e)
				}
			} finally {
				if (seq === this.previewSeq) {
					this.previewing = false
				}
			}
		},

		/**
		 * Rebind the case, and keep the dialog open on a refusal.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md
		 * @spec openspec/changes/case-type-rebind-property-impact/specs/zaaktype-versioning/spec.md
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
					status: this.status,
					reason: this.reason.trim(),
					properties: this.answers,
					remap: this.remap,
					confirmDropped: this.droppedNames,
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
		 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md
		 */
		endpoint() {
			return generateUrl(
				`/apps/dossiq/api/case/${encodeURIComponent(this.targetCaseId)}/rebind`,
			)
		},

		/**
		 * The refusal sentence the server wrote, or a fallback.
		 *
		 * Shown verbatim from `message`, which is the sentence its author wrote
		 * at the throw site (ADR-050). Replacing it with a generic line is how
		 * "that case type requires bouwjaar in that status" becomes "could not
		 * complete the request".
		 *
		 * @param {object} e The axios error.
		 * @return {string} The sentence.
		 *
		 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md
		 */
		refusalOf(e) {
			return (
				e?.response?.data?.message
				|| t('dossiq', 'The case was not rebound.')
			)
		},
	},
}
</script>

<style scoped>
.case-rebind {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 16px 16px;
}

.case-rebind__preview {
	display: flex;
	flex-direction: column;
	gap: 8px;
	border-inline-start: 4px solid var(--color-border);
	padding-inline-start: 12px;
}

.case-rebind__note {
	color: var(--color-text-maxcontrast);
}

.case-rebind__warning {
	color: var(--color-warning-text, var(--color-warning));
}

.case-rebind__error {
	color: var(--color-error-text, var(--color-error));
}
</style>
