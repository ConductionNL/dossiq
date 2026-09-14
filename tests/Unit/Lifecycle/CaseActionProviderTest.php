<?php

/**
 * The case lifecycle provider answers on OpenRegister's vocabulary.
 *
 * OpenRegister asks this class what a case can do next, and renders the answer
 * as a clickable timeline. Every assertion below is about the MAPPING, not
 * about the moves: the moves are StatusTransitionService's answer, and this
 * class exists precisely so nothing re-derives them.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Lifecycle;

use OCA\Dossiq\Lifecycle\CaseActionProvider;
use OCA\Dossiq\Service\StatusTransitionService;
use OCA\Dossiq\Service\Transitions\CaseResultWriter;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use OCA\Dossiq\Service\Transitions\GuardRegistry;
use OCA\Dossiq\Service\Transitions\SideEffectDispatcher;
use OCA\Dossiq\Service\Transitions\StatusChecklist;
use OCA\Dossiq\Service\Transitions\TransitionAuthorizer;
use OCA\Dossiq\Service\Transitions\TransitionSpecReader;
use OCA\Dossiq\Service\WorkflowTemplateLoader;
use OCA\OpenRegister\Exception\LifecycleProviderException;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Maps dossiq's transitions onto OpenRegister's published actions.
 *
 * @covers \OCA\Dossiq\Lifecycle\CaseActionProvider
 * @uses \OCA\Dossiq\Service\StatusTransitionService
 * @uses \OCA\Dossiq\Service\Transitions\TransitionSpecReader
 */
class CaseActionProviderTest extends TestCase {

	/**
	 * A case as OpenRegister hands it to the provider.
	 *
	 * @var array<string, mixed>
	 */
	private const CASE_PAYLOAD = [
		'id' => 'c7b1f0de-0a6c-4a1e-9a0e-3b1f0de0a6c4',
		'title' => 'Handhavingsverzoek Kerkstraat 12',
		'caseType' => 'ct-handhaving',
		'status' => 'st-intake',
		'@self' => ['id' => 'c7b1f0de-0a6c-4a1e-9a0e-3b1f0de0a6c4', 'version' => 4],
	];

	/**
	 * The `current` block a loaded case always carries.
	 *
	 * @var array<string, string>
	 */
	private const CURRENT = [
		'statusId' => 'st-intake',
		'statusName' => 'Intake',
		'statusColour' => '#1a73e8',
	];

