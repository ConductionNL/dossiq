import fs from 'fs'
import path from 'path'
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * documents-on-the-case 3.3: the case header runs the shipped "Generate a
 * document" flow's generate node directly, with the flow's per-instance uuid
 * resolved from initial state, and keeps the composing dialog beside it.
 *
 * @spec openspec/changes/documents-on-the-case/tasks.md#3.3
 */
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (...parts) => JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))

const manifest = read('src', 'manifest.json')
const detail = manifest.pages.find((page) => page.id === 'CaseDetail')
const actions = detail.config.headerActions
const flowFragment = read('lib', 'Settings', 'register.d', '96-generate-document-flow.json')
const flow = flowFragment.components.schemas.case.configuration['x-openregister-flows'][0]

describe('the case header runs the generate node', () => {
	const action = actions.find((a) => a.id === 'generate-from-template')

	it('is a run-node action on the shipped flow, resolved per instance', () => {
		expect(action, 'the header offers no generate-from-template action').toBeTruthy()
		expect(action.type).toBe('run-node')
		// The uuid is minted when OpenRegister imports the flow, so the manifest
		// can only name it; ShippedFlowIds fills the slot this key reads.
		expect(action.flowId).toBe('@resolve:generate_document_flow')
		expect(action.nodeId).toBe('generate')
	})

	it('names a node the shipped flow has, of filinq\'s generate type', () => {
		const node = flow.nodes.find((n) => n.id === action.nodeId)
		expect(node, 'the flow has no node by the action\'s nodeId').toBeTruthy()
		expect(node.type).toBe('filinq.generate-document')
		expect(node.config.requestingApp).toBe('dossiq')
	})

	it('ships a flow nothing starts on its own', () => {
		expect(flow.name).toBe('Generate a document')
		expect(flow.trigger).toBe('manual')
		expect(flow.nodes.map((n) => n.type)).toEqual([
			'openregister.trigger-manual',
			'filinq.generate-document',
			'openregister.end',
		])
		// Every node has somewhere to send its token but the end node.
		const from = new Set(flow.edges.map((e) => e.from))
		flow.nodes.filter((n) => n.type !== 'openregister.end').forEach((n) => expect(from.has(n.id)).toBe(true))
	})

	it('keeps the composing dialog beside it', () => {
		const dialog = actions.find((a) => a.id === 'generate-document')
		expect(dialog.type).toBe('open-modal')
		expect(dialog.target).toBe('BeschikkingComposerDialog')
	})

	it('is the key the server provides', () => {
		const php = fs.readFileSync(path.join(ROOT, 'lib', 'Service', 'Flow', 'ShippedFlowIds.php'), 'utf8')
		expect(php).toContain(`'generate_document_flow' => '${flow.name}'`)
	})
})
