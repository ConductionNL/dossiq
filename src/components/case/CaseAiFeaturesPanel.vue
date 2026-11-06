<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  CaseAiFeaturesPanel — the AI features this case's type switched on, and the
  report group the case is in (board DqZaakAssistent, the assistant panel).

  🔴 A REFUSAL IS SHOWN AS ONE. When hermiq will not run a feature, the panel
  names the feature, the reason and hermiq's own gate. A generic "AI error"
  sends a handler to the wrong person; "will not read a document that has not
  been redacted" sends them to the redaction.

  🔴 WHERE IT RUNS IS HERMIQ'S ANSWER. The provider and the residency beside
  each feature come from hermiq's register through dossiq's feature endpoint.
  A feature hermiq does not offer reads unavailable, never local.

  🔑 THE GROUP IS READ, NEVER PLACED. Opening the case reads the group the
  case was put in when it was created; it does not ask hermiq to place it
  again, which would count the same resident twice. The near-duplicates are
  the members hermiq was unsure about, listed so somebody looks at them.

  Registered in src/registry.js as `CaseAiFeaturesPanel` and wired as a
  `component:` sidebar tab on CaseDetail in src/manifest.json.

  @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#requirement-a-request-that-reads-a-document-carries-the-reference-and-a-refusal-is-shown-as-one-req-aic-03
  @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#requirement-identical-reports-collapse-on-the-case-and-dossiq-decides-what-a-group-means-req-aic-04
-->
<template>
	<div class="case-ai-features" data-testid="case-ai-features">
		<h3>{{ t('dossiq', 'Assistant') }}</h3>

		<div v-if="loading" class="case-ai-features__loading">
			<NcLoadingIcon :size="20" />
			{{ t('dossiq', 'Reading the AI features on this case') }}
		</div>

		<template v-else>
			<section
				v-if="group.state !== 'off'"
				class="case-ai-features__group"
				data-testid="case-ai-group">
				<h4>{{ t('dossiq', 'Reports like this one') }}</h4>
				<p
					v-if="group.state === 'unavailable'"
					class="case-ai-features__hint"
					data-testid="case-ai-group-unavailable">
					{{ group.reason }}
					<span v-if="group.gate" class="case-ai-features__gate">
						{{ t('dossiq', 'Gate: {gate}', { gate: group.gate }) }}
					</span>
				</p>
				<template v-else>
					<p
						class="case-ai-features__count"
						data-testid="case-ai-group-count">
						{{
							n(
								'dossiq',
								'{count} report in this group',
								'{count} reports in this group',
								group.count,
								{ count: group.count },
							)
						}}
					</p>
					<template v-if="group.nearDuplicates.length > 0">
						<p class="case-ai-features__hint">
							{{
								t(
									'dossiq',
									'hermiq was not sure these belong to the group. Check whether they are the same report.',
								)
							}}
						</p>
						<ul
							class="case-ai-features__near"
							data-testid="case-ai-group-near">
							<li
								v-for="member in group.nearDuplicates"
								:key="member.reportId"
								data-testid="case-ai-group-near-item">
								<router-link
									:to="{
										name: 'CaseDetail',
										params: { id: member.reportId },
									}">
									{{ member.reportId }}
								</router-link>
								<span class="case-ai-features__hint">
									{{
										t('dossiq', '{percent}% alike', {
											percent: Math.round(member.score * 100),
										})
									}}
								</span>
							</li>
						</ul>
					</template>
				</template>
			</section>

			<section
				v-if="prompts && prompts.length > 0"
				class="case-ai-features__questions"
				data-testid="case-ai-questions">
				<h4>{{ t('dossiq', 'Questions') }}</h4>
				<ul class="case-ai-features__prompts">
					<li v-for="prompt in prompts" :key="prompt.id || prompt.label">
						<NcButton
							:disabled="asking"
							:data-testid="`case-ai-question-${prompt.id}`"
							@click="ask(prompt)">
							{{ prompt.label }}
						</NcButton>
					</li>
				</ul>
				<div
					v-if="answer"
					class="case-ai-features__output"
					data-testid="case-ai-question-answer">
					{{ answer }}
				</div>
				<AiSuggestionDecision
					v-if="answer"
					:text="answer"
					testid="case-ai-question-decision"
					@decide="decide('question', $event)" />
				<p
					v-if="answerFailed"
					class="case-ai-features__unreadable"
					data-testid="case-ai-question-failed">
					{{ answerFailed }}
				</p>
			</section>

			<p
				v-if="features === null"
				class="case-ai-features__unreadable"
				data-testid="case-ai-features-unreadable">
				{{
					t(
						'dossiq',
						'The AI features on this case could not be read. This panel is incomplete, not empty.',
					)
				}}
			</p>

			<p
				v-else-if="features.length === 0"
				class="case-ai-features__hint"
				data-testid="case-ai-features-empty">
				{{
					t(
						'dossiq',
						'This case type switches on no AI feature on the case.',
					)
				}}
			</p>

			<template v-else>
				<section
					v-for="feature in features"
					:key="feature.slug"
					class="case-ai-features__feature"
					:data-testid="`case-ai-feature-${feature.slug}`">
					<h4>{{ feature.slug }}</h4>
					<p
						class="case-ai-features__hint"
						:data-testid="`case-ai-feature-where-${feature.slug}`">
						{{ whereItRuns(feature) }}
					</p>

					<form
						v-if="feature.available === true"
						class="case-ai-features__run"
						@submit.prevent="run(feature)">
						<label :for="fieldId(feature, 'document')">
							{{ t('dossiq', 'Document') }}
						</label>
						<select
							:id="fieldId(feature, 'document')"
							v-model="runs[feature.slug].documentReference"
							:data-testid="`case-ai-feature-document-${feature.slug}`">
							<option value="">
								{{ t('dossiq', 'Choose a document') }}
							</option>
							<option
								v-for="doc in documents || []"
								:key="doc.id"
								:value="doc.id">
								{{ doc.title }}
							</option>
						</select>

						<label :for="fieldId(feature, 'instruction')">
							{{ t('dossiq', 'What should it do with the document?') }}
						</label>
						<textarea
							:id="fieldId(feature, 'instruction')"
							v-model="runs[feature.slug].instruction"
							rows="2" />

						<NcButton
							type="submit"
							:disabled="runs[feature.slug].busy"
							:data-testid="`case-ai-feature-run-${feature.slug}`">
							{{ t('dossiq', 'Run') }}
						</NcButton>
					</form>

					<NcNoteCard
						v-if="runs[feature.slug] && runs[feature.slug].refusal"
						type="warning"
						:data-testid="`case-ai-feature-refusal-${feature.slug}`">
						<p>{{ runs[feature.slug].refusal.sentence }}</p>
						<p
							v-if="runs[feature.slug].refusal.gate"
							class="case-ai-features__gate">
							{{
								t('dossiq', 'Gate: {gate}', {
									gate: runs[feature.slug].refusal.gate,
								})
							}}
						</p>
					</NcNoteCard>

					<p
						v-if="runs[feature.slug] && runs[feature.slug].failed"
						class="case-ai-features__unreadable"
						:data-testid="`case-ai-feature-failed-${feature.slug}`">
						{{
							t(
								'dossiq',
								'hermiq could not be reached. Nothing was run.',
							)
						}}
					</p>

					<div
						v-if="runs[feature.slug] && runs[feature.slug].output"
						class="case-ai-features__output"
						:data-testid="`case-ai-feature-output-${feature.slug}`">
						{{ runs[feature.slug].output }}
					</div>
					<AiSuggestionDecision
						v-if="runs[feature.slug] && runs[feature.slug].output"
						:text="runs[feature.slug].output"
						:testid="`case-ai-feature-decision-${feature.slug}`"
						@decide="decide(`feature:${feature.slug}`, $event)" />
				</section>

				<p class="case-ai-features__hint">
					{{
						t(
							'dossiq',
							'The assistant does nothing without your confirmation. It sees only what you see on this case.',
						)
					}}
				</p>
			</template>
		</template>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import AiSuggestionDecision from './AiSuggestionDecision.vue'
