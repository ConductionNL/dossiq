<template>
	<div class="stalled-cases">
		<template v-if="stalledCases.length === 0">
			<div class="stalled-cases__empty">
				<span class="stalled-cases__check">&#10003;</span>
				<p>{{ t('procest', 'All cases active') }}</p>
			</div>
		</template>

		<template v-else>
			<div class="stalled-cases__list">
				<div
					v-for="item in stalledCases"
					:key="item.id"
					class="stalled-cases__row"
					role="button"
					tabindex="0"
					@click="$router.push({ name: 'CaseDetail', params: { id: item.id } })"
					@keydown.enter="$router.push({ name: 'CaseDetail', params: { id: item.id } })"
					@keydown.space.prevent="$router.push({ name: 'CaseDetail', params: { id: item.id } })">
					<div class="stalled-cases__info">
						<span class="stalled-cases__identifier">{{ item.identifier }}</span>
						<span class="stalled-cases__title">{{ item.title }}</span>
						<span class="stalled-cases__type">{{ item.caseTypeName }}</span>
					</div>
					<div class="stalled-cases__meta">
						<span class="stalled-cases__days">
							{{ t('procest', '{days} days inactive', { days: item.daysSinceActivity }) }}
						</span>
						<span class="stalled-cases__handler">{{ item.handler }}</span>
					</div>
				</div>
			</div>
		</template>
	</div>
</template>

<script>
export default {
	name: 'StalledCases',
	props: {
		stalledCases: { type: Array, default: () => [] },
	},
}
</script>

<style scoped>
.stalled-cases {
	padding: 4px 0;
}

.stalled-cases__list {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.stalled-cases__row {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	padding: 10px 12px;
	margin-bottom: 4px;
	border-radius: var(--border-radius);
	border-left: 3px solid var(--color-text-maxcontrast);
	cursor: pointer;
	transition: background 0.15s ease;
}

.stalled-cases__row:hover {
	background: var(--color-background-hover);
}

.stalled-cases__info {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
	flex: 1;
}

.stalled-cases__identifier {
	font-weight: bold;
	font-size: 13px;
}

.stalled-cases__title {
	font-size: 13px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.stalled-cases__type {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.stalled-cases__meta {
	display: flex;
	flex-direction: column;
	align-items: flex-end;
	gap: 2px;
	flex-shrink: 0;
	margin-left: 12px;
}

.stalled-cases__days {
	font-size: 13px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}

.stalled-cases__handler {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.stalled-cases__empty {
	text-align: center;
	padding: 20px;
	color: var(--color-success);
}

.stalled-cases__check {
	font-size: 24px;
	display: block;
	margin-bottom: 4px;
}

@media (prefers-reduced-motion: reduce) {
	.stalled-cases__row {
		transition: none;
	}
}
</style>
