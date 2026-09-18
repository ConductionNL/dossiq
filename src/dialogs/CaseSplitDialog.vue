<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Dividing this case in two.

  The inverse of the merge, and a different act from the copy. Two complaints
  arrive on one form and turn out to be about two departments. Copying opens a
  second case holding everything, so both claim the same document and somebody
  tidies up by hand. Splitting MOVES what is chosen, and leaves a reference
  behind so the original file still reads as a whole.

  🔴 NOTHING IS TICKED FOR YOU. Which document belongs to which half is a
  judgment about content, and a default selection is a judgment that gets
  confirmed. The handler ticks, and the case type decides what may be ticked at
  all: a kind this case type does not allow is not offered.

  🔴 A PARTY CAN BE ON BOTH HALVES, AND THAT IS NOT THE SAME AS MOVING IT. A
  party on a case is a role, not a copy of a person, so a counter-party relevant
  to both halves keeps its role on each. The two ticks are separate columns for
  that reason, and ticking both is refused rather than quietly meaning one.

  Every refusal on screen is the server's, read verbatim. A case type that
  forbids dividing documents and a task the engine cannot move are two different
  answers with two different ways out.

  @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
-->
<template>
	<NcDialog
		:name="t('dossiq', 'Split this case in two')"
		data-testid="case-split-dialog"
		@closing="$emit('close')">
		<div class="case-split-dialog">
			<p class="case-split-dialog__explainer">
				{{
					t(
						'dossiq',
						'What you tick moves to the new case. This case keeps a reference to it, so the file still reads as a whole.',
					)
				}}
			</p>

			<NcTextField
				v-model="title"
				data-testid="case-split-title"
				:label="t('dossiq', 'What the new case is called')" />

			<NcLoadingIcon v-if="loading" :size="24" />

			<template v-else>
				<section
					v-if="allows('documents') && documents.length > 0"
					class="case-split-dialog__section"
					data-testid="case-split-documents">
					<h4>{{ t('dossiq', 'Documents that move') }}</h4>
					<NcCheckboxRadioSwitch
						v-for="row in documents"
						:key="row.id"
						:modelValue="chosenDocuments.includes(row.id)"
						:data-testid="`case-split-document-${row.id}`"
						@update:modelValue="toggle(chosenDocuments, row.id)">
						{{ row.label }}
					</NcCheckboxRadioSwitch>
				</section>

				<section
					v-if="allows('parties') && parties.length > 0"
					class="case-split-dialog__section"
					data-testid="case-split-parties">
					<h4>{{ t('dossiq', 'Parties that move') }}</h4>
					<div
						v-for="row in parties"
						:key="row.id"
						class="case-split-dialog__party">
						<NcCheckboxRadioSwitch
							:modelValue="chosenParties.includes(row.id)"
							:data-testid="`case-split-party-${row.id}`"
							@update:modelValue="toggle(chosenParties, row.id)">
							{{ row.label }}
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch
							:modelValue="onBoth.includes(row.id)"
							:data-testid="`case-split-party-both-${row.id}`"
							@update:modelValue="toggle(onBoth, row.id)">
							{{ t('dossiq', 'On both cases') }}
						</NcCheckboxRadioSwitch>
					</div>
				</section>

				<p
					v-if="allowed.length === 0"
					class="case-split-dialog__empty"
					data-testid="case-split-forbidden">
					{{
						t(
							'dossiq',
							'This case type does not allow a split to divide anything.',
						)
					}}
				</p>

				<p
					v-else-if="nothingToDivide"
					class="case-split-dialog__empty"
					data-testid="case-split-empty">
					{{
						t(
							'dossiq',
							'This case holds nothing of the kinds a split may divide here.',
						)
					}}
				</p>
			</template>

			<p
				v-if="error"
				class="case-split-dialog__error"
				data-testid="case-split-error"
				role="alert">
				{{ error }}
			</p>
		</div>

		<template #actions>
			<NcButton data-testid="case-split-cancel" @click="$emit('close')">
				{{ t('dossiq', 'Cancel') }}
			</NcButton>
			<NcButton
				data-testid="case-split-confirm"
				variant="primary"
				:disabled="!canConfirm"
				@click="confirm">
				{{ t('dossiq', 'Split') }}
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
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'

const PAGE_REFRESH = 'cn:page:refresh'

