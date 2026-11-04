<?php

/**
 * Audit trail double for the HearingService golden test
 *
 * Records every entry by parameter name, and fails every entry when told to.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Bezwaar
 *
 * @author    Conduction Development Team <info@conduction.nl>
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

namespace OCA\Dossiq\Tests\Unit\Service\Bezwaar;

use OCA\Dossiq\Service\Bezwaar\BezwaarAuditTrail;
use RuntimeException;

/**
 * Audit trail double that records entries instead of writing them.
 */
class GoldenHearingAuditTrail extends BezwaarAuditTrail {

	/**
	 * Constructor. Skips the parent: nothing here reaches OpenRegister.
	 *
	 * @param bool               $fails Whether every entry fails.
	 * @param array<int, string> $log   Shared log the entries are appended to.
	 */
	public function __construct(
		private readonly bool $fails,
		private array &$log,
	) {
	}//end __construct()

	/**
	 * Record an entry on an existing record.
	 *
	 * @param string               $register   The register.
	 * @param string               $schema     The schema.
	 * @param string               $objectUuid The record.
	 * @param string               $event      Event slug.
	 * @param array<string, mixed> $payload    The payload.
	 * @param string               $tag        The tag.
	 *
	 * @return void
	 */
	public function record(string $register, string $schema, string $objectUuid, string $event, array $payload, string $tag = ''): void {
		$this->note(call: ['record' => compact('register', 'schema', 'objectUuid', 'event', 'payload', 'tag')]);
		$this->failWhenTold(event: $event);
	}//end record()

	/**
	 * Record an entry on a record that was just created.
	 *
	 * @param object               $objectService The object service.
	 * @param string               $register      The register.
	 * @param string               $schema        The schema.
	 * @param string               $objectUuid    The record.
	 * @param string               $event         Event slug.
	 * @param array<string, mixed> $payload       The payload.
	 * @param string               $tag           The tag.
	 *
	 * @return void
	 */
	public function recordForNewRecord(object $objectService, string $register, string $schema, string $objectUuid, string $event, array $payload, string $tag = ''): void {
		$objectService = get_class($objectService);
		$this->note(call: ['recordForNewRecord' => compact('objectService', 'register', 'schema', 'objectUuid', 'event', 'payload', 'tag')]);
		$this->failWhenTold(event: $event);
	}//end recordForNewRecord()

	/**
	 * Record an entry, then apply the change.
	 *
	 * @param string               $register   The register.
	 * @param string               $schema     The schema.
	 * @param string               $objectUuid The record.
	 * @param string               $event      Event slug.
	 * @param array<string, mixed> $payload    The payload.
	 * @param string               $tag        The tag.
	 * @param callable             $apply      The change.
	 *
	 * @return mixed
	 */
	public function recordThenApply(string $register, string $schema, string $objectUuid, string $event, array $payload, string $tag, callable $apply): mixed {
		$this->note(call: ['recordThenApply' => compact('register', 'schema', 'objectUuid', 'event', 'payload', 'tag')]);
		$this->failWhenTold(event: $event);

		return $apply();
	}//end recordThenApply()

	/**
	 * Record a refusal.
	 *
	 * @param string               $register   The register.
	 * @param string               $schema     The schema.
	 * @param string               $objectUuid The record.
	 * @param string               $event      Event slug.
	 * @param array<string, mixed> $payload    The payload.
	 * @param string               $tag        The tag.
	 *
	 * @return bool
	 */
	public function recordRefusal(string $register, string $schema, string $objectUuid, string $event, array $payload, string $tag = ''): bool {
		$this->note(call: ['recordRefusal' => compact('register', 'schema', 'objectUuid', 'event', 'payload', 'tag')]);

		return $this->fails === false;
	}//end recordRefusal()

	/**
	 * Throw when every entry is set to fail.
	 *
	 * @param string $event Event slug.
	 *
	 * @return void
	 */
	private function failWhenTold(string $event): void {
		if ($this->fails === true) {
			throw new RuntimeException('entry not written: ' . $event);
		}
	}//end failWhenTold()

	/**
	 * Append one call to the shared log.
	 *
	 * @param array<string, mixed> $call The call.
	 *
	 * @return void
	 */
	private function note(array $call): void {
		$this->log[] = json_encode(['trail' => $call], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}//end note()
}//end class
