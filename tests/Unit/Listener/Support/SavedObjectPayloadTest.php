<?php

/**
 * Tests for reading a saved object's array off an event value.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener\Support;

use JsonSerializable;
use OCA\Dossiq\Listener\Support\SavedObjectPayload;
use PHPUnit\Framework\TestCase;

/**
 * An array or a serialisable entity becomes the object's array with a top-level id; anything else is nothing.
 *
 * @covers \OCA\Dossiq\Listener\Support\SavedObjectPayload
 *
 * @spec openspec/changes/termijnbewaking-op-engine-timers/specs/termijnbewaking-op-engine-timers/spec.md
 */
class SavedObjectPayloadTest extends TestCase {

	/**
	 * A host for the trait.
	 *
	 * @return object
	 */
	private function host(): object {
		return new class {
			use SavedObjectPayload;

			/**
			 * The object array of an entity.
			 *
			 * @param mixed $entity The entity.
			 *
			 * @return array<string, mixed>|null
			 */
			public function arrayOf(mixed $entity): ?array {
				return $this->objectArray(entity: $entity);
			}//end arrayOf()
		};
	}//end host()

	/**
	 * A serialisable entity.
	 *
	 * @param mixed $data What it serialises to.
	 *
	 * @return JsonSerializable
	 */
	private function entity(mixed $data): JsonSerializable {
		return new class ($data) implements JsonSerializable {
			/**
			 * Constructor.
			 *
			 * @param mixed $data What it serialises to.
			 */
			public function __construct(private readonly mixed $data) {
			}

			/**
			 * Serialise.
			 *
			 * @return mixed
			 */
			public function jsonSerialize(): mixed {
				return $this->data;
			}
		};
	}//end entity()

	/**
	 * Every shape answers as it always did.
	 *
	 * @return void
	 */
	public function testEveryShapeBecomesItsArrayOrNothing(): void {
		$host = $this->host();

		$this->assertSame(['a' => 1, 'id' => ''], $host->arrayOf(['a' => 1]));
		$this->assertSame(['id' => 'x-1'], $host->arrayOf(['id' => 'x-1']));
		$this->assertSame(['@self' => ['id' => 's-1'], 'id' => 's-1'], $host->arrayOf(['@self' => ['id' => 's-1']]));
		$this->assertSame(['id' => '7', '@self' => ['id' => 's-1']], $host->arrayOf(['id' => 7, '@self' => ['id' => 's-1']]));
		$this->assertSame(['b' => 2, 'id' => 'e-1'], $host->arrayOf($this->entity(['b' => 2, 'id' => 'e-1'])));
		$this->assertNull($host->arrayOf($this->entity('not an array')));
		$this->assertNull($host->arrayOf(new \stdClass()));
		$this->assertNull($host->arrayOf('a string'));
		$this->assertNull($host->arrayOf(null));
	}//end testEveryShapeBecomesItsArrayOrNothing()
}//end class
