<?php

/**
 * Golden record of the case marker derivation.
 *
 * Records, per world, the data the listener writes into the save and every
 * call it makes, so a refactor cannot change which saves get their risk and
 * attention markers derived.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\CaseMarkerDerivationListener;
use OCA\Dossiq\Service\CaseAttentionMarkerService;
use OCA\Dossiq\Service\CaseRiskAssessmentService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * An update event that carries the modified data, as OpenRegister's does.
 */
class MarkerDerivationUpdatingEvent extends ObjectUpdatingEvent {

	/**
	 * The data the save will write on top of the object.
	 *
	 * @var array<string, mixed>
	 */
	public array $modified = [];

	/**
	 * The data the save will write on top of the object.
	 *
	 * @return array<string, mixed>
	 */
	public function getModifiedData(): array {
		return $this->modified;
	}//end getModifiedData()

	/**
	 * Replace the data the save will write.
	 *
	 * @param array<string, mixed> $data The data.
	 *
	 * @return void
	 */
	public function setModifiedData(array $data): void {
		$this->modified = $data;
	}//end setModifiedData()
}//end class

/**
 * Golden test for CaseMarkerDerivationListener::handle.
 *
 * @covers \OCA\Dossiq\Listener\CaseMarkerDerivationListener
 * @uses \OCA\Dossiq\Listener\Support\PreSaveCheck
 * @uses \OCA\Dossiq\Listener\Support\SchemaMembership
 */
class CaseMarkerDerivationListenerGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	/**
	 * Calls made in the current world.
	 *
	 * @var list<string>
	 */
	private array $calls = [];

	/**
	 * Every world the listener distinguishes, recorded in one golden file.
	 *
	 * @return void
	 */
	public function testDerivationAcrossWorlds(): void {
		$worlds = [
			'another event' => ['event' => 'other', 'schema' => 'case', 'uuid' => 'case-1', 'modified' => []],
			'a create of another schema' => ['event' => 'create', 'schema' => 'note', 'uuid' => '', 'modified' => ['title' => 'x']],
			'a new case' => ['event' => 'create', 'schema' => 'case', 'uuid' => '', 'modified' => ['priority' => 'high']],
			'an updated case' => ['event' => 'update', 'schema' => 'case', 'uuid' => 'case-1', 'modified' => ['status' => 'review']],
			'an update of another schema' => ['event' => 'update', 'schema' => 'note', 'uuid' => 'note-1', 'modified' => ['title' => 'x']],
		];

		$record = [];
		foreach ($worlds as $name => $world) {
			$this->calls = [];
			$event = $this->eventFor(world: $world);
			$this->listener()->handle($event);
			$modified = null;
			if (method_exists($event, 'getModifiedData') === true) {
				$modified = $event->getModifiedData();
			}

			$record[$name] = ['modified' => $modified, 'calls' => $this->calls];
		}

		$this->assertMatchesGolden(name: 'case-marker-derivation', value: $record);
	}//end testDerivationAcrossWorlds()

	/**
	 * The event of one world.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return Event
	 */
	private function eventFor(array $world): Event {
		$entity = new ObjectEntity();
		$entity->setObject(['title' => 'A case', 'status' => 'intake']);
		$entity->setSchema($world['schema']);
		$entity->setUuid($world['uuid']);
		if ($world['event'] === 'create') {
			$event = new ObjectCreatingEvent($entity);
			$event->setModifiedData($world['modified']);
			return $event;
		}

		if ($world['event'] === 'update') {
			$event = new MarkerDerivationUpdatingEvent($entity);
			$event->modified = $world['modified'];
			return $event;
		}

		return new Event();
	}//end eventFor()

	/**
	 * The listener, with services that record what they are asked.
	 *
	 * @return CaseMarkerDerivationListener
	 */
	private function listener(): CaseMarkerDerivationListener {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($key === 'case_schema') ? 'case' : $default
		);

		$risk = $this->createMock(CaseRiskAssessmentService::class);
		$risk->method('resolve')->willReturnCallback(
			function (array $case): array {
				$this->calls[] = 'risk status=' . ($case['status'] ?? '') . ' title=' . ($case['title'] ?? '');
				return ['riskLevel' => 'low'];
			}
		);

		$markers = $this->createMock(CaseAttentionMarkerService::class);
		$markers->method('contextFor')->willReturnCallback(
			function (string $caseId): array {
				$this->calls[] = 'contextFor "' . $caseId . '"';
				return ['related' => $caseId];
			}
		);
		$markers->method('resolve')->willReturnCallback(
			function (array $case, mixed $now = null, array $context = []): array {
				$this->calls[] = 'markers ' . json_encode($context);
				return ['attentionMarkers' => ['overdue']];
			}
		);

		return new CaseMarkerDerivationListener($settings, $risk, $markers, $this->createMock(LoggerInterface::class));
	}//end listener()
}//end class
