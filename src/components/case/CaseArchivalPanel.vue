<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  What happens to this case when its business use ends, as openregister
  decided it.

  THE PANEL DERIVES NOTHING. Every value on screen is read off
  `@self._retention`, which openregister writes once, at closure, with the rule
  and the selectielijst row that produced it. A second derivation here would
  eventually disagree with the stored one, and a records manager reading a
  disposal date would have no way to tell which of the two they were looking at.

  IT FAILS CLOSED. An unreachable openregister renders an error with a retry,
  never an empty archival block, because "openregister did not answer" and "this
  case has no archival future" look identical from the browser and only one of
  them is safe to act on.

  AN UNNOMINATABLE CASE IS DRAWN APART FROM A CASE WITH NO NOMINATION. A case
  nobody could nominate has no archival future at all, which is the failure mode
  that keeps personal data past its lawful term; a case nobody has closed yet is
  nobody's problem. They look the same from an absent appraisal, so they are
  given different words on purpose.

  @spec openspec/changes/the-case-archives-through-openregister/specs/archief-edepot-handover/spec.md
-->
<template>
	<div class="case-archival" data-testid="case-archival">
		<NcLoadingIcon v-if="loading" :size="24" />

		<NcEmptyContent
			v-else-if="error"
			data-testid="case-archival-error"
			:name="t('dossiq', 'The archival facts are unavailable')"
			:description="error">
			<template #icon>
				<AlertCircleOutline :size="20" />
			</template>
			<template #action>
				<NcButton data-testid="case-archival-retry" @click="load">
					{{ t('dossiq', 'Try again') }}
				</NcButton>
			</template>
		</NcEmptyContent>

		<div v-else>
			<NcNoteCard
				v-if="isUnnominatable"
				type="warning"
				data-testid="case-archival-unnominatable">
				{{ t('dossiq', 'Nothing could decide what happens to this case.') }}
				{{ unnominatableReason }}
			</NcNoteCard>

			<NcEmptyContent
				v-else-if="hasNoNomination"
				data-testid="case-archival-none"
				:name="t('dossiq', 'This case has not been closed yet')"
				:description="t('dossiq', 'Its archival future is decided when it closes.')">
				<template #icon>
					<ArchiveOutline :size="20" />
				</template>
			</NcEmptyContent>

			<dl v-else class="case-archival__facts" data-testid="case-archival-facts">
				<div v-for="fact in facts" :key="fact.key" class="case-archival__fact">
					<dt>{{ fact.label }}</dt>
					<dd :data-testid="`case-archival-${fact.key}`">
						{{ fact.value }}
					</dd>
				</div>
			</dl>

			<div v-if="outcome" class="case-archival__outcome" data-testid="case-archival-outcome">
				<h4>{{ t('dossiq', 'Outcome') }}</h4>
				<p data-testid="case-archival-outcome-kind">
					{{ outcomeSentence }}
				</p>
				<p v-if="outcome.transferListUuid" data-testid="case-archival-transfer-list">
					{{ t('dossiq', 'Transfer list') }}: {{ outcome.transferListUuid }}
				</p>
			</div>

			<div class="case-archival__recompute">
				<NcButton
					v-if="mayRecompute === false"
					disabled
					data-testid="case-archival-recompute-denied">
					{{ t('dossiq', 'Recompute needs the archivist role') }}
				</NcButton>

				<template v-else>
					<NcTextField
						:modelValue="reason"
						data-testid="case-archival-reason"
						:label="t('dossiq', 'Why are you recomputing this?')"
						@update:modelValue="(v) => (reason = v)" />
					<p class="case-archival__attribution" data-testid="case-archival-attribution">
						{{ attribution }}
					</p>
					<NcButton
						:disabled="canRecompute === false"
						data-testid="case-archival-recompute"
						@click="recompute">
						{{ t('dossiq', 'Recompute the nomination') }}
					</NcButton>
				</template>
			</div>
		</div>
	</div>
</template>

<script>
import { getCurrentUser } from '@nextcloud/auth'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import ArchiveOutline from 'vue-material-design-icons/ArchiveOutline.vue'
import { caseRetention, recomputeNomination } from '../../services/archivalApi.js'

