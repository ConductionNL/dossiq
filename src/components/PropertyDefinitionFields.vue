<template>
	<div class="pd-fields">
		<p v-if="vocabularySource === 'snapshot'" class="pd-fields__notice">
			{{
				t(
					'dossiq',
					'Reading a built-in copy of the field vocabulary. Update OpenRegister to read the list this instance itself accepts.',
				)
			}}
		</p>

		<div class="pd-fields__row">
			<NcTextField
				:modelValue="value.name || ''"
				:label="t('dossiq', 'Name')"
				:error="!!nameError"
				class="pd-fields__field"
				@update:modelValue="(v) => set('name', v)" />
		</div>

		<div class="pd-fields__row">
			<div class="pd-fields__field">
				<label class="pd-fields__label" :for="id('type')">{{
					t('dossiq', 'Type')
				}}</label>
				<select
					:id="id('type')"
					:value="storedType.type"
					:disabled="!storedType.known"
					class="pd-fields__select"
					@change="chooseType($event.target.value)">
					<optgroup
						v-for="group in typeGroups"
						:key="group.category"
						:label="group.label">
						<option
							v-for="option in group.options"
							:key="option.type"
							:value="option.type">
							{{ typeLabel(option) }}
						</option>
					</optgroup>
					<option v-if="!storedType.known" :value="storedType.type">
						{{ storedType.type }}
					</option>
				</select>
				<span v-if="!storedType.known" class="pd-fields__hint">
					{{
						t(
							'dossiq',
							'This field has the type {type}, which this instance does not offer. dossiq keeps it as it is.',
							{ type: storedType.type },
						)
					}}
				</span>
				<span v-else-if="storedType.legacy" class="pd-fields__hint">
					{{ legacyHint }}
				</span>
			</div>

			<div v-if="formats.length > 0" class="pd-fields__field">
				<label class="pd-fields__label" :for="id('format')">{{
					t('dossiq', 'Format')
				}}</label>
				<select
					:id="id('format')"
					:value="value.format || ''"
					class="pd-fields__select"
					@change="set('format', $event.target.value)">
					<option value="">
						{{ t('dossiq', 'Plain text') }}
					</option>
					<option v-for="format in formats" :key="format" :value="format">
						{{ format }}
					</option>
				</select>
			</div>
		</div>

		<div class="pd-fields__row">
			<NcTextField
				:modelValue="value.description || ''"
				:label="t('dossiq', 'Help text')"
				:helperText="
					t('dossiq', 'The sentence a handler reads under the field.')
				"
				class="pd-fields__field"
				@update:modelValue="(v) => set('description', v)" />
		</div>

		<div class="pd-fields__row">
			<NcTextField
				:modelValue="value.definition || ''"
				:label="t('dossiq', 'Definition')"
				class="pd-fields__field"
				@update:modelValue="(v) => set('definition', v)" />
		</div>

		<div class="pd-fields__row">
			<NcTextField
				v-if="takes('maxLength')"
				:modelValue="value.maxLength ? String(value.maxLength) : ''"
				:label="t('dossiq', 'Max length')"
				type="number"
				class="pd-fields__field pd-fields__field--small"
				@update:modelValue="(v) => setNumber('maxLength', v)" />
			<NcTextField
				v-if="takes('minimum')"
				:modelValue="numberValue('minimum')"
				:label="t('dossiq', 'Lowest value')"
				type="number"
				class="pd-fields__field pd-fields__field--small"
				@update:modelValue="(v) => setNumber('minimum', v)" />
			<NcTextField
				v-if="takes('maximum')"
				:modelValue="numberValue('maximum')"
				:label="t('dossiq', 'Highest value')"
				type="number"
				class="pd-fields__field pd-fields__field--small"
				@update:modelValue="(v) => setNumber('maximum', v)" />
		</div>

		<div v-if="storedType.type === 'array'" class="pd-fields__row">
			<div class="pd-fields__field">
				<label class="pd-fields__label" :for="id('items')">{{
					t('dossiq', 'List entry type')
				}}</label>
				<select
					:id="id('items')"
					:value="itemsType"
					class="pd-fields__select"
					@change="setItemsType($event.target.value)">
					<option
						v-for="option in entryTypes"
						:key="option.type"
						:value="option.type">
						{{ typeLabel(option) }}
					</option>
				</select>
			</div>
		</div>

		<div class="pd-fields__row pd-fields__row--stacked">
			<NcCheckboxRadioSwitch
				:modelValue="choiceList"
				type="switch"
				@update:modelValue="toggleChoiceList">
				{{ t('dossiq', 'Limit answers to a list') }}
			</NcCheckboxRadioSwitch>
			<div v-if="choiceList" class="pd-fields__field">
				<label class="pd-fields__label" :for="id('enum')">{{
					t('dossiq', 'One choice per line')
				}}</label>
				<textarea
					:id="id('enum')"
					:value="enumText"
					rows="3"
					class="pd-fields__textarea"
					@input="setEnumValues($event.target.value)" />
			</div>
			<div class="pd-fields__field">
				<label class="pd-fields__label" :for="id('concept-scheme')">{{
					t('dossiq', 'Or take the choices from a concept scheme')
				}}</label>
				<input
					:id="id('concept-scheme')"
					:value="value.conceptScheme || ''"
					type="text"
					class="pd-fields__input"
					:placeholder="t('dossiq', 'wijken')"
					@input="setConceptScheme($event.target.value)">
				<span class="pd-fields__hint">
					{{
						t(
							'dossiq',
							'The list lives in OpenRegister and every case type binds to the same one, so a municipal list is kept in one place.',
						)
					}}
				</span>
				<span
					v-if="schemeWarning"
					class="pd-fields__hint pd-fields__hint--warning"
					data-testid="concept-scheme-warning">
					{{ schemeWarning }}
				</span>
			</div>
		</div>

		<div class="pd-fields__row pd-fields__row--stacked">
			<NcCheckboxRadioSwitch
				:modelValue="!!value.isRequired"
				type="switch"
				@update:modelValue="(v) => set('isRequired', v)">
				{{ t('dossiq', 'Always required') }}
			</NcCheckboxRadioSwitch>
			<div class="pd-fields__field">
				<label class="pd-fields__label" :for="id('status')">{{
					t('dossiq', 'Required from status')
				}}</label>
				<select
					:id="id('status')"
					:value="value.requiredAtStatus || ''"
					class="pd-fields__select"
					@change="set('requiredAtStatus', $event.target.value || null)">
					<option value="">
						{{ t('dossiq', 'Optional') }}
					</option>
					<option v-for="st in statusTypes" :key="st.id" :value="st.id">
						{{ st.name }}
					</option>
				</select>
			</div>
		</div>

		<details class="pd-fields__more">
			<summary>{{ t('dossiq', 'More options') }}</summary>
			<div class="pd-fields__row">
				<NcTextField
					:modelValue="value.defaultValue || ''"
					:label="t('dossiq', 'Default value')"
					class="pd-fields__field"
					@update:modelValue="(v) => set('defaultValue', v)" />
			</div>
			<div v-if="takes('pattern')" class="pd-fields__row">
				<NcTextField
					:modelValue="value.pattern || ''"
					:label="t('dossiq', 'Pattern')"
					class="pd-fields__field"
					@update:modelValue="(v) => set('pattern', v)" />
			</div>
			<div class="pd-fields__row">
				<NcTextField
					:modelValue="value.ref || ''"
					:label="t('dossiq', 'Reference to another object')"
					class="pd-fields__field"
					@update:modelValue="(v) => set('ref', v)" />
			</div>
			<div class="pd-fields__row">
				<NcTextField
					:modelValue="value.propertySource || ''"
					:label="t('dossiq', 'Values from a register')"
					class="pd-fields__field"
					@update:modelValue="(v) => set('propertySource', v)" />
			</div>
			<div class="pd-fields__row">
				<div class="pd-fields__field">
					<label class="pd-fields__label" :for="id('calculation')">{{
						t('dossiq', 'Calculation')
					}}</label>
					<textarea
						:id="id('calculation')"
						:value="calculationText"
						rows="3"
						class="pd-fields__textarea"
						@input="setCalculation($event.target.value)" />
					<span v-if="calculationError" class="pd-fields__error">{{
						calculationError
					}}</span>
				</div>
			</div>
		</details>
	</div>
