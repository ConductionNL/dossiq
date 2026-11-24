<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/supervision-plan-page/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use DateTimeImmutable;
use OCA\Dossiq\Controller\SupervisionPlanController;
use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Service\Inspection\SupervisionPlanProgress;
use OCA\Dossiq\Service\Inspection\SupervisionScheduleProposal;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The plan's figures and its proposal are read in the reader's scope and write nothing.
 */
class SupervisionPlanControllerTest extends TestCase {

	/**
	 * A register double: one plan, two categories, inspections per category; it records every call.
	 *
	 * @param bool $broken Whether every read throws.
	 *
	 * @return object The double.
	 */
	private function objectService(bool $broken=false): object {
		return new class($broken) {
			/**
			 * The calls, as [method, schema, filters, scope].
			 *
			 * @var list<array<int, mixed>>
			 */
			public array $calls = [];

			/**
			 * Constructor.
			 *
			 * @param bool $broken Whether every read throws.
			 */
			public function __construct(private readonly bool $broken) {
			}

			/**
			 * Find one object.
			 *
			 * @param string $id      The uuid.
			 * @param mixed  $options The options.
			 *
			 * @return array<string, mixed>|null The plan.
			 */
			public function find(string $id, mixed $options=null): ?array {
				$this->calls[] = ['find', $id];
				if ($this->broken === true) {
					throw new RuntimeException('down');
				}

				return ($id === 'plan-2026') ? ['id' => 'plan-2026', 'year' => 2026, 'capacityHours' => 1600] : null;
			}

			/**
			 * Search a schema by slug.
			 *
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param array<string, mixed> $filters  The filters.
			 * @param mixed                ...$scope The scope flags.
			 *
			 * @return list<array<string, mixed>> The rows.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters=[], mixed ...$scope): array {
				$this->calls[] = ['search', $schema, $filters, $scope];
				if ($schema === 'supervisionPlanCategory') {
					return [
						['id' => 'hos', 'name' => 'Hospitality', 'planned' => 40, 'order' => 1, 'hoursPerInspection' => 6],
						['id' => 'con', 'name' => 'Construction', 'planned' => 60, 'order' => 2, 'hoursPerInspection' => 12],
					];
				}

				if ($schema === 'fieldInspection' && ($filters['planCategory'] ?? '') === 'hos') {
					return [
						['planCategory' => 'hos', 'scheduledAt' => '2026-03-02', 'completedAt' => '2026-03-02'],
						['planCategory' => 'hos', 'scheduledAt' => '2026-04-02', 'status' => 'planned'],
					];
				}

				return [];
			}
		};
	}//end objectService()

	/**
	 * Build the controller.
	 *
	 * @param object|null $objectService The register double, or null when OpenRegister is absent.
	 * @param bool        $signedIn      Whether a user is signed in.
	 * @param string      $from          The `from` parameter.
	 * @param bool        $configured    Whether the schemas are configured.
	 *
	 * @return SupervisionPlanController The controller.
	 */
	private function controller(?object $objectService, bool $signedIn=true, string $from='', bool $configured=true): SupervisionPlanController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default=null): mixed => ($key === 'from' ? $from : $default));

		$settings = $this->createMock(SettingsServiceInterface::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$config = [
			'register'                         => 'dossiq',
			'supervision_plan_schema'          => 'supervisionPlan',
			'supervision_plan_category_schema' => 'supervisionPlanCategory',
			'field_inspection_schema'          => ($configured === true) ? 'fieldInspection' : '',
		];
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => ($config[$key] ?? ''));

		$time = $this->createMock(ITimeFactory::class);
		$time->method('now')->willReturn(new DateTimeImmutable('2026-11-24T10:00:00+01:00'));

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(($signedIn === true) ? $this->createMock(IUser::class) : null);

		return new SupervisionPlanController(
			$request,
			$settings,
			new SupervisionPlanProgress(),
			new SupervisionScheduleProposal(),
			$time,
			$session,
			$this->createMock(LoggerInterface::class)
		);
	}//end controller()

	/**
	 * The figures count the categories' inspections, searched per category in the reader's scope.
	 *
	 * @return void
	 */
	public function testProgressCountsTheInspectionsOfTheCategories(): void {
		$objects  = $this->objectService();
		$response = $this->controller(objectService: $objects)->progress(id: 'plan-2026');
		$data     = $response->getData();

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: 100, actual: $data['scheduled']);
		$this->assertSame(expected: 1, actual: $data['completed']);
		$this->assertSame(expected: ['1 of 40', '0 of 60'], actual: array_column($data['categories'], 'label'));

		$searches = array_values(array_filter($objects->calls, static fn (array $call): bool => $call[0] === 'search'));
		$this->assertSame(expected: ['supervisionPlanCategory', ['plan' => 'plan-2026'], []], actual: array_slice($searches[0], 1));
		$this->assertSame(expected: ['fieldInspection', ['planCategory' => 'hos'], []], actual: array_slice($searches[1], 1));
		$this->assertSame(expected: ['fieldInspection', ['planCategory' => 'con'], []], actual: array_slice($searches[2], 1));
	}//end testProgressCountsTheInspectionsOfTheCategories()

	/**
	 * The proposal starts today when no day is given, and says nothing was written.
	 *
	 * @return void
	 */
	public function testTheProposalStartsTodayAndWritesNothing(): void {
		$objects  = $this->objectService();
		$response = $this->controller(objectService: $objects)->scheduleProposal(id: 'plan-2026');
		$data     = $response->getData();

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertFalse(condition: $data['written']);
		$this->assertSame(expected: '2026-11-24', actual: $data['categories'][0]['visits'][0]['date']);
		$this->assertSame(expected: [], actual: array_filter($objects->calls, static fn (array $call): bool => in_array($call[0], ['saveObject', 'createFromArray', 'updateFromArray'], true)));
	}//end testTheProposalStartsTodayAndWritesNothing()

	/**
	 * A `from` day is honoured; one that is not a date falls back to today.
	 *
	 * @return void
	 */
	public function testTheProposalHonoursAValidFromDay(): void {
		$given = $this->controller(objectService: $this->objectService(), from: '2026-01-05')->scheduleProposal(id: 'plan-2026')->getData();
		$wrong = $this->controller(objectService: $this->objectService(), from: '2026-02-30')->scheduleProposal(id: 'plan-2026')->getData();

		$this->assertSame(expected: '2026-01-05', actual: $given['categories'][0]['visits'][0]['date']);
		$this->assertSame(expected: '2026-11-24', actual: $wrong['categories'][0]['visits'][0]['date']);
	}//end testTheProposalHonoursAValidFromDay()

	/**
	 * Signed out is 401, an unknown plan 404, no register or an unmapped schema 503, a broken read 500.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $this->controller(objectService: $this->objectService(), signedIn: false)->progress(id: 'plan-2026')->getStatus());
		$this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $this->controller(objectService: $this->objectService())->progress(id: 'nope')->getStatus());
		$this->assertSame(expected: Http::STATUS_SERVICE_UNAVAILABLE, actual: $this->controller(objectService: null)->scheduleProposal(id: 'plan-2026')->getStatus());
		$this->assertSame(expected: Http::STATUS_SERVICE_UNAVAILABLE, actual: $this->controller(objectService: $this->objectService(), configured: false)->progress(id: 'plan-2026')->getStatus());
		$this->assertSame(expected: Http::STATUS_INTERNAL_SERVER_ERROR, actual: $this->controller(objectService: $this->objectService(broken: true))->progress(id: 'plan-2026')->getStatus());
	}//end testRefusals()
}//end class
