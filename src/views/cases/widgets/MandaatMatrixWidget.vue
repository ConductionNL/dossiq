<template>
	<aside class="mandaat-widget">
		<header class="mandaat-widget__header">
			<h4>{{ mandaat.omschrijving || mandaat.mandaatNummer }}</h4>
			<button type="button"
				class="mandaat-widget__close"
				:aria-label="t('procest', 'Close mandate details')"
				@click="$emit('close')">
				×
			</button>
		</header>

		<dl class="mandaat-widget__props">
			<dt>{{ t('procest', 'Mandate #') }}</dt>
			<dd>{{ mandaat.mandaatNummer }}</dd>

			<dt>{{ t('procest', 'Legal basis') }}</dt>
			<dd>
				<a v-if="legalLink"
					:href="legalLink"
					target="_blank"
					rel="noopener">
					{{ mandaat.wettelijkeGrondslag }}
				</a>
				<span v-else>{{ mandaat.wettelijkeGrondslag || '-' }}</span>
			</dd>

			<dt>{{ t('procest', 'Source decision') }}</dt>
			<dd>{{ mandaat.mandateDecision || '-' }}</dd>

			<dt>{{ t('procest', 'Role holders') }}</dt>
			<dd>
				<ul v-if="roleHolders.length">
					<li v-for="h in roleHolders" :key="h.userId">
						{{ h.displayName || h.userId }}
						<span v-if="h.toewijzingType === 'waarnemer'" class="mandaat-widget__waarnemer">
							({{ t('procest', 'substitute') }})
						</span>
					</li>
				</ul>
				<span v-else>{{ t('procest', 'No active holders') }}</span>
			</dd>
		</dl>

		<p v-if="hasWaarnemer" class="mandaat-widget__note">
			{{ t('procest', 'A waarnemer (deputy) holder is active. Decisions taken by them are valid under the mandate.') }}
		</p>
	</aside>
</template>

<script>
/**
 * Mandate-row detail widget.
 *
 * Shows wettelijke grondslag, source besluit, current role holders,
 * and waarnemer status.
 *
 * @spec openspec/changes/mandaat-matrix-08-user-ui/tasks.md
 */
export default {
	name: 'MandaatMatrixWidget',

	props: {
		mandaat: {
			type: Object,
			required: true,
		},
		roleHolders: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['close'],

	computed: {
		/**
		 * Build a wetten.overheid.nl deep-link if the basis matches a known pattern.
		 *
		 * @return {string|null} Link.
		 *
		 * @spec openspec/changes/mandaat-matrix-08-user-ui/tasks.md
		 */
		legalLink() {
			const basis = this.mandaat.wettelijkeGrondslag || ''
			const wetMatch = basis.match(/^(AWB|Wabo|Wmo|Woo)\s/i)
			if (!wetMatch) {
				return null
			}
			return 'https://wetten.overheid.nl/zoeken?text=' + encodeURIComponent(basis)
		},

		/**
		 * Whether a waarnemer is among the role holders.
		 *
		 * @return {boolean} Has waarnemer.
		 *
		 * @spec openspec/changes/mandaat-matrix-08-user-ui/tasks.md
		 */
		hasWaarnemer() {
			return this.roleHolders.some((h) => h.toewijzingType === 'waarnemer')
		},
	},
}
</script>

<style scoped>
.mandaat-widget {
	margin-top: var(--default-grid-baseline, 8px);
	padding: calc(var(--default-grid-baseline, 8px) * 2);
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
}

.mandaat-widget__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.mandaat-widget__close {
	background: transparent;
	border: 0;
	font-size: 1.25rem;
	cursor: pointer;
}

.mandaat-widget__props {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 4px 12px;
	margin: 0;
}

.mandaat-widget__props dt {
	font-weight: 600;
}

.mandaat-widget__props dd {
	margin: 0;
}

.mandaat-widget__waarnemer {
	color: var(--color-warning);
	font-style: italic;
}

.mandaat-widget__note {
	margin-top: var(--default-grid-baseline, 8px);
	color: var(--color-text-maxcontrast);
}
</style>
