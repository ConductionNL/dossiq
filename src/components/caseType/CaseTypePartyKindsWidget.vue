<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
	Which kinds of party a case of this type accepts, in the order a handler
	sees them (board DqZaaktype, the Partijsoorten section).

	🔴 PIPELINQ HOLDS THE ANSWER. The kinds are pipelinq's vocabulary and the
	declaration is written into pipelinq as `dossiq:case:<case type>`, through
	dossiq's controller and PartyKindConsumer, which patches an existing
	declaration rather than replacing pipelinq's row. Dossiq stores nothing of
	it.

	🔴 WITHOUT PIPELINQ THERE IS NOTHING TO SAVE, AND IT SAYS SO. Dossiq's own
	three kinds are listed as what a case offers, read only, with the sentence
	that pipelinq is not installed. A save button that could only fail would
	be a promise the page cannot keep.

	An undeclared case type offers every active kind; the editor then starts
	with all of them ticked, in vocabulary order, and the first save makes it a
	declaration.

	@spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-type-declares-which-party-kinds-it-accepts-and-dossiq-ships-no-vocabulary-of-its-own-once-pipelinq-answers-req-plq-04
-->
<template>
	<div class="case-type-party-kinds" data-testid="case-type-party-kinds">
		<p class="case-type-party-kinds__lead">
			{{
				t(
					'dossiq',
					'Which kinds of party a case of this type accepts, in the order the handler sees them',
				)
			}}
		</p>

		<NcLoadingIcon v-if="loading" :size="24" />

		<NcEmptyContent
			v-else-if="failed"
			:name="t('dossiq', 'The party kinds could not be read')" />

		<template v-else>
			<CnOrderedListField
				:modelValue="accepted"
				:options="kindOptions"
				:label="t('dossiq', 'Accepted kinds, in order')"
				:addLabel="t('dossiq', 'Accept another kind')"
				:emptyText="t('dossiq', 'No kind is accepted yet.')"
				:disabled="!editable"
				data-testid="case-type-party-kind-list"
				@update:modelValue="reorder" />

			<p
				class="case-type-party-kinds__muted"
				data-testid="case-type-party-kinds-source">
				{{
					editable
						? t(
								'dossiq',
								'Kinds from pipelinq. Pipelinq keeps the choice, so every app offers the same kinds.',
							)
						: t(
								'dossiq',
								"Pipelinq is not installed on this instance, so a case offers dossiq's own kinds and nothing can be saved here.",
							)
				}}
			</p>

			<p
				v-if="error"
				class="case-type-party-kinds__error"
				role="alert"
				data-testid="case-type-party-kinds-error">
				{{ error }}
			</p>
			<p v-if="savedNote" class="case-type-party-kinds__muted" role="status">
				{{ savedNote }}
			</p>

			<NcButton
				v-if="editable"
				variant="primary"
				data-testid="case-type-party-kinds-save"
				:disabled="busy || accepted.length === 0"
				@click="save">
				{{ t('dossiq', 'Save in pipelinq') }}
			</NcButton>
		</template>
	</div>
</template>

<script>
import { CnOrderedListField } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import {
	declareCaseTypePartyKinds,
	fetchCaseTypePartyKinds,
	kindCode,
	refusalOf,
} from '../../services/pipelinqCaseApi.js'

