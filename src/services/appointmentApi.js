import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const baseUrl = generateUrl('/apps/procest/api/appointments')

export async function listAppointments(caseId) {
	const response = await axios.get(baseUrl, { params: { caseId } })
	return response.data
}

export async function bookAppointment(data) {
	const response = await axios.post(baseUrl, data)
	return response.data
}

export async function cancelAppointment(appointmentId) {
	const response = await axios.delete(`${baseUrl}/${appointmentId}`)
	return response.data
}

export async function markNoShow(appointmentId) {
	const response = await axios.post(`${baseUrl}/${appointmentId}/no-show`)
	return response.data
}

export async function getTimeslots(productId, locationId, date) {
	const response = await axios.get(`${baseUrl}/timeslots`, { params: { productId, locationId, date } })
	return response.data
}
