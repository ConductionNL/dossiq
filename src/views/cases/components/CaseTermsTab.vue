<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  CaseTermsTab — the four clocks on this case, and how far it has got.

  🔴 IT RENDERS THE SERVER'S NUMBERS AND COMPUTES NONE OF THEM.

  A gemeente runs four clocks: the statutory term the applicant was told about,
  the planned end the team steers on, the internal target a teamleider steers
  on, and the phase term inside the case term. This panel shows all four, apart,
  each saying what it is. A case late against its plan and on time against the
  Awb reads as exactly that, because those two facts lead to different actions.

  The progress figure and the days-left count come from
  /api/cases/{id}/terms and are computed at read time, stored nowhere. The list
  column will read the same call once the library carries a column type for it,
  so the two cannot disagree.

  A read that fails says so. It does not fall back to an empty list: "we could
  not ask" and "this case has no deadline" are opposite answers to a handler.

  Registered in src/registry.js as `CaseTermsTab` and wired as a `component:`
  sidebar tab on CaseDetail in src/manifest.json.

  @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-binding/spec.md
  @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-reporting/spec.md
-->
<template>
	<div class="case-terms-tab" data-testid="case-terms-tab">
		<h3>{{ t('dossiq', 'The clocks on this case') }}</h3>

		<div v-if="loading" class="case-terms-tab__loading">
			<NcLoadingIcon :size="20" />
			{{ t('dossiq', 'Reading the terms on this case') }}
		</div>

		<template v-else>
			<p
				v-if="unreadable"
				class="case-terms-tab__unreadable"
				data-testid="case-terms-unreadable">
				{{
					t(
						'dossiq',
						'The terms on this case could not be read. This panel is incomplete, not empty.',
					)
				}}
			</p>

			<template v-else>
				<div
					class="case-terms-tab__progress"
					data-testid="case-terms-progress">
					<label class="case-terms-tab__progress-label" :for="progressId">
						{{ t('dossiq', 'Progress') }}
					</label>
					<progress
						:id="progressId"
						class="case-terms-tab__progress-bar"
						:value="progress.progress || 0"
						max="100" />
					<span
						class="case-terms-tab__progress-figure"
						data-testid="case-terms-progress-figure">
						{{
							t('dossiq', '{percent}%', {
								percent: progress.progress || 0,
							})
						}}
					</span>
					<span class="case-terms-tab__hint">
						{{
							t('dossiq', '{done} of {total} phases done', {
								done: progress.phasesDone || 0,
								total: progress.phasesTotal || 0,
							})
						}}
					</span>
				</div>

				<p
					v-if="attention"
					class="case-terms-tab__attention"
					data-testid="case-terms-attention">
					{{ t('dossiq', 'At least one clock on this case has run out.') }}
				</p>

				<p
					v-if="rows.length === 0"
					class="case-terms-tab__empty"
					data-testid="case-terms-empty">
					{{
						t(
							'dossiq',
							'This case type declares no term, so no clock is running.',
						)
					}}
				</p>

				<table v-else class="case-terms-tab__table">
					<thead>
						<tr>
							<th scope="col">{{ t('dossiq', 'Clock') }}</th>
							<th scope="col">{{ t('dossiq', 'Ends on') }}</th>
							<th scope="col">{{ t('dossiq', 'Time left') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr
							v-for="row in rows"
							:key="row.id || row.kind"
							class="case-terms-tab__row"
							:class="`case-terms-tab__row--${row.tone}`"
							:data-testid="`case-terms-row-${row.kind}`"
							:data-tone="row.tone">
							<td>
								<span class="case-terms-tab__kind">{{
									row.label
								}}</span>
								<span class="case-terms-tab__hint">{{
									row.hint
								}}</span>
							</td>
							<td>{{ row.endDate || t('dossiq', 'Not set') }}</td>
							<td>
								<span class="case-terms-tab__sentence">{{
									row.sentence
								}}</span>
								<span
									v-if="!row.citizenVisible"
									class="case-terms-tab__hint">
									{{ t('dossiq', 'Not shown to the applicant') }}
								</span>
								<span
									v-if="row.pause"
									class="case-terms-tab__hint"
									:data-testid="`case-terms-pause-${row.kind}`">
									{{ row.pause }}
								</span>
							</td>
						</tr>
					</tbody>
				</table>
			</template>
		</template>
	</div>
</template>

<script>
import { NcLoadingIcon } from '@nextcloud/vue'
import { fetchCaseTerms } from '../../../services/caseTermsApi.js'
import { needsAttention, termRows } from '../../../utils/caseTerms.js'

export default {
	name: 'CaseTermsTab',
	components: {
		NcLoadingIcon,
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
			unreadable: false,
			terms: [],
			progress: {},
		}
	},

	computed: {
		/**
		 * The clocks in reading order, each with a label, a hint and a tone.
		 *
		 * @return {Array} The rows to render.
		 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-binding/spec.md
		 */
		rows() {
			return termRows(this.terms, t)
		},

		/**
		 * Whether any clock on this case has run out.
		 *
		 * @return {boolean} True when one has.
		 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-reporting/spec.md
		 */
		attention() {
			return needsAttention(this.progress)
		},

		/**
		 * A DOM id for the progress bar, so its label associates with it.
		 *
		 * @return {string} The id.
		 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-reporting/spec.md
		 */
		progressId() {
			return `case-terms-progress-${this.objectId || 'unknown'}`
		},
	},

	async mounted() {
		await this.load()
	},

	methods: {
		/**
		 * Ask dossiq once, and render what came back.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-reporting/spec.md
		 */
		async load() {
			this.loading = true
			const body = this.objectId ? await fetchCaseTerms(this.objectId) : null

			if (body === null) {
				this.unreadable = true
				this.terms = []
				this.progress = {}
				this.loading = false
				return
			}

			this.unreadable = false
			this.terms = Array.isArray(body.terms) ? body.terms : []
			this.progress = body.progress || {}
			this.loading = false
		},
	},
}
</script>

<style scoped>
.case-terms-tab {
	padding: 12px;
}

.case-terms-tab__table {
	width: 100%;
	border-collapse: collapse;
}

.case-terms-tab__table th,
.case-terms-tab__table td {
	text-align: start;
	padding: 4px 8px;
	border-bottom: 1px solid var(--color-border);
	vertical-align: top;
}

.case-terms-tab__kind,
.case-terms-tab__sentence {
	display: block;
}

.case-terms-tab__hint {
	display: block;
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.case-terms-tab__row--overdue .case-terms-tab__sentence {
	color: var(--color-error-text, var(--color-error));
	font-weight: bold;
}

.case-terms-tab__row--soon .case-terms-tab__sentence {
	color: var(--color-warning-text, var(--color-warning));
}

.case-terms-tab__progress {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
	margin-bottom: 12px;
}

.case-terms-tab__progress-bar {
	flex: 1 1 120px;
	min-width: 120px;
}

.case-terms-tab__attention {
	color: var(--color-error-text, var(--color-error));
}

.case-terms-tab__unreadable {
	color: var(--color-warning-text, var(--color-text-maxcontrast));
}

.case-terms-tab__empty {
	color: var(--color-text-maxcontrast);
}
</style>
