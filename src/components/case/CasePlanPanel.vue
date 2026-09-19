<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  The adaptive case plan, served by OpenRegister's case layer.

  Same widget slot as the retiring CMMN panel (`cmmn-case-plan`), new data
  source. The stages, tasks and milestones are rows in `openregister_case_items`
  read over `/api/cases`, not a blob this app decodes.

  IT FAILS CLOSED. An unreachable case layer renders an error with a retry, not
  an empty plan, because "OpenRegister did not answer" and "this case has no
  work left" look identical from the browser and only one of them is safe to act
  on. A caseworker who reads an outage as an empty plan closes a case that still
  has an open advice task in it.

  Nothing here judges a transition. The buttons are advisory; OpenRegister
  decides and refuses naming item, type, from-state and to-state, and this panel
  relays the refusal without holding a second opinion.

  @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-005-the-case-detail-renders-the-openregister-plan
-->
<template>
	<div class="case-plan" data-testid="case-plan">
		<NcLoadingIcon v-if="loading" :size="24" />

		<NcEmptyContent
			v-else-if="error"
			class="case-plan__error"
			data-testid="case-plan-error"
			:name="t('dossiq', 'The case plan is unavailable')"
			:description="error">
			<template #icon>
				<AlertCircleOutline :size="20" />
			</template>
			<template #action>
				<NcButton data-testid="case-plan-retry" @click="load">
					{{ t('dossiq', 'Try again') }}
				</NcButton>
			</template>
		</NcEmptyContent>

		<ul v-else-if="roots.length > 0" class="case-plan__tree">
			<li v-for="node in roots" :key="node.uuid" class="case-plan__node">
				<div class="case-plan__row">
					<span class="case-plan__name">{{ node.name }}</span>
					<span class="case-plan__state" :data-state="node.state">{{
						stateLabel(node.state)
					}}</span>

					<NcButton
						v-if="node.state === 'enabled' && node.discretionary"
						:disabled="busy"
						:data-testid="`case-plan-enable-${node.key}`"
						@click="enable(node)">
						{{ t('dossiq', 'Start') }}
					</NcButton>

					<NcButton
						v-for="target in transitionsFor(node)"
						:key="target"
						:disabled="busy"
						:data-testid="`case-plan-${target}-${node.key}`"
						@click="transition(node, target)">
						{{ transitionLabel(target) }}
					</NcButton>
				</div>

				<ul v-if="node.children.length > 0" class="case-plan__children">
					<li
						v-for="child in node.children"
						:key="child.uuid"
						class="case-plan__node">
						<div class="case-plan__row">
							<span class="case-plan__name">{{ child.name }}</span>
							<span
								class="case-plan__state"
								:data-state="child.state"
								>{{ stateLabel(child.state) }}</span
							>

							<NcButton
								v-if="
									child.state === 'enabled' && child.discretionary
								"
								:disabled="busy"
								:data-testid="`case-plan-enable-${child.key}`"
								@click="enable(child)">
								{{ t('dossiq', 'Start') }}
							</NcButton>

							<NcButton
								v-for="target in transitionsFor(child)"
								:key="target"
								:disabled="busy"
								:data-testid="`case-plan-${target}-${child.key}`"
								@click="transition(child, target)">
								{{ transitionLabel(target) }}
							</NcButton>
						</div>
					</li>
				</ul>
			</li>
		</ul>

		<p v-else class="case-plan__empty" data-testid="case-plan-empty">
			{{ t('dossiq', 'This case type has no adaptive plan') }}
		</p>
	</div>
</template>

<script>
import { emit, subscribe, unsubscribe } from '@nextcloud/event-bus'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import {
	enablePlanItem,
	fetchCasePlan,
	groupPlanByStage,
	hasPlanRows,
	offeredTransitions,
	planErrorMessage,
	transitionPlanItem,
} from '../../services/casePlanApi.js'
import {
	decidePlanSource,
	hasLocalPlanBlob,
	normaliseLocalPlanItems,
	prefersOpenRegister,
	SOURCE_LOCAL,
	SOURCE_OPENREGISTER,
} from '../../services/casePlanSource.js'
import {
	completeTask as completeLocalTask,
	enableDiscretionaryItem as enableLocalItem,
	fetchCasePlan as fetchLocalCasePlan,
	terminateTask as terminateLocalTask,
} from '../../services/cmmnApi.js'
import { useObjectStore } from '../../store/modules/object.js'
import { initializeStores } from '../../store/store.js'

const PAGE_REFRESH = 'cn:page:refresh'

