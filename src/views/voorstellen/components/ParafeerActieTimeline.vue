<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl> -->
<!--
  Read-only chronological action history for a voorstel.
  Loads parafeeracties via the procest /api/parafeer-actie GET endpoint.

  @spec openspec/changes/parafering-actions/tasks.md#T09
-->
<template>
	<div class="parafeer-actie-timeline">
		<NcLoadingIcon v-if="loading" :size="32" />
		<p v-else-if="error" class="parafeer-actie-timeline__error">
			{{ error }}
		</p>
		<p v-else-if="acties.length === 0" class="parafeer-actie-timeline__empty">
			{{ t('procest', 'No actions recorded yet') }}
		</p>
		<ul v-else class="parafeer-actie-timeline__list">
			<li
				v-for="(actie, idx) in acties"
				:key="actie.id || idx"
				class="parafeer-actie-timeline__entry">
				<div class="parafeer-actie-timeline__header">
					<strong>{{ formatStageLabel(actie) }}</strong>
					<span class="parafeer-actie-timeline__time">{{
						formatTimestamp(actie)
					}}</span>
				</div>
				<div class="parafeer-actie-timeline__actor">
					{{ formatActor(actie) }}
				</div>
				<div v-if="actie.advice" class="parafeer-actie-timeline__body">
					<em>{{ t('procest', 'Advice') }}:</em> {{ actie.advice }}
				</div>
				<div v-else-if="actie.comment" class="parafeer-actie-timeline__body">
					{{ actie.comment }}
				</div>
			</li>
		</ul>
	</div>
</template>

<script>
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { listActions } from '../../../services/parafeerActieApi.js'

const ACTION_LABELS = {
	advised: 'Advised',
	parafered: 'Endorsed',
	accorded: 'Accorded',
	returned: 'Returned',
	skipped: 'Skipped',
}

export default {
	name: 'ParafeerActieTimeline',
	components: {
		NcLoadingIcon,
	},
	props: {
		voorstelId: {
			type: String,
			required: true,
		},
	},
	data() {
		return {
			acties: [],
			loading: true,
			error: '',
		}
	},
	async mounted() {
		await this.load()
	},
	methods: {
		/** @spec openspec/specs/parafering-actions/spec.md */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const results = await listActions(this.voorstelId)
				this.acties = Array.isArray(results) ? results : []
			} catch (error) {
				const serverMessage = error?.response?.data?.message
				this.error = serverMessage || this.t('procest', 'Operation failed')
				this.acties = []
			} finally {
				this.loading = false
			}
		},
		/**
		 * @param actie
		 * @spec openspec/specs/parafering-actions/spec.md
		 */
		formatStageLabel(actie) {
			const englishKey = ACTION_LABELS[actie.action] || actie.action || ''
			const localized = this.t('procest', englishKey)
			return this.t('procest', 'Step {step} — {action}', {
				step: actie.step,
				action: localized,
			})
		},
		/**
		 * @param actie
		 * @spec openspec/specs/parafering-actions/spec.md
		 */
		formatActor(actie) {
			if (actie.actorType === 'delegate' && actie.onBehalfOf) {
				return this.t('procest', 'On behalf of {name} (mandate {ref})', {
					name: actie.onBehalfOf,
					ref: actie.mandate || '—',
				})
			}
			return actie.actor || '—'
		},
		/**
		 * @param actie
		 * @spec openspec/specs/parafering-actions/spec.md
		 */
		formatTimestamp(actie) {
			const raw = actie.createdAt || actie.created || actie['@self']?.created
			if (!raw) return ''
			try {
				const date = new Date(raw)
				if (Number.isNaN(date.getTime())) return ''
				return date.toLocaleString()
			} catch {
				return ''
			}
		},
	},
}
</script>

<style scoped>
.parafeer-actie-timeline__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.parafeer-actie-timeline__entry {
	border-left: 3px solid var(--color-primary-element);
	padding: 8px 12px;
	margin-bottom: 12px;
	background: var(--color-background-hover);
}

.parafeer-actie-timeline__header {
	display: flex;
	justify-content: space-between;
	align-items: baseline;
}

.parafeer-actie-timeline__time {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.parafeer-actie-timeline__actor {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin-top: 2px;
}

.parafeer-actie-timeline__body {
	margin-top: 6px;
}

.parafeer-actie-timeline__empty,
.parafeer-actie-timeline__error {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.parafeer-actie-timeline__error {
	color: var(--color-error);
}
</style>
