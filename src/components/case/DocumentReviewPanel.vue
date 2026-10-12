<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Document review on a case: relevance apart from the verdict, the stopping rule
  and the recall samples, the batches and their reviewers, and what is still open.
  Board DqWooReview (design-system #191).

  GENERIC (decision 182). Any case type switches it on with
  `documentReview.enabled`; the Woo-verzoek is one such case type. The panel
  asks the summary whether review is on and says so plainly when it is not,
  rather than drawing empty tiles on a case that never reviews its documents.

  IT DERIVES NO VERDICT. Counts, unseen pages, estimates and whether the
  stopping rule was met all come from the server, so this panel and the
  routes that refuse a decision cannot disagree.

  Triage rules (REQ-WRT-002) are not drawn yet: their backend is not built,
  and the custodian and source-system conditions wait on document provenance.

  @spec openspec/changes/woo-review-triage/specs/document-review/spec.md
-->
<template>
	<div class="document-review" data-testid="document-review">
		<NcLoadingIcon v-if="loading" :size="24" />

		<NcEmptyContent
			v-else-if="error"
			data-testid="document-review-error"
			:name="t('dossiq', 'The document review is unavailable')"
			:description="error">
			<template #icon>
				<AlertCircleOutline :size="20" />
			</template>
			<template #action>
				<NcButton data-testid="document-review-retry" @click="load">
					{{ t('dossiq', 'Try again') }}
				</NcButton>
			</template>
		</NcEmptyContent>

		<p
			v-else-if="!enabled"
			class="document-review__off"
			data-testid="document-review-off">
			{{
				t(
					'dossiq',
					'The case type of this case does not review its documents for relevance.',
				)
			}}
		</p>

		<template v-else>
			<section
				class="document-review__section"
				data-testid="document-review-status">
				<h3>{{ t('dossiq', 'Where the review stands') }}</h3>
				<p class="document-review__lead">
					{{
						t(
							'dossiq',
							'First relevant or not, then a verdict. Only relevant documents get a verdict.',
						)
					}}
				</p>
				<ul class="document-review__tiles">
					<li
						v-for="tile in tiles"
						:key="tile.key"
						:data-testid="'document-review-tile-' + tile.key">
						<span class="document-review__tile-label">{{
							tile.label
						}}</span>
						<strong class="document-review__tile-value">{{
							tile.value
						}}</strong>
						<span class="document-review__tile-note">{{
							tile.note
						}}</span>
					</li>
				</ul>
				<p
					v-if="unseenPageCount > 0"
					class="document-review__unseen"
					role="status"
					data-testid="document-review-unseen">
					{{
						t(
							'dossiq',
							'The decision and the publication wait until every required page is seen: {pages} pages in {documents} documents to go.',
							{
								pages: unseenPageCount,
								documents: unseenDocumentCount,
							},
						)
					}}
				</p>
			</section>

			<section
				class="document-review__section"
				data-testid="document-review-recall">
				<div class="document-review__head">
					<h3>
						{{ t('dossiq', 'Stopping rule and how much was found') }}
					</h3>
					<NcButton
						:disabled="!canDraw"
						data-testid="document-review-draw"
						@click="showSampleDialog = true">
						{{ t('dossiq', 'Draw a sample') }}
					</NcButton>
				</div>

				<p
					v-if="rule"
					class="document-review__lead"
					data-testid="document-review-rule">
					{{
						t(
							'dossiq',
							'Target: {target}% found with {confidence}% confidence. Declared by {user} on {date}, before the first document was marked. Nobody can change it after that.',
							ruleWords,
						)
					}}
				</p>
				<template v-else>
					<p
						class="document-review__lead"
						data-testid="document-review-no-rule">
						{{
							reviewStarted
								? t(
										'dossiq',
										'No stopping rule was declared before review started, so none will be recorded as met.',
									)
								: t(
										'dossiq',
										'No stopping rule is declared yet. It can only be declared before the first document is marked.',
									)
						}}
					</p>
					<form
						v-if="!reviewStarted"
						class="document-review__rule-form"
						data-testid="document-review-rule-form"
						@submit.prevent="declareRule">
						<label for="document-review-target">{{
							t('dossiq', 'Target recall (%)')
						}}</label>
						<input
							id="document-review-target"
							v-model.number="ruleForm.target"
							type="number"
							min="50"
							max="99"
							required />
						<label for="document-review-confidence">{{
							t('dossiq', 'Confidence')
						}}</label>
						<select
							id="document-review-confidence"
							v-model.number="ruleForm.confidence">
							<option :value="0.9">90%</option>
							<option :value="0.95">95%</option>
							<option :value="0.99">99%</option>
						</select>
						<NcButton type="submit" variant="primary" :disabled="saving">
							{{ t('dossiq', 'Declare the stopping rule') }}
						</NcButton>
					</form>
				</template>
				<p
					v-if="actionError"
					class="document-review__refusal"
					role="alert"
					data-testid="document-review-refusal">
					{{ actionError }}
				</p>

				<ul class="document-review__samples">
					<li
						v-for="sample in samples"
						:key="sample.id"
						:class="{
							'document-review__sample--met':
								!!sample.stoppingRuleMetAt,
						}"
						data-testid="document-review-sample">
						<strong>{{
							t('dossiq', 'Sample drawn {date}', {
								date: day(sample.drawnAt),
							})
						}}</strong>
						<span
							class="document-review__pill"
							data-testid="document-review-sample-state"
							>{{ sampleState(sample) }}</span
						>
						<span>{{ sampleLine(sample) }}</span>
						<span class="document-review__meta">{{
							sampleMeta(sample)
						}}</span>
						<ul
							v-if="sample.recallLowerBound === undefined"
							class="document-review__judge">
							<li
								v-for="ref in unjudged(sample)"
								:key="ref"
								data-testid="document-review-sample-document">
								<span>{{ ref }}</span>
								<NcButton
									:disabled="saving"
									data-testid="document-review-judge-in"
									@click="judge(sample, ref, true)">
									{{ t('dossiq', 'Relevant after all') }}
								</NcButton>
								<NcButton
									:disabled="saving"
									data-testid="document-review-judge-out"
									@click="judge(sample, ref, false)">
									{{ t('dossiq', 'Not relevant') }}
								</NcButton>
							</li>
						</ul>
					</li>
				</ul>
			</section>

			<section
				class="document-review__section"
				data-testid="document-review-batches">
				<div class="document-review__head">
					<h3>{{ t('dossiq', 'Batches and reviewers') }}</h3>
					<NcButton
						data-testid="document-review-new-batch"
						@click="showBatchDialog = true">
						{{ t('dossiq', 'Make a batch') }}
					</NcButton>
				</div>
				<p class="document-review__lead">
					{{
						t(
							'dossiq',
							'Each batch has one reviewer, who gets a task for it. A document is in at most one open batch.',
						)
					}}
				</p>
				<table v-if="batches.length > 0" class="document-review__table">
					<thead>
						<tr>
							<th>{{ t('dossiq', 'Review batch') }}</th>
							<th>{{ t('dossiq', 'Reviewer') }}</th>
							<th>{{ t('dossiq', 'Documents') }}</th>
							<th>{{ t('dossiq', 'Progress') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr
							v-for="batch in batches"
							:key="batch.id"
							data-testid="document-review-batch">
							<td>{{ batch.name }}</td>
							<td>{{ batch.assignee }}</td>
							<td>{{ (batch.documents || []).length }}</td>
							<td>
								{{
									t('dossiq', '{done} of {total}', {
										done: batch.progress?.assessed ?? 0,
										total: batch.progress?.total ?? 0,
									})
								}}
							</td>
						</tr>
					</tbody>
				</table>
				<p
					v-else
					class="document-review__lead"
					data-testid="document-review-no-batches">
					{{ t('dossiq', 'No batch yet.') }}
				</p>
			</section>

			<section
				class="document-review__section"
				data-testid="document-review-outstanding">
				<h3>{{ t('dossiq', 'What is still open') }}</h3>
				<p class="document-review__lead">
					{{
						t(
							'dossiq',
							'Unmarked documents and relevant documents without a verdict. What is not relevant is not listed here.',
						)
					}}
				</p>
				<ul class="document-review__outstanding">
					<li
						v-for="ref in outstanding"
						:key="ref"
						data-testid="document-review-open-document">
						<span>{{ ref }}</span>
						<span v-if="unseen[ref]" class="document-review__meta">
							{{
								t('dossiq', 'Pages still to see: {pages}', {
									pages: unseen[ref].join(', '),
								})
							}}
						</span>
					</li>
				</ul>
			</section>
		</template>

		<RecallSampleDialog
			v-if="showSampleDialog"
			:caseId="objectId"
			:nullSetSize="nullSetSize"
			@close="showSampleDialog = false"
			@drawn="onChanged" />
		<ReviewBatchDialog
			v-if="showBatchDialog"
			:caseId="objectId"
			:documents="outstanding"
			@close="showBatchDialog = false"
			@created="onChanged" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import RecallSampleDialog from '../../dialogs/RecallSampleDialog.vue'
import ReviewBatchDialog from '../../dialogs/ReviewBatchDialog.vue'

/**
 * A share as a percentage with one decimal, Dutch style.
 *
 * @param {number} value A share between 0 and 1.
 * @return {string} The percentage.
 */
function percent(value) {
	return (Math.round(Number(value) * 1000) / 10).toLocaleString('nl-NL')
}

export default {
	name: 'DocumentReviewPanel',

	components: {
		AlertCircleOutline,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		RecallSampleDialog,
		ReviewBatchDialog,
	},

	props: {
		/**
		 * The case uuid, bound by CnDetailWidgetHost.
		 */
		objectId: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			loading: true,
			error: '',
			actionError: '',
			saving: false,
			summary: null,
			batches: [],
			rule: null,
			reviewStarted: false,
			samples: [],
			ruleForm: { target: 80, confidence: 0.95 },
			showSampleDialog: false,
			showBatchDialog: false,
		}
	},

	computed: {
		/**
		 * Whether the case type switches document review on.
		 *
		 * @return {boolean} True when on.
		 *
		 * @spec openspec/changes/woo-review-triage/specs/woo-review-triage/spec.md#requirement-the-woo-verzoek-case-type-enables-document-review-req-wrt-w01
		 */
		enabled() {
			return this.summary?.enabled === true
		},

		/**
		 * The four tiles of the board: relevant, not relevant, unmarked, still to assess.
		 *
		 * @return {Array<object>} The tiles.
		 *
		 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-relevance-is-marked-apart-from-the-verdict-and-reported-req-wrt-001
		 */
		tiles() {
			const relevance = this.summary?.relevance ?? {}
			const verdicts = this.summary?.verdicts?.total ?? 0
			const open = this.summary?.outstanding?.count ?? 0
			return [
				{
					key: 'in-scope',
					label: t('dossiq', 'Relevant'),
					value: relevance.inScope ?? 0,
					note: t('dossiq', '{count} with a verdict', { count: verdicts }),
				},
				{
					key: 'out-of-scope',
					label: t('dossiq', 'Not relevant'),
					value: relevance.outOfScope ?? 0,
					note: t('dossiq', 'no verdict needed'),
				},
				{
					key: 'unmarked',
					label: t('dossiq', 'Not marked yet'),
					value: relevance.unmarked ?? 0,
					note: t('dossiq', 'waiting for a rule or a reviewer'),
				},
				{
					key: 'outstanding',
					label: t('dossiq', 'Still to assess'),
					value: open,
					note: t('dossiq', 'relevant without a verdict, and unmarked'),
				},
			]
		},

		/**
		 * The outstanding documents.
		 *
		 * @return {Array<string>} The document refs.
		 */
		outstanding() {
			const documents = this.summary?.outstanding?.documents
			return Array.isArray(documents)
				? documents.map((ref) => String(ref?.documentRef ?? ref))
				: []
		},

		/**
		 * Unseen required pages by document.
		 *
		 * @return {object} Pages by document ref.
		 */
		unseen() {
			const unseen = this.summary?.unseen
			return unseen && typeof unseen === 'object' && !Array.isArray(unseen)
				? unseen
				: {}
		},

		/**
		 * How many required pages nobody has seen yet.
		 *
		 * @return {number} The pages.
		 */
		unseenPageCount() {
			return Object.values(this.unseen).reduce(
				(sum, pages) => sum + (Array.isArray(pages) ? pages.length : 0),
				0,
			)
		},

		/**
		 * How many documents still have unseen required pages.
		 *
		 * @return {number} The documents.
		 */
		unseenDocumentCount() {
			return Object.keys(this.unseen).length
		},

		/**
		 * The null set: not relevant plus unmarked.
		 *
		 * @return {number} N.
		 */
		nullSetSize() {
			const relevance = this.summary?.relevance ?? {}
			return (relevance.outOfScope ?? 0) + (relevance.unmarked ?? 0)
		},

		/**
		 * Whether a sample can be drawn: there is something in the null set.
		 *
		 * @return {boolean} True when it can.
		 */
		canDraw() {
			return this.nullSetSize > 0
		},

		/**
		 * The words of the declared rule.
		 *
		 * @return {object} The placeholders.
		 */
		ruleWords() {
			return {
				target: percent(this.rule?.targetRecall ?? 0),
				confidence: percent(this.rule?.confidence ?? 0),
				user: this.rule?.declaredBy ?? '',
				date: this.day(this.rule?.declaredAt),
			}
		},
	},

	watch: {
		objectId: 'load',
	},

	async mounted() {
		await this.load()
	},

	methods: {
		t,

		/**
		 * The base url of the case's review routes.
		 *
		 * @param {string} path The path under `/review`.
		 * @return {string} The url.
		 */
		url(path) {
			return generateUrl(
				`/apps/dossiq/api/cases/${encodeURIComponent(this.objectId)}/review${path}`,
			)
		},

		/**
		 * Read the summary, and when review is on, the batches and the recall.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md
		 */
		async load() {
			if (!this.objectId) {
				this.loading = false
				return
			}
			this.loading = true
			this.error = ''
			try {
				const { data } = await axios.get(this.url('/summary'))
				this.summary = data ?? null
				if (this.enabled) {
					const [batches, recall] = await Promise.all([
						axios.get(this.url('/batches')),
						axios.get(this.url('/recall')),
					])
					this.batches = Array.isArray(batches.data?.results)
						? batches.data.results
						: []
					this.rule = recall.data?.rule ?? null
					this.reviewStarted = recall.data?.reviewStarted === true
					this.samples = Array.isArray(recall.data?.samples)
						? recall.data.samples
						: []
				}
			} catch (e) {
				// Fails closed: an unreadable review and an empty one look the same, and only one is safe to decide on.
				this.error =
					e?.response?.data?.message
					|| t('dossiq', 'The document review could not be read.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Declare the stopping rule from the form.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-a-stopping-rule-is-declared-before-review-and-then-fixed-req-wrs-001
		 */
		async declareRule() {
			this.saving = true
			this.actionError = ''
			try {
				const { data } = await axios.post(this.url('/stopping-rule'), {
					targetRecall: Number(this.ruleForm.target) / 100,
					confidence: Number(this.ruleForm.confidence),
				})
				this.rule = data
			} catch (e) {
				this.actionError =
					e?.response?.data?.message
					|| t(
						'dossiq',
						'The stopping rule cannot be stored, so nothing was declared.',
					)
			} finally {
				this.saving = false
			}
		},

		/**
		 * The sampled documents not judged yet.
		 *
		 * @param {object} sample The sample.
		 * @return {Array<string>} The document refs.
		 */
		unjudged(sample) {
			const judged =
				sample.judgements && typeof sample.judgements === 'object'
					? sample.judgements
					: {}
			return (sample.documents || [])
				.map(String)
				.filter((ref) => !(ref in judged))
		},

		/**
		 * Judge one sampled document in or out of scope, in the sample only.
		 *
		 * @param {object}  sample  The sample.
		 * @param {string}  ref     The document.
		 * @param {boolean} inScope Relevant after all.
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-the-recall-is-estimated-from-an-elusion-sample-with-its-uncertainty-req-wrs-002
		 */
		async judge(sample, ref, inScope) {
			this.saving = true
			this.actionError = ''
			try {
				const { data } = await axios.post(
					this.url(
						`/recall/samples/${encodeURIComponent(sample.id)}/judgements`,
					),
					{ documentRef: ref, inScope },
				)
				this.samples = this.samples.map((row) =>
					row.id === sample.id ? data : row,
				)
			} catch (e) {
				this.actionError =
					e?.response?.data?.message
					|| t(
						'dossiq',
						'The Recall sample cannot be stored, so nothing was drawn or judged.',
					)
			} finally {
				this.saving = false
			}
		},

		/**
		 * A dialog drew a sample or made a batch: read the review again.
		 *
		 * @return {Promise<void>}
		 */
		async onChanged() {
			this.showSampleDialog = false
			this.showBatchDialog = false
			await this.load()
		},

		/**
		 * A date as the board writes it.
		 *
		 * @param {string} value An ISO moment.
		 * @return {string} The day.
		 */
		day(value) {
			if (!value) {
				return ''
			}
			const date = new Date(value)
			return Number.isNaN(date.getTime())
				? String(value)
				: date.toLocaleDateString('nl-NL', { day: 'numeric', month: 'long' })
		},

		/**
		 * The state of a sample: in progress, met, or under the target.
		 *
		 * @param {object} sample The sample.
		 * @return {string} The state.
		 *
		 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-meeting-the-stopping-rule-is-recorded-req-wrs-003
		 */
		sampleState(sample) {
			if (sample.recallLowerBound === undefined) {
				return t('dossiq', '{judged} of {total} judged', {
					judged: sample.progress?.judged ?? 0,
					total: sample.progress?.total ?? 0,
				})
			}
			if (sample.stoppingRuleMetAt) {
				return t('dossiq', 'Stopping rule met')
			}
			return this.rule
				? t('dossiq', 'Below the target')
				: t('dossiq', 'Estimated')
		},

		/**
		 * The estimate line, or nothing while judgements are open.
		 *
		 * @param {object} sample The sample.
		 * @return {string} The line.
		 *
		 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-the-recall-is-estimated-from-an-elusion-sample-with-its-uncertainty-req-wrs-002
		 */
		sampleLine(sample) {
			if (sample.recallLowerBound === undefined) {
				return ''
			}
			const words = {
				estimate: percent(sample.recallEstimate),
				lower: percent(sample.recallLowerBound),
				confidence: percent(sample.confidence),
			}
			if (this.rule && !sample.stoppingRuleMetAt) {
				return t(
					'dossiq',
					'An estimated {estimate}% found. Lower bound {lower}% at {confidence}% confidence, against a target of {target}%.',
					{ ...words, target: percent(this.rule.targetRecall) },
				)
			}
			return t(
				'dossiq',
				'An estimated {estimate}% found. Lower bound {lower}% at {confidence}% confidence.',
				words,
			)
		},

		/**
		 * The numbers an estimate rests on: n, k, N and found.
		 *
		 * @param {object} sample The sample.
		 * @return {string} The line.
		 *
		 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-the-recall-is-estimated-from-an-elusion-sample-with-its-uncertainty-req-wrs-002
		 */
		sampleMeta(sample) {
			return t(
				'dossiq',
				'{n} of {N} not relevant or unmarked documents · {k} relevant after all · {found} found',
				{
					n: (sample.documents || []).length,
					N: sample.nullSetSize ?? 0,
					k: sample.k ?? '–',
					found: sample.found ?? 0,
				},
			)
		},
	},
}
</script>

<style scoped>
.document-review {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 6);
}

.document-review__section {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
}

.document-review__head {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: calc(var(--default-grid-baseline) * 2);
}

.document-review__lead,
.document-review__meta {
	color: var(--color-text-maxcontrast);
}

.document-review__tiles {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
	gap: calc(var(--default-grid-baseline) * 2);
}

.document-review__tiles li {
	display: flex;
	flex-direction: column;
	padding: calc(var(--default-grid-baseline) * 3);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.document-review__tile-value {
	font-size: 1.6em;
}

.document-review__unseen,
.document-review__refusal {
	padding: calc(var(--default-grid-baseline) * 2)
		calc(var(--default-grid-baseline) * 3);
	border-radius: var(--border-radius);
	background: var(--color-warning-hover, var(--color-background-dark));
}

.document-review__rule-form {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
}

.document-review__samples li {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	padding: calc(var(--default-grid-baseline) * 3);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.document-review__judge li {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
}

.document-review__sample--met {
	border-color: var(--color-success);
}

.document-review__pill {
	align-self: flex-start;
	padding: 0 calc(var(--default-grid-baseline) * 2);
	border-radius: var(--border-radius-pill);
	background: var(--color-background-dark);
	font-weight: 600;
}

.document-review__table {
	width: 100%;
	border-collapse: collapse;
}

.document-review__table th,
.document-review__table td {
	padding: calc(var(--default-grid-baseline) * 2);
	text-align: start;
	border-bottom: 1px solid var(--color-border);
}
</style>