export default {
	name: 'CaseArchivalPanel',

	components: {
		AlertCircleOutline,
		ArchiveOutline,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		NcTextField,
	},

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
			error: '',
			retention: null,
			reason: '',
			busy: false,
		}
	},

	computed: {
		/** @spec openspec/changes/the-case-archives-through-openregister/specs/archief-edepot-handover/spec.md */
		caseId() {
			return String(this.objectId || this.$route?.params?.id || '')
		},

		/** @spec openspec/changes/the-case-archives-through-openregister/specs/archief-edepot-handover/spec.md */
		nomination() {
			return (this.retention?.nomination ?? null)
		},

		/** @spec openspec/changes/the-case-archives-through-openregister/specs/archief-edepot-handover/spec.md */
		isUnnominatable() {
			return this.nomination?.status === 'unnominatable'
		},

		/** @spec openspec/changes/the-case-archives-through-openregister/specs/archief-edepot-handover/spec.md */
		hasNoNomination() {
			return this.nomination === null
		},

		/** @spec openspec/changes/the-case-archives-through-openregister/specs/archief-edepot-handover/spec.md */
		unnominatableReason() {
			return String(this.nomination?.unnominatableReason ?? '')
		},

		/** @spec openspec/changes/the-case-archives-through-openregister/specs/archief-edepot-handover/spec.md */
		outcome() {
			return (this.retention?.outcome ?? null)
		},

		/**
		 * The stored facts, in the order a records manager reads them.
		 *
		 * @return {Array<object>} Label, value and a test key per fact.
		 * @spec openspec/changes/the-case-archives-through-openregister/specs/archief-edepot-handover/spec.md
		 */
		facts() {
			const r = (this.retention ?? {})

			return [
				{ key: 'appraisal', label: this.t('dossiq', 'Appraisal'), value: r.appraisal },
				{ key: 'disposal-date', label: this.t('dossiq', 'Disposal date'), value: r.disposalDate },
				{ key: 'retention-period', label: this.t('dossiq', 'Retention period'), value: r.retentionPeriod },
				{ key: 'selection-list-row', label: this.t('dossiq', 'Selectielijst row'), value: r.selectionListRow },
				{ key: 'rule', label: this.t('dossiq', 'Decided by'), value: this.nomination?.rule },
				{ key: 'decided-at', label: this.t('dossiq', 'Written on'), value: this.nomination?.at },
			].filter((fact) => fact.value !== undefined && fact.value !== null && fact.value !== '')
		},

		/** @spec openspec/changes/the-case-archives-through-openregister/specs/archief-edepot-handover/spec.md */
		outcomeSentence() {
			const o = (this.outcome ?? {})

			return this.t('dossiq', 'Handed over as {kind} on {at} by {by}.', {
				kind: String(o.kind ?? ''),
				at: String(o.at ?? ''),
				by: String(o.by ?? ''),
			})
		},

		/**
		 * May this reader ask for a recomputation?
		 *
		 * openregister refuses anyone who is neither archivist nor
		 * administrator, so the control is shown disabled with the role rather
		 * than hidden: a hidden control teaches nobody which role they need.
		 *
		 * @return {boolean} True for an archivist or an administrator.
		 * @spec openspec/changes/the-case-archives-through-openregister/specs/archief-edepot-handover/spec.md
		 */
		mayRecompute() {
			const user = getCurrentUser()
			if (user?.isAdmin === true) {
				return true
			}

			const groups = (user?.groups ?? [])

			return Array.isArray(groups) && groups.includes('archivaris')
		},

		/** @spec openspec/changes/the-case-archives-through-openregister/specs/archief-edepot-handover/spec.md */
		canRecompute() {
			return this.busy === false && String(this.reason).trim() !== ''
		},

		/** @spec openspec/changes/the-case-archives-through-openregister/specs/archief-edepot-handover/spec.md */
		attribution() {
			return this.t('dossiq', 'This is recorded against {user}.', {
				user: String(getCurrentUser()?.uid ?? ''),
			})
		},
	},

	/**
	 * Read the archival facts once the panel is on the page.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/changes/the-case-archives-through-openregister/specs/archief-edepot-handover/spec.md
	 */
	async mounted() {
		await this.load()
	},

	methods: {
		/**
		 * Read `@self._retention` off the case.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/the-case-archives-through-openregister/specs/archief-edepot-handover/spec.md
		 */
		async load() {
			this.loading = true
			this.error = ''

			try {
				this.retention = await caseRetention(this.caseId)
			} catch (e) {
				this.error = String(e?.message ?? e)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Ask openregister to derive this case's nomination again.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/the-case-archives-through-openregister/specs/archief-edepot-handover/spec.md
		 */
		async recompute() {
			if (this.canRecompute === false) {
				return
			}

			this.busy = true

			try {
				await recomputeNomination(this.caseId, String(this.reason).trim())
				this.reason = ''
				await this.load()
			} catch (e) {
				this.error = String(e?.message ?? e)
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.case-archival__fact {
	display: flex;
	gap: var(--default-grid-baseline, 4px);
}

.case-archival__attribution {
	color: var(--color-text-maxcontrast);
}
</style>
