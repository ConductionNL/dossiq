<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  The family plan: its goals, the interventions under them, and what is late.

  🔴 THIS IS NOT THE ADAPTIVE CASE PLAN. `CasePlanPanel` beside it renders
  OpenRegister's case layer, the stages and milestones of any case. This is the
  Jeugdwet gezinsplan: what this household agreed to work on, who is doing what
  about it and by when. Two things called a plan, and confusing them would put
  a household's goals in a widget every case type shows.

  🔴 WHAT MAKES IT A PLAN RATHER THAN A NOTE is that every line here carries
  something a review can decide about. A goal says what would count as met, so
  it can be closed against an observation. An intervention says who carries it
  out and by when, so it can be late. The register used to hold both as plain
  strings, which is why this panel exists at all.

  THE INTERVENTIONS SIT UNDER THEIR GOAL, and several may sit under one,
  because that is what a real plan looks like: three things happening in
  service of one thing the family wants. An intervention that names no goal is
  shown separately rather than hidden, because activity nobody can connect to a
  goal is exactly what a reviewer should see.

  IT DERIVES NO VERDICT. `overdue` and `dueForReview` come from the server, so
  this panel and the plan endpoint cannot disagree about which household is
  being worked to a stale plan.

  @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-jeugdwet/spec.md
-->
<template>
	<div class="family-plan" data-testid="family-plan">
		<NcLoadingIcon v-if="loading" :size="24" />

		<NcEmptyContent
			v-else-if="error"
			class="family-plan__error"
			data-testid="family-plan-error"
			:name="t('dossiq', 'The family plan is unavailable')"
			:description="error">
			<template #icon>
				<AlertCircleOutline :size="20" />
			</template>
			<template #action>
				<NcButton data-testid="family-plan-retry" @click="load">
					{{ t('dossiq', 'Try again') }}
				</NcButton>
			</template>
		</NcEmptyContent>

		<template v-else>
			<p
				v-if="dueForReview"
				class="family-plan__stale"
				role="status"
				data-testid="family-plan-due-for-review">
				{{
					t(
						'dossiq',
						'This plan was due for review on {date}. A plan that is not revisited stops describing the household it is about.',
						{ date: reviewDate },
					)
				}}
			</p>

			<NcEmptyContent
				v-if="goals.length === 0 && looseInterventions.length === 0"
				data-testid="family-plan-empty"
				:name="t('dossiq', 'This plan has no goals yet')"
				:description="
					t(
						'dossiq',
						'Write down what this household wants to change, and what would be true if it had.',
					)
				" />

			<section
				v-for="goal in goals"
				:key="goal.id"
				class="family-plan__goal"
				data-testid="family-plan-goal">
				<h4 class="family-plan__goal-title">
					{{ goal.title }}
				</h4>
				<p class="family-plan__met-when" data-testid="family-plan-met-when">
					{{
						t('dossiq', 'Met when: {observation}', {
							observation: goal.metWhen,
						})
					}}
				</p>
				<p
					v-if="goal.closedObservation"
					class="family-plan__closed"
					data-testid="family-plan-goal-closed">
					{{
						t('dossiq', 'Closed on {date}: {observation}', {
							date: goal.closedDate,
							observation: goal.closedObservation,
						})
					}}
				</p>

				<ul class="family-plan__interventions">
					<li
						v-for="item in interventionsFor(goal.id)"
						:key="item.id"
						class="family-plan__intervention"
						:class="{
							'family-plan__intervention--overdue': item.overdue,
						}"
						data-testid="family-plan-intervention">
						<span class="family-plan__intervention-title">{{
							item.title
						}}</span>
						<span class="family-plan__intervention-provider">
							{{ providerOf(item) }}
						</span>
						<span
							v-if="item.targetDate"
							class="family-plan__intervention-date">
							{{ t('dossiq', 'By {date}', { date: item.targetDate }) }}
						</span>
						<span
							v-if="item.overdue"
							class="family-plan__overdue"
							data-testid="family-plan-overdue">
							{{ t('dossiq', 'Overdue') }}
						</span>
					</li>
				</ul>
			</section>

			<section
				v-if="looseInterventions.length > 0"
				class="family-plan__goal"
				data-testid="family-plan-loose">
				<h4 class="family-plan__goal-title">
					{{ t('dossiq', 'Not tied to a goal') }}
				</h4>
				<ul class="family-plan__interventions">
					<li
						v-for="item in looseInterventions"
						:key="item.id"
						class="family-plan__intervention"
						:class="{
							'family-plan__intervention--overdue': item.overdue,
						}"
						data-testid="family-plan-intervention">
						<span class="family-plan__intervention-title">{{
							item.title
						}}</span>
						<span class="family-plan__intervention-provider">
							{{ providerOf(item) }}
						</span>
					</li>
				</ul>
			</section>
		</template>
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

