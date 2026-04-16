<template>
	<div class="deelzaak-hierarchy-tree">
		<!-- Root case node -->
		<div class="hierarchy-node hierarchy-node--root">
			<span class="hierarchy-node__icon">📁</span>
			<span class="hierarchy-node__title">{{ node.case.title || '—' }}</span>
			<span class="status-badge" :class="getStatusClass(node.case)">
				{{ getStatusName(node.case) }}
			</span>
			<span v-if="isCurrentCase" class="hierarchy-node__badge-current">
				{{ t('procest', 'This case') }}
			</span>
		</div>

		<!-- Children -->
		<div v-if="node.children && node.children.length > 0" class="hierarchy-children">
			<div
				v-for="child in node.children"
				:key="child.case.id"
				class="hierarchy-child-wrapper">
				<div class="hierarchy-connector" />
				<!-- Recurse for each child — supports arbitrary depth (min 3 levels per spec) -->
				<DeelzaakHierarchyTree
					:node="child"
					:status-type-map="statusTypeMap"
					:current-case-id="currentCaseId"
					@navigate="$emit('navigate', $event)" />
			</div>
		</div>
	</div>
</template>

<script>
/**
 * Recursive hierarchy tree component for deelzaken (sub-cases).
 *
 * Renders a hoofdzaak with all its deelzaken at arbitrary depth,
 * each with a coloured status badge. Clicking a non-current node
 * emits 'navigate' with the case ID.
 *
 * @spec openspec/changes/deelzaak-support/tasks.md#T04
 */
export default {
	name: 'DeelzaakHierarchyTree',

	props: {
		/**
		 * Hierarchy node: { case: {...}, children: [...] }
		 */
		node: {
			type: Object,
			required: true,
		},
		/**
		 * Map of statusType UUID → statusType object for badge labels.
		 */
		statusTypeMap: {
			type: Object,
			default: () => ({}),
		},
		/**
		 * UUID of the case currently being viewed (highlighted differently).
		 */
		currentCaseId: {
			type: String,
			default: null,
		},
	},

	emits: ['navigate'],

	computed: {
		isCurrentCase() {
			return this.node.case.id === this.currentCaseId
		},
	},

	methods: {
		/**
		 * Return the status display name for a case.
		 *
		 * @param {object} caseObj The case object
		 * @returns {string}
		 *
		 * @spec openspec/changes/deelzaak-support/tasks.md#T04
		 */
		getStatusName(caseObj) {
			if (!caseObj.status) return '—'
			return this.statusTypeMap[caseObj.status]?.name || '—'
		},

		/**
		 * Return CSS modifier class for the status badge.
		 *
		 * @param {object} caseObj The case object
		 * @returns {string}
		 *
		 * @spec openspec/changes/deelzaak-support/tasks.md#T04
		 */
		getStatusClass(caseObj) {
			if (caseObj.endDate) return 'status-badge--closed'
			const st = caseObj.status ? this.statusTypeMap[caseObj.status] : null
			if (st?.isFinal === true || st?.isFinal === 'true') return 'status-badge--final'
			return 'status-badge--active'
		},
	},
}
</script>

<style scoped>
.deelzaak-hierarchy-tree {
	font-size: 14px;
}

.hierarchy-node {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 6px 8px;
	border-radius: var(--border-radius);
	cursor: pointer;
	transition: background-color 0.15s ease;
}

.hierarchy-node:hover {
	background: var(--color-background-hover);
}

.hierarchy-node--root {
	font-weight: 600;
}

.hierarchy-node__title {
	flex: 1;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.hierarchy-node__badge-current {
	font-size: 11px;
	padding: 1px 6px;
	border-radius: var(--border-radius-pill);
	background: var(--color-primary);
	color: var(--color-primary-text);
}

.hierarchy-children {
	padding-left: 20px;
	border-left: 2px solid var(--color-border);
	margin-left: 12px;
}

.hierarchy-child-wrapper {
	position: relative;
}

.hierarchy-connector {
	position: absolute;
	left: -20px;
	top: 50%;
	width: 18px;
	height: 2px;
	background: var(--color-border);
}

.status-badge {
	font-size: 11px;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	white-space: nowrap;
}

.status-badge--active {
	background: var(--color-primary-light);
	color: var(--color-primary-text);
}

.status-badge--final,
.status-badge--closed {
	background: var(--color-success);
	color: #fff;
}
</style>
