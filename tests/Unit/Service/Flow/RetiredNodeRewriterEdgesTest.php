<?php

/**
 * Unit tests for the edge cases of RetiredNodeRewriter's graph surgery.
 *
 * The rewriter, the map and the translator are real; only the translator's
 * instance data (configured register and schema, the decision-table store and
 * the l10n) are answered by doubles.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Flow;

use OCA\Dossiq\Service\Flow\RetiredDocumentSteps;
use OCA\Dossiq\Service\Flow\RetiredNodeMap;
use OCA\Dossiq\Service\Flow\RetiredNodeRewriter;
use OCA\Dossiq\Service\Flow\RetiredNodeTranslator;
use OCA\Dossiq\Service\Flow\RetiredTemplateSyntax;
use OCA\Dossiq\Service\Flow\RetiredWebhookSteps;
use OCA\Dossiq\Service\SettingsService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IL10N;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * @covers \OCA\Dossiq\Service\Flow\RetiredNodeRewriter
 * @uses \OCA\Dossiq\Service\Flow\RetiredNodeMap
 * @uses \OCA\Dossiq\Service\Flow\NodeRewrite
 * @uses \OCA\Dossiq\Service\Flow\RetiredStep
 * @uses \OCA\Dossiq\Service\Flow\RetiredNodeTranslator
 * @uses \OCA\Dossiq\Service\Flow\RetiredTemplateSyntax
 * @uses \OCA\Dossiq\Service\Flow\RetiredDocumentSteps
 * @uses \OCA\Dossiq\Service\Flow\RetiredWebhookSteps
 */
class RetiredNodeRewriterEdgesTest extends TestCase {

