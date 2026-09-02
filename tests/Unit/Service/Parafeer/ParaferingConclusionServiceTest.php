<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Service\Parafeer
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Parafeer;

use OCA\Dossiq\Event\ParafeerTransitionEvent;
use OCA\Dossiq\Service\Parafeer\ParafeerVoorstelRepository;
use OCA\Dossiq\Service\Parafeer\ParaferingConclusionService;
use OCA\Dossiq\Service\ParaferingNotificationService;
use OCA\Dossiq\Service\Support\ObjectArrayNormalizer;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers the recorder that projects a concluded chain onto the case.
 *
 * This is the sanctioned door: it must keep the administrative-law record the
 * decision app hands back — who signed, on whose behalf, under which mandate —
 * and it must be safe to run twice, because the in-flight repair and a
 * re-delivered event both replay the same conclusion. A doubled signature and
 * a lost onBehalfOf are each a corruption of a legal record, so both are
 * pinned here.
 */
class ParaferingConclusionServiceTest extends TestCase {

	/**
	 * Parafeeractie rows the fake register already holds.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $existingActions = [];

	/**
	 * Everything saved back, keyed nothing — a flat log.
	 *
	 * @var array<int, array{schema: string, object: array<string, mixed>}>
	 */
	private array $saved = [];

	/**
	 * The voorstel the repository returns.
	 *
	 * @var array<string, mixed>
	 */
	private array $voorstel = ['id' => 'v-1', 'status' => 'in_parafering', 'author' => 'steller', 'subject' => 'Voorstel'];

	/**
	 * Transition events raised.
	 *
	 * @var array<int, ParafeerTransitionEvent>
	 */
	private array $transitions = [];

	/**
	 * Build the recorder over canned collaborators.
	 *
	 * @return ParaferingConclusionService The service.
	 */
	private function service(): ParaferingConclusionService {
		$objectService = new class ($this->existingActions, $this->saved) {
			/**
			 * @param array<int, array<string, mixed>> $existing The stored actions.
			 * @param array<int, array{schema: string, object: array<string, mixed>}> $saved The save log.
			 */
			public function __construct(private array $existing, private array &$saved) {
			}

			/**
			 * The slug-path read the SearchesObjects trait uses.
			 *
			 * @param string $register The register slug.
			 * @param string $schema The schema slug.
			 * @param array<string, mixed> $filters The filters.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				return $this->existing;
			}

			/**
			 * @param array<string, mixed> $object The object.
			 * @param string $register The register.
			 * @param string $schema The schema.
			 *
			 * @return array<string, mixed> The saved object.
			 */
			public function saveObject(array $object, string $register, string $schema): array {
				$this->saved[] = ['schema' => $schema, 'object' => $object];

				return $object;
			}
		};

		$repository = $this->createMock(ParafeerVoorstelRepository::class);
		$repository->method('resolveSchemas')->willReturn(['dossiq', 'proposal', 'parafeeractie']);
		$repository->method('requireObjectService')->willReturn($objectService);
		$repository->method('findVoorstel')->willReturn($this->voorstel);

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (Event $e): void {
				if ($e instanceof ParafeerTransitionEvent) {
					$this->transitions[] = $e;
				}
			}
		);