	/**
	 * Build the provider over a transition engine we dictate.
	 *
	 * @param array<string, mixed> $answer What getAvailableTransitions() returns.
	 * @param array<int, string> $finalStatuses The statusType ids that close a case.
	 *
	 * @return CaseActionProvider
	 */
	private function providerAnswering(array $answer, array $finalStatuses = []): CaseActionProvider {
		$engine = $this->createMock(StatusTransitionService::class);
		$engine->method('getAvailableTransitions')->willReturn($answer);

		return new CaseActionProvider(
			transitionEngine: $engine,
			resultWriter: $this->resultWriterClosingOn(finalStatuses: $finalStatuses),
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end providerAnswering()

	/**
	 * A result writer that calls exactly the named statuses final.
	 *
	 * @param array<int, string> $finalStatuses The statusType ids that close a case.
	 *
	 * @return CaseResultWriter
	 */
	private function resultWriterClosingOn(array $finalStatuses): CaseResultWriter {
		$resultWriter = $this->createMock(CaseResultWriter::class);
		$resultWriter->method('isFinalStatus')->willReturnCallback(
			static fn (string $statusTypeId): bool => in_array($statusTypeId, $finalStatuses, true)
		);

		return $resultWriter;
	}//end resultWriterClosingOn()

	/**
	 * An ordinary move arrives on OpenRegister's vocabulary, key for key.
	 *
	 * @return void
	 */
	public function testAnOpenMoveIsPublishedOnOpenRegistersVocabulary(): void {
		$provider = $this->providerAnswering(
			answer: [
				'current' => self::CURRENT,
				'transitions' => [
					[
						'id' => 'tr-in-behandeling',
						'label' => 'In behandeling nemen',
						'description' => 'Neem het verzoek in behandeling.',
						'toStatus' => 'st-behandeling',
						'guardsPassed' => true,
						'failedGuards' => [],
					],
				],
			],
		);

		self::assertSame(
			[
				[
					'action' => 'tr-in-behandeling',
					'to' => 'st-behandeling',
					'requires' => null,
					'description' => 'Neem het verzoek in behandeling.',
					'inputs' => [],
					'label' => 'In behandeling nemen',
					'blocked' => false,
				],
			],
			$provider->availableActions(object: self::CASE_PAYLOAD, userId: 'behandelaar'),
		);
	}//end testAnOpenMoveIsPublishedOnOpenRegistersVocabulary()

	/**
	 * A guard-failing move stays visible, is marked blocked, and says why.
	 *
	 * The reason matters more than the flag. A handler told only that a move
	 * is blocked has to guess which of the transition's guards to satisfy, so
	 * every failed guard's own message travels with it.
	 *
	 * @return void
	 */
	public function testAGuardFailingMoveIsBlockedAndKeepsItsMessages(): void {
		$provider = $this->providerAnswering(
			answer: [
				'current' => self::CURRENT,
				'transitions' => [
					[
						'id' => 'tr-besluiten',
						'label' => 'Besluit nemen',
						'description' => 'Neem een besluit op het verzoek.',
						'toStatus' => 'st-besluit',
						'guardsPassed' => false,
						'failedGuards' => [
							[
								'type' => 'checklist',
								'passed' => false,
								'failureMessage' => '2 checklistitem niet afgevinkt: \'Hoorzitting ingepland\'',
								'details' => [],
							],
							[
								'type' => 'requiredField',
								'passed' => false,
								'failureMessage' => 'Vereist veld ontbreekt: motivering',
								'details' => [],
							],
						],
					],
				],
			],
		);

		$actions = $provider->availableActions(object: self::CASE_PAYLOAD, userId: 'behandelaar');

		self::assertCount(1, $actions, 'A blocked move is still offered, greyed out, not hidden.');
		self::assertTrue($actions[0]['blocked'], 'A move whose guards failed must be marked blocked.');
		self::assertSame(
			'2 checklistitem niet afgevinkt: \'Hoorzitting ingepland\' Vereist veld ontbreekt: motivering',
			$actions[0]['description'],
			'A blocked move must carry every failed guard\'s own message, not the transition description.',
		);
	}//end testAGuardFailingMoveIsBlockedAndKeepsItsMessages()

	/**
	 * A move into a final status declares the result the write path demands.
	 *
	 * `StatusTransitionService::execute()` refuses a closing transition that
	 * carries no resultType, before it mutates anything. Declaring the input
	 * is what turns that refusal into a question asked first.
	 *
	 * @return void
	 */
	public function testAClosingMoveDeclaresItsResultType(): void {
		$provider = $this->providerAnswering(
			answer: [
				'current' => self::CURRENT,
				'transitions' => [
					[
						'id' => 'tr-afhandelen',
						'label' => 'Afhandelen',
						'description' => '',
						'toStatus' => 'st-afgehandeld',
						'guardsPassed' => true,
						'failedGuards' => [],
					],
					[
						'id' => 'tr-aanhouden',
						'label' => 'Aanhouden',
						'description' => '',
						'toStatus' => 'st-aangehouden',
						'guardsPassed' => true,
						'failedGuards' => [],
					],
				],
			],
			finalStatuses: ['st-afgehandeld'],
		);

		$actions = $provider->availableActions(object: self::CASE_PAYLOAD, userId: 'behandelaar');

		self::assertSame(
			[['field' => 'resultTypeId', 'required' => true]],
			$actions[0]['inputs'],
			'A move that closes the case must declare resultTypeId as a required input.',
		);
		self::assertSame(
			[],
			$actions[1]['inputs'],
			'A move that does not close the case declares no inputs.',
		);
	}//end testAClosingMoveDeclaresItsResultType()

	/**
	 * A role-hidden move never reaches OpenRegister.
	 *
	 * This one runs the REAL engine, because the filter is the engine's:
	 * `getAvailableTransitions()` drops a transition whose roleGuard denied
	 * silently, through `TransitionSpecReader::isRoleHidden()`. The provider
	 * publishes what it is given and adds nothing, so a provider that started
	 * deriving its own list would surface the hidden move here.
	 *
	 * @return void
	 */
	public function testARoleHiddenMoveIsAbsent(): void {
		$template = [
			'transitions' => [
				[
					'id' => 'tr-in-behandeling',
					'label' => 'In behandeling nemen',
					'fromStatus' => 'st-intake',
					'toStatus' => 'st-behandeling',
					'guards' => [],
				],
				[
					'id' => 'tr-seponeren',
					'label' => 'Seponeren',
					'fromStatus' => 'st-intake',
					'toStatus' => 'st-geseponeerd',
					'guards' => [['type' => 'roleGuard', 'allowedRoles' => ['rol-teamleider']]],
				],
			],
		];

		$store = $this->createMock(CaseStatusStore::class);
		$store->method('loadCase')->willReturn(self::CASE_PAYLOAD);
		$store->method('lookupStatusName')->willReturn('Intake');
		$store->method('lookupStatusColour')->willReturn('#1a73e8');

		$templateLoader = $this->createMock(WorkflowTemplateLoader::class);
		$templateLoader->method('getTemplateForCase')->willReturn($template);

		// The first transition's guards all pass; the second is denied by a
		// roleGuard that asked to stay silent, which is how dossiq hides a move
		// from a user who may not make it.
		$guardRegistry = $this->createMock(GuardRegistry::class);
		$guardRegistry->method('evaluateAll')->willReturnOnConsecutiveCalls(
			[['type' => 'statusChecklist', 'passed' => true, 'failureMessage' => null, 'details' => []]],
			[
				[
					'type' => 'roleGuard',
					'passed' => false,
					'failureMessage' => 'Onvoldoende rechten',
					'details' => ['silent' => true],
				],
			],
		);

		$engine = new StatusTransitionService(
			templateLoader: $templateLoader,
			guardRegistry: $guardRegistry,
			sideEffectDispatcher: $this->createMock(SideEffectDispatcher::class),
			store: $store,
			authorizer: $this->createMock(TransitionAuthorizer::class),
			specReader: new TransitionSpecReader(),
			userSession: $this->createMock(IUserSession::class),
			logger: $this->createMock(LoggerInterface::class),
			resultWriter: $this->createMock(CaseResultWriter::class),
			statusChecklist: $this->createMock(StatusChecklist::class),
		);

		$provider = new CaseActionProvider(
			transitionEngine: $engine,
			resultWriter: $this->resultWriterClosingOn(finalStatuses: []),
			logger: $this->createMock(LoggerInterface::class),
		);

		$actions = $provider->availableActions(object: self::CASE_PAYLOAD, userId: 'behandelaar');

		self::assertSame(
			['tr-in-behandeling'],
			array_column($actions, 'action'),
			'A move a silent roleGuard hides must not be published at all, blocked or otherwise.',
		);
	}//end testARoleHiddenMoveIsAbsent()

	/**
	 * A throwing engine does not escape, and does not become an empty answer.
	 *
	 * Both halves are the assertion. Letting the throwable out makes
	 * OpenRegister answer 502 with a dossiq stack trace in it; swallowing it to
	 * `[]` tells the widget the case is finished. The provider does neither: it
	 * rethrows the type OpenRegister documents, carrying the original as its
	 * previous so the log still names the real fault.
	 *
	 * @return void
	 */
	public function testAThrowingEngineDoesNotEscape(): void {
		$failure = new RuntimeException('storage_unavailable');
		$engine = $this->createMock(StatusTransitionService::class);
		$engine->method('getAvailableTransitions')->willThrowException($failure);

		$provider = new CaseActionProvider(
			transitionEngine: $engine,
			resultWriter: $this->resultWriterClosingOn(finalStatuses: []),
			logger: $this->createMock(LoggerInterface::class),
		);

		try {
			$provider->availableActions(object: self::CASE_PAYLOAD, userId: 'behandelaar');
			self::fail('A failing transition engine must not be published as a case with no moves.');
		} catch (LifecycleProviderException $e) {
			self::assertSame(
				$failure,
				$e->getPrevious(),
				'The original failure must travel with the provider exception, or the log names nothing.',
			);
		}
	}//end testAThrowingEngineDoesNotEscape()

	/**
	 * A case that could not be read is not published as a case with no moves.
	 *
	 * `getAvailableTransitions()` answers with an empty `current` block when it
	 * could not load the case at all. Only `current` tells that apart from a
	 * case sitting in a terminal status, which is why it is read here.
	 *
	 * @return void
	 */
	public function testACaseThatCouldNotBeReadThrows(): void {
		$provider = $this->providerAnswering(answer: ['current' => [], 'transitions' => []]);

		$this->expectException(LifecycleProviderException::class);
		$provider->availableActions(object: self::CASE_PAYLOAD, userId: 'behandelaar');
	}//end testACaseThatCouldNotBeReadThrows()

	/**
	 * A case that loaded and offers nothing answers with an empty list.
	 *
	 * This is the answer the throw above exists to stay distinct from: a
	 * genuinely terminal case is not a failure, and must not read as one.
	 *
	 * @return void
	 */
	public function testATerminalCaseAnswersWithNoMoves(): void {
		$provider = $this->providerAnswering(
			answer: [
				'current' => ['statusId' => 'st-afgehandeld', 'statusName' => 'Afgehandeld', 'statusColour' => '#0b8043'],
				'transitions' => [],
			],
		);

		self::assertSame([], $provider->availableActions(object: self::CASE_PAYLOAD, userId: 'behandelaar'));
	}//end testATerminalCaseAnswersWithNoMoves()

	/**
	 * A payload carrying no case id is refused rather than answered.
	 *
	 * @return void
	 */
	public function testAnUnidentifiablePayloadThrows(): void {
		$provider = $this->providerAnswering(answer: ['current' => self::CURRENT, 'transitions' => []]);

		$this->expectException(LifecycleProviderException::class);
		$provider->availableActions(object: ['title' => 'Nameless'], userId: 'behandelaar');
	}//end testAnUnidentifiablePayloadThrows()
}//end class
