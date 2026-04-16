<template>
	<div v-if="result || showEmpty" class="result-section">
		<h4>{{ t('procest', 'Result') }}</h4>
		<template v-if="result">
			<div class="result-section__card">
				<div class="result-section__name">
					{{ result.name || '—' }}
				</div>
				<div v-if="result.description" class="result-section__description">
					{{ result.description }}
				</div>
				<div v-if="resultTypeName" class="result-section__type">
					{{ t('procest', 'Type: {type}', { type: resultTypeName }) }}
				</div>
				<div v-if="archivalInfo" class="result-section__archival">
					{{ archivalInfo }}
				</div>
			</div>
		</template>
		<template v-else>
			<p class="result-section__empty">
				{{ t('procest', 'No result recorded yet') }}
			</p>
		</template>
	</div>
</template>

<script>
export default {
	/**
	 * @spec openspec/changes/roles-decisions/tasks.md#task-4
	 */
	name: 'ResultSection',
	props: {
		result: {
			type: Object,
			default: null,
		},
		resultTypes: {
			type: Array,
			default: () => [],
		},
		showEmpty: {
			type: Boolean,
			default: false,
		},
	},
	computed: {
		resultType() {
			if (!this.result?.resultType) return null
			return this.resultTypes.find(t => t.id === this.result.resultType) || null
		},
		resultTypeName() {
			return this.resultType?.name || ''
		},
		archivalInfo() {
			if (!this.resultType) return ''
			const rt = this.resultType
			const parts = []

			if (rt.archivalAction) {
				const actionLabel = rt.archivalAction === 'bewaren'
					? t('procest', 'retain')
					: rt.archivalAction === 'vernietigen'
						? t('procest', 'destroy')
						: rt.archivalAction === 'blijvend_bewaren'
							? t('procest', 'permanently retain')
							: rt.archivalAction
				parts.push(t('procest', 'Archive: {action}', { action: actionLabel }))
			}

			if (rt.archivalPeriod) {
				parts.push(t('procest', 'Retention: {period}', { period: this.formatPeriod(rt.archivalPeriod) }))
			}

			return parts.join(' — ')
		},
	},
	methods: {
		formatPeriod(isoDuration) {
			if (!isoDuration) return ''
			const match = isoDuration.match(/P(\d+)Y/)
			if (match) return t('procest', '{years} years', { years: match[1] })
			const dayMatch = isoDuration.match(/P(\d+)D/)
			if (dayMatch) return t('procest', '{days} days', { days: dayMatch[1] })
			return isoDuration
		},
	},
}
</script>

<style scoped>
.result-section {
	margin-top: 16px;
}

.result-section h4 {
	margin: 0 0 8px;
	font-size: 14px;
}

.result-section__card {
	padding: 12px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	border-left: 3px solid var(--color-success);
}

.result-section__name {
	font-weight: 600;
	margin-bottom: 4px;
}

.result-section__description {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	margin-bottom: 4px;
}

.result-section__type {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.result-section__archival {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	margin-top: 4px;
	padding-top: 4px;
	border-top: 1px solid var(--color-border);
}

.result-section__empty {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}
</style>
