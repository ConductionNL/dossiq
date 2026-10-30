<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<div class="deadline-definitions-tab">
		<div class="deadline-definitions-tab__header">
			<p
				v-if="timezone"
				class="deadline-definitions-tab__calendar"
				data-testid="deadline-calendar-line">
				{{
					t('dossiq', 'Days and dates are counted in {zone}.', {
						zone: timezone,
					})
				}}
				<span v-if="rollAvailable === false">
					{{
						t(
							'dossiq',
							'No organisation calendar is answering, so a term set to move off a weekend or a holiday will not move.',
						)
					}}
				</span>
			</p>
			<NcButton variant="secondary" @click="openNew">
				{{ t('dossiq', 'New term definition') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" :size="32" />

		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<div
			v-if="!loading && definitions.length === 0"
			class="deadline-definitions-tab__empty">
			<NcEmptyContent
				:name="t('dossiq', 'No term definitions')"
				:description="
					t(
						'dossiq',
						'No AWB term definitions configured yet. Create one to enable termijnbewaking for a zaaktype.',
					)
				">
				<template #icon>
					<TimerSand :size="48" />
				</template>
			</NcEmptyContent>
		</div>

		<table
			v-if="!loading && definitions.length > 0"
			class="deadline-definitions-tab__table"
			data-testid="deadline-definitions-table">
			<thead>
				<tr>
					<th v-if="showCaseType" scope="col">
						{{ t('dossiq', 'Case type') }}
					</th>
					<th scope="col">
						{{ t('dossiq', 'Basis') }}
					</th>
					<th scope="col">
						{{ t('dossiq', 'Duration') }}
					</th>
					<th scope="col">
						{{ t('dossiq', 'Version') }}
					</th>
					<th scope="col">
						{{ t('dossiq', 'Counting') }}
					</th>
					<th scope="col">
						{{ t('dossiq', 'Closed day') }}
					</th>
					<th scope="col">
						{{ t('dossiq', 'Valid') }}
					</th>
					<th scope="col">
						{{ t('dossiq', 'State') }}
					</th>
					<th scope="col">
						{{ t('dossiq', 'Actions') }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="def in definitions"
					:key="def.id"
					class="deadline-definitions-tab__row"
					:class="{ 'deadline-definitions-tab__row--inactive': !isActive(def) }">
					<td v-if="showCaseType" class="deadline-definitions-tab__row-name">
						{{ caseTypeOf(def) }}
					</td>
					<td>{{ basisOf(def) || t('dossiq', '(no grondslag)') }}</td>
					<td>{{ formatDuur(def) }}</td>
					<td>v{{ def.version || 1 }}</td>
					<td data-testid="deadline-counting-mode-pill">
						{{ countingModeLabel(def) }}
					</td>
					<td>
						<span v-if="rollsToWorkingDay(def)" data-testid="deadline-roll-pill">
							{{ t('dossiq', 'Moves off a closed day') }}
						</span>
						<span v-else>—</span>
					</td>
					<td class="deadline-definitions-tab__validity">
						{{ def.validFrom || '?' }} → {{ def.validUntil || '∞' }}
					</td>
					<td>
						<span
							class="cn-status-badge"
							:class="isActive(def) ? 'cn-status-badge--success' : 'cn-status-badge--default'">
							{{
								isActive(def)
									? t('dossiq', 'Active')
									: t('dossiq', 'Inactive')
							}}
						</span>
					</td>
					<td class="deadline-definitions-tab__row-actions">
						<NcButton variant="secondary" @click="openEdit(def)">
							{{ t('dossiq', 'New version') }}
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>

		<!-- Editor modal (own file under src/modals/ per ADR-004) -->
		<DeadlineDefinitionEditor
			v-if="editorOpen"
			:definition="editingDefinition"
			:zaaktypeOptions="zaaktypeOptions"
			@save="onSave"
			@close="closeEditor" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import TimerSand from 'vue-material-design-icons/TimerSand.vue'
import DeadlineDefinitionEditor from '../../../modals/DeadlineDefinitionEditor.vue'

export default {
	name: 'DeadlineDefinitionsTab',
	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		TimerSand,
		DeadlineDefinitionEditor,
	},

	data() {
		return {
			loading: false,
			error: null,
			definitions: [],
			editorOpen: false,
			editingDefinition: null,
			/** The zone every date on this instance is counted in. */
			timezone: '',
			/** Whether an organisation calendar answers the Awt roll. */
			rollAvailable: null,
		}
	},

	computed: {
		/**
		 * Whether any definition names its case type, so the column is worth drawing.
		 *
		 * @return {boolean} TRUE when one does.
		 * @spec openspec/changes/deadline-definitions-board-table/specs/admin-settings/spec.md
		 */
		showCaseType() {
			return this.definitions.some((def) => Boolean(this.caseTypeOf(def)))
		},

		/** @spec openspec/changes/termijnbewaking-dwangsom-engine-11-tests-admin-docs/tasks.md */
		zaaktypeOptions() {
			const seen = new Set()
			const opts = []
			for (const d of this.definitions) {
				const z = this.caseTypeOf(d)
				if (z && !seen.has(z)) {
					seen.add(z)
					opts.push({ id: z, label: z })
				}
			}
			return opts
		},
	},

	/**
	 * Read the definitions and the working calendar when the tab opens.
	 *
	 * BOTH, and not only the definitions: a term is resolved against the
	 * administered calendar, so a tab showing the definitions beside a
	 * calendar it never loaded would present two halves of one answer as if
	 * they agreed.
	 *
	 * @return {void}
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-11-tests-admin-docs/tasks.md
	 */
	mounted() {
		this.load()
		this.loadCalendar()
	},

	methods: {
		t,
		/** @spec openspec/changes/termijnbewaking-dwangsom-engine-11-tests-admin-docs/tasks.md */
		async load() {
			this.loading = true
			this.error = null
			try {
				const res = await axios.get(
					generateUrl('/apps/dossiq/api/deadlines/definitions'),
				)
				this.definitions = Array.isArray(res.data)
					? res.data
					: res.data?.results || []
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| e.message
					|| t('dossiq', 'Failed to load term definitions')
			} finally {
				this.loading = false
			}
		},

		/**
		 * What the terms are counted against: the zone, and whether a
		 * calendar answers.
		 *
		 * Failure is silent here on purpose. The line it feeds is context, so
		 * an error banner over the definitions would be louder than the
		 * failure warrants; an unanswered call simply leaves the line off.
		 *
		 * @return {Promise<void>} Nothing.
		 * @spec openspec/changes/terms-on-the-engine-calendar/specs/termijnbewaking-schemas/spec.md
		 */
		async loadCalendar() {
			try {
				const res = await axios.get(
					generateUrl('/apps/dossiq/api/deadlines/calendar'),
				)
				this.timezone = String(res.data?.timezone ?? '')
				this.rollAvailable = res.data?.rollAvailable === true
			} catch {
				this.timezone = ''
				this.rollAvailable = null
			}
		},

		/**
		 * Whether a definition asks for the Awt roll.
		 *
		 * @param {object} def The definition.
		 * @return {boolean} True when it does.
		 * @spec openspec/changes/terms-on-the-engine-calendar/specs/termijnbewaking-schemas/spec.md
		 */
		rollsToWorkingDay(def) {
			return def?.rollToWorkingDay === true
		},

		/**
		 * What this term counts, in the words a caseworker uses.
		 *
		 * Shown on every definition rather than only on the working-day ones,
		 * unlike the Awt pill beside it. Which days a term counts changes
		 * every date it produces, and a reader who sees nothing cannot tell a
		 * calendar-day term from one whose mode nobody has set.
		 *
		 * @param {object} def The definition.
		 * @return {string} The label.
		 * @spec openspec/changes/counting-mode-per-term/specs/termijnbewaking-schemas/spec.md
		 */
		countingModeLabel(def) {
			return def?.countingMode === 'workingDays'
				? t('dossiq', 'Working days')
				: t('dossiq', 'Calendar days')
		},

		/**
		 * @param {object} def The definition being edited.
		 * @spec openspec/changes/termijnbewaking-dwangsom-engine-11-tests-admin-docs/tasks.md
		 */
		isActive(def) {
			const today = new Date().toISOString().slice(0, 10)
			const from = def.validFrom || '0000-00-00'
			const until = def.validUntil || '9999-12-31'
			return from <= today && today <= until
		},

		/**
		 * @param {object} def The definition being edited.
		 * @spec openspec/changes/termijnbewaking-dwangsom-engine-11-tests-admin-docs/tasks.md
		 */
		formatDuur(def) {
			const weeks = Number(def.standardDurationWeeks || 0)
			if (weeks > 0) {
				return t('dossiq', '{n} weeks', { n: weeks })
			}
			const days = Number(def.standardDurationDays || def.duurDagen || def.duur || 0)
			return days ? t('dossiq', '{n} days', { n: days }) : '—'
		},

		/**
		 * A definition's legal basis, as the register stores it (`legalBasis`).
		 *
		 * @param {object} def The definition.
		 * @return {string} The basis, or the empty string.
		 * @spec openspec/changes/deadline-definitions-board-table/specs/admin-settings/spec.md
		 */
		basisOf(def) {
			return String(def?.legalBasis || def?.basis || '')
		},

		/**
		 * The case type a definition binds to, by slug (`caseType`).
		 *
		 * @param {object} def The definition.
		 * @return {string} The slug, or the empty string.
		 * @spec openspec/changes/deadline-definitions-board-table/specs/admin-settings/spec.md
		 */
		caseTypeOf(def) {
			return String(def?.caseType || def?.case_type || '')
		},

		/** @spec openspec/changes/termijnbewaking-dwangsom-engine-11-tests-admin-docs/tasks.md */
		openNew() {
			this.editingDefinition = null
			this.editorOpen = true
		},

		/**
		 * @param {object} def The definition being edited.
		 * @spec openspec/changes/termijnbewaking-dwangsom-engine-11-tests-admin-docs/tasks.md
		 */
		openEdit(def) {
			this.editingDefinition = def
			this.editorOpen = true
		},

		/** @spec openspec/changes/termijnbewaking-dwangsom-engine-11-tests-admin-docs/tasks.md */
		closeEditor() {
			this.editorOpen = false
			this.editingDefinition = null
		},

		/**
		 * @param {object} payload The payload.
		 * @spec openspec/changes/termijnbewaking-dwangsom-engine-11-tests-admin-docs/tasks.md
		 */
		async onSave(payload) {
			// Versioning: new version's validFrom=tomorrow, prior validUntil=today.
			const today = new Date()
			const tomorrow = new Date(today.getTime() + 24 * 60 * 60 * 1000)
			const isoTomorrow = tomorrow.toISOString().slice(0, 10)
			const isoToday = today.toISOString().slice(0, 10)

			const next = {
				...payload,
				validFrom: isoTomorrow,
				validUntil: null,
				version: this.editingDefinition
					? Number(this.editingDefinition.version || 1) + 1
					: 1,
			}

			try {
				// Close the prior version if editing
				if (this.editingDefinition) {
					await axios.patch(
						generateUrl(
							'/apps/dossiq/api/deadlines/definitions/'
								+ encodeURIComponent(this.editingDefinition.id),
						),
						{ validUntil: isoToday },
					)
				}
				await axios.post(
					generateUrl('/apps/dossiq/api/deadlines/definitions'),
					next,
				)
				this.closeEditor()
				await this.load()
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| e.message
					|| t('dossiq', 'Failed to save')
			}
		},
	},
}
</script>

<style scoped>
.deadline-definitions-tab {
	padding: 0;
}

.deadline-definitions-tab__header {
	margin-bottom: 16px;
}

/* The card's second paragraph, as the board draws it. */
.deadline-definitions-tab__calendar {
	color: var(--color-text-maxcontrast);
	font-size: 14px;
	line-height: 1.5;
	margin: 0 0 16px;
}

/* One table, a definition per row, as board DqBeheerGeavanceerd
   "AWB-termijndefinities" draws it. The board look styles the plain table. */
.deadline-definitions-tab__table {
	width: 100%;
	border-collapse: collapse;
	white-space: normal;
}

/* Heads on one line, as drawn: a head that may wrap gives its column less
   room under the auto layout ("Gesloten dag" 130 against 140). */
.deadline-definitions-tab__table th {
	white-space: nowrap;
}

.deadline-definitions-tab__row--inactive {
	opacity: 0.65;
}

.deadline-definitions-tab__row-name {
	font-weight: 600;
}

.deadline-definitions-tab__row-actions {
	text-align: end;
}

.deadline-definitions-tab__empty {
	margin-top: 32px;
}
</style>
