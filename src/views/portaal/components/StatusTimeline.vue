<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  -
  - Status timeline for the "Mijn gemeente" citizen portal (zaakportaal-mijngemeente).
  - Renders a case's status history as an accessible vertical timeline plus a
  - deadline indicator that turns green/orange/red as the termijn approaches and
  - is exceeded. A visually-hidden table provides a screen-reader fallback
  - (WCAG 2.2 AA — non-text content has a text alternative).
-->
<template>
	<section class="zp-timeline" :aria-label="t('procest', 'Status timeline')">
		<ol class="zp-timeline__list">
			<li v-for="(step, index) in steps"
				:key="index"
				class="zp-timeline__step"
				:class="{ 'zp-timeline__step--current': index === steps.length - 1 }">
				<span class="zp-timeline__marker" aria-hidden="true" />
				<div class="zp-timeline__body">
					<span class="zp-timeline__status">{{ step.status }}</span>
					<span class="zp-timeline__date">{{ step.datum }}</span>
					<span v-if="step.toelichting" class="zp-timeline__note">{{ step.toelichting }}</span>
				</div>
			</li>
		</ol>

		<div v-if="deadline"
			class="zp-timeline__deadline"
			:class="`zp-timeline__deadline--${deadlineLevel}`"
			role="status">
			<span class="zp-timeline__deadline-text">{{ deadlineText }}</span>
			<div class="zp-timeline__progress" aria-hidden="true">
				<div class="zp-timeline__progress-bar" :style="{ width: progressPercent + '%' }" />
			</div>
		</div>

		<!-- Screen-reader fallback table -->
		<table class="zp-visually-hidden">
			<caption>{{ t('procest', 'Status timeline, {count} steps', { count: steps.length }) }}</caption>
			<thead>
				<tr>
					<th scope="col">
						{{ t('procest', 'Date') }}
					</th>
					<th scope="col">
						{{ t('procest', 'Status') }}
					</th>
					<th scope="col">
						{{ t('procest', 'Explanation') }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="(step, index) in steps" :key="'sr-' + index">
					<td>{{ step.datum }}</td>
					<td>{{ step.status }}</td>
					<td>{{ step.toelichting }}</td>
				</tr>
			</tbody>
		</table>
	</section>
</template>

<script>
export default {
	name: 'StatusTimeline',
	props: {
		steps: {
			type: Array,
			default: () => [],
		},
		deadline: {
			type: String,
			default: '',
		},
		daysRemaining: {
			type: Number,
			default: 0,
		},
		exceeded: {
			type: Boolean,
			default: false,
		},
	},
	computed: {
		deadlineLevel() {
			if (this.exceeded) {
				return 'exceeded'
			}
			if (this.daysRemaining <= 7) {
				return 'warning'
			}
			return 'ontrack'
		},
		deadlineText() {
			if (this.exceeded) {
				return this.t('procest', 'The handling deadline ({date}) has been exceeded. Please contact your case handler.', { date: this.deadline })
			}
			return this.t('procest', 'Handling deadline: until {date} ({days} days remaining)', { date: this.deadline, days: this.daysRemaining })
		},
		progressPercent() {
			if (this.exceeded) {
				return 100
			}
			// Map remaining days (0..42) onto a used-progress percentage.
			const span = 42
			const used = Math.max(0, span - Math.min(this.daysRemaining, span))
			return Math.round((used / span) * 100)
		},
	},
}
</script>

<style scoped>
.zp-timeline__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.zp-timeline__step {
	position: relative;
	padding: 0 0 16px 24px;
	border-left: 2px solid var(--color-border, #d0d0d0);
}

.zp-timeline__step--current {
	font-weight: 600;
}

.zp-timeline__marker {
	position: absolute;
	left: -7px;
	top: 2px;
	width: 12px;
	height: 12px;
	border-radius: 50%;
	background: var(--color-primary-element, #21468B);
}

.zp-timeline__body {
	display: flex;
	flex-direction: column;
}

.zp-timeline__date {
	color: var(--color-text-maxcontrast, #6b6b6b);
	font-size: 0.9em;
}

.zp-timeline__deadline {
	margin-top: 16px;
	padding: 12px;
	border-radius: var(--border-radius-large, 8px);
	border: 1px solid var(--color-border, #d0d0d0);
}

.zp-timeline__deadline--ontrack {
	border-color: var(--color-success, #2d7d46);
}

.zp-timeline__deadline--warning {
	border-color: var(--color-warning, #c98600);
}

.zp-timeline__deadline--exceeded {
	border-color: var(--color-error, #c4341f);
}

.zp-timeline__progress {
	margin-top: 8px;
	height: 8px;
	background: var(--color-background-dark, #ededed);
	border-radius: 4px;
	overflow: hidden;
}

.zp-timeline__progress-bar {
	height: 100%;
	background: var(--color-primary-element, #21468B);
}

.zp-visually-hidden {
	position: absolute;
	width: 1px;
	height: 1px;
	padding: 0;
	margin: -1px;
	overflow: hidden;
	clip: rect(0, 0, 0, 0);
	white-space: nowrap;
	border: 0;
}
</style>
