<?php

/**
 * DsoIntakeService Unit Tests
 *
 * Characterization tests for the Dossiq DsoIntakeService. These pin the
 * observable behaviour of the live DSO webhook path (`processAanvraag`):
 * the exact ObjectService::saveObject() call sequence — including the
 * ARGUMENT POSITIONS the named arguments bind to — the case-property rows
 * that are written or skipped, and the byte shape of the returned payload
 * that the webhook echoes back to the Omgevingsloket.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/dso-omgevingsloket-client/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\DsoIntakeService;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * ObjectService stub mirroring OpenRegister's real saveObject() signature.
 *
 * The parameter ORDER and NAMES here are load-bearing: they are a verbatim
 * (narrowed) copy of
 * `OCA\OpenRegister\Service\ObjectService::saveObject(array|ObjectEntity $object,
 * ?array $extend = [], Register|string|int|null $register = null,
 * Schema|string|int|null $schema = null, ...)`.
 *
 * Because Dossiq calls saveObject() with NAMED arguments, PHP binds those
 * names against this declaration — so a caller that reverts to the positional
 * `saveObject($register, $schema, $data)` order lands a string in `$object`
 * and a string in `$extend`, which this declaration rejects. That is what
 * makes the argument-order assertions below non-vacuous.
 */
interface DsoIntakeObjectServiceStub {

	/**
	 * Save or update an object.
	 *
	 * @param array $object The object payload.
	 * @param array|null $extend Properties to extend the object with.
	 * @param string|int|null $register The register object or its ID/UUID.
	 * @param string|int|null $schema The schema object or its ID/UUID.
	 *
	 * @return object The saved object.
	 */
	public function saveObject(
		array $object,
		?array $extend = [],
		string|int|null $register = null,
		string|int|null $schema = null,
	): object;
}//end interface

/**
 * Saved-object double exposing only the getUuid() accessor Dossiq uses.
 */
final class DsoIntakeSavedObjectDouble {

	/**
	 * Constructor.
	 *
	 * @param string $uuid The UUID this saved object reports.
	 */
	public function __construct(
		private readonly string $uuid,
	) {
	}//end __construct()

	/**
	 * Get the object UUID.
	 *
	 * @return string The UUID.
	 */
	public function getUuid(): string {
		return $this->uuid;
	}//end getUuid()
}//end class

/**
 * Recording ObjectService fake.
 *
 * A hand-written fake rather than a PHPUnit mock on purpose: PHP itself binds
 * the named arguments against the declared signature, so the recorded call log
 * reflects real parameter binding rather than PHPUnit's argument marshalling.
 */
final class DsoIntakeRecordingObjectService implements DsoIntakeObjectServiceStub {

	/**
	 * Recorded saveObject() invocations, in call order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $calls = [];

	/**
	 * Save or update an object, recording how the arguments bound.
	 *
	 * @param array $object The object payload.
	 * @param array|null $extend Properties to extend the object with.
	 * @param string|int|null $register The register slug.
	 * @param string|int|null $schema The schema slug.
	 *
	 * @return object The saved object double.
	 */
	public function saveObject(
		array $object,
		?array $extend = [],
		string|int|null $register = null,
		string|int|null $schema = null,
	): object {
		$this->calls[] = [
			'object' => $object,
			'extend' => $extend,
			'register' => $register,
			'schema' => $schema,
		];

		return new DsoIntakeSavedObjectDouble('case-uuid-' . count($this->calls));
	}//end saveObject()
}//end class

/**
 * Characterization tests for DsoIntakeService.
 *
 * @covers \OCA\Dossiq\Service\DsoIntakeService
 */
class DsoIntakeServiceTest extends TestCase {

	/**
	 * Mocked SettingsService.
	 *
	 * @var SettingsService|MockObject
	 */
	private SettingsService $settings;

	/**
	 * Mocked LoggerInterface.
	 *
	 * @var LoggerInterface|MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Service under test.
	 *
	 * @var DsoIntakeService
	 */
	private DsoIntakeService $service;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settings = $this->createMock(SettingsService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->service = new DsoIntakeService(
			settingsService: $this->settings,
			logger: $this->logger,
		);
	}//end setUp()

	/**
	 * Wire the mocked SettingsService to a recording ObjectService.
	 *
	 * @param string $register The configured register slug.
	 * @param string $caseSchema The configured case schema slug.
	 * @param string $propertySchema The configured case property schema slug.
	 *
	 * @return DsoIntakeRecordingObjectService The recorder handed to the service.
	 */
	private function wireObjectService(
		string $register = 'procest',
		string $caseSchema = 'case',
		string $propertySchema = 'zaakeigenschap',
	): DsoIntakeRecordingObjectService {
		$recorder = new DsoIntakeRecordingObjectService();
		$this->settings->method('getObjectService')->willReturn($recorder);
		$this->settings->method('getConfigValue')->willReturnMap(
			[
				['register', $register],
				['case_schema', $caseSchema],
				['case_property_schema', $propertySchema],
			]
		);

		return $recorder;
	}//end wireObjectService()

