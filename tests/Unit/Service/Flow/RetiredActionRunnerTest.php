<?php

/**
 * Unit tests for RetiredActionRunner.
 *
 * The node map and the translator are real; the map is given rows without a
 * translation, so the translator hands the declared action on as the
 * replacement's config. OpenRegister's node catalogue is its stub with a node
 * double that records what it was called with. Only the container and the
 * logger are mocks.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Flow;

use OCA\Dossiq\Service\Flow\RetiredActionRunner;
use OCA\Dossiq\Service\Flow\RetiredDocumentSteps;
use OCA\Dossiq\Service\Flow\RetiredNodeMap;
use OCA\Dossiq\Service\Flow\RetiredNodeTranslator;
use OCA\Dossiq\Service\Flow\RetiredTemplateSyntax;
use OCA\Dossiq\Service\Flow\RetiredWebhookSteps;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistry;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Flow\RetiredActionRunner
 * @uses   \OCA\Dossiq\Service\Flow\RetiredNodeMap
 * @uses   \OCA\Dossiq\Service\Flow\RetiredNodeTranslator
 * @uses   \OCA\Dossiq\Service\Flow\UnmappableStep
 */
class RetiredActionRunnerTest extends TestCase {

	private const REGISTRY = 'OCA\\OpenRegister\\Service\\Flow\\FlowNodeRegistry';

	/**
	 * The rows the runner reads: replacements without a translation, and one
	 * retired type nothing replaces.
	 */
	private const ROWS = [
		'dossiq.setField' => ['replacement' => 'openregister.object-write', 'reason' => 'moved'],
		'dossiq.sendEmail' => ['replacement' => 'openregister.send-email', 'reason' => 'moved'],
		'dossiq.webhook' => ['replacement' => 'integriq.source-call', 'reason' => 'moved'],
		'dossiq.notify' => ['replacement' => null, 'reason' => 'nothing sends it any more'],
	];

	/**
	 * The container.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Every log call as [level, message, context].
	 *
	 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $logs = [];

	/**
	 * The calls the node received.
	 *
	 * @var array<int, FlowNodeCall>
	 */
	private array $calls = [];

