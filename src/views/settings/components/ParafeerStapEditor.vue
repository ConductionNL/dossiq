<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl> -->
<template>
	<div class="parafeer-stap-editor">
		<div v-if="!steps.length" class="parafeer-stap-editor__empty">
			{{ t('procest', 'No steps yet. Add a step to get started.') }}
		</div>
		<div
			v-for="(step, idx) in steps"
			:key="idx"
			class="parafeer-stap-editor__row">
			<span class="parafeer-stap-editor__order">{{ idx + 1 }}</span>
			<NcSelect
				:modelValue="step.type"
				:options="stepTypeOptions"
				:inputLabel="t('procest', 'Type')"
				:placeholder="t('procest', 'Type')"
				class="parafeer-stap-editor__type"
				@update:modelValue="(v) => updateStep(idx, 'type', v)" />
			<NcSelect
				:modelValue="step.actorType"
				:options="actorTypeOptions"
				:inputLabel="t('procest', 'Actor type')"
				:placeholder="t('procest', 'Actor type')"
				class="parafeer-stap-editor__actor-type"
				@update:modelValue="(v) => updateStep(idx, 'actorType', v)" />
			<NcTextField
				:modelValue="step.actor"
				:placeholder="t('procest', 'Actor (UID, groep of rol)')"
				:label="t('procest', 'Actor')"
				:labelVisible="false"
				class="parafeer-stap-editor__actor"
				@update:modelValue="(v) => updateStep(idx, 'actor', v)" />
			<NcCheckboxRadioSwitch
				:modelValue="step.mandatory"
				class="parafeer-stap-editor__mandatory"
				@update:modelValue="(v) => updateStep(idx, 'mandatory', v)">
				{{ t('procest', 'Required') }}
			</NcCheckboxRadioSwitch>
			<div class="parafeer-stap-editor__actions">
				<NcButton
					:disabled="idx === 0"
					:title="t('procest', 'Omhoog')"
					variant="tertiary-no-background"
					@click="moveStep(idx, -1)">
					<template #icon>
						<ChevronUp :size="20" />
					</template>
				</NcButton>
				<NcButton
					:disabled="idx === steps.length - 1"
					:title="t('procest', 'Omlaag')"
					variant="tertiary-no-background"
					@click="moveStep(idx, 1)">
					<template #icon>
						<ChevronDown :size="20" />
					</template>
				</NcButton>
				<NcButton
					:title="t('procest', 'Stap verwijderen')"
					variant="tertiary-no-background"
					@click="removeStep(idx)">
					<template #icon>
						<Delete :size="20" />
					</template>
				</NcButton>
			</div>
		</div>
		<NcButton class="parafeer-stap-editor__add" @click="addStep">
			<template #icon>
				<Plus :size="20" />
			</template>
			{{ t('procest', 'Stap toevoegen') }}
		</NcButton>
	</div>
</template>

<script>
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import ChevronUp from 'vue-material-design-icons/ChevronUp.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Plus from 'vue-material-design-icons/Plus.vue'

export default {
	name: 'ParafeerStapEditor',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcSelect,
		NcTextField,
		ChevronUp,
		ChevronDown,
		Delete,
		Plus,
	},

	props: {
		steps: {
			type: Array,
			required: true,
		},
	},

	data() {
		return {
			stepTypeOptions: ['advies', 'parafering', 'accordering'],
			actorTypeOptions: ['user', 'group', 'role'],
		}
	},

	methods: {
		/**
		 * @param steps
		 * @spec openspec/specs/parafering-actions/spec.md
		 */
		emitUpdate(steps) {
			const renumbered = steps.map((s, i) => ({ ...s, order: i + 1 }))
			this.$emit('update:steps', renumbered)
		},

		/**
		 * @param idx
		 * @param key
		 * @param value
		 * @spec openspec/specs/parafering-actions/spec.md
		 */
		updateStep(idx, key, value) {
			const next = this.steps.map((s, i) =>
				i === idx ? { ...s, [key]: value } : s,
			)
			this.emitUpdate(next)
		},

		/** @spec openspec/specs/parafering-actions/spec.md */
		addStep() {
			this.emitUpdate([
				...this.steps,
				{
					order: this.steps.length + 1,
					type: 'parafering',
					actor: '',
					actorType: 'user',
					mandatory: true,
				},
			])
		},

		/**
		 * @param idx
		 * @spec openspec/specs/parafering-actions/spec.md
		 */
		removeStep(idx) {
			const next = this.steps.filter((_, i) => i !== idx)
			this.emitUpdate(next)
		},

		/**
		 * @param idx
		 * @param delta
		 * @spec openspec/specs/parafering-actions/spec.md
		 */
		moveStep(idx, delta) {
			const target = idx + delta
			if (target < 0 || target >= this.steps.length) return
			const next = [...this.steps]
			const tmp = next[idx]
			next[idx] = next[target]
			next[target] = tmp
			this.emitUpdate(next)
		},
	},
}
</script>

<style scoped>
.parafeer-stap-editor__row {
	display: grid;
	grid-template-columns: 32px 1fr 1fr 2fr auto auto;
	gap: 8px;
	align-items: center;
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border);
}

.parafeer-stap-editor__order {
	font-weight: 600;
	text-align: center;
	color: var(--color-text-maxcontrast);
}

.parafeer-stap-editor__actions {
	display: flex;
	gap: 2px;
	justify-content: flex-end;
}

.parafeer-stap-editor__empty {
	padding: 12px;
	color: var(--color-text-maxcontrast);
	text-align: center;
	font-style: italic;
}

.parafeer-stap-editor__add {
	margin-top: 8px;
}
</style>