export default {
	name: 'CaseSplitDialog',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcLoadingIcon,
		NcTextField,
	},

	props: {
		/**
		 * The case being split. Absent when the manifest opened the dialog: an
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
			// What the case type allows, answered by the server before this
			// dialog draws. It starts EMPTY rather than as all three: drawing
			// every section first and removing the forbidden ones when the
			// answer lands would flash a choice the handler may not make.
			allowed: [],
			documents: [],
			parties: [],
			chosenDocuments: [],
			chosenParties: [],
			onBoth: [],
			error: '',
			loading: true,
			busy: false,
		}
	},

	computed: {
		/** @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md */
		targetCaseId() {
			return this.caseId || String(this.$route?.params?.id ?? '')
		},

		/**
		 * Whether the case type allows something but this case holds none of
		 * it. A DIFFERENT FACT from a case type that forbids dividing
		 * anything, and one sentence for both told a handler with an empty
		 * case that their case type was the problem.
		 *
		 * @return {boolean} True when there is nothing to tick.
		 * @spec openspec/changes/split-picker-asks-the-policy/specs/case-management/spec.md
		 */
		nothingToDivide() {
			return (
				(!this.allows('documents') || this.documents.length === 0)
				&& (!this.allows('parties') || this.parties.length === 0)
			)
		},

		/**
		 * Whether there is a division to make.
		 *
		 * A party ticked as moving AND as on both halves is two contradictory
		 * instructions, so the button refuses rather than the server picking
		 * one of them.
		 *
		 * @return {boolean} True when the split can be asked for.
		 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
		 */
		canConfirm() {
			const contradicts = this.chosenParties.some((id) =>
				this.onBoth.includes(id),
			)

			return (
				this.busy === false
				&& contradicts === false
				&& this.title.trim().length > 0
				&& (this.chosenDocuments.length > 0
					|| this.chosenParties.length > 0
					|| this.onBoth.length > 0)
			)
		},
	},

	watch: {
		targetCaseId: {
			immediate: true,

			/**
			 * Read what this case holds, and what its type allows.
			 *
			 * @return {void}
			 */
			handler() {
				this.load()
			},
		},
	},

	methods: {
		t,

		/**
		 * Tick or untick one id.
		 *
		 * @param {Array<string>} list The list being ticked into.
		 * @param {string} id The id.
		 * @return {void}
		 */
		toggle(list, id) {
			const at = list.indexOf(id)
			if (at === -1) {
				list.push(id)
				return
			}

			list.splice(at, 1)
		},

		/**
		 * Read the documents and parties this case holds.
		 *
		 * Read from the server rather than from the page, because a split moves
		 * rows and the page holds a projection of the case rather than its
		 * children.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
		 */
		async load() {
			if (this.targetCaseId === '') {
				this.loading = false
				return
			}

			this.loading = true
			this.error = ''

			try {
				// 🔴 THE RULE FIRST, THEN THE ROWS. `CaseSplitPolicy` decides
				// what this case type allows, and asking it before drawing is
				// the whole point: otherwise the handler ticks a document,
				// confirms, and learns from the refusal that documents may not
				// be divided here. The rule was always enforced; it was just
				// never said until after the attempt.
				const { data: rules } = await axios.get(
					generateUrl(
						`/apps/dossiq/api/case/${encodeURIComponent(this.targetCaseId)}/split`,
					),
				)
				this.allowed = Array.isArray(rules?.allowed) ? rules.allowed : []

				const [documents, parties] = await Promise.all([
					this.allows('documents')
						? this.children('caseDocument', [
								'title',
								'name',
								'documentType',
							])
						: [],
					this.allows('parties')
						? this.children('role', ['roleType', 'name', 'displayName'])
						: [],
				])
				this.documents = documents
				this.parties = parties
			} catch (loadError) {
				this.allowed = []
				this.documents = []
				this.parties = []
				this.error =
					loadError?.response?.data?.error
					|| t(
						'dossiq',
						'What this case holds could not be read, so nothing was split.',
					)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Whether the case type allows this part to be divided.
		 *
		 * @param {string} part One of documents, parties or tasks.
		 * @return {boolean} True when it may be divided.
		 * @spec openspec/changes/split-picker-asks-the-policy/specs/case-management/spec.md
		 */
		allows(part) {
			return this.allowed.includes(part)
		},

		/**
		 * One kind of child on this case, as pickable rows.
		 *
		 * @param {string} schema The schema slug.
		 * @param {Array<string>} labelFields The fields a label is built from, in order.
		 * @return {Promise<Array<object>>} The rows.
		 */
		async children(schema, labelFields) {
			const { data } = await axios.get(
				generateUrl(`/apps/openregister/api/objects/dossiq/${schema}`),
				{ params: { case: this.targetCaseId, _limit: 100 } },
			)

			return (data?.results || []).map((row) => ({
				id: String(row?.id ?? ''),
				label:
					labelFields
						.map((field) => row?.[field])
						.filter(Boolean)
						.join(' · ') || String(row?.id ?? ''),
			}))
		},

		/**
		 * Ask the server to split, and keep the dialog open on a refusal.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-a-case-splits-by-moving-what-was-chosen-req-spl-01
		 */
		async confirm() {
			if (!this.canConfirm || this.targetCaseId === '') {
				return
			}

			this.busy = true
			this.error = ''

			try {
				await axios.post(
					generateUrl(
						`/apps/dossiq/api/case/${encodeURIComponent(this.targetCaseId)}/split`,
					),
					{
						title: this.title.trim(),
						documents: this.chosenDocuments,
						parties: this.chosenParties,
						partiesOnBoth: this.onBoth,
					},
				)
				emit(PAGE_REFRESH, {})
				this.$emit('close')
			} catch (splitError) {
				this.error =
					splitError?.response?.data?.message
					|| splitError?.response?.data?.error
					|| t('dossiq', 'This case was not split.')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.case-split-dialog {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 16px 16px;
}

.case-split-dialog__section {
	display: flex;
	flex-direction: column;
	gap: 4px;
	max-height: 200px;
	overflow-y: auto;
}

.case-split-dialog__section h4 {
	margin: 0;
}

.case-split-dialog__party {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
	align-items: center;
}

.case-split-dialog__empty {
	color: var(--color-text-maxcontrast);
}

.case-split-dialog__error {
	color: var(--color-error-text, var(--color-error));
}
</style>