export default {
	name: 'CaseTypePartyKindsWidget',

	components: { CnOrderedListField, NcButton, NcEmptyContent, NcLoadingIcon },

	data() {
		return {
			loading: true,
			failed: false,
			source: 'dossiq',
			available: false,
			kinds: [],
			accepted: [],
			busy: false,
			error: '',
			savedNote: '',
		}
	},

	computed: {
		/** @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-type-declares-which-party-kinds-it-accepts-and-dossiq-ships-no-vocabulary-of-its-own-once-pipelinq-answers-req-plq-04 */
		caseTypeId() {
			return String(this.$route?.params?.id ?? '')
		},

		/** @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-type-declares-which-party-kinds-it-accepts-and-dossiq-ships-no-vocabulary-of-its-own-once-pipelinq-answers-req-plq-04 */
		editable() {
			return this.available === true && this.source === 'pipelinq'
		},

		/**
		 * Every kind as a choice for the ordered list: the accepted ones are its
		 * rows, the rest are offered by its picker.
		 *
		 * @return {Array<{value: string, label: string}>} The choices.
		 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-type-declares-which-party-kinds-it-accepts-and-dossiq-ships-no-vocabulary-of-its-own-once-pipelinq-answers-req-plq-04
		 */
		kindOptions() {
			return this.kinds.map((kind) => ({ value: kindCode(kind), label: labelOf(kind) }))
		},
	},

	watch: {
		caseTypeId: {
			immediate: true,
			/** @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-type-declares-which-party-kinds-it-accepts-and-dossiq-ships-no-vocabulary-of-its-own-once-pipelinq-answers-req-plq-04 */
			handler() {
				this.load()
			},
		},
	},

	methods: {
		t,

		/**
		 * Read the vocabulary and this case type's declaration.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-type-declares-which-party-kinds-it-accepts-and-dossiq-ships-no-vocabulary-of-its-own-once-pipelinq-answers-req-plq-04
		 */
		async load() {
			if (this.caseTypeId === '') {
				this.loading = false
				return
			}

			this.loading = true
			this.failed = false
			try {
				const answer = await fetchCaseTypePartyKinds(this.caseTypeId)
				this.source = String(answer?.source || 'dossiq')
				this.available = answer?.available === true
				this.kinds = (
					Array.isArray(answer?.kinds) ? answer.kinds : []
				).filter((kind) => kindCode(kind) !== '')
				// Nothing declared: every kind is offered today, so every kind starts ticked.
				this.accepted = Array.isArray(answer?.accepted)
					? answer.accepted.map(String)
					: this.kinds.map(kindCode)
			} catch {
				this.failed = true
			} finally {
				this.loading = false
			}
		},

		/**
		 * Take the accepted kinds in their new order: one added, removed or moved.
		 *
		 * @param {Array<string>} codes The accepted kind codes, in order.
		 * @return {void}
		 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-type-declares-which-party-kinds-it-accepts-and-dossiq-ships-no-vocabulary-of-its-own-once-pipelinq-answers-req-plq-04
		 */
		reorder(codes) {
			this.accepted = (Array.isArray(codes) ? codes : []).map(String)
			this.savedNote = ''
		},

		/**
		 * Declare the accepted kinds, in order, to pipelinq.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-type-declares-which-party-kinds-it-accepts-and-dossiq-ships-no-vocabulary-of-its-own-once-pipelinq-answers-req-plq-04
		 */
		async save() {
			this.busy = true
			this.error = ''
			this.savedNote = ''
			try {
				const answer = await declareCaseTypePartyKinds(
					this.caseTypeId,
					this.accepted,
				)
				if (Array.isArray(answer?.accepted)) {
					this.accepted = answer.accepted.map(String)
				}
				this.savedNote = t('dossiq', 'Saved in pipelinq.')
			} catch (error) {
				this.error = refusalOf(
					error,
					t('dossiq', 'The party kinds could not be saved.'),
				)
			} finally {
				this.busy = false
			}
		},
	},
}

/**
 * The label a kind is shown with.
 *
 * @param {object} kind The kind.
 * @return {string} The label, the code when it has none.
 */
function labelOf(kind) {
	return String(kind?.label || kindCode(kind))
}
</script>

<style scoped lang="scss">
.case-type-party-kinds {
	display: flex;
	flex-direction: column;
	gap: 10px;

	&__lead,
	&__muted {
		margin: 0;
		color: var(--color-text-maxcontrast);
	}

	&__error {
		margin: 0;
		color: var(--color-error-text);
	}
}
</style>