	/**
	 * A representative full DSO vergunningaanvraag payload.
	 *
	 * @return array<string, mixed> The payload.
	 */
	private function fullPayload(): array {
		return [
			'zaaknummer' => 'DSO-2026-0042',
			'activiteiten' => [
				['name' => 'Bouwactiviteit'],
				['name' => 'Kapactiviteit'],
			],
			'location' => 'Dorpsstraat 1, Utrecht',
			'applicant' => ['name' => 'Jan de Vries'],
			'bouwkosten' => 125000,
			'procedureType' => 'uitgebreid',
		];
	}//end fullPayload()

	/**
	 * processAanvraag throws when OpenRegister is unavailable.
	 *
	 * @return void
	 */
	public function testProcessAanvraagThrowsWhenObjectServiceUnavailable(): void {
		$this->settings->method('getObjectService')->willReturn(null);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('OpenRegister is not available');

		$this->service->processAanvraag(dsoMessage: $this->fullPayload());
	}//end testProcessAanvraagThrowsWhenObjectServiceUnavailable()

	/**
	 * processAanvraag throws when the register is not configured.
	 *
	 * @return void
	 */
	public function testProcessAanvraagThrowsWhenRegisterNotConfigured(): void {
		$this->wireObjectService(register: '');

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Dossiq register not configured');

		$this->service->processAanvraag(dsoMessage: $this->fullPayload());
	}//end testProcessAanvraagThrowsWhenRegisterNotConfigured()

	/**
	 * The case object is saved first, with register/schema in the named slots.
	 *
	 * This pins the ARGUMENT BINDING, not just the values: `$object` must
	 * receive the case payload, `$extend` must stay at its `[]` default, and
	 * the register/schema slugs must land in `$register` / `$schema`.
	 *
	 * @return void
	 */
	public function testProcessAanvraagSavesCaseWithNamedArgumentBinding(): void {
		$recorder = $this->wireObjectService();

		$this->service->processAanvraag(dsoMessage: $this->fullPayload());

		$this->assertSame(
			[
				'object' => [
					'title' => 'Omgevingsvergunning: Bouwactiviteit, Kapactiviteit',
					'description' => 'Vergunningaanvraag ontvangen via DSO/Omgevingsloket (DSO: DSO-2026-0042)',
					'startDate' => date('Y-m-d'),
					'priority' => 'normal',
				],
				'extend' => [],
				'register' => 'procest',
				'schema' => 'case',
			],
			$recorder->calls[0]
		);
	}//end testProcessAanvraagSavesCaseWithNamedArgumentBinding()

	/**
	 * Every non-empty DSO property is written as its own case-property object.
	 *
	 * @return void
	 */
	public function testProcessAanvraagWritesCasePropertyRowsInOrder(): void {
		$recorder = $this->wireObjectService();

		$this->service->processAanvraag(dsoMessage: $this->fullPayload());

		$this->assertCount(7, $recorder->calls);

		$expected = [
			['dsoZaaknummer', 'DSO-2026-0042'],
			['activiteiten', 'Bouwactiviteit, Kapactiviteit'],
			['location', 'Dorpsstraat 1, Utrecht'],
			['bouwkosten', '125000'],
			['procedureType', 'uitgebreid'],
			['aanvragerNaam', 'Jan de Vries'],
		];

		$properties = array_slice($recorder->calls, 1);

		foreach ($expected as $index => [$name, $value]) {
			$this->assertSame(
				[
					'object' => [
						'case' => 'case-uuid-1',
						'name' => $name,
						'value' => $value,
					],
					'extend' => [],
					'register' => 'procest',
					'schema' => 'zaakeigenschap',
				],
				$properties[$index],
				'case property #' . $index . ' (' . $name . ')'
			);
		}
	}//end testProcessAanvraagWritesCasePropertyRowsInOrder()

	/**
	 * Empty property values are skipped rather than written as blank rows.
	 *
	 * An empty payload still writes bouwkosten ("0" — the numeric default
	 * stringifies to a non-empty value) and the defaulted procedureType.
	 *
	 * @return void
	 */
	public function testProcessAanvraagSkipsEmptyPropertyValues(): void {
		$recorder = $this->wireObjectService();

		$this->service->processAanvraag(dsoMessage: []);

		$this->assertCount(3, $recorder->calls);
		$this->assertSame(
			[
				['case' => 'case-uuid-1', 'name' => 'bouwkosten', 'value' => '0'],
				['case' => 'case-uuid-1', 'name' => 'procedureType', 'value' => 'regulier'],
			],
			[$recorder->calls[1]['object'], $recorder->calls[2]['object']]
		);
	}//end testProcessAanvraagSkipsEmptyPropertyValues()

	/**
	 * An empty payload still produces the bare title and description.
	 *
	 * @return void
	 */
	public function testProcessAanvraagBuildsBareTitleWithoutActivities(): void {
		$recorder = $this->wireObjectService();

		$this->service->processAanvraag(dsoMessage: []);

		$this->assertSame(
			[
				'title' => 'Omgevingsvergunning',
				'description' => 'Vergunningaanvraag ontvangen via DSO/Omgevingsloket',
				'startDate' => date('Y-m-d'),
				'priority' => 'normal',
			],
			$recorder->calls[0]['object']
		);
	}//end testProcessAanvraagBuildsBareTitleWithoutActivities()