</template>

<script>
import { NcCheckboxRadioSwitch, NcTextField } from '@nextcloud/vue'
import { hasCompetingSources } from '../services/conceptScheme.js'
import {
	constraintsForType,
	formatsForType,
	resolveStoredType,
	typeOptionsFor,
} from '../services/propertyVocabulary.js'

/**
 * The vocabulary's own category names, as a person reads them.
 *
 * Written as literal `t()` calls rather than a lookup table, because the l10n
 * extractor reads literals: a table keyed by category would ship every group
 * heading untranslated with nothing failing.
 *
 * @param {string} category The vocabulary category.
 * @return {string} The heading for that group of types.
 */
function categoryLabel(category) {
	switch (category) {
		case 'text':
			return t('dossiq', 'Text')
		case 'numeric':
			return t('dossiq', 'Numbers')
		case 'composite':
			return t('dossiq', 'Lists and nested data')
		case 'file':
			return t('dossiq', 'Files')
		case 'spatial':
			return t('dossiq', 'Map')
		case 'presentation':
			return t('dossiq', 'Colour')
		case 'temporal':
			return t('dossiq', 'Repeating')
		case 'nextcloud':
			return t('dossiq', 'Nextcloud')
		case 'legacy':
			return t('dossiq', 'Old type, kept for this field')
		default:
			return category
	}
}

