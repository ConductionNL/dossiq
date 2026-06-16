<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  -
  - Supplier profile form — leverancier-zaakportaal chain member 12.
  -
  - Three sections: address (immediate update), contact person
  - (immediate update), IBAN change (4-eyes, creates a Procest case).
  - Each section has its own form + success banner so the supplier can
  - tell which write succeeded.
  -
  - The accreditation upload sub-flow is delegated to the IBANVerification
  - flow modal (chain member 12 sub-task) — for round 3 the form surfaces
  - the path and binds to the existing requestIbanChange endpoint.
  -
  - @spec openspec/changes/leverancier-zaakportaal-12-master-data-mutations/tasks.md
  -->
<template>
	<div class="lz-profile" data-testid="leverancier-profile-form">
		<header class="lz-toolbar">
			<h1>{{ t('procest', 'Mijn gegevens') }}</h1>
		</header>

		<section class="lz-profile-section">
			<h2>{{ t('procest', 'Adres') }}</h2>
			<p class="lz-section-intro">
				{{ t('procest', 'Adreswijzigingen worden direct verwerkt.') }}
			</p>
			<form class="lz-form" @submit.prevent="onSubmitAddress">
				<div class="lz-form-group">
					<label for="lz-street">{{ t('procest', 'Straat + nummer') }}</label>
					<input id="lz-street"
						v-model="address.street"
						type="text"
						data-testid="leverancier-profile-street"
						class="lz-input">
				</div>
				<div class="lz-form-row">
					<div class="lz-form-group">
						<label for="lz-postal">{{ t('procest', 'Postcode') }}</label>
						<input id="lz-postal"
							v-model="address.postalCode"
							type="text"
							data-testid="leverancier-profile-postal"
							class="lz-input">
					</div>
					<div class="lz-form-group lz-form-group--grow">
						<label for="lz-city">{{ t('procest', 'Plaats') }}</label>
						<input id="lz-city"
							v-model="address.city"
							type="text"
							data-testid="leverancier-profile-city"
							class="lz-input">
					</div>
				</div>
				<button type="submit"
					class="lz-button lz-button--primary"
					data-testid="leverancier-profile-address-submit"
					:disabled="busy.address">
					{{ busy.address ? t('procest', 'Bezig…') : t('procest', 'Adres bijwerken') }}
				</button>
				<p v-if="status.address"
					class="lz-status lz-status--success"
					role="status"
					data-testid="leverancier-profile-address-status">
					{{ status.address }}
				</p>
			</form>
		</section>

		<section class="lz-profile-section">
			<h2>{{ t('procest', 'Contactpersoon') }}</h2>
			<p class="lz-section-intro">
				{{ t('procest', 'Wijzigingen aan de contactpersoon worden direct verwerkt.') }}
			</p>
			<form class="lz-form" @submit.prevent="onSubmitContact">
				<div class="lz-form-group">
					<label for="lz-contact">{{ t('procest', 'Naam contactpersoon') }}</label>
					<input id="lz-contact"
						v-model="contactPerson"
						type="text"
						required
						data-testid="leverancier-profile-contact"
						class="lz-input">
				</div>
				<button type="submit"
					class="lz-button lz-button--primary"
					data-testid="leverancier-profile-contact-submit"
					:disabled="busy.contact">
					{{ busy.contact ? t('procest', 'Bezig…') : t('procest', 'Contactpersoon bijwerken') }}
				</button>
				<p v-if="status.contact"
					class="lz-status lz-status--success"
					role="status"
					data-testid="leverancier-profile-contact-status">
					{{ status.contact }}
				</p>
			</form>
		</section>

		<section class="lz-profile-section">
			<h2>{{ t('procest', 'IBAN-wijziging') }}</h2>
			<p class="lz-section-intro">
				{{ t('procest', 'IBAN-wijzigingen vereisen verificatie door de gemeente. Een Procest-zaak wordt aangemaakt.') }}
			</p>
			<form class="lz-form" @submit.prevent="onSubmitIban">
				<div class="lz-form-group">
					<label for="lz-iban">{{ t('procest', 'Nieuwe IBAN') }}</label>
					<input id="lz-iban"
						v-model="newIban"
						type="text"
						required
						data-testid="leverancier-profile-iban"
						placeholder="NL00 BANK 0000 0000 00"
						class="lz-input">
					<p v-if="errors.iban"
						class="lz-error"
						role="alert"
						data-testid="leverancier-profile-iban-error">
						{{ errors.iban }}
					</p>
				</div>
				<button type="submit"
					class="lz-button lz-button--primary"
					data-testid="leverancier-profile-iban-submit"
					:disabled="busy.iban">
					{{ busy.iban ? t('procest', 'Bezig…') : t('procest', 'IBAN-wijziging indienen') }}
				</button>
				<p v-if="status.iban"
					class="lz-status lz-status--success"
					role="status"
					data-testid="leverancier-profile-iban-status">
					{{ status.iban }}
				</p>
			</form>
		</section>
	</div>
