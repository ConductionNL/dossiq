<?php
/**
 * A recording stand-in for OpenRegister's ObjectService, for the email
 * repository tests: it answers find, search and save from canned values and
 * records every call.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Email
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Email;

use Throwable;

/**
 * Records calls; answers from the canned values.
 */
class RecordingObjectService {

	/**
	 * The calls, as [method, arguments].
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	public array $calls = [];

	/**
	 * Constructor.
	 *
	 * @param mixed          $found    What find() answers.
	 * @param mixed          $searched What searchObjectsBySlug() answers.
	 * @param mixed          $saved    What saveObject() answers.
	 * @param Throwable|null $findFails Thrown by find() when set.
	 */
	public function __construct(
		private readonly mixed $found = null,
		private readonly mixed $searched = [],
		private readonly mixed $saved = null,
		private readonly ?Throwable $findFails = null,
	) {
	}//end __construct()

	/**
	 * Find one object.
	 *
	 * @param string     $id       The id.
	 * @param int|string $register The register.
	 * @param int|string $schema   The schema.
	 *
	 * @return mixed The canned object.
	 */
	public function find(string $id, int|string $register = '', int|string $schema = ''): mixed {
		$this->calls[] = ['find', ['id' => $id, 'register' => $register, 'schema' => $schema]];
		if ($this->findFails !== null) {
			throw $this->findFails;
		}

		return $this->found;
	}//end find()

	/**
	 * Search by slugs.
	 *
	 * @param string               $registerSlug The register slug.
	 * @param string               $schemaSlug   The schema slug.
	 * @param array<string, mixed> $filters      The filters.
	 *
	 * @return mixed The canned rows.
	 */
	public function searchObjectsBySlug(string $registerSlug, string $schemaSlug, array $filters = []): mixed {
		$this->calls[] = ['search', ['register' => $registerSlug, 'schema' => $schemaSlug, 'filters' => $filters]];
		return $this->searched;
	}//end searchObjectsBySlug()

	/**
	 * Save an object.
	 *
	 * @param array<string, mixed> $object   The object.
	 * @param int|string           $register The register.
	 * @param int|string           $schema   The schema.
	 *
	 * @return mixed The canned saved object.
	 */
	public function saveObject(array $object, int|string $register = '', int|string $schema = ''): mixed {
		$this->calls[] = ['save', ['object' => $object, 'register' => $register, 'schema' => $schema]];
		return $this->saved;
	}//end saveObject()
}//end class