export default {
	name: 'PropertyDefinitionFields',
	components: {
		NcCheckboxRadioSwitch,
		NcTextField,
	},

	props: {
		/** The definition being authored. */
		value: { type: Object, required: true },
		/** The vocabulary OpenRegister published, or the snapshot. */
		vocabulary: { type: Object, required: true },
		/** Where that vocabulary came from: `instance` or `snapshot`. */
		vocabularySource: { type: String, default: 'instance' },
		/** The case type's statuses, for the obligation picker. */
		statusTypes: { type: Array, default: () => [] },
		/** Prefix that keeps this form's input ids unique on the page. */
		idPrefix: { type: String, default: 'pd' },
		/** A refusal to show against the name field. */
		nameError: { type: String, default: '' },
	},

	emits: ['update'],

	data() {
		return {
			calculationError: '',
		}
	},

	computed: {
		/** @spec openspec/changes/casetype-field-vocabulary/specs/property-definition-management/spec.md */
		storedType() {
			return resolveStoredType(this.vocabulary, this.value.propertyType)
		},

		/** @spec openspec/changes/casetype-field-vocabulary/specs/property-definition-management/spec.md */
		typeGroups() {
			const groups = []
			typeOptionsFor(this.vocabulary, this.value.propertyType).forEach(
				(option) => {
					let group = groups.find(
						(entry) => entry.category === option.category,
					)
					if (!group) {
						group = {
							category: option.category,
							label: categoryLabel(option.category),
							options: [],
						}
						groups.push(group)
					}
					group.options.push(option)
				},
			)
			return groups
		},

		/** @spec openspec/changes/casetype-field-vocabulary/specs/property-definition-management/spec.md */
		entryTypes() {
			return typeOptionsFor(this.vocabulary, '').filter(
				(option) => option.type !== 'array',
			)
		},

		/** @spec openspec/changes/casetype-field-vocabulary/specs/property-definition-management/spec.md */
		formats() {
			return formatsForType(this.vocabulary, this.storedType.type)
		},

		/** @spec openspec/changes/casetype-field-vocabulary/specs/property-definition-management/spec.md */
		legacyHint() {
			const replacement = this.storedType.replacement || {}
			if (replacement.format) {
				return t(
					'dossiq',
					'Old type. Use {type} with format {format} instead.',
					{ type: replacement.type, format: replacement.format },
				)
			}
			return t('dossiq', 'Old type. Use {type} instead.', {
				type: replacement.type || 'string',
			})
		},

		/** @spec openspec/changes/casetype-field-vocabulary/specs/property-definition-management/spec.md */
		itemsType() {
			return this.value.items?.type || 'string'
		},

		/** @spec openspec/changes/casetype-field-vocabulary/specs/property-definition-management/spec.md */
		enumText() {
			return (this.value.enumValues || []).join('\n')
		},

		/**
		 * What to say when a field names a scheme and a list of its own.
		 *
		 * The scheme rules, so the typed list is what a handler will not see.
		 * Saying so is the whole point: resolving it in silence is how an
		 * administrator ships a field whose options are not the ones on screen.
		 *
		 * @return {string} The warning, or an empty string when there is none.
		 * @spec openspec/changes/code-lists-from-concepts/specs/property-definition-management/spec.md
		 */
		schemeWarning() {
			if (!hasCompetingSources(this.value)) {
				return ''
			}
			return t(
				'dossiq',
				'This field names a concept scheme and carries its own list. The scheme wins and the typed choices are ignored.',
			)
		},

		/** @spec openspec/changes/casetype-field-vocabulary/specs/property-definition-management/spec.md */
		calculationText() {
			if (!this.value.calculation) {
				return ''
			}
			return JSON.stringify(this.value.calculation, null, 1)
		},

		/**
		 * Whether this field limits its answers to a list.
		 *
		 * A stored `enum` type or stored values turn it on. Once the switch is
		 * touched its own answer wins, and it is that answer a save is refused
		 * against: a list switched on and left empty is the trap this fixes.
		 * The key is stripped from the payload, it is the form's state and not
		 * the definition's.
		 *
		 * @return {boolean} True when the choice list is on.
		 * @spec openspec/changes/casetype-field-vocabulary/specs/property-definition-management/spec.md
		 */
		choiceList() {
			if (typeof this.value.choiceList === 'boolean') {
				return this.value.choiceList
			}
			return (
				this.value.propertyType === 'enum'
				|| (this.value.enumValues || []).length > 0
			)
		},
	},

	methods: {
		/**
		 * A DOM id that stays unique when two of these forms are open.
		 *
		 * @param {string} name The field name.
		 * @return {string} The id.
		 * @spec openspec/changes/casetype-field-vocabulary/specs/property-definition-management/spec.md
		 */
		id(name) {
			return `${this.idPrefix}-${name}`
		},

		/**
		 * @param {object} option One type option.
		 * @return {string} The option label.
		 * @spec openspec/changes/casetype-field-vocabulary/specs/property-definition-management/spec.md
		 */
		typeLabel(option) {
			return option.description
				? `${option.type} (${option.description})`
				: option.type
		},

		/**
		 * Whether the chosen type takes a constraint key.
		 *
		 * @param {string} key The constraint key.
		 * @return {boolean} True when the vocabulary allows it here.
		 * @spec openspec/changes/casetype-field-vocabulary/specs/property-definition-management/spec.md
		 */
		takes(key) {
			return constraintsForType(
				this.vocabulary,
				this.storedType.type,
			).includes(key)
		},

		/**
		 * @param {string} key The field name.
		 * @return {string} The stored number as text.
		 * @spec openspec/changes/casetype-field-vocabulary/specs/property-definition-management/spec.md
		 */
		numberValue(key) {
			const stored = this.value[key]
			return stored === null || stored === undefined ? '' : String(stored)
		},

		/**
		 * @param {string} key The field name.
		 * @param {unknown} entry The new value.
		 * @spec openspec/changes/casetype-field-vocabulary/specs/property-definition-management/spec.md
		 */
		set(key, entry) {
			this.$emit('update', { [key]: entry })
		},

		/**
		 * @param {string} key The field name.
		 * @param {string} entry The typed number.
		 * @spec openspec/changes/casetype-field-vocabulary/specs/property-definition-management/spec.md
		 */
		setNumber(key, entry) {
			const parsed = Number.parseFloat(entry)
			this.set(key, Number.isNaN(parsed) ? null : parsed)
		},

		/**
		 * Choose a type, and drop the keys the new one does not take.
		 *
		 * A format left behind on a number is a key the engine refuses, and a
		 * maximum left behind on a string is one nothing reads.
		 *
		 * @param {string} type The chosen type.
		 * @spec openspec/changes/casetype-field-vocabulary/specs/property-definition-management/spec.md
		 */
		chooseType(type) {
			const update = { propertyType: type }
			const allowed = constraintsForType(this.vocabulary, type)
			;['format', 'pattern', 'maxLength', 'minimum', 'maximum'].forEach(
				(key) => {
					if (!allowed.includes(key)) {
						update[key] = null
					}
				},
			)
			if (type !== 'array') {
				update.items = null
			}
			this.$emit('update', update)
		},

		/**
		 * @param {string} type The type of one list entry.
		 * @spec openspec/changes/casetype-field-vocabulary/specs/property-definition-management/spec.md
		 */
		setItemsType(type) {
			this.set('items', { type })
		},

		/**
		 * @param {boolean} on Whether the choice list is on.
		 * @spec openspec/changes/casetype-field-vocabulary/specs/property-definition-management/spec.md
		 */
		toggleChoiceList(on) {
			this.$emit('update', {
				choiceList: on,
				enumValues: on === true ? this.value.enumValues || [] : [],
			})
		},

		/**
		 * @param {string} text The scheme reference, or an empty string to unbind.
		 * @spec openspec/changes/code-lists-from-concepts/specs/property-definition-management/spec.md
		 */
		setConceptScheme(text) {
			this.set('conceptScheme', String(text).trim())
		},

		/**
		 * @param {string} text The typed choices, one per line.
		 * @spec openspec/changes/casetype-field-vocabulary/specs/property-definition-management/spec.md
		 */
		setEnumValues(text) {
			this.set(
				'enumValues',
				String(text)
					.split('\n')
					.map((entry) => entry.trim())
					.filter(Boolean),
			)
		},

		/**
		 * Read the calculation as JSON, and say so when it is not.
		 *
		 * @param {string} text The typed expression.
		 * @spec openspec/changes/casetype-field-vocabulary/specs/property-definition-management/spec.md
		 */
		setCalculation(text) {
			this.calculationError = ''
			if (!String(text).trim()) {
				this.set('calculation', null)
				return
			}
			try {
				this.set('calculation', JSON.parse(text))
			} catch {
				this.calculationError = t(
					'dossiq',
					'A calculation is written as JSON. OpenRegister evaluates it.',
				)
			}
		},
	},
}
</script>

