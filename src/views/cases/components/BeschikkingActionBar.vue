<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<template>
	<div class="beschikking-actionbar">
		<NcButton
			v-if="status === 'ontwerp'"
			variant="primary"
			:disabled="busy"
			@click="onAkkoord">
			{{ t('procest', 'Akkoord aanvragen') }}
		</NcButton>
		<NcButton
			v-if="status === 'akkoord-mandaat'"
			variant="primary"
			:disabled="busy"
			@click="onOnderteken">
			{{ t('procest', 'Ondertekenen') }}
		</NcButton>
		<NcButton
			v-if="status === 'ondertekend'"
			variant="primary"
			:disabled="busy"
			@click="onVerzend">
			{{ t('procest', 'Verzenden') }}
		</NcButton>
		<NcButton v-if="canExport" :disabled="busy" @click="onExport">
			{{ t('procest', 'Audit-pakket exporteren') }}
		</NcButton>

		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
	</div>
</template>

<script>
import { NcButton, NcNoteCard } from '@nextcloud/vue'
import {
	akkoord,
	exportAuditPacket,
	onderteken,
	verzend,
} from '../../../services/beschikkingApi.js'

export default {
	name: 'BeschikkingActionBar',
	components: {
		NcButton,
		NcNoteCard,
	},

	props: {
		beschikkingId: {
			type: String,
			required: true,
		},

		status: {
			type: String,
			required: true,
		},

		tspProvider: {
			type: String,
			default: 'kpn-gekwalificeerde-handtekening',
		},
	},

	emits: ['updated'],
	data() {
		return {
			busy: false,
			error: '',
		}
	},

	computed: {
		canExport() {
			return ['verzonden', 'ontvangen-bevestiging', 'gearchiveerd'].includes(
				this.status,
			)
		},
	},

	methods: {
		async onAkkoord() {
			await this.run(() => akkoord(this.beschikkingId))
		},

		async onOnderteken() {
			await this.run(() => onderteken(this.beschikkingId, this.tspProvider))
		},

		async onVerzend() {
			await this.run(() => verzend(this.beschikkingId))
		},

		async run(fn) {
			this.busy = true
			this.error = ''
			try {
				const updated = await fn()
				this.$emit('updated', updated)
			} catch (e) {
				this.error = t('procest', 'The action could not be performed.')
			} finally {
				this.busy = false
			}
		},

		async onExport() {
			this.busy = true
			this.error = ''
			try {
				const blob = await exportAuditPacket(this.beschikkingId)
				const url = window.URL.createObjectURL(blob)
				const link = document.createElement('a')
				link.href = url
				link.download = `audit-pakket-${this.beschikkingId}.zip`
				link.click()
				window.URL.revokeObjectURL(url)
			} catch (e) {
				this.error = t('procest', 'The audit package could not be exported.')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.beschikking-actionbar {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	align-items: center;
}
</style>
