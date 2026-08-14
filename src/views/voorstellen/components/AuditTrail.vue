<template>
	<div class="audit-trail">
		<NcLoadingIcon v-if="loading" :size="20" />

		<div v-else-if="acties.length === 0" class="audit-trail__empty">
			{{ t('procest', 'No actions recorded') }}
		</div>

		<div v-else>
			<div
				v-for="actie in acties"
				:key="actie.id"
				class="audit-trail__entry"
				:class="`audit-trail__entry--${actie.action}`">
				<div class="audit-trail__entry-header">
					<span
						class="audit-trail__action-badge"
						:class="`audit-trail__action-badge--${actie.action}`">
						{{ formatAction(actie.action) }}
					</span>
					<span class="audit-trail__step">
						{{ t('procest', 'Step {n}', { n: actie.step }) }}
					</span>
					<span class="audit-trail__timestamp">
						{{ formatTimestamp(actie) }}
					</span>
				</div>
				<div class="audit-trail__actor">
					<template
						v-if="actie.actorType === 'delegate' && actie.onBehalfOf">
						{{
							t(
								'procest',
								'Endorsed by {delegate} on behalf of {principal}',
								{
									delegate: actie.actor,
									principal: actie.onBehalfOf,
								},
							)
						}}
					</template>
					<template v-else>
						{{ actie.actor }}
					</template>
				</div>
				<div v-if="actie.comment" class="audit-trail__comment">
					<strong>{{ t('procest', 'Comment') }}:</strong>
					{{ actie.comment }}
				</div>
				<div v-if="actie.advice" class="audit-trail__advice">
					<strong>{{ t('procest', 'Advice') }}:</strong> {{ actie.advice }}
				</div>
				<div v-if="actie.mandate" class="audit-trail__mandate">
					<em>{{ t('procest', 'Mandaat') }}: {{ actie.mandate }}</em>
				</div>
			</div>

			<!-- Export button -->
			<NcButton class="audit-trail__export" @click="exportAuditTrail">
				{{ t('procest', 'Export') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'

const ACTION_LABELS = {
	parafered: 'Endorsed',
	returned: 'Returned',
	advised: 'Advised',
	skipped: 'Skipped',
}

export default {
	name: 'AuditTrail',
	components: {
		NcButton,
		NcLoadingIcon,
	},
	props: {
		acties: {
			type: Array,
			default: () => [],
		},
		loading: {
			type: Boolean,
			default: false,
		},
	},
	methods: {
		/**
		 * @param action
		 * @spec openspec/specs/parafering-actions/spec.md
		 */
		formatAction(action) {
			return t('procest', ACTION_LABELS[action] || action)
		},
		/**
		 * @param actie
		 * @spec openspec/specs/parafering-actions/spec.md
		 */
		formatTimestamp(actie) {
			const ts = actie._self?.created || actie.timestamp
			if (!ts) return '-'
			return new Date(ts).toLocaleString('nl-NL', {
				day: '2-digit',
				month: '2-digit',
				year: 'numeric',
				hour: '2-digit',
				minute: '2-digit',
			})
		},
		/** @spec openspec/specs/parafering-actions/spec.md */
		exportAuditTrail() {
			const data = this.acties.map((a) => ({
				step: a.step,
				action: this.formatAction(a.action),
				actor: a.actor,
				actorType: a.actorType || 'user',
				onBehalfOf: a.onBehalfOf || '',
				comment: a.comment || '',
				advice: a.advice || '',
				mandate: a.mandate || '',
				timestamp: this.formatTimestamp(a),
			}))

			const json = JSON.stringify(data, null, 2)
			const blob = new Blob([json], { type: 'application/json' })
			const url = URL.createObjectURL(blob)
			const link = document.createElement('a')
			link.href = url
			link.download = `parafeerhistorie-${Date.now()}.json`
			link.click()
			URL.revokeObjectURL(url)
		},
	},
}
</script>

<style scoped>
.audit-trail__empty {
	color: var(--color-text-maxcontrast);
	padding: 4px 0;
}

.audit-trail__entry {
	padding: 10px 12px;
	border-left: 3px solid var(--color-border);
	margin-bottom: 8px;
	border-radius: 0 var(--border-radius) var(--border-radius) 0;
	background: var(--color-background-hover);
}

.audit-trail__entry--parafered {
	border-left-color: var(--color-success, #2e7d32);
}

.audit-trail__entry--returned {
	border-left-color: var(--color-warning, #e65100);
}

.audit-trail__entry--advised {
	border-left-color: var(--color-primary-element);
}

.audit-trail__entry--skipped {
	border-left-color: var(--color-text-maxcontrast);
}

.audit-trail__entry-header {
	display: flex;
	gap: 8px;
	align-items: center;
	margin-bottom: 4px;
}

.audit-trail__action-badge {
	display: inline-block;
	padding: 1px 8px;
	border-radius: var(--border-radius);
	font-size: 0.85em;
	font-weight: 600;
}

.audit-trail__action-badge--parafered {
	background: var(--color-success-light, #e8f5e9);
	color: var(--color-success, #2e7d32);
}

.audit-trail__action-badge--returned {
	background: var(--color-warning-light, #fff3e0);
	color: var(--color-warning, #e65100);
}

.audit-trail__action-badge--advised {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element);
}

.audit-trail__action-badge--skipped {
	background: var(--color-background-dark);
}

.audit-trail__step {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.audit-trail__timestamp {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
	margin-left: auto;
}

.audit-trail__actor {
	font-weight: 600;
	margin-bottom: 4px;
}

.audit-trail__comment,
.audit-trail__advice {
	font-size: 0.9em;
	margin-top: 4px;
}

.audit-trail__mandate {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
	margin-top: 4px;
}

.audit-trail__export {
	margin-top: 12px;
}
</style>