import { createSuggestionTracker } from '../../services/aiSuggestionTracker.js'
import { converse } from '../../services/assistantApi.js'
import {
	AiFeatureRefusal,
	refusalSentence,
	runFeatureOnDocument,
} from '../../services/caseAiFeatureGateway.js'
import {
	fetchCaseDocuments,
	fetchCaseFeatures,
	fetchCasePrompts,
	fetchReportGroup,
	groupView,
	whereItRuns,
} from '../../services/caseAiFeaturesApi.js'

export default {
	name: 'CaseAiFeaturesPanel',
	components: {
		AiSuggestionDecision,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
	},

	props: {
		/** The case uuid, injected by CnObjectSidebar. */
		objectId: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			loading: true,
			features: [],
			documents: [],
			group: groupView({ declared: false }),
			runs: {},
			prompts: [],
			asking: false,
			answer: '',
			answerFailed: '',
		}
	},

	/**
	 * Start tracking the suggestions shown on this case, and report the
	 * undecided ones as closed when the page is left.
	 *
	 * @spec openspec/specs/ai-oversight-dispatch/spec.md
	 */
	created() {
		this.tracker = createSuggestionTracker({ caseId: this.objectId })
		this.onPageHide = () => this.tracker.closeAll({ leaving: true })
		window.addEventListener('pagehide', this.onPageHide)
	},

	async mounted() {
		await this.load()
	},

	/**
	 * Report the undecided suggestions as closed when the panel closes.
	 *
	 * @spec openspec/specs/ai-oversight-dispatch/spec.md
	 */
	beforeUnmount() {
		window.removeEventListener('pagehide', this.onPageHide)
		this.tracker.closeAll()
	},

	methods: {
		whereItRuns,

		/**
		 * Read the features, the group and the documents once.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#scenario-the-near-duplicates-are-visible-beside-the-group
		 */
		async load() {
			this.loading = true
			if (this.objectId === '') {
				this.features = null
				this.loading = false
				return
			}

			const [features, group, prompts] = await Promise.all([
				fetchCaseFeatures(this.objectId),
				fetchReportGroup(this.objectId),
				fetchCasePrompts(),
			])
			this.features = features
			this.prompts = prompts || []
			this.group = groupView(group)

			const runs = {}
			for (const feature of features || []) {
				runs[feature.slug] = {
					documentReference: '',
					instruction: '',
					busy: false,
					refusal: null,
					failed: false,
					output: '',
				}
			}
			this.runs = runs

			const offersARun = (features || []).some(
				(feature) => feature.available === true,
			)
			this.documents = offersARun
				? await fetchCaseDocuments(this.objectId)
				: []
			this.loading = false
		},

		/**
		 * Ask the assistant one of hermiq's prompts about this case.
		 *
		 * @param {object} prompt The prompt, as hermiq holds it.
		 * @return {Promise<void>}
		 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#scenario-the-prompts-offered-on-a-case-come-from-hermiq
		 */
		async ask(prompt) {
			this.tracker.dismiss('question', 'superseded')
			this.asking = true
			this.answer = ''
			this.answerFailed = ''
			try {
				const result = await converse(this.objectId, prompt.prompt)
				this.answer = String(result?.reply ?? '')
				if (this.answer !== '') {
					this.tracker.show('question', {
						type: 'qa',
						text: this.answer,
						prompt: prompt.prompt,
						model: String(result?.model ?? ''),
					})
				}
			} catch (error) {
				this.answerFailed =
					String(error?.response?.data?.error ?? '')
					|| t('dossiq', 'The assistant did not answer.')
			} finally {
				this.asking = false
			}
		},

		/**
		 * Report what the person did with the suggestion in a slot.
		 *
		 * @param {string} slot The slot key.
		 * @param {object} decision `{userAction, actualValue?}` from AiSuggestionDecision.
		 * @return {Promise<void>}
		 * @spec openspec/specs/ai-oversight-dispatch/spec.md
		 */
		decide(slot, decision) {
			return this.tracker.decide(slot, decision.userAction, {
				actualValue: decision.actualValue,
			})
		},

		/**
		 * A DOM id for one field of one feature's form.
		 *
		 * @param {object} feature The feature.
		 * @param {string} field The field name.
		 * @return {string} The id.
		 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#requirement-a-request-that-reads-a-document-carries-the-reference-and-a-refusal-is-shown-as-one-req-aic-03
		 */
		fieldId(feature, field) {
			return `case-ai-${this.objectId || 'unknown'}-${feature.slug}-${field}`
		},

		/**
		 * Run one feature on the chosen document, and show what came back.
		 *
		 * @param {object} feature The feature.
		 * @return {Promise<void>}
		 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#scenario-an-unredacted-document-is-refused-in-words-a-handler-can-act-on
		 */
		async run(feature) {
			const state = this.runs[feature.slug]
			this.tracker.dismiss(`feature:${feature.slug}`, 'superseded')
			state.busy = true
			state.refusal = null
			state.failed = false
			state.output = ''
			try {
				const answer = await runFeatureOnDocument({
					feature: feature.slug,
					documentReference: state.documentReference,
					instruction: state.instruction,
				})
				state.output = String(answer?.output ?? '')
				if (state.output !== '') {
					this.tracker.show(`feature:${feature.slug}`, {
						type: 'decision_support',
						text: state.output,
						prompt: state.instruction,
						model: String(answer?.model ?? ''),
						documentId: state.documentReference,
					})
				}
			} catch (error) {
				if (error instanceof AiFeatureRefusal) {
					state.refusal = {
						sentence: refusalSentence(error, feature.slug),
						gate: error.gate,
					}
				} else {
					state.failed = true
				}
			} finally {
				state.busy = false
			}
		},
	},
}
</script>

<style scoped>
.case-ai-features {
	padding: 12px;
}

.case-ai-features__feature,
.case-ai-features__group {
	margin-bottom: 16px;
	padding-bottom: 12px;
	border-bottom: 1px solid var(--color-border);
}

.case-ai-features__run {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin: 8px 0;
}

.case-ai-features__hint,
.case-ai-features__gate {
	display: block;
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.case-ai-features__count {
	font-weight: bold;
}

.case-ai-features__output {
	white-space: pre-wrap;
	padding: 8px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
}

.case-ai-features__unreadable {
	color: var(--color-warning-text, var(--color-text-maxcontrast));
}
</style>
