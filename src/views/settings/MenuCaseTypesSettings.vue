<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Case types in my menu: the case types this user wants under My case types
  in dossiq's sidebar, in their own order (board DqPersoonlijkeInstellingen).

  It lives in Nextcloud's personal settings, in the Dossiq section, because the
  choice is the reader's own. Every change is saved at once, as the board says.

  Reordering has two ways in. A mouse drags a row by its handle; a keyboard
  focuses the handle and presses Arrow up or Arrow down. The handle's name says
  where the row is, and a polite live region says where it went.

  Beside each case type, in the list and in the picker, stands how many open
  cases of that type the reader may see (REQ-CTN-006). The numbers come with
  the first read and are kept here, so saving the list does not ask again.

  @spec openspec/changes/case-types-in-my-menu/specs/case-type-navigation/spec.md#REQ-CTN-005
  @spec openspec/changes/menu-case-type-counts/specs/case-type-navigation/spec.md#requirement-req-ctn-006-the-picker-says-how-many-open-cases-each-case-type-has
-->
<template>
	<div class="menu-case-types" data-testid="menu-case-types">
		<p v-if="loadError" class="menu-case-types__error" role="alert">
			{{ loadError }}
		</p>

		<ul
			v-if="chosen.length > 0"
			class="menu-case-types__list"
			:aria-label="t('dossiq', 'Case types in my menu, in this order')"
			data-testid="menu-case-types-list">
			<li
				v-for="(caseType, index) in chosen"
				:key="caseType.id"
				class="menu-case-types__row"
				:class="{ 'menu-case-types__row--dragging': dragIndex === index }"
				draggable="true"
				:data-testid="`menu-case-types-row-${caseType.id}`"
				@dragstart="onDragStart(index, $event)"
				@dragover.prevent
				@drop.prevent="onDropRow(index)"
				@dragend="dragIndex = null">
				<button
					type="button"
					class="menu-case-types__handle"
					:aria-label="moveLabel(caseType, index)"
					:title="t('dossiq', 'Drag, or use the arrow keys, to move')"
					data-testid="menu-case-types-move"
					@keydown.up.prevent="move(index, -1)"
					@keydown.down.prevent="move(index, 1)">
					<DragVertical :size="20" />
				</button>
				<span class="menu-case-types__icon" aria-hidden="true">
					<FolderOutline :size="20" />
				</span>
				<span class="menu-case-types__text">
					<span class="menu-case-types__label">{{ caseType.title }}</span>
					<span
						v-if="openCasesLabel(caseType)"
						class="menu-case-types__meta"
						data-testid="menu-case-types-count"
						>{{ openCasesLabel(caseType) }}</span
					>
				</span>
				<NcButton
					variant="tertiary"
					:aria-label="
						t('dossiq', '{name}, remove from my menu', {
							name: caseType.title,
						})
					"
					:title="t('dossiq', 'Remove from my menu')"
					data-testid="menu-case-types-remove"
					@click="remove(index)">
					<template #icon>
						<Close :size="20" />
					</template>
				</NcButton>
			</li>
		</ul>
		<p
			v-else-if="loaded"
			class="menu-case-types__empty"
			data-testid="menu-case-types-empty">
			{{ t('dossiq', 'Your menu shows no case types yet. Add one below.') }}
		</p>

		<NcSelect
			:modelValue="null"
			class="menu-case-types__add"
			:options="addable"
			label="title"
			:inputLabel="t('dossiq', 'Add case type')"
			:placeholder="t('dossiq', 'Search a case type')"
			:disabled="!loaded"
			data-testid="menu-case-types-add"
			@update:modelValue="add">
			<template #option="option">
				<span class="menu-case-types__option">
					<span>{{ option.title }}</span>
					<span
						v-if="openCasesLabel(option)"
						class="menu-case-types__meta"
						data-testid="menu-case-types-option-count"
						>{{ openCasesLabel(option) }}</span
					>
				</span>
			</template>
		</NcSelect>
		<p class="menu-case-types__hint">
			{{
				t(
					'dossiq',
					'You see the case types your team handles cases in. A case type you add goes to the bottom of the list.',
				)
			}}
		</p>

		<p
			class="hidden-visually"
			aria-live="polite"
			data-testid="menu-case-types-status">
			{{ status }}
		</p>
	</div>
</template>

