/**
 * OpenRegister availability check utility.
 *
 * Provides functions to verify that OpenRegister is available
 * and that the Procest register is properly configured.
 */

/**
 * Check whether OpenRegister is available by querying the settings endpoint.
 *
 * @return {Promise<{ available: boolean, configured: boolean, error: string|null }>}
 */
export async function checkOpenRegisterStatus() {
	try {
		const response = await fetch('/apps/procest/api/settings', {
			method: 'GET',
			headers: {
				'Content-Type': 'application/json',
				requesttoken: OC.requestToken,
				'OCS-APIREQUEST': 'true',
			},
		})

		if (!response.ok) {
			return { available: false, configured: false, error: `HTTP ${response.status}` }
		}

		const data = await response.json()
		const available = data.openRegisters === true
		const config = data.config || {}
		const configured = !!(config.register && config.case_schema)

		return { available, configured, error: null }
	} catch (err) {
		return { available: false, configured: false, error: err.message }
	}
}

/**
 * Get a human-readable status message for OpenRegister availability.
 *
 * @param {{ available: boolean, configured: boolean, error: string|null }} status
 * @return {string}
 */
export function getStatusMessage(status) {
	if (status.error) {
		return t('procest', 'Could not check OpenRegister status: {error}', { error: status.error })
	}
	if (!status.available) {
		return t('procest', 'OpenRegister is not installed or enabled. Please install OpenRegister from the App Store.')
	}
	if (!status.configured) {
		return t('procest', 'OpenRegister is available but the Procest register is not configured. Go to Administration Settings > Procest to import the configuration.')
	}
	return ''
}
