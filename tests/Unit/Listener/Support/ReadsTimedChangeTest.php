<?php

/**
 * ReadsTimedChange — the saved object when a timed field changed.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener\Support;

use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Listener\Support\ReadsTimedChange;
use OCA\Dossiq\Listener\Support\SavedObjectPayload;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * In schema and changed, outside the schema, unchanged, and no object.
 *
 * @covers \OCA\Dossiq\Listener\Support\ReadsTimedChange
 */
final class ReadsTimedChangeTest extends TestCase {

	/**
	 * The trait's answer for an event carrying the given objects.
	 *
	 * @param array<string, mixed>|null $saved    The saved object.
	 * @param array<string, mixed>|null $previous The previous object.
	 *
	 * @return array<string, mixed>|null
	 */
	private function changeOf(?array $saved, ?array $previous): ?array {
		$settings = $this->createMock(SettingsServiceInterface::class);
		$settings->method('getConfigValue')->willReturnMap([['timed_schema', '', 'timed']]);
		$event = new class($saved, $previous) extends Event {
			/**
			 * Constructor.
			 *
			 * @param array<string, mixed>|null $saved    The saved object.
			 * @param array<string, mixed>|null $previous The previous object.
			 */
			public function __construct(private readonly ?array $saved, private readonly ?array $previous) {
				parent::__construct();
			}

			/**
			 * The saved object.
			 *
			 * @return array<string, mixed>|null
			 */
			public function getObject(): ?array {
				return $this->saved;
			}

			/**
			 * The previous object.
			 *
			 * @return array<string, mixed>|null
			 */
			public function getOldObject(): ?array {
				return $this->previous;
			}
		};
		$subject = new class($settings) {
			use SavedObjectPayload;
			use ReadsTimedChange {
				timedChange as public;
			}

			private const TIMED_FIELDS = ['deadline'];

			/**
			 * Constructor.
			 *
			 * @param SettingsServiceInterface $settingsService The settings.
			 */
			public function __construct(private readonly SettingsServiceInterface $settingsService) {
			}
		};

		return $subject->timedChange($event, 'timed_schema');
	}//end changeOf()

	/**
	 * A changed timed field in the schema answers the saved object.
	 *
	 * @return void
	 */
	public function testAnswersAChangedObjectInTheSchema(): void {
		$saved = ['id' => 'a', 'schema' => 'timed', 'deadline' => '2026-12-01'];

		self::assertSame($saved, $this->changeOf($saved, ['id' => 'a', 'schema' => 'timed', 'deadline' => '2026-11-01']));
		self::assertSame($saved, $this->changeOf($saved, null));
	}//end testAnswersAChangedObjectInTheSchema()

	/**
	 * No object, another schema or an unchanged timed field answers null.
	 *
	 * @return void
	 */
	public function testAnswersNullOtherwise(): void {
		$saved = ['id' => 'a', 'schema' => 'timed', 'deadline' => '2026-12-01'];

		self::assertNull($this->changeOf(null, null));
		self::assertNull($this->changeOf(['id' => 'a', 'schema' => 'other', 'deadline' => 'x'], null));
		self::assertNull($this->changeOf($saved, $saved + ['note' => 'only this moved']));
	}//end testAnswersNullOtherwise()
}//end class