<script>
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { NcButton, NcSelect } from '@nextcloud/vue'
import Close from 'vue-material-design-icons/Close.vue'
import DragVertical from 'vue-material-design-icons/DragVertical.vue'
import FolderOutline from 'vue-material-design-icons/FolderOutline.vue'
import {
	fetchMenuCaseTypes,
	saveMenuCaseTypes,
} from '../../services/menuCaseTypesApi.js'

export default {
	name: 'MenuCaseTypesSettings',

	components: {
		Close,
		DragVertical,
		FolderOutline,
		NcButton,
		NcSelect,
	},

	data() {
		return {
			chosen: [],
			available: [],
			loaded: false,
			loadError: '',
			status: '',
			dragIndex: null,
			openCases: {},
		}
	},

	computed: {
		/**
		 * The case types not in the menu yet.
		 *
		 * @return {Array<{id: string, title: string}>} The options of the picker.
		 * @spec openspec/changes/case-types-in-my-menu/specs/case-type-navigation/spec.md#REQ-CTN-005
		 */
		addable() {
			const taken = new Set(this.chosen.map((caseType) => caseType.id))
			return this.available.filter((caseType) => !taken.has(caseType.id))
		},
	},

	/**
	 * Read the user's own list and the case types they may add.
	 *
	 * @return {Promise<void>} When the read has finished.
	 * @spec openspec/changes/case-types-in-my-menu/specs/case-type-navigation/spec.md#REQ-CTN-005
	 */
	async mounted() {
		try {
			const { chosen, available } = await fetchMenuCaseTypes()
			this.openCases = Object.fromEntries(
				[...available, ...chosen].map((caseType) => [
					caseType.id,
					caseType.openCases ?? null,
				]),
			)
			this.chosen = chosen
			this.available = available
			this.loaded = true
		} catch {
			this.loadError = t(
				'dossiq',
				'Your case types could not be loaded. Reload the page to try again.',
			)
		}
	},

	methods: {
		t,

		/**
		 * "{n} open cases" for a case type, or '' when the count is unknown.
		 *
		 * A count the server could not read stays off the screen: an empty
		 * place is honest, a 0 nobody counted is not.
		 *
		 * @param {{id: string}} caseType The case type.
		 * @return {string} The label.
		 * @spec openspec/changes/menu-case-type-counts/specs/case-type-navigation/spec.md#requirement-req-ctn-006-the-picker-says-how-many-open-cases-each-case-type-has
		 */
		openCasesLabel(caseType) {
			const count = this.openCases[caseType?.id]
			if (typeof count !== 'number' || !Number.isFinite(count)) {
				return ''
			}

			return n('dossiq', '{count} open case', '{count} open cases', count, {
				count,
			})
		},

		/**
		 * The handle's name: which row, and where it is now.
		 *
		 * @param {{title: string}} caseType The row.
		 * @param {number} index Its place, from 0.
		 * @return {string} The accessible name.
		 * @spec openspec/changes/case-types-in-my-menu/specs/case-type-navigation/spec.md#REQ-CTN-005
		 */
		moveLabel(caseType, index) {
			return t('dossiq', '{name}, move, now {position} of {total}', {
				name: caseType.title,
				position: index + 1,
				total: this.chosen.length,
			})
		},

		/**
		 * Move a row one place up or down, from the keyboard.
		 *
		 * @param {number} index The row.
		 * @param {number} step -1 for up, 1 for down.
		 * @return {Promise<void>} When the list is saved.
		 * @spec openspec/changes/case-types-in-my-menu/specs/case-type-navigation/spec.md#REQ-CTN-005
		 */
		async move(index, step) {
			const target = index + step
			if (target < 0 || target >= this.chosen.length) {
				return
			}

			await this.reorder(index, target)
			// The row moved, so its handle did too: keep the focus on it.
			this.$nextTick(() => {
				this.$el
					.querySelectorAll('[data-testid="menu-case-types-move"]')
					[target]?.focus()
			})
		},

		/**
		 * Start dragging a row.
		 *
		 * @param {number} index The row.
		 * @param {DragEvent} event The drag.
		 * @return {void}
		 * @spec openspec/changes/case-types-in-my-menu/specs/case-type-navigation/spec.md#REQ-CTN-005
		 */
		onDragStart(index, event) {
			this.dragIndex = index
			if (event.dataTransfer) {
				event.dataTransfer.effectAllowed = 'move'
				event.dataTransfer.setData('text/plain', this.chosen[index].id)
			}
		},

		/**
		 * Drop the dragged row on another row's place.
		 *
		 * @param {number} index Where it was dropped.
		 * @return {Promise<void>} When the list is saved.
		 * @spec openspec/changes/case-types-in-my-menu/specs/case-type-navigation/spec.md#REQ-CTN-005
		 */
		async onDropRow(index) {
			const from = this.dragIndex
			this.dragIndex = null
			if (from === null || from === index) {
				return
			}

			await this.reorder(from, index)
		},

		/**
		 * Move a row from one place to another, save, and say where it went.
		 *
		 * @param {number} from The row's place.
		 * @param {number} to Its new place.
		 * @return {Promise<void>} When the list is saved.
		 * @spec openspec/changes/case-types-in-my-menu/specs/case-type-navigation/spec.md#REQ-CTN-005
		 */
		async reorder(from, to) {
			const list = [...this.chosen]
			const [row] = list.splice(from, 1)
			list.splice(to, 0, row)
			if (!(await this.save(list))) {
				return
			}
			this.status = t('dossiq', '{name} is now {position} of {total}', {
				name: row.title,
				position: to + 1,
				total: list.length,
			})
		},

		/**
		 * Add a case type at the bottom.
		 *
		 * @param {{id: string, title: string}|null} caseType The picked option.
		 * @return {Promise<void>} When the list is saved.
		 * @spec openspec/changes/case-types-in-my-menu/specs/case-type-navigation/spec.md#REQ-CTN-005
		 */
		async add(caseType) {
			if (!caseType) {
				return
			}

			if (await this.save([...this.chosen, caseType])) {
				this.status = t('dossiq', '{name} added to your menu', {
					name: caseType.title,
				})
			}
		},

		/**
		 * Remove a case type from the menu.
		 *
		 * @param {number} index The row.
		 * @return {Promise<void>} When the list is saved.
		 * @spec openspec/changes/case-types-in-my-menu/specs/case-type-navigation/spec.md#REQ-CTN-005
		 */
		async remove(index) {
			const removed = this.chosen[index]
			if (await this.save(this.chosen.filter((_, i) => i !== index))) {
				this.status = t('dossiq', '{name} removed from your menu', {
					name: removed.title,
				})
			}
		},

		/**
		 * Show the new list at once and store it; the server's answer wins.
		 *
		 * @param {Array<{id: string, title: string}>} list The new list.
		 * @return {Promise<boolean>} Whether the server kept it.
		 * @spec openspec/changes/case-types-in-my-menu/specs/case-type-navigation/spec.md#REQ-CTN-005
		 */
		async save(list) {
			const before = this.chosen
			this.chosen = list
			try {
				this.chosen = await saveMenuCaseTypes(
					list.map((caseType) => caseType.id),
				)
				return true
			} catch {
				this.chosen = before
				this.status = t('dossiq', 'Your change was not saved. Try again.')
				return false
			}
		},
	},
}
</script>

