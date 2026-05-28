import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const baseUrl = generateUrl('/apps/procest/api/appointments')

/**
 * @param caseId
 * @spec openspec/changes/retrofit-2026-05-25-appointment-booking/tasks.md
 */
export async function listAppointments(caseId) {
	const response = await axios.get(baseUrl, { params: { caseId } })
	return response.data
}

/**
 * @param data
 * @spec openspec/changes/retrofit-2026-05-25-appointment-booking/tasks.md
 */
export async function bookAppointment(data) {
	const response = await axios.post(baseUrl, data)
	return response.data
}

/**
 * @param appointmentId
 * @spec openspec/changes/retrofit-2026-05-25-appointment-booking/tasks.md
 */
export async function cancelAppointment(appointmentId) {
	const response = await axios.delete(`${baseUrl}/${appointmentId}`)
	return response.data
}

/**
 * @param appointmentId
 * @spec openspec/changes/retrofit-2026-05-25-appointment-booking/tasks.md
 */
export async function markNoShow(appointmentId) {
	const response = await axios.post(`${baseUrl}/${appointmentId}/no-show`)
	return response.data
}

/**
 * @param productId
 * @param locationId
 * @param date
 * @spec openspec/changes/retrofit-2026-05-25-appointment-booking/tasks.md
 */
export async function getTimeslots(productId, locationId, date) {
	const response = await axios.get(`${baseUrl}/timeslots`, { params: { productId, locationId, date } })
	return response.data
}