<style scoped>
.pd-fields__row {
	display: flex;
	gap: 12px;
	margin-bottom: 8px;
	align-items: flex-end;
}

.pd-fields__row--stacked {
	flex-direction: column;
	align-items: stretch;
}

.pd-fields__field {
	flex: 1;
}

.pd-fields__field--small {
	max-width: 120px;
}

.pd-fields__label {
	display: block;
	font-size: 12px;
	font-weight: 500;
	margin-bottom: 4px;
	color: var(--color-text-maxcontrast);
}

.pd-fields__select,
.pd-fields__input,
.pd-fields__textarea {
	width: 100%;
	padding: 8px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.pd-fields__hint {
	display: block;
	font-size: 12px;
	margin-top: 4px;
	color: var(--color-text-maxcontrast);
}

.pd-fields__hint--warning {
	color: var(--color-warning-text, var(--color-error));
}

.pd-fields__error {
	display: block;
	font-size: 12px;
	margin-top: 4px;
	color: var(--color-error);
}

.pd-fields__notice {
	padding: 8px 12px;
	margin-bottom: 12px;
	border-radius: var(--border-radius);
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
	font-size: 12px;
}

.pd-fields__more {
	margin-top: 8px;
}

.pd-fields__more summary {
	cursor: pointer;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	margin-bottom: 8px;
}
</style>