		return new ParaferingConclusionService(
			$repository,
			$this->createMock(ParaferingNotificationService::class),
			$this->createMock(IRootFolder::class),
			$dispatcher,
			new ObjectArrayNormalizer(),
			$this->createMock(LoggerInterface::class),
		);
	}

	/**
	 * The recorded parafeeractie rows, in save order.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function recordedActions(): array {
		$rows = [];
		foreach ($this->saved as $entry) {
			if ($entry['schema'] === 'parafeeractie') {
				$rows[] = $entry['object'];
			}
		}

		return $rows;
	}

	/**
	 * The voorstel patch the recorder wrote.
	 *
	 * @return array<string, mixed> The last proposal-schema save.
	 */
	private function voorstelPatch(): array {
		$patch = [];
		foreach ($this->saved as $entry) {
			if ($entry['schema'] === 'proposal') {
				$patch = $entry['object'];
			}
		}

		return $patch;
	}

	/**
	 * A concluded chain records one parafeeractie per sign-off, translated.
	 *
	 * @return void
	 */
	public function testItRecordsEachSignOffTranslated(): void {
		$service = $this->service();
		$service->recordConclusion(
			proposalId: 'v-1',
			outcome: 'approved',
			actor: 'carol',
			actions: [
				['step' => 1, 'actor' => 'erik', 'action' => 'advised', 'advice' => 'Akkoord'],
				['step' => 2, 'actor' => 'alice', 'action' => 'endorsed'],
				['step' => 3, 'actor' => 'carol', 'action' => 'approved'],
			],
		);

		$rows = $this->recordedActions();
		$this->assertCount(3, $rows);
		$this->assertSame(['advised', 'parafered', 'accorded'], array_column($rows, 'action'));
		$this->assertSame('geaccordeerd', $this->voorstelPatch()['status']);
	}

	/**
	 * The delegate record survives: actorType, onBehalfOf and mandate.
	 *
	 * @return void
	 */
	public function testItPreservesTheDelegateRecord(): void {
		$service = $this->service();
		$service->recordConclusion(
			proposalId: 'v-1',
			outcome: 'approved',
			actor: 'bob',
			actions: [
				['step' => 1, 'actor' => 'bob', 'action' => 'approved', 'actorType' => 'delegate', 'onBehalfOf' => 'carol', 'mandate' => 'mandaat-9'],
			],
		);

		$row = $this->recordedActions()[0];
		$this->assertSame('delegate', $row['actorType']);
		$this->assertSame('carol', $row['onBehalfOf']);
		$this->assertSame('mandaat-9', $row['mandate']);
	}

	/**
	 * A returned outcome writes teruggestuurd and asks for no signature.
	 *
	 * @return void
	 */
	public function testAReturnedOutcomeWritesTeruggestuurd(): void {
		$service = $this->service();
		$service->recordConclusion(
			proposalId: 'v-1',
			outcome: 'returned',
			actor: 'alice',
			actions: [
				['step' => 1, 'actor' => 'erik', 'action' => 'advised', 'advice' => 'ok'],
				['step' => 2, 'actor' => 'alice', 'action' => 'returned', 'comment' => 'Onvolledig'],
			],
		);

		$this->assertSame('teruggestuurd', $this->voorstelPatch()['status']);
	}

	/**
	 * A sign-off already on file is not recorded twice.
	 *
	 * The in-flight repair and a re-delivered event both replay the same
	 * record; a doubled signature reads as two people having signed.
	 *
	 * @return void
	 */
	public function testItDoesNotRecordASignOffTwice(): void {
		$this->existingActions = [
			['proposal' => 'v-1', 'step' => 1, 'actor' => 'erik', 'action' => 'advised'],
		];

		$service = $this->service();
		$service->recordConclusion(
			proposalId: 'v-1',
			outcome: 'approved',
			actor: 'alice',
			actions: [
				['step' => 1, 'actor' => 'erik', 'action' => 'advised', 'advice' => 'ok'],
				['step' => 2, 'actor' => 'alice', 'action' => 'endorsed'],
			],
		);

		$rows = $this->recordedActions();
		$this->assertCount(1, $rows, 'Only the not-yet-recorded sign-off is written.');
		$this->assertSame('parafered', $rows[0]['action']);
	}

	/**
	 * A conclusion on an already-terminal voorstel changes nothing.
	 *
	 * @return void
	 */
	public function testItLeavesAnAlreadyConcludedVoorstelAlone(): void {
		$this->voorstel = ['id' => 'v-1', 'status' => 'geaccordeerd', 'author' => 'steller'];

		$service = $this->service();
		$service->recordConclusion(
			proposalId: 'v-1',
			outcome: 'approved',
			actor: 'carol',
			actions: [['step' => 1, 'actor' => 'carol', 'action' => 'approved']],
		);

		$this->assertSame([], $this->saved, 'A replayed conclusion on a concluded voorstel writes nothing.');
	}

	/**
	 * Each recorded sign-off raises its audit transition, keeping the trail.
	 *
	 * @return void
	 */
	public function testItRaisesTheAuditTransition(): void {
		$service = $this->service();
		$service->recordConclusion(
			proposalId: 'v-1',
			outcome: 'approved',
			actor: 'carol',
			actions: [['step' => 3, 'actor' => 'carol', 'action' => 'approved']],
		);

		$this->assertCount(1, $this->transitions);
		$this->assertSame('completed', $this->transitions[0]->getAction());
	}
}
