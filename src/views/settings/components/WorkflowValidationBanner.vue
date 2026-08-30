<template>
	<div v-if="errors.length > 0" class="workflow-validation">
		<div
			v-for="(error, index) in errors"
			:key="index"
			class="workflow-validation__item"
			:class="`workflow-validation__item--${error.type}`">
			<span class="workflow-validation__icon">
				{{ error.type === 'error' ? '!' : '?' }}
			</span>
			<span class="workflow-validation__message">{{ error.message }}</span>
		</div>
		<button class="workflow-validation__dismiss" @click="$emit('dismiss')">
			{{ t('dossiq', 'Dismiss') }}
		</button>
	</div>
</template>

<script>
export default {
	name: 'WorkflowValidationBanner',
	props: {
		errors: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['dismiss'],
}
</script>

<style scoped>
.workflow-validation {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	padding: 8px 12px;
	background: var(--color-warning-light, rgba(var(--color-warning-rgb), 0.1));
	border: 1px solid var(--color-warning);
	border-radius: var(--border-radius);
	margin-bottom: 8px;
	align-items: center;
}

.workflow-validation__item {
	display: flex;
	align-items: center;
	gap: 4px;
	font-size: 13px;
}

.workflow-validation__item--error {
	color: var(--color-error);
}

.workflow-validation__item--warning {
	color: var(--color-warning);
}

.workflow-validation__icon {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 18px;
	height: 18px;
	border-radius: 50%;
	font-size: 11px;
	font-weight: bold;
}

.workflow-validation__item--error .workflow-validation__icon {
	background: var(--color-error);
	color: white;
}

.workflow-validation__item--warning .workflow-validation__icon {
	background: var(--color-warning);
	color: white;
}

.workflow-validation__dismiss {
	margin-left: auto;
	border: none;
	background: none;
	cursor: pointer;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	text-decoration: underline;
}
</style>
