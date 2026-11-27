<?php

/**
 * PortalDrainCalls Unit Tests
 *
 * The case holding a source is found by the source id as its external
 * reference; a case is submitted through the form submit into the case
 * schema as the drain's user, a refusal answered as its findings; a source
 * is marked with its case without its own metadata.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Command;

use OCA\Dossiq\Command\PortalDrainCalls;
use OCA\Dossiq\Command\PortalDrainSetup;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for PortalDrainCalls.
 *
 * @covers \OCA\Dossiq\Command\PortalDrainCalls
 * @uses   \OCA\Dossiq\Command\PortalDrainSetup
 */
final class PortalDrainCallsTest extends TestCase {

	/**
	 * A store that answers the holding case and records saves.
	 *
	 * @return object
	 */
	private function objects(): object {
		return new class {
			/**
			 * The searches, in order.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $searched = [];

			/**
			 * The saves, in order.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $saved = [];

			/**
			 * Answer the case holding source pv-1.
			 *
			 * @param array<string, mixed> $query         The query.
			 * @param bool                 $_rbac         Whether RBAC applies.
			 * @param bool                 $_multitenancy Whether multitenancy applies.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function searchObjects(array $query, bool $_rbac=true, bool $_multitenancy=true): array {
				$this->searched[] = $query;
				if (($query['externalReference'] ?? null) === 'pv-1') {
					return [['id' => 'case-9']];
				}
				return [];
			}

			/**
			 * Record the save.
			 *
			 * @param array<string, mixed> $object        The object.
			 * @param ObjectSaveOptions    $options       Where to save it.
			 * @param bool                 $_rbac         Whether RBAC applies.
			 * @param bool                 $_multitenancy Whether multitenancy applies.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, ObjectSaveOptions $options, bool $_rbac=true, bool $_multitenancy=true): array {
				$this->saved[] = ['object' => $object, 'schema' => $options->target->schema, 'uuid' => $options->uuid];
				return $object;
			}
		};
	}//end objects()

	/**
	 * A form submit that answers a new case, or throws the given refusal.
	 *
	 * @param RuntimeException|null $refusal What the submit throws, if anything.
	 *
	 * @return object
	 */
	private function submitter(?RuntimeException $refusal=null): object {
		return new class($refusal) {
			/**
			 * The submissions, in order.
			 *
			 * @var array<int, object>
			 */
			public array $submits = [];

			/**
			 * Constructor.
			 *
			 * @param RuntimeException|null $refusal What the submit throws.
			 */
			public function __construct(private ?RuntimeException $refusal) {
			}

			/**
			 * Record the submission and answer the case, or refuse.
			 *
			 * @param object      $submission     The submission.
			 * @param string|null $idempotencyKey The key.
			 *
			 * @return array<string, mixed>
			 */
			public function submit(object $submission, ?string $idempotencyKey=null): array {
				$this->submits[] = $submission;
				if ($this->refusal !== null) {
					throw $this->refusal;
				}
				return ['id' => 'case-new'];
			}
		};
	}//end submitter()

	/**
	 * Calls over the given store and submit, as the given admin.
	 *
	 * @param object $objects   The store.
	 * @param object $submitter The form submit.
	 * @param IUser  $admin     The drain's user.
	 *
	 * @return PortalDrainCalls
	 */
	private function calls(object $objects, object $submitter, IUser $admin): PortalDrainCalls {
		return new PortalDrainCalls(
			setup: new PortalDrainSetup(
				objectService: $objects,
				submitter: $submitter,
				admin: $admin,
				register: '3',
				caseSchema: '5',
				sourceSchema: '9'
			),
			deliveredField: 'newCaseId',
		);
	}//end calls()

	/**
	 * The holding case is found by the source id, and a source without an id holds none.
	 *
	 * @return void
	 */
	public function testTheHoldingCaseIsFoundByTheSourceId(): void {
		$objects = $this->objects();
		$existing = $this->calls(objects: $objects, submitter: $this->submitter(), admin: $this->createMock(originalClassName: IUser::class))
			->existingCase();

		$this->assertSame(expected: 'case-9', actual: $existing(['id' => 'pv-1']));
		$this->assertSame(expected: '', actual: $existing(['id' => 'pv-2']));
		$this->assertSame(expected: '', actual: $existing(['id' => ' ']));
		$this->assertCount(expectedCount: 2, haystack: $objects->searched);
	}//end testTheHoldingCaseIsFoundByTheSourceId()

	/**
	 * A case is submitted into the case schema as the drain's user; a refusal is answered as findings.
	 *
	 * @return void
	 */
	public function testACaseIsSubmittedAndARefusalIsItsFindings(): void {
		$admin = $this->createMock(originalClassName: IUser::class);
		$submitter = $this->submitter();
		$submit = $this->calls(objects: $this->objects(), submitter: $submitter, admin: $admin)->submitter();

		$this->assertSame(expected: ['id' => 'case-new'], actual: $submit(['title' => 'Bezwaar']));
		$this->assertSame(expected: [['register' => '3', 'schema' => '5', 'mapping' => null]], actual: $submitter->submits[0]->writes);
		$this->assertSame(expected: ['title' => 'Bezwaar'], actual: $submitter->submits[0]->payload);
		$this->assertSame(expected: $admin, actual: $submitter->submits[0]->subject);

		$refused = $this->calls(
			objects: $this->objects(),
			submitter: $this->submitter(refusal: new RuntimeException('title missing')),
			admin: $admin
		)->submitter();
		$this->assertSame(
			expected: ['findings' => [['property' => '', 'code' => 'refused', 'message' => 'title missing']]],
			actual: $refused(['title' => ''])
		);
	}//end testACaseIsSubmittedAndARefusalIsItsFindings()

	/**
	 * A source is saved back with the case id under the delivered field, without its metadata.
	 *
	 * @return void
	 */
	public function testASourceIsMarkedWithItsCase(): void {
		$objects = $this->objects();
		$mark = $this->calls(objects: $objects, submitter: $this->submitter(), admin: $this->createMock(originalClassName: IUser::class))
			->sourceMarker();

		$mark(['id' => 'pv-1', 'uuid' => 'pv-1', '@self' => ['x' => 1], 'kind' => 'klachtschrift'], 'case-new');

		$this->assertSame(
			expected: [['object' => ['kind' => 'klachtschrift', 'newCaseId' => 'case-new'], 'schema' => '9', 'uuid' => 'pv-1']],
			actual: $objects->saved
		);
	}//end testASourceIsMarkedWithItsCase()
}//end class