</template>

<script>
import {
	updateSupplierAddress,
	updateSupplierContact,
	requestIbanChange,
} from '../../services/leverancierApi.js'

export default {
	name: 'ProfileForm',
	data() {
		return {
			address: { street: '', postalCode: '', city: '', country: 'NL' },
			contactPerson: '',
			newIban: '',
			busy: { address: false, contact: false, iban: false },
			status: { address: '', contact: '', iban: '' },
			errors: { iban: '' },
		}
	},
	computed: {
		supplierRef() {
			return (this.$route.query && this.$route.query.supplierRef) || ''
		},
	},
	methods: {
		async onSubmitAddress() {
			if (!this.supplierRef) { return }
			this.busy.address = true
			this.status.address = ''
			try {
				const r = await updateSupplierAddress(this.supplierRef, this.address)
				this.status.address = (r && r.message) || this.t('procest', 'Adres bijgewerkt')
			} catch (e) {
				this.status.address = ''
			} finally {
				this.busy.address = false
			}
		},
		async onSubmitContact() {
			if (!this.supplierRef) { return }
			this.busy.contact = true
			this.status.contact = ''
			try {
				const r = await updateSupplierContact(this.supplierRef, this.contactPerson)
				this.status.contact = (r && r.message) || this.t('procest', 'Contactpersoon bijgewerkt')
			} catch (e) {
				this.status.contact = ''
			} finally {
				this.busy.contact = false
			}
		},
		async onSubmitIban() {
			if (!this.supplierRef) { return }
			this.busy.iban = true
			this.status.iban = ''
			this.errors.iban = ''
			try {
				const r = await requestIbanChange(this.supplierRef, this.newIban.replace(/\s+/g, ''))
				if (r && r.ok) {
					this.status.iban = r.message || this.t('procest', 'Wijziging ingediend')
				} else {
					this.errors.iban = this.t('procest', 'IBAN-wijziging geweigerd.')
				}
			} catch (e) {
				const respMsg = e && e.response && e.response.data && e.response.data.error
				this.errors.iban = respMsg
					? String(respMsg)
					: this.t('procest', 'IBAN-wijziging kon niet worden ingediend.')
			} finally {
				this.busy.iban = false
			}
		},
	},
}
</script>

<style scoped>
.lz-profile { padding: 20px; max-width: 720px; margin: 0 auto; }
.lz-toolbar { margin-bottom: 16px; }
.lz-profile-section { margin-bottom: 32px; padding: 20px; background: var(--color-main-background, #fff); border: 1px solid var(--color-border, #ddd); border-radius: 8px; }
.lz-profile-section h2 { margin-top: 0; font-size: 16px; }
.lz-section-intro { margin: 0 0 12px 0; color: var(--color-text-maxcontrast, #555); font-size: 13px; }
.lz-form { display: flex; flex-direction: column; gap: 12px; }
.lz-form-row { display: flex; gap: 12px; }
.lz-form-group { display: flex; flex-direction: column; gap: 4px; }
.lz-form-group--grow { flex: 1; }
.lz-form-group label { font-weight: 600; font-size: 13px; }
.lz-input { padding: 8px 10px; border: 1px solid var(--color-border-dark, #aaa); border-radius: 4px; font-family: inherit; }
.lz-button { padding: 8px 16px; border: 1px solid var(--color-border-dark, #aaa); border-radius: 4px; background: var(--color-main-background, #fff); cursor: pointer; align-self: flex-start; }
.lz-button--primary { background: var(--color-primary-element, #0082c9); color: #fff; border-color: var(--color-primary-element, #0082c9); }
.lz-button--primary:disabled { opacity: 0.6; cursor: not-allowed; }
.lz-status { margin: 4px 0 0 0; font-size: 13px; }
.lz-status--success { color: var(--color-success, #46ba61); }
.lz-error { margin: 4px 0 0 0; color: var(--color-error, #c00); font-size: 12px; }
</style>
