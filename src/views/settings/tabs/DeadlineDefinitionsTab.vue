<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<div class="deadline-definitions-tab">
		<div class="deadline-definitions-tab__header">
			<p class="deadline-definitions-tab__description">
				{{
					t(
						'dossiq',
						'Configure statutory term definitions per zaaktype (legal basis, duration, validity). Saving a new version automatically sets validFrom=tomorrow on the new version and validUntil=today on the prior version. New cases use the latest version; running cases keep the version they were bound to.',
					)
				}}
			</p>
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
			<NcButton variant="primary" @click="openNew">
				<template #icon>
					<Plus :size="18" />
				</template>
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

		<div
			v-if="!loading && definitions.length > 0"
			class="deadline-definitions-tab__list">
			<div
				v-for="def in definitions"
				:key="def.id"
				class="deadline-definitions-tab__row"
				:class="{ 'deadline-definitions-tab__row--inactive': !isActive(def) }">
				<div class="deadline-definitions-tab__row-info">
					<strong class="deadline-definitions-tab__row-name">
						{{ def.case_type }}
					</strong>
					<span class="deadline-definitions-tab__pill">
						{{ def.basis || t('dossiq', '(no grondslag)') }}
					</span>
					<span
						class="deadline-definitions-tab__pill deadline-definitions-tab__pill--alt">
						{{ formatDuur(def) }}
					</span>
					<span
						class="deadline-definitions-tab__pill deadline-definitions-tab__pill--alt">
						v{{ def.version || 1 }}
					</span>
					<span
						class="deadline-definitions-tab__pill deadline-definitions-tab__pill--alt"
						data-testid="deadline-counting-mode-pill">
						{{ countingModeLabel(def) }}
					</span>
					<span
						v-if="rollsToWorkingDay(def)"
						class="deadline-definitions-tab__pill deadline-definitions-tab__pill--alt"
						data-testid="deadline-roll-pill">
						{{ t('dossiq', 'Moves off a closed day') }}
					</span>
					<span class="deadline-definitions-tab__validity">
						{{ def.validFrom || '?' }} → {{ def.validUntil || '∞' }}
					</span>
					<span
						class="deadline-definitions-tab__badge"
						:class="
							isActive(def)
								? 'deadline-definitions-tab__badge--active'
								: 'deadline-definitions-tab__badge--inactive'
						">
						{{
							isActive(def)
								? t('dossiq', 'Active')
								: t('dossiq', 'Inactive')
						}}
					</span>
				</div>
				<div class="deadline-definitions-tab__row-actions">
					<NcButton size="small" @click="openEdit(def)">
						{{ t('dossiq', 'New version') }}
					</NcButton>
				</div>
			</div>
		</div>

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
import Plus from 'vue-material-design-icons/Plus.vue'
import TimerSand from 'vue-material-design-icons/TimerSand.vue'
import DeadlineDefinitionEditor from '../../../modals/DeadlineDefinitionEditor.vue'

export default {
	name: 'DeadlineDefinitionsTab',
	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		Plus,
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
		/** @spec openspec/changes/termijnbewaking-dwangsom-engine-11-tests-admin-docs/tasks.md */
		zaaktypeOptions() {
			const seen = new Set()
			const opts = []
			for (const d of this.definitions) {
				const z = d.case_type || ''
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
			const v = def.duurDagen || def.duur || 0
			return v ? t('dossiq', '{n} days', { n: v }) : '—'
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
	padding: 8px 0;
}

.deadline-definitions-tab__header {
	margin-bottom: 16px;
}

.deadline-definitions-tab__calendar {
	color: var(--color-text-maxcontrast);
	margin: 0 0 8px;
}

.deadline-definitions-tab__description {
	color: var(--color-text-maxcontrast);
	margin: 4px 0 12px 0;
}

.deadline-definitions-tab__list {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.deadline-definitions-tab__row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	padding: 10px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
}

.deadline-definitions-tab__row--inactive {
	opacity: 0.65;
}

.deadline-definitions-tab__row-info {
	display: flex;
	align-items: center;
	gap: 10px;
	flex-wrap: wrap;
}

.deadline-definitions-tab__pill {
	font-size: 12px;
	padding: 2px 8px;
	border-radius: 8px;
	background: var(--color-background-dark);
}

.deadline-definitions-tab__pill--alt {
	background: var(--color-background-darker);
}

.deadline-definitions-tab__validity {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.deadline-definitions-tab__badge {
	font-size: 11px;
	padding: 2px 8px;
	border-radius: 8px;
	font-weight: 500;
}

.deadline-definitions-tab__badge--active {
	background: var(--color-success);
	color: var(--color-success-text);
}

.deadline-definitions-tab__badge--inactive {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.deadline-definitions-tab__row-actions {
	display: flex;
	gap: 6px;
}

.deadline-definitions-tab__empty {
	margin-top: 32px;
}
</style>