<style scoped>
.menu-case-types {
	display: flex;
	flex-direction: column;
	gap: 12px;
	max-width: 640px;
}

.menu-case-types__list {
	margin: 0;
	padding: 0;
	list-style: none;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.menu-case-types__row {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 8px 10px 8px 4px;
}

.menu-case-types__row + .menu-case-types__row {
	border-top: 1px solid var(--color-border);
}

.menu-case-types__row--dragging {
	opacity: 0.5;
}

.menu-case-types__handle {
	flex: none;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 32px;
	min-height: 36px;
	margin: 0;
	padding: 0;
	border: 0;
	border-radius: var(--border-radius);
	background: transparent;
	color: var(--color-text-maxcontrast);
	cursor: grab;
}

.menu-case-types__handle:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 2px;
}

.menu-case-types__icon {
	flex: none;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 36px;
	height: 36px;
	border-radius: var(--border-radius-large);
	background: var(--color-background-dark);
	color: var(--color-main-text);
}

.menu-case-types__text {
	display: flex;
	flex: 1 1 auto;
	flex-direction: column;
	min-width: 0;
}

.menu-case-types__label {
	font-weight: 600;
}

.menu-case-types__option {
	display: flex;
	flex-direction: column;
}

.menu-case-types__meta {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.menu-case-types__hint,
.menu-case-types__empty {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.menu-case-types__error {
	margin: 0;
	color: var(--color-error-text);
}
</style>