export default {
	name: 'CasePlanSociaalDomeinPanel',

	components: {
		AlertCircleOutline,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
	},

	props: {
		/**
		 * The case itself, so the panel can find the plan it belongs to.
		 *
		 * 🔴 THE NAME IS `objectData` AND NOT `object`.
		 * `CnDetailWidgetHost.rendererProps()` binds `objectData`, and a prop
		 * called `object` arrives as null on every case.
		 */
		objectData: {
			type: Object,
			default: null,
		},
	},

	data() {
		return {
			plan: null,
			goals: [],
			interventions: [],
			dueForReview: false,
			error: '',
			loading: true,
		}
	},

	computed: {
		/**
		 * The plan this case names, or ''.
		 *
		 * `gezinsplanId` is what `jeugdwetZaak` calls it; the panel reads the
		 * case's own field rather than searching, because a search would answer
		 * a plan from another case of the same household.
		 *
		 * @return {string} The plan uuid.
		 *
		 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-jeugdwet/spec.md
		 */
		planId() {
			return String(this.objectData?.gezinsplanId ?? '')
		},

		/**
		 * When this plan was due for review.
		 *
		 * @return {string} The date, or ''.
		 *
		 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-jeugdwet/spec.md
		 */
		reviewDate() {
			return String(this.plan?.reviewDate ?? '')
		},

		/**
		 * The interventions that name no goal.
		 *
		 * Shown rather than hidden: activity nobody can connect to a goal is
		 * exactly what a reviewer should be looking at.
		 *
		 * @return {Array<object>} The interventions.
		 *
		 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-jeugdwet/spec.md
		 */
		looseInterventions() {
			return this.interventions.filter((item) => !item.goal)
		},
	},

	watch: {
		planId: 'load',
	},

	async mounted() {
		await this.load()
	},

	methods: {
		t,

		/**
		 * The plan, its goals and its interventions, in one call.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-jeugdwet/spec.md
		 */
		async load() {
			if (!this.planId) {
				this.loading = false
				return
			}
			this.loading = true
			this.error = ''
			try {
				const { data } = await axios.get(
					generateUrl(
						`/apps/dossiq/api/family-plans/${encodeURIComponent(this.planId)}`,
					),
				)
				this.plan = data?.plan ?? null
				this.goals = Array.isArray(data?.goals) ? data.goals : []
				this.interventions = Array.isArray(data?.interventions)
					? data.interventions
					: []
				this.dueForReview = data?.dueForReview === true
			} catch (e) {
				// FAILS CLOSED. An unreachable register and a household with no
				// plan look identical as an empty panel, and only one of them
				// is safe to act on: a consulent who reads an outage as an
				// empty plan writes a second plan over the one already there.
				this.error =
					e?.response?.data?.message
					|| t('dossiq', 'The family plan could not be read.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * The interventions serving one goal.
		 *
		 * @param {string} goalId The goal.
		 * @return {Array<object>} Its interventions.
		 *
		 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-jeugdwet/spec.md
		 */
		interventionsFor(goalId) {
			return this.interventions.filter((item) => item.goal === goalId)
		},

		/**
		 * Who carries an intervention out, as a party where one resolved.
		 *
		 * The resolved party first, the stored display name behind it, and a
		 * sentence when neither is there. "Provider: " with nothing after it is
		 * how a plan says it has a provider while naming nobody.
		 *
		 * @param {object} item The intervention.
		 * @return {string} The provider.
		 *
		 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-jeugdwet/spec.md
		 */
		providerOf(item) {
			const name = item?.providerParty?.displayName || item?.providerName || ''
			if (!name) {
				return t('dossiq', 'No provider recorded')
			}
			const phone = item?.providerParty?.telephone
			return phone ? `${name} (${phone})` : name
		},
	},
}
</script>

<style scoped>
.family-plan {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 0;
}

.family-plan__stale {
	color: var(--color-warning-text, var(--color-warning));
}

.family-plan__error {
	color: var(--color-error-text, var(--color-error));
}

.family-plan__goal {
	border-inline-start: 4px solid var(--color-border);
	padding-inline-start: 12px;
}

.family-plan__goal-title {
	margin: 0 0 4px;
}

.family-plan__met-when,
.family-plan__closed {
	margin: 0 0 4px;
	color: var(--color-text-maxcontrast);
}

.family-plan__interventions {
	list-style: none;
	margin: 0;
	padding: 0;
}

.family-plan__intervention {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	padding-block: 2px;
}

.family-plan__intervention-provider,
.family-plan__intervention-date {
	color: var(--color-text-maxcontrast);
}

.family-plan__intervention--overdue .family-plan__intervention-title {
	font-weight: bold;
}

.family-plan__overdue {
	color: var(--color-error-text, var(--color-error));
}
</style>
