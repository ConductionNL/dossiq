<?php

/**
 * Golden record of how two listeners read the object an event carries.
 *
 * `RoleMutationListener` and `ChecklistRunImmutabilityListener` each read the
 * event's object as an array: as it is, or what it serialises to, or what its
 * `toArray()` gives. This records, per event shape, what each one reads.
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

use OCA\Dossiq\Listener\ChecklistRunImmutabilityListener;
use OCA\Dossiq\Listener\RoleMutationListener;
use OCA\Dossiq\Service\RoleResolverService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * An event with configurable getters.
 */
class ExtractionEvent extends Event {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $values What each getter answers.
	 */
	public function __construct(private array $values) {
		parent::__construct();
	}//end __construct()

	/**
	 * The object.
	 *
	 * @return mixed
	 */
	public function getObject(): mixed {
		return ($this->values['getObject'] ?? null);
	}//end getObject()

	/**
	 * The new object.
	 *
	 * @return mixed
	 */
	public function getNewObject(): mixed {
		return ($this->values['getNewObject'] ?? null);
	}//end getNewObject()

	/**
	 * The old object.
	 *
	 * @return mixed
	 */
	public function getOldObject(): mixed {
		return ($this->values['getOldObject'] ?? null);
	}//end getOldObject()
}//end class

/**
 * A value that serialises to something, and may have a toArray().
 */
class ExtractionValue implements \JsonSerializable {

	/**
	 * Constructor.
	 *
	 * @param mixed $serialised What jsonSerialize() answers.
	 * @param mixed $asArray    What toArray() answers.
	 */
	public function __construct(private mixed $serialised, private mixed $asArray) {
	}//end __construct()

	/**
	 * Serialise.
	 *
	 * @return mixed
	 */
	public function jsonSerialize(): mixed {
		return $this->serialised;
	}//end jsonSerialize()

	/**
	 * As an array.
	 *
	 * @return mixed
	 */
	public function toArray(): mixed {
		return $this->asArray;
	}//end toArray()
}//end class

/**
 * A value with only a toArray().
 */
class ExtractionArrayable {

	/**
	 * As an array.
	 *
	 * @return array<string, string>
	 */
	public function toArray(): array {
		return ['from' => 'toArray only'];
	}//end toArray()
}//end class

/**
 * Golden test for the two listeners' object extraction.
 *
 * @covers \OCA\Dossiq\Listener\RoleMutationListener
 * @covers \OCA\Dossiq\Listener\ChecklistRunImmutabilityListener
 * @uses \OCA\Dossiq\Listener\Support\SerialisedObject
 */
class ListenerObjectExtractionGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	/**
	 * Every event shape, recorded in one golden file.
	 *
	 * @return void
	 */
	public function testExtractionAcrossShapes(): void {
		$shapes = [
			'no getters' => new Event(),
			'an array object' => new ExtractionEvent(['getObject' => ['from' => 'object']]),
			'only a new object' => new ExtractionEvent(['getNewObject' => ['from' => 'new']]),
			'only an old object' => new ExtractionEvent(['getOldObject' => ['from' => 'old']]),
			'a serialisable object' => new ExtractionEvent(['getObject' => new ExtractionValue(['from' => 'json'], ['from' => 'toArray'])]),
			'serialising to a string, with toArray' => new ExtractionEvent(['getObject' => new ExtractionValue('text', ['from' => 'toArray'])]),
			'serialising to a string, toArray a string' => new ExtractionEvent(['getObject' => new ExtractionValue('text', 'text'), 'getNewObject' => ['from' => 'new']]),
			'only toArray' => new ExtractionEvent(['getNewObject' => new ExtractionArrayable()]),
			'a scalar object, then an old one' => new ExtractionEvent(['getObject' => 'id-1', 'getOldObject' => ['from' => 'old']]),
		];

		$role = new ReflectionMethod(RoleMutationListener::class, 'extractObject');
		$role->setAccessible(true);
		$checklist = new ReflectionMethod(ChecklistRunImmutabilityListener::class, 'extractObject');
		$checklist->setAccessible(true);
		$roleListener = new RoleMutationListener(
			$this->createMock(RoleResolverService::class),
			$this->createMock(SettingsService::class),
			$this->createMock(LoggerInterface::class),
		);
		$checklistListener = new ChecklistRunImmutabilityListener(
			$this->createMock(SettingsService::class),
			$this->createMock(LoggerInterface::class),
		);

		$record = [];
		foreach ($shapes as $name => $event) {
			$record[$name] = [
				'role' => $role->invoke($roleListener, $event),
				'checklist new' => $checklist->invoke($checklistListener, $event, 'getNewObject'),
				'checklist old' => $checklist->invoke($checklistListener, $event, 'getOldObject'),
			];
		}

		$this->assertMatchesGolden(name: 'listener-object-extraction', value: $record);
	}//end testExtractionAcrossShapes()
}//end class
