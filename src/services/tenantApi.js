/**
 * Tenant API service for Procest multi-tenant SaaS.
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const baseUrl = generateUrl('/apps/procest/api/tenants')

export async function listTenants() {
	const response = await axios.get(baseUrl)
	return response.data
}

export async function createTenant(data) {
	const response = await axios.post(baseUrl, data)
	return response.data
}

export async function getTenant(tenantId) {
	const response = await axios.get(`${baseUrl}/${tenantId}`)
	return response.data
}

export async function updateTenant(tenantId, data) {
	const response = await axios.put(`${baseUrl}/${tenantId}`, data)
	return response.data
}

export async function provisionTenant(tenantId) {
	const response = await axios.post(`${baseUrl}/${tenantId}/provision`)
	return response.data
}

export async function getTenantUsage(tenantId) {
	const response = await axios.get(`${baseUrl}/${tenantId}/usage`)
	return response.data
}

export async function getCurrentTenant() {
	const response = await axios.get(`${baseUrl}/current`)
	return response.data
}