	/**
	 * A real translator over a configured register and case schema.
	 *
	 * @return RetiredNodeTranslator
	 */
	private function translator(): RetiredNodeTranslator {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => (['register' => '12', 'case_schema' => '34'][$key] ?? '')
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $params = []): string => vsprintf($text, $params)
		);

		$syntax = new RetiredTemplateSyntax();
		$webhooks = new RetiredWebhookSteps($this->createMock(IEventDispatcher::class), $this->createMock(IUserSession::class), $syntax);

		return new RetiredNodeTranslator(
			$settings,
			$this->createMock(ContainerInterface::class),
			$l10n,
			$syntax,
			new RetiredDocumentSteps($syntax),
			$webhooks
		);
	}//end translator()

	/**
	 * A rewriter whose only row translates a set-field step into a set and a case write.
	 *
	 * @return RetiredNodeRewriter
	 */
	private function setFieldRewriter(): RetiredNodeRewriter {
		$map = new RetiredNodeMap(
			[
				'dossiq.setField' => ['replacement' => 'openregister.set-fields', 'reason' => 'moved', 'translation' => RetiredNodeMap::SET_FIELD],
			]
		);

		return new RetiredNodeRewriter($map, $this->translator());
	}//end setFieldRewriter()

	/**
	 * A translation into one step keeps the edges exactly as they were.
	 *
	 * @return void
	 */
	public function testASingleStepTranslationLeavesTheEdgesAlone(): void {
		$map = new RetiredNodeMap(
			[
				'dossiq.action.sendEmail' => ['replacement' => 'openregister.send-email', 'reason' => 'moved', 'translation' => RetiredNodeMap::EMAIL_ACTION],
			]
		);
		$nodes = [
			['id' => 'start', 'type' => 'openregister.trigger-object'],
			['id' => 'mail', 'type' => 'dossiq.action.sendEmail', 'config' => ['recipientRef' => 'email:loket@example.org', 'bodyTemplate' => 'Hallo']],
		];
		$edges = [['id' => 'e1', 'from' => 'start', 'to' => 'mail']];

		$result = (new RetiredNodeRewriter($map, $this->translator()))->rewrite(nodes: $nodes, edges: $edges);

		self::assertSame(['start', 'mail'], array_column($result['nodes'], 'id'));
		self::assertSame('openregister.send-email', $result['nodes'][1]['type']);
		self::assertSame($edges, $result['edges']);
		self::assertSame('replaced', $result['changes'][0]['outcome']);
		self::assertSame('openregister.send-email', $result['changes'][0]['replacement']);
	}//end testASingleStepTranslationLeavesTheEdgesAlone()

	/**
	 * A two-step translation moves a single-id source onto the last step and links the two.
	 *
	 * @return void
	 */
	public function testAChainedTranslationMovesAStringSourceToTheLastStep(): void {
		$nodes = [
			['id' => 'set', 'type' => 'dossiq.setField', 'config' => ['field' => 'archiveStatus', 'value' => 'gearchiveerd'], '_note' => 'migrated'],
			['id' => 'end', 'type' => 'openregister.end'],
		];
		$edges = [
			['id' => 'e1', 'from' => 'set', 'to' => 'end'],
			['id' => 'e2', 'from' => ['set', 'other'], 'to' => ['end']],
			'not-an-edge',
		];

		$result = $this->setFieldRewriter()->rewrite(nodes: $nodes, edges: $edges);

		self::assertSame(['set', 'set--2', 'end'], array_column($result['nodes'], 'id'));
		self::assertSame('migrated', $result['nodes'][0]['_note']);
		self::assertArrayNotHasKey('_note', $result['nodes'][1]);
		self::assertSame(
			[
				['id' => 'e1', 'from' => 'set--2', 'to' => 'end'],
				['id' => 'e2', 'from' => ['set--2', 'other'], 'to' => ['end']],
				'not-an-edge',
				['id' => 'set-set--2', 'from' => 'set', 'to' => 'set--2'],
			],
			$result['edges']
		);
		self::assertSame('openregister.set-fields + openregister.object-write', $result['changes'][0]['replacement']);
	}//end testAChainedTranslationMovesAStringSourceToTheLastStep()

	/**
	 * Removing a step keeps unrelated and malformed edges, and keeps an edge that
	 * also leaves from another step, without the removed one among its sources.
	 *
	 * @return void
	 */
	public function testRemovingAStepKeepsEdgesThatDoNotDependOnIt(): void {
		$nodes = [
			['id' => 'start', 'type' => 'openregister.trigger-object'],
			['id' => 'remind', 'type' => 'dossiq.action.scheduleReminder'],
			['id' => 'side', 'type' => 'openregister.set-fields'],
			['id' => 'end', 'type' => 'openregister.end'],
		];
		$edges = [
			'garbage',
			['id' => 'e1', 'from' => 'start', 'to' => 'side'],
			['id' => 'e2', 'from' => ['remind', 'side'], 'to' => ['end']],
			['id' => 'e3', 'from' => 'start', 'to' => 'remind'],
			['id' => 'e4', 'from' => 42, 'to' => null],
		];

		$result = (new RetiredNodeRewriter(new RetiredNodeMap()))->rewrite(nodes: $nodes, edges: $edges);

		self::assertSame(['start', 'side', 'end'], array_column($result['nodes'], 'id'));
		self::assertSame(
			[
				'garbage',
				['id' => 'e1', 'from' => 'start', 'to' => 'side'],
				['id' => 'e2', 'from' => ['side'], 'to' => ['end']],
				['id' => 'e3', 'from' => 'start', 'to' => 'end'],
				['id' => 'e4', 'from' => 42, 'to' => null],
			],
			$result['edges']
		);
		self::assertFalse($result['changes'][0]['orphaned']);
	}//end testRemovingAStepKeepsEdgesThatDoNotDependOnIt()

	/**
	 * Endpoint lists drop blank and non-string ids, and a fan-out without an edge id is numbered from "edge".
	 *
	 * @return void
	 */
	public function testEndpointListsIgnoreBlankIdsAndUnnamedEdgesAreNumbered(): void {
		$nodes = [
			['id' => 'start', 'type' => 'openregister.trigger-object'],
			['id' => 'remind', 'type' => 'dossiq.action.scheduleReminder'],
		];
		$edges = [
			['from' => 'start', 'to' => 'remind'],
			['id' => 'out', 'from' => ['remind', '', 7], 'to' => ['a', '', 'b', 9]],
		];

		$result = (new RetiredNodeRewriter(new RetiredNodeMap()))->rewrite(nodes: $nodes, edges: $edges);

		self::assertSame(
			[
				['from' => 'start', 'to' => 'a', 'id' => 'edge-1'],
				['from' => 'start', 'to' => 'b', 'id' => 'edge-2'],
			],
			$result['edges']
		);
	}//end testEndpointListsIgnoreBlankIdsAndUnnamedEdgesAreNumbered()
}//end class
