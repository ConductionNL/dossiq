/**
 * GIS Proxy API client.
 *
 * Frontend service for communicating with the backend GIS proxy
 * that handles CORS-restricted WMS/WFS requests and GetCapabilities parsing.
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Forward a WMS/WFS request through the backend proxy.
 *
 * @param {object} params Proxy request parameters
 * @param {string} params.url        Target service URL
 * @param {object} params.query      Query parameters to forward
 * @param {string} params.type       Request type: 'wms', 'wfs', 'capabilities'
 * @param {string} params.responseType Expected response type: 'image', 'json', 'xml'
 * @return {Promise<*>} The proxied response data
 */
/**
 * @param root0
 * @param root0.url
 * @param root0.query
 * @param root0.type
 * @param root0.responseType
 * @spec openspec/changes/retrofit-2026-05-24-wms-wfs-layers/tasks.md
 */
export async function proxyRequest({ url, query = {}, type = 'wms', responseType = 'json' }) {
	const apiUrl = generateUrl('/apps/procest/api/gis/proxy')
	const axiosConfig = {}

	if (responseType === 'image') {
		axiosConfig.responseType = 'blob'
	}

	const response = await axios.post(apiUrl, {
		url,
		query,
		type,
	}, axiosConfig)

	return response.data
}

/**
 * Fetch and parse GetCapabilities from a WMS/WFS service.
 *
 * @param {string} url    The service base URL
 * @param {string} type   Service type: 'wms' or 'wfs'
 * @return {Promise<object>} Parsed capabilities with available layers
 */
/**
 * @param url
 * @param type
 * @spec openspec/changes/retrofit-2026-05-24-wms-wfs-layers/tasks.md
 */
export async function getCapabilities(url, type = 'wms') {
	const apiUrl = generateUrl('/apps/procest/api/gis/capabilities')
	const response = await axios.get(apiUrl, {
		params: { url, type },
	})
	return response.data
}
