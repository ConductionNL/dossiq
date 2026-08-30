<template>
	<g
		class="workflow-transition"
		:class="{ 'workflow-transition--selected': selected }"
		@click.stop="$emit('click')"
		@dblclick.stop="$emit('dblclick')">
		<!-- Arrow path -->
		<path
			:d="arrowPath"
			fill="none"
			:stroke="
				selected ? 'var(--color-primary)' : 'var(--color-text-maxcontrast)'
			"
			stroke-width="2"
			class="workflow-transition__path" />

		<!-- Arrowhead -->
		<polygon
			:points="arrowheadPoints"
			:fill="
				selected ? 'var(--color-primary)' : 'var(--color-text-maxcontrast)'
			" />

		<!-- Label -->
		<text
			v-if="transition.label"
			:x="midX"
			:y="midY - 8"
			text-anchor="middle"
			class="workflow-transition__label"
			:fill="
				selected ? 'var(--color-primary)' : 'var(--color-text-maxcontrast)'
			">
			{{ transition.label }}
		</text>

		<!-- Click target (wider invisible path for easier clicking) -->
		<path
			:d="arrowPath"
			fill="none"
			stroke="transparent"
			stroke-width="16"
			class="workflow-transition__click-target"
			style="cursor: pointer" />
	</g>
</template>

<script>
export default {
	name: 'WorkflowTransitionArrow',
	props: {
		transition: {
			type: Object,
			required: true,
		},

		fromPos: {
			type: Object,
			required: true,
		},

		toPos: {
			type: Object,
			required: true,
		},

		selected: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['click', 'dblclick'],
	computed: {
		/** @spec openspec/specs/workflow-definition-model/spec.md */
		midX() {
			return (this.fromPos.x + this.toPos.x) / 2
		},

		/** @spec openspec/specs/workflow-definition-model/spec.md */
		midY() {
			return (this.fromPos.y + this.toPos.y) / 2
		},

		/** @spec openspec/specs/workflow-definition-model/spec.md */
		arrowPath() {
			const dx = this.toPos.x - this.fromPos.x
			const dy = this.toPos.y - this.fromPos.y

			// Simple cubic bezier for smooth curves
			const cx1 = this.fromPos.x + dx * 0.3
			const cy1 = this.fromPos.y + dy * 0.0
			const cx2 = this.toPos.x - dx * 0.3
			const cy2 = this.toPos.y - dy * 0.0

			return `M ${this.fromPos.x} ${this.fromPos.y} C ${cx1} ${cy1}, ${cx2} ${cy2}, ${this.toPos.x} ${this.toPos.y}`
		},

		/** @spec openspec/specs/workflow-definition-model/spec.md */
		arrowheadPoints() {
			// Calculate arrowhead at the end point
			const dx = this.toPos.x - this.fromPos.x
			const dy = this.toPos.y - this.fromPos.y
			const angle = Math.atan2(dy, dx)
			const headLength = 10
			const headWidth = 6

			const x = this.toPos.x
			const y = this.toPos.y
			const x1 = x - headLength * Math.cos(angle - Math.PI / headWidth)
			const y1 = y - headLength * Math.sin(angle - Math.PI / headWidth)
			const x2 = x - headLength * Math.cos(angle + Math.PI / headWidth)
			const y2 = y - headLength * Math.sin(angle + Math.PI / headWidth)

			return `${x},${y} ${x1},${y1} ${x2},${y2}`
		},
	},
}
</script>

<style scoped>
.workflow-transition__path {
	transition: stroke 0.15s ease;
}

.workflow-transition__label {
	font-size: 11px;
	font-weight: 500;
	pointer-events: none;
}

.workflow-transition:hover .workflow-transition__path {
	stroke: var(--color-primary);
	stroke-width: 3;
}

@media (prefers-reduced-motion: reduce) {
	.workflow-transition__path {
		transition: none;
	}
}
</style>
