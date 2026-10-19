<?php

/**
 * Case Requester Projection Listener Test
 *
 * The case's requester projection is written in the save that sets the
 * requester: on create, and when a person is replaced by a company, so the
 * case list never names the previous requester.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/initiator-display/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\CaseRequesterProjectionListener;
use OCA\Dossiq\Service\People\RequesterProjection;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Dossiq\Listener\CaseRequesterProjectionListener
 * @covers \OCA\Dossiq\Service\People\RequesterProjection
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 */
class CaseRequesterProjectionListenerTest extends TestCase {

	private const PERSON = 'uuid-person-1';

	private const COMPANY = 'uuid-company-1';

	/**
	 * An entity carrying one case payload.
	 *
	 * @param array<string, mixed> $payload The case.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entity(array $payload): ObjectEntity {
		$payload['@self'] = ['schema' => 'case'];
		$entity = $this->createMock(originalClassName: ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($payload);

		return $entity;
	}//end entity()

	/**
	 * What the listener wrote for this save.
	 *
	 * @param array<string, mixed>      $now    The case as it is being saved.
	 * @param array<string, mixed>|null $before The case as it was, or null on create.
	 *
	 * @return array<string, mixed> The modified data.
	 */
	private function written(array $now, ?array $before = null): array {
		$register = new InMemoryRegister();
		$register->seed(schema: 'brpPerson', uuid: self::PERSON, row: ['citizenServiceNumber' => '999990627', 'name' => ['givenNames' => 'Stephan', 'surname' => 'Janssen']]);
		$register->seed(schema: 'kvkCompany', uuid: self::COMPANY, row: ['kvkNumber' => '24157431', 'tradeName' => 'Visser Bouw B.V.']);

		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($register);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ['case_schema' => 'case', 'register' => 'dossiq'][$key] ?? ''
		);

		$event = new ObjectCreatingEvent($this->entity(payload: $now));
		if ($before !== null) {
			$event = new ObjectUpdatingEvent($this->entity(payload: $now), $this->entity(payload: $before));
		}

		(new CaseRequesterProjectionListener(
			settingsService: $settings,
			projection: new RequesterProjection(settingsService: $settings),
			logger: new NullLogger(),
		))->handle($event);

		return $event->getModifiedData();
	}//end written()

	public function testACaseCreatedWithARequesterIsProjectedInTheSameSave(): void {
		self::assertSame(
			expected: ['initiatorType' => 'person', 'initiatorSourceId' => '999990627', 'initiatorDisplayName' => 'Stephan Janssen'],
			actual: $this->written(now: ['title' => 'Nieuw', 'requester' => self::PERSON]),
		);
	}//end testACaseCreatedWithARequesterIsProjectedInTheSameSave()

	public function testReplacingAPersonByACompanyReplacesTheProjection(): void {
		$person = ['requester' => self::PERSON, 'initiatorType' => 'person', 'initiatorSourceId' => '999990627', 'initiatorDisplayName' => 'Stephan Janssen'];

		self::assertSame(
			expected: ['initiatorType' => 'company', 'initiatorSourceId' => '24157431', 'initiatorDisplayName' => 'Visser Bouw B.V.'],
			actual: $this->written(now: array_merge($person, ['requester' => self::COMPANY]), before: $person),
		);
	}//end testReplacingAPersonByACompanyReplacesTheProjection()

	public function testASaveThatKeepsTheRequesterWritesNothing(): void {
		$person = ['requester' => self::PERSON, 'initiatorType' => 'person', 'initiatorSourceId' => '999990627', 'initiatorDisplayName' => 'Stephan Janssen'];

		self::assertSame(expected: [], actual: $this->written(now: array_merge($person, ['title' => 'Andere titel']), before: $person));
	}//end testASaveThatKeepsTheRequesterWritesNothing()

	public function testAContactRequesterWithoutAUuidKeepsItsProjection(): void {
		self::assertSame(
			expected: [],
			actual: $this->written(now: ['requester' => '', 'initiatorType' => 'contact', 'initiatorDisplayName' => 'Henk']),
		);
	}//end testAContactRequesterWithoutAUuidKeepsItsProjection()

	public function testAnUnresolvableRequesterLeavesTheCaseAsWritten(): void {
		self::assertSame(expected: [], actual: $this->written(now: ['requester' => 'uuid-unknown']));
	}//end testAnUnresolvableRequesterLeavesTheCaseAsWritten()
}//end class