export default {
	name: 'CasePlanPanel',

	components: { AlertCircleOutline, NcButton, NcEmptyContent, NcLoadingIcon },

	props: {
		/** The case this panel belongs to, bound by CnDetailWidgetHost. */
		objectId: {
			type: [String, Number],
			default: '',
		},
	},

	data() {
		return {
			loading: true,
			busy: false,
			error: '',
			roots: [],
			source: 'none',
		}
	},

	computed: {
		/** @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-005-the-case-detail-renders-the-openregister-plan */
		caseId() {
			return String(this.objectId || this.$route?.params?.id || '')
		},
	},

	/**
	 * Read the plan once the panel is on the page.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-005-the-case-detail-renders-the-openregister-plan
	 */
	async mounted() {
		subscribe(PAGE_REFRESH, this.load)
		await initializeStores()
		await this.load()
	},

	beforeUnmount() {
		unsubscribe(PAGE_REFRESH, this.load)
	},

	methods: {
		t,

		/**
		 * Read the plan OpenRegister holds for this case.
		 *
		 * A 404 is not an error: it means OpenRegister holds no plan for this
		 * case, which is what a BPMN case and a caseType without a caseModel
		 * both look like. Every other failure is an error with a retry.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-001-case-semantics-are-consumed-from-openregister
		 */
		async load() {
			if (!this.caseId) {
				this.loading = false
				return
			}

			this.loading = true
			this.error = ''
			try {
				const plan = await this.readOpenRegisterPlan()
				const rows = hasPlanRows(plan)
				const prefer = prefersOpenRegister()
				// The case is read even when OpenRegister answered rows. Short
				// circuiting here would restate `decidePlanSource`'s first rule
				// in a second place, and the two would drift the first time the
				// rule changed: the panel would keep the old answer and no test
				// over the decision could see it.
				const blob = hasLocalPlanBlob(await this.readCase())

				this.source = decidePlanSource({
					hasOpenRegisterRows: rows,
					hasLocalBlob: blob,
					preferOpenRegister: prefer,
				})

				if (this.source === SOURCE_OPENREGISTER) {
					this.roots = groupPlanByStage(plan?.items)
				} else if (this.source === SOURCE_LOCAL) {
					const local = await fetchLocalCasePlan(this.caseId)
					this.roots = groupPlanByStage(
						normaliseLocalPlanItems(local?.items),
					)
				} else {
					this.roots = []
				}
			} catch (exception) {
				this.roots = []
				this.error = planErrorMessage(exception)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Read OpenRegister's plan, treating a 404 as "it holds none".
		 *
		 * A 404 is an answer: OpenRegister has no rows for this case, which is
		 * what a BPMN case, an undrained case and a caseType without a
		 * caseModel all look like. Every other failure is an outage and is
		 * rethrown, because falling back to the local engine during one is how
		 * two runtimes quietly disagree about one case.
		 *
		 * @return {Promise<object|null>} The plan, or null when there is none.
		 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-001-case-semantics-are-consumed-from-openregister
		 */
		async readOpenRegisterPlan() {
			try {
				return await fetchCasePlan(this.caseId)
			} catch (exception) {
				if (exception?.response?.status === 404) {
					return null
				}

				throw exception
			}
		},

		/**
		 * Read the case record, for its `casePlanState` blob and nothing else.
		 *
		 * @return {Promise<object>} The case, or an empty object.
		 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-004-the-blob-retires-after-the-drain-not-before
		 */
		async readCase() {
			try {
				return (
					(await useObjectStore().fetchObject('case', this.caseId)) || {}
				)
			} catch {
				return {}
			}
		},

		/**
		 * Which target states to offer for one item.
		 *
		 * @param {object} item The plan item.
		 * @return {Array<string>} The targets.
		 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-005-the-case-detail-renders-the-openregister-plan
		 */
		transitionsFor(item) {
			return offeredTransitions(item)
		},

		/**
		 * Enable a discretionary item, then re-read the plan.
		 *
		 * @param {object} item The plan item.
		 * @return {Promise<void>}
		 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-005-the-case-detail-renders-the-openregister-plan
		 */
		async enable(item) {
			await this.act(() =>
				this.source === SOURCE_LOCAL
					? enableLocalItem(this.caseId, item.key)
					: enablePlanItem(item.uuid),
			)
		},

		/**
		 * Transition an item, then re-read the plan.
		 *
		 * @param {object} item   The plan item.
		 * @param {string} target The target state.
		 * @return {Promise<void>}
		 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-005-the-case-detail-renders-the-openregister-plan
		 */
		async transition(item, target) {
			await this.act(() => {
				if (this.source !== SOURCE_LOCAL) {
					return transitionPlanItem(item.uuid, target)
				}

				return target === 'completed'
					? completeLocalTask(this.caseId, item.key)
					: terminateLocalTask(this.caseId, item.key)
			})
		},

		/**
		 * Run one write, then re-read the plan from OpenRegister.
		 *
		 * The panel never patches its own rows from a write response: a
		 * transition can cascade, so the authoritative plan is the one the next
		 * read returns.
		 *
		 * @param {() => Promise<object>} write The write to run.
		 * @return {Promise<void>}
		 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-005-the-case-detail-renders-the-openregister-plan
		 */
		async act(write) {
			this.busy = true
			try {
				await write()
				await this.load()
				emit(PAGE_REFRESH, {})
			} catch (exception) {
				this.error = planErrorMessage(exception)
			} finally {
				this.busy = false
			}
		},

		/**
		 * The reader's word for a plan-item state.
		 *
		 * @param {string} state The state.
		 * @return {string} The label.
		 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-005-the-case-detail-renders-the-openregister-plan
		 */
		stateLabel(state) {
			return (
				{
					available: t('dossiq', 'Waiting'),
					enabled: t('dossiq', 'Ready to start'),
					active: t('dossiq', 'In progress'),
					completed: t('dossiq', 'Done'),
					terminated: t('dossiq', 'Stopped'),
					disabled: t('dossiq', 'Skipped'),
				}[state] ?? state
			)
		},

		/**
		 * The reader's word for a transition button.
		 *
		 * @param {string} target The target state.
		 * @return {string} The label.
		 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-005-the-case-detail-renders-the-openregister-plan
		 */
		transitionLabel(target) {
			return (
				{
					completed: t('dossiq', 'Complete'),
					terminated: t('dossiq', 'Stop'),
				}[target] ?? target
			)
		},
	},
}
</script>

<style scoped>
.case-plan__tree,
.case-plan__children {
	list-style: none;
	margin: 0;
	padding: 0;
}

.case-plan__children {
	padding-inline-start: 1.5rem;
}

.case-plan__row {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	padding-block: 0.25rem;
}

.case-plan__name {
	flex: 1 1 auto;
}

.case-plan__state {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.case-plan__state[data-state='active'] {
	color: var(--color-primary-element);
}

.case-plan__empty {
	color: var(--color-text-maxcontrast);
}
</style>
