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
			<ol class="case-type-party-kinds__list">
				<li
					v-for="row in rows"
					:key="row.code"
					class="case-type-party-kinds__row"
					data-testid="case-type-party-kind">
					<NcCheckboxRadioSwitch
						:modelValue="row.accepted"
						:disabled="!editable"
						:data-testid="'case-type-party-kind-' + row.code"
						@update:modelValue="toggle(row.code, $event)">
						{{
							row.accepted
								? acceptedIndex(row.code) + 1 + '. ' + row.label
								: row.label
						}}
					</NcCheckboxRadioSwitch>
					<span v-if="!row.accepted" class="case-type-party-kinds__muted">
						{{ t('dossiq', 'Not accepted by this case type') }}
					</span>
					<span
						v-if="editable && row.accepted"
						class="case-type-party-kinds__move">
						<NcButton
							:aria-label="
								t('dossiq', 'Move {kind} up', { kind: row.label })
							"
							:disabled="acceptedIndex(row.code) === 0"
							@click="move(row.code, -1)">
							↑
						</NcButton>
						<NcButton
							:aria-label="
								t('dossiq', 'Move {kind} down', { kind: row.label })
							"
							:disabled="
								acceptedIndex(row.code) === accepted.length - 1
							"
							@click="move(row.code, 1)">
							↓
						</NcButton>
					</span>
				</li>
			</ol>

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
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
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

	components: { NcButton, NcCheckboxRadioSwitch, NcEmptyContent, NcLoadingIcon },

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
		 * The accepted kinds first, in declared order, then the rest.
		 *
		 * @return {Array<{code: string, label: string, accepted: boolean}>} The rows.
		 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-type-declares-which-party-kinds-it-accepts-and-dossiq-ships-no-vocabulary-of-its-own-once-pipelinq-answers-req-plq-04
		 */
		rows() {
			const byCode = new Map(this.kinds.map((kind) => [kindCode(kind), kind]))
			const first = this.accepted
				.filter((code) => byCode.has(code))
				.map((code) => ({
					code,
					label: labelOf(byCode.get(code)),
					accepted: true,
				}))
			const rest = this.kinds
				.filter((kind) => !this.accepted.includes(kindCode(kind)))
				.map((kind) => ({
					code: kindCode(kind),
					label: labelOf(kind),
					accepted: false,
				}))

			return [...first, ...rest]
		},
	},

	watch: {
		caseTypeId: {
			immediate: true,
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
		 * The position of a kind among the accepted ones.
		 *
		 * @param {string} code The kind code.
		 * @return {number} The index, -1 when not accepted.
		 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-type-declares-which-party-kinds-it-accepts-and-dossiq-ships-no-vocabulary-of-its-own-once-pipelinq-answers-req-plq-04
		 */
		acceptedIndex(code) {
			return this.accepted.indexOf(code)
		},

		/**
		 * Accept or stop accepting a kind; a newly accepted one goes last.
		 *
		 * @param {string} code The kind code.
		 * @param {boolean} on Whether it is accepted now.
		 * @return {void}
		 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-type-declares-which-party-kinds-it-accepts-and-dossiq-ships-no-vocabulary-of-its-own-once-pipelinq-answers-req-plq-04
		 */
		toggle(code, on) {
			this.savedNote = ''
			this.accepted = on
				? [...this.accepted.filter((c) => c !== code), code]
				: this.accepted.filter((c) => c !== code)
		},

		/**
		 * Move an accepted kind one place up or down.
		 *
		 * @param {string} code The kind code.
		 * @param {number} step -1 for up, 1 for down.
		 * @return {void}
		 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md#requirement-a-case-type-declares-which-party-kinds-it-accepts-and-dossiq-ships-no-vocabulary-of-its-own-once-pipelinq-answers-req-plq-04
		 */
		move(code, step) {
			const from = this.accepted.indexOf(code)
			const to = from + step
			if (from < 0 || to < 0 || to >= this.accepted.length) {
				return
			}

			const next = [...this.accepted]
			next.splice(from, 1)
			next.splice(to, 0, code)
			this.accepted = next
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

	&__list {
		margin: 0;
		padding: 0;
		list-style: none;
	}

	&__row {
		display: flex;
		flex-wrap: wrap;
		gap: 6px 16px;
		align-items: center;
		padding: 8px 0;
		border-top: 1px solid var(--color-border);
	}

	&__move {
		display: flex;
		gap: 6px;
		margin-inline-start: auto;
	}

	&__error {
		margin: 0;
		color: var(--color-error-text);
	}
}
</style>
