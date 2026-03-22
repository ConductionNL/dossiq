/**
 * AI API service for Procest AI-assisted processing.
 *
 * Provides methods for document classification, data extraction,
 * knowledge base Q&A, summarization, and decision support.
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const baseUrl = generateUrl('/apps/procest/api/ai')

/**
 * Classify a document using AI.
 *
 * @param {string} caseId The case UUID
 * @param {string} documentId The document UUID
 * @return {Promise<object>} Classification suggestion with confidence
 */
export async function classifyDocument(caseId, documentId) {
	const response = await axios.post(`${baseUrl}/classify`, { caseId, documentId })
	return response.data
}

/**
 * Extract structured data from a document.
 *
 * @param {string} caseId The case UUID
 * @param {string|null} documentId Optional document UUID
 * @return {Promise<object>} Extracted fields with confidence scores
 */
export async function extractData(caseId, documentId = null) {
	const response = await axios.post(`${baseUrl}/extract`, { caseId, documentId })
	return response.data
}

/**
 * Ask a knowledge base question in case context.
 *
 * @param {string} caseId The case UUID
 * @param {string} question The question to ask
 * @return {Promise<object>} Answer with source citations
 */
export async function askQuestion(caseId, question) {
	const response = await axios.post(`${baseUrl}/ask`, { caseId, question })
	return response.data
}

/**
 * Generate a summary of a case, document, or timeline.
 *
 * @param {string} caseId The case UUID
 * @param {string} type Summary type: case, document, or timeline
 * @param {string|null} documentId Optional document UUID
 * @return {Promise<object>} Generated summary
 */
export async function summarize(caseId, type = 'case', documentId = null) {
	const response = await axios.post(`${baseUrl}/summarize`, { caseId, type, documentId })
	return response.data
}

/**
 * Get routing suggestion for a case.
 *
 * @param {string} caseId The case UUID
 * @return {Promise<object>} Routing suggestion
 */
export async function suggestRouting(caseId) {
	const response = await axios.post(`${baseUrl}/suggest-routing`, { caseId })
	return response.data
}

/**
 * Get next-step suggestion for a case.
 *
 * @param {string} caseId The case UUID
 * @return {Promise<object>} Next-step suggestion
 */
export async function suggestNext(caseId) {
	const response = await axios.post(`${baseUrl}/suggest-next`, { caseId })
	return response.data
}

/**
 * Get AI audit trail entries.
 *
 * @param {object} filters Query filters (caseId, type, limit, offset)
 * @return {Promise<object>} Audit log entries
 */
export async function getAuditLog(filters = {}) {
	const response = await axios.get(`${baseUrl}/audit`, { params: filters })
	return response.data
}

/**
 * Get AI settings.
 *
 * @return {Promise<object>} AI configuration
 */
export async function getAiSettings() {
	const response = await axios.get(`${baseUrl}/settings`)
	return response.data
}

/**
 * Update AI settings.
 *
 * @param {object} settings Settings to update
 * @return {Promise<object>} Updated settings
 */
export async function updateAiSettings(settings) {
	const response = await axios.post(`${baseUrl}/settings`, settings)
	return response.data
}

/**
 * Test AI model connectivity.
 *
 * @return {Promise<object>} Health check result
 */
export async function testAiHealth() {
	const response = await axios.post(`${baseUrl}/health`)
	return response.data
}