	/**
	 * Build the container and logger doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->container = $this->createMock(ContainerInterface::class);
		$this->logs      = [];
		$this->calls     = [];

	}//end setUp()

	/**
	 * The runner over the given rows.
	 *
	 * @param array<string, array<string, mixed>> $rows The node map rows.
	 *
	 * @return RetiredActionRunner
	 */
	private function runner(array $rows = self::ROWS): RetiredActionRunner {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string|\Stringable $message, array $context = []): void {
				$this->logs[] = ['warning', (string) $message, $context];
			}
		);

		$translator = new RetiredNodeTranslator(
			$this->createMock(SettingsService::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(IL10N::class),
			$this->createMock(RetiredTemplateSyntax::class),
			$this->createMock(RetiredDocumentSteps::class),
			$this->createMock(RetiredWebhookSteps::class)
		);

		return new RetiredActionRunner(new RetiredNodeMap($rows), $translator, $this->container, $logger);

	}//end runner()

	/**
	 * A catalogue whose `$type` node records its calls and answers, or throws.
	 *
	 * @param string          $type   The node type.
	 * @param \Throwable|null $throws What the node throws, if anything.
	 *
	 * @return void
	 */
	private function catalogueWith(string $type, ?\Throwable $throws = null): void {
		$node = $this->createMock(IFlowNode::class);
		$node->method('getId')->willReturn($type);
		$node->method('execute')->willReturnCallback(
			function (FlowNodeCall $call) use ($throws): array {
				$this->calls[] = $call;
				if ($throws !== null) {
					throw $throws;
				}

				return [['json' => ['done' => true]]];
			}
		);

		$registry = new FlowNodeRegistry($this->createMock(IEventDispatcher::class), $this->createMock(LoggerInterface::class));
		$registry->register($node);
		$this->container->method('get')->with(self::REGISTRY)->willReturn($registry);

	}//end catalogueWith()

	public function testItHandlesOnlyTypesTheMapRetires(): void {
		$runner = $this->runner();

		$this->assertTrue($runner->handles(type: 'setField'));
		$this->assertTrue($runner->handles(type: 'notify'));
		$this->assertFalse($runner->handles(type: 'evaluateDecision'));
		$this->assertFalse($runner->handles(type: ''));

	}//end testItHandlesOnlyTypesTheMapRetires()

	public function testRunnableTypesAreTheDeclaredOnesWithAReplacementInDeclaredOrder(): void {
		$this->assertSame(['sendEmail', 'setField', 'webhook'], $this->runner()->runnableTypes());

	}//end testRunnableTypesAreTheDeclaredOnesWithAReplacementInDeclaredOrder()

	public function testATypeNothingReplacesFailsAsUnmappableAndIsLogged(): void {
		$this->container->expects($this->never())->method('get');

		$result = $this->runner()->run(type: 'notify', action: [], case: ['id' => 'case-1'], context: []);

		$this->assertSame(
			['type' => 'notify', 'ok' => false, 'error' => 'retired_action_unmappable: it is retired and nothing replaces it (nothing sends it any more)'],
			$result
		);
		$this->assertSame(
			[['warning', 'Dossiq: a declared "notify" action cannot run as its replacement: it is retired and nothing replaces it (nothing sends it any more)', ['type' => 'notify', 'case' => 'case-1']]],
			$this->logs
		);

	}//end testATypeNothingReplacesFailsAsUnmappableAndIsLogged()

	public function testATypeTheMapDoesNotKnowFailsAsUnmappable(): void {
		$result = $this->runner()->run(type: 'evaluateDecision', action: [], case: [], context: []);

		$this->assertSame('retired_action_unmappable: "evaluateDecision" is not a retired action', $result['error']);
		$this->assertFalse($result['ok']);
		$this->assertSame('', $this->logs[0][2]['case']);

	}//end testATypeTheMapDoesNotKnowFailsAsUnmappable()

	public function testWithoutAnOpenRegisterCatalogueTheActionFailsAndTheGapIsLogged(): void {
		$this->container->method('get')->willThrowException(new RuntimeException('no openregister'));

		$result = $this->runner()->run(type: 'setField', action: ['field' => 'status'], case: ['id' => 'c'], context: []);

		$this->assertSame(['type' => 'setField', 'ok' => false, 'error' => 'retired_action_needs_openregister'], $result);
		$this->assertSame(
			[['warning', 'Dossiq: OpenRegister node catalogue unavailable for a retired action', ['error' => 'no openregister']]],
			$this->logs
		);

	}//end testWithoutAnOpenRegisterCatalogueTheActionFailsAndTheGapIsLogged()

	public function testSomethingElseThanTheCatalogueIsNoCatalogue(): void {
		$this->container->method('get')->willReturn(new \stdClass());

		$result = $this->runner()->run(type: 'setField', action: [], case: [], context: []);

		$this->assertSame('retired_action_needs_openregister', $result['error']);
		$this->assertSame([], $this->logs);

	}//end testSomethingElseThanTheCatalogueIsNoCatalogue()

	public function testTheReplacementRunsOnTheCaseAsTheActingUser(): void {
		$this->catalogueWith('openregister.object-write');

		$result = $this->runner()->run(
			type: 'setField',
			action: ['field' => 'status', 'value' => 'closed'],
			case: ['id' => 'case-7', 'title' => 'Bezwaar'],
			context: ['userId' => 'behandelaar-1', 'transitionLabel' => ' Afsluiten ']
		);

		$this->assertSame(['type' => 'setField', 'ok' => true], $result);
		$this->assertCount(1, $this->calls);
		$this->assertSame([['json' => ['id' => 'case-7', 'title' => 'Bezwaar']]], $this->calls[0]->items);
		$this->assertSame(['field' => 'status', 'value' => 'closed', 'transitionLabel' => 'Afsluiten'], $this->calls[0]->config);
		$this->assertSame('behandelaar-1', $this->calls[0]->context['runAs']);
		$this->assertSame('behandelaar-1', $this->calls[0]->context['triggeredBy']);

	}//end testTheReplacementRunsOnTheCaseAsTheActingUser()

	public function testAnOwnerTheContextAlreadyNamesIsKept(): void {
		$this->catalogueWith('openregister.object-write');

		$this->runner()->run(type: 'setField', action: [], case: [], context: ['userId' => 'u1', 'runAs' => 'system', 'triggeredBy' => ' ']);

		$this->assertSame('system', $this->calls[0]->context['runAs']);
		$this->assertSame('u1', $this->calls[0]->context['triggeredBy']);

	}//end testAnOwnerTheContextAlreadyNamesIsKept()

	public function testAMailTakesTheTransitionLabelAsSubjectUnlessItHasOne(): void {
		$this->catalogueWith('openregister.send-email');
		$runner = $this->runner();

		$runner->run(type: 'sendEmail', action: ['to' => 'a@example.org'], case: [], context: ['transitionLabel' => 'Besluit']);
		$runner->run(type: 'sendEmail', action: ['subject' => 'Eigen onderwerp'], case: [], context: ['transitionLabel' => 'Besluit']);
		$runner->run(type: 'sendEmail', action: [], case: [], context: []);

		$this->assertSame('Besluit', $this->calls[0]->config['subject']);
		$this->assertSame('Eigen onderwerp', $this->calls[1]->config['subject']);
		$this->assertSame([], $this->calls[2]->config);

	}//end testAMailTakesTheTransitionLabelAsSubjectUnlessItHasOne()

	public function testAWebhookCarriesTheTransitionBesideTheCase(): void {
		$this->catalogueWith('integriq.source-call');
		$context = ['transitionLabel' => 'Doorsturen', 'from' => 'open', 'to' => 'sent'];

		$this->runner()->run(type: 'webhook', action: ['url' => 'https://example.org/hook'], case: [], context: $context);

		$this->assertSame($context, $this->calls[0]->config[RetiredWebhookSteps::TRANSITION_KEY]);
		$this->assertSame('https://example.org/hook', $this->calls[0]->config['url']);

	}//end testAWebhookCarriesTheTransitionBesideTheCase()

	public function testAFailingReplacementAnswersItsMessageOrAGenericOne(): void {
		$this->catalogueWith('openregister.object-write', new RuntimeException('object locked'));
		$this->assertSame(
			['type' => 'setField', 'ok' => false, 'error' => 'object locked'],
			$this->runner()->run(type: 'setField', action: [], case: [], context: [])
		);

	}//end testAFailingReplacementAnswersItsMessageOrAGenericOne()

	public function testAFailureWithoutAMessageIsActionFailed(): void {
		$this->catalogueWith('openregister.object-write', new RuntimeException(''));
		$this->assertSame('action_failed', $this->runner()->run(type: 'setField', action: [], case: [], context: [])['error']);

	}//end testAFailureWithoutAMessageIsActionFailed()
}//end class
