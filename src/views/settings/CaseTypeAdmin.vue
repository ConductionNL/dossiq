<template>
	<div class="case-type-admin">
		<CaseTypeDetail
			v-if="currentView === 'detail'"
			:case-type-id="currentId"
			@back="showList"
			@saved="onSaved" />
		<CaseTypeList
			v-else
			@select="openDetail"
			@create="openCreate" />
	</div>
</template>

<script>
import CaseTypeList from './CaseTypeList.vue'
import CaseTypeDetail from './CaseTypeDetail.vue'

export default {
	name: 'CaseTypeAdmin',
	components: {
		CaseTypeList,
		CaseTypeDetail,
	},
	data() {
		return {
			currentView: 'list',
			currentId: null,
		}
	},
	methods: {
		/**
		 * @param id
		 * @spec openspec/changes/retrofit-2026-05-24-case-types/tasks.md
		 */
		openDetail(id) {
			this.currentId = id
			this.currentView = 'detail'
		},
		/** @spec openspec/changes/retrofit-2026-05-24-case-types/tasks.md */
		openCreate() {
			this.currentId = null
			this.currentView = 'detail'
		},
		/** @spec openspec/changes/retrofit-2026-05-24-case-types/tasks.md */
		showList() {
			this.currentView = 'list'
			this.currentId = null
		},
		/**
		 * @param id
		 * @spec openspec/changes/retrofit-2026-05-24-case-types/tasks.md
		 */
		onSaved(id) {
			this.currentId = id
		},
	},
}
</script>