	/**
	 * A structured locatie object is stored JSON-encoded.
	 *
	 * @return void
	 */
	public function testProcessAanvraagJsonEncodesStructuredLocatie(): void {
		$recorder = $this->wireObjectService();

		$this->service->processAanvraag(
			dsoMessage: [
				'location' => [
					'street' => 'Dorpsstraat',
					'houseNumber' => 1,
				],
			]
		);

		$locationRows = array_values(
			array_filter(
				$recorder->calls,
				static fn (array $call): bool => ($call['object']['name'] ?? '') === 'location'
			)
		);

		$this->assertCount(1, $locationRows);
		$this->assertSame(
			'{"street":"Dorpsstraat","houseNumber":1}',
			$locationRows[0]['object']['value']
		);
	}//end testProcessAanvraagJsonEncodesStructuredLocatie()

	/**
	 * Scalar activiteiten entries are used verbatim as activity names.
	 *
	 * @return void
	 */
	public function testProcessAanvraagAcceptsScalarActiviteiten(): void {
		$recorder = $this->wireObjectService();

		$result = $this->service->processAanvraag(
			dsoMessage: ['activiteiten' => ['Sloopactiviteit', 'Milieubelastende activiteit']]
		);

		$this->assertSame(
			'Omgevingsvergunning: Sloopactiviteit, Milieubelastende activiteit',
			$recorder->calls[0]['object']['title']
		);
		$this->assertSame(
			['Sloopactiviteit', 'Milieubelastende activiteit'],
			$result['activiteiten']
		);
	}//end testProcessAanvraagAcceptsScalarActiviteiten()

	/**
	 * The webhook response body keeps its exact five-key shape.
	 *
	 * This array is serialised straight into the 201 JSONResponse the
	 * Omgevingsloket receives, so it is an external contract.
	 *
	 * @return void
	 */
	public function testProcessAanvraagReturnsWebhookResponseShape(): void {
		$this->wireObjectService();

		$result = $this->service->processAanvraag(dsoMessage: $this->fullPayload());

		$this->assertSame(
			[
				'caseId' => 'case-uuid-1',
				'dsoZaaknummer' => 'DSO-2026-0042',
				'activiteiten' => ['Bouwactiviteit', 'Kapactiviteit'],
				'procedureType' => 'uitgebreid',
				'deadline' => 'P182D',
			],
			$result
		);
	}//end testProcessAanvraagReturnsWebhookResponseShape()

	/**
	 * The regulier deadline is the default for a missing or unknown type.
	 *
	 * @param string|null $procedureType The procedure type in the payload.
	 * @param string $expectedDeadline The ISO 8601 duration expected back.
	 *
	 * @dataProvider deadlineProvider
	 *
	 * @return void
	 */
	public function testProcessAanvraagResolvesDeadlinePerProcedureType(
		?string $procedureType,
		string $expectedDeadline,
	): void {
		$this->wireObjectService();

		$payload = [];
		if ($procedureType !== null) {
			$payload['procedureType'] = $procedureType;
		}

		$result = $this->service->processAanvraag(dsoMessage: $payload);

		$this->assertSame($expectedDeadline, $result['deadline']);
	}//end testProcessAanvraagResolvesDeadlinePerProcedureType()

	/**
	 * Procedure type to expected deadline duration.
	 *
	 * @return array<string, array{0: string|null, 1: string}>
	 */
	public static function deadlineProvider(): array {
		return [
			'missing type falls back to regulier' => [null, 'P56D'],
			'regulier' => ['regulier', 'P56D'],
			'uitgebreid' => ['uitgebreid', 'P182D'],
			'unknown type falls back to regulier' => ['unknown', 'P56D'],
		];
	}//end deadlineProvider()

	/**
	 * Intake is logged once, naming the created case and the DSO reference.
	 *
	 * @return void
	 */
	public function testProcessAanvraagLogsTheCreatedCase(): void {
		$this->wireObjectService();

		$this->logger->expects($this->once())
			->method('info')
			->with(
				'DSO intake processed: case case-uuid-1 (DSO: DSO-2026-0042)',
				['app' => 'dossiq']
			);

		$this->service->processAanvraag(dsoMessage: $this->fullPayload());
	}//end testProcessAanvraagLogsTheCreatedCase()

	/**
	 * getDeadlineDuration exposes the same table the intake path uses.
	 *
	 * @return void
	 */
	public function testGetDeadlineDurationMatchesIntakeDeadlines(): void {
		$this->assertSame('P56D', $this->service->getDeadlineDuration('regulier'));
		$this->assertSame('P182D', $this->service->getDeadlineDuration('uitgebreid'));
		$this->assertSame('P56D', $this->service->getDeadlineDuration('unknown'));
	}//end testGetDeadlineDurationMatchesIntakeDeadlines()
}//end class
