<?php

/**
 * Dossiq ForkedSchemaRetirement.
 *
 * One retirement of the `dossiq.demo` schema fork: for each forked schema
 * it decides whether the schema must be spared (and why) or may be
 * cascade-deleted, through the OpenRegister schema mapper and deletion
 * service, and keeps the counts the repair step reports.
 *
 * @category  Repair
 * @package   OCA\Dossiq\Repair
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\AppInfo\Application;
use Throwable;

/**
 * Retires forked schemas one by one; built by RepairDemoDataSchemaFork for
 * each run.
 *
 * @spec exclude No canonical spec covers repairing the damage of a defective
 *  demo import. Pointing this at the first-time-setup spec would report
 *  conformance to a requirement that says nothing about the fork.
 */
final class ForkedSchemaRetirement {
	/**
	 * OpenRegister's schema mapper.
	 *
	 * @var object
	 */
	public readonly object $schemaMapper;

	/**
	 * OpenRegister's schema deletion service.
	 *
	 * @var object
	 */
	public readonly object $deletion;

	/**
	 * The schema ids any register still links.
	 *
	 * @var array<int, int>
	 */
	public array $linked = [];

	/**
	 * How many forked schemas were retired.
	 *
	 * @var integer
	 */
	public int $retired = 0;

	/**
	 * How many demo objects went with them.
	 *
	 * @var integer
	 */
	public int $objects = 0;

	/**
	 * The schemas kept, by slug, with the reason.
	 *
	 * @var array<string, string>
	 */
	public array $spared = [];

	/**
	 * Start a retirement through the two OpenRegister collaborators.
	 *
	 * @param object $schemaMapper OpenRegister's schema mapper.
	 * @param object $deletion     OpenRegister's schema deletion service.
	 */
	public function __construct(object $schemaMapper, object $deletion) {
		$this->schemaMapper = $schemaMapper;
		$this->deletion = $deletion;
	}//end __construct()

	/**
	 * Retire one forked schema with its objects, or record why it is kept.
	 *
	 * @param object $schema A `dossiq.demo` schema.
	 *
	 * @return void
	 *
	 * @spec exclude No canonical spec covers repairing the damage of a
	 *  defective demo import; see the class docblock.
	 */
	public function retire(object $schema): void {
		$slug = (string)$schema->getSlug();
		$reason = $this->reasonToSpare(schema: $schema);
		if ($reason !== '') {
			$this->spared[$slug] = $reason;
			return;
		}

		try {
			$result = $this->deletion->cascadeDeleteSchema(schema: $schema);
			$this->retired++;
			$this->objects += (int)($result['deletedCount'] ?? 0);
		} catch (Throwable $e) {
			$this->spared[$slug] = 'deletion failed: ' . $e->getMessage();
		}
	}//end retire()

	/**
	 * Say why this forked schema must be kept, or '' when it may go.
	 *
	 * Three separate questions, each with its own answer, because merging them
	 * into one boolean is what makes a report unactionable: an operator needs
	 * to know WHICH of the three held.
	 *
	 * @param object $schema The forked schema.
	 *
	 * @return string The reason to spare it, or '' when every condition is met.
	 */
	private function reasonToSpare(object $schema): string {
		$slug = (string)$schema->getSlug();
		if ($slug === '') {
			return 'it has no slug, so its twin cannot be identified';
		}

		if (in_array((int)$schema->getId(), $this->linked, true) === true) {
			return 'a register still links it, so it is reachable';
		}

		$twins = $this->schemaMapper->findAll(
			filters: [
				'application' => Application::APP_ID,
				'slug' => $slug,
			],
			_rbac: false,
			_multitenancy: false
		);

		if (count($twins) === 0) {
			return 'no schema of that slug exists under `dossiq`, so this may be the only copy';
		}

		if (count($twins) > 1) {
			return 'more than one schema of that slug exists under `dossiq`, so the real home is ambiguous';
		}

		return '';
	}//end reasonToSpare()
}//end class
