<template>
	<div class="deadline-alerts">
		<template v-if="overdue.length === 0 && atRisk.length === 0">
			<div class="deadline-alerts__empty">
				<span class="deadline-alerts__check">&#10003;</span>
				<p>{{ t('procest', 'No deadline alerts') }}</p>
			</div>
		</template>

		<template v-else>
			<div class="deadline-alerts__list">
				<!-- Overdue section -->
				<div
					v-for="item in overdue"
					:key="'overdue-' + item.id"
					class="deadline-alerts__row deadline-alerts__row--overdue"
					role="button"
					tabindex="0"
					@click="
						$router.push({ name: 'CaseDetail', params: { id: item.id } })
					"
					@keydown.enter="
						$router.push({ name: 'CaseDetail', params: { id: item.id } })
					"
					@keydown.space.prevent="
						$router.push({ name: 'CaseDetail', params: { id: item.id } })
					">
					<div class="deadline-alerts__info">
						<span class="deadline-alerts__identifier">{{
							item.identifier
						}}</span>
						<span class="deadline-alerts__title">{{ item.title }}</span>
						<span class="deadline-alerts__type">{{
							item.caseTypeName
						}}</span>
					</div>
					<div class="deadline-alerts__meta">
						<span
							class="deadline-alerts__days deadline-alerts__days--overdue">
							{{
								t('procest', '{days} days overdue', {
									days: item.daysOverdue,
								})
							}}
						</span>
						<span class="deadline-alerts__handler">{{
							item.handler
						}}</span>
					</div>
				</div>

				<!-- At-risk section -->
				<div
					v-for="item in atRisk"
					:key="'atrisk-' + item.id"
					class="deadline-alerts__row deadline-alerts__row--warning"
					role="button"
					tabindex="0"
					@click="
						$router.push({ name: 'CaseDetail', params: { id: item.id } })
					"
					@keydown.enter="
						$router.push({ name: 'CaseDetail', params: { id: item.id } })
					"
					@keydown.space.prevent="
						$router.push({ name: 'CaseDetail', params: { id: item.id } })
					">
					<div class="deadline-alerts__info">
						<span class="deadline-alerts__identifier">{{
							item.identifier
						}}</span>
						<span class="deadline-alerts__title">{{ item.title }}</span>
						<span class="deadline-alerts__type">{{
							item.caseTypeName
						}}</span>
					</div>
					<div class="deadline-alerts__meta">
						<span
							class="deadline-alerts__days deadline-alerts__days--warning">
							<template v-if="item.daysRemaining === 0">
								{{ t('procest', 'Due today') }}
							</template>
							<template v-else>
								{{
									t('procest', '{days} days remaining', {
										days: item.daysRemaining,
									})
								}}
							</template>
						</span>
						<span class="deadline-alerts__handler">{{
							item.handler
						}}</span>
					</div>
				</div>
			</div>

			<div class="deadline-alerts__footer">
				<a
					href="#"
					@click.prevent="
						$router.push({ name: 'Cases', query: { overdue: 'true' } })
					">
					{{ t('procest', 'View all deadline alerts') }} &rarr;
				</a>
			</div>
		</template>
	</div>
</template>

<script>
export default {
	name: 'DeadlineAlerts',
	props: {
		overdue: { type: Array, default: () => [] },
		atRisk: { type: Array, default: () => [] },
	},
}
</script>

<style scoped>
.deadline-alerts {
	padding: 4px 0;
}

.deadline-alerts__list {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.deadline-alerts__row {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	padding: 10px 12px;
	margin-bottom: 4px;
	border-radius: var(--border-radius);
	cursor: pointer;
	transition: background 0.15s ease;
}

.deadline-alerts__row:hover {
	background: var(--color-background-hover);
}

.deadline-alerts__row--overdue {
	border-left: 3px solid var(--color-error);
	background: rgba(var(--color-error-rgb, 229, 57, 53), 0.05);
}

.deadline-alerts__row--warning {
	border-left: 3px solid var(--color-warning);
	background: rgba(var(--color-warning-rgb, 255, 152, 0), 0.05);
}

.deadline-alerts__info {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
	flex: 1;
}

.deadline-alerts__identifier {
	font-weight: bold;
	font-size: 13px;
}

.deadline-alerts__title {
	font-size: 13px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.deadline-alerts__type {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.deadline-alerts__meta {
	display: flex;
	flex-direction: column;
	align-items: flex-end;
	gap: 2px;
	flex-shrink: 0;
	margin-left: 12px;
}

.deadline-alerts__days {
	font-size: 13px;
	font-weight: 600;
	white-space: nowrap;
}

.deadline-alerts__days--overdue {
	color: var(--color-error);
}

.deadline-alerts__days--warning {
	color: var(--color-warning-text);
}

.deadline-alerts__handler {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.deadline-alerts__footer {
	margin-top: 12px;
	text-align: center;
}

.deadline-alerts__footer a {
	font-size: 13px;
	color: var(--color-primary);
	text-decoration: none;
}

.deadline-alerts__empty {
	text-align: center;
	padding: 20px;
	color: var(--color-success);
}

.deadline-alerts__check {
	font-size: 24px;
	display: block;
	margin-bottom: 4px;
}

@media (prefers-reduced-motion: reduce) {
	.deadline-alerts__row {
		transition: none;
	}
}
</style>
