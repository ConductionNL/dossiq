<?php

/**
 * A capture made at a site reaches the record.
 *
 * `EvidenceMetadataService` and `TranscriptionService` shipped with
 * mobiel-inspectie-offline, both with their own green suites, and nothing
 * called either. There was no endpoint a device could post to, so a photo or a
 * voice memo taken at an inspection reached nobody, and the `fieldEvidence`
 * admin page reads a schema no writer has ever touched.
 *
 * 🔴 THE FIXTURES SPEAK THE DECLARED SHAPE. Writing them from
 * `lib/Settings/register.d/40-mobiel-inspectie-offline.json` rather than from
 * the implementation is what exposed the three reasons this could never have
 * worked: `field_evidence_schema` is a config key nothing configured, so
 * `TranscriptionService::persist()` returned early and wrote nothing at all;
 * `transcriptionStatus` declared the syncQueue's `syncing`/`synced` while the
 * service writes `queued`/`running`/`done`/`manual`; and the attempt count the
 * retry limit reads was never a declared property, so it would have been
 * dropped on every write and read back as zero.
 *
 * MUTATION-CHECKED 2026-09-18, five of them, each reddening the named test:
 *   - dropping the `hasCaseMutationAccess` branch reddens
 *     testSomebodyElsesInspectionIsRefused;
 *   - not calling `queueTranscriptionIfSpoken()` reddens
 *     testAVoiceMemoIsQueuedForTranscription;
 *   - taking the uuid back off `TranscriptionService::persist()` reddens the
 *     "ONE MEMO IS ONE RECORD" assertion in that same test;
 *   - writing the gpsLocation block unconditionally reddens
 *     testACaptureWithNoPositionCarriesNone;
 *   - putting `syncing`/`synced` back in the enum reddens
 *     testTheSchemaDeclaresEveryStatusTheServiceWrites.
 * Restored after each.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#task-8
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\FieldEvidenceController;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\EvidenceMetadataService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\TranscriptionService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The door onto the evidence validator and the transcription queue.
 *
 * @covers \OCA\Dossiq\Controller\FieldEvidenceController
 * @uses \OCA\Dossiq\Service\EvidenceMetadataService
 * @uses \OCA\Dossiq\Service\TranscriptionService
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 *
 * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#task-8
 */
class FieldEvidenceIsReachableTest extends TestCase {

	/**
	 * The store the controller reads and writes.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * One inspection on a case, with a position of its own.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryRegister();
		$this->store->seed(
			schema: 'fieldInspection',
			uuid: 'inspection-1',
			row: [
				'id' => 'inspection-1',
				'caseRef' => 'case-1',
				'inspectorRef' => 'inspecteur',
				'status' => 'in_progress',
				'gpsLocation' => ['lat' => 52.09, 'lon' => 5.12],
			],
		);
	}//end setUp()

	/**
	 * A photo taken at the site lands on the inspection.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#task-8
	 */
	public function testAPhotoLandsOnTheInspection(): void {
		$response = $this->controller(
			params: [
				'type' => 'photo',
				'localBlobRef' => 'idb://capture-1',
				'byteSize' => 900000,
				'gpsLocation' => ['lat' => 52.1601, 'lon' => 4.4970, 'accuracy' => 8.0],
			]
		)->capture(inspectionRef: 'inspection-1');

		self::assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());

		$stored = $this->store->all(schema: 'fieldEvidence');
		self::assertCount(expectedCount: 1, haystack: $stored, message: 'The capture reached the record.');
		self::assertSame(expected: 'inspection-1', actual: $stored[0]['inspectionRef']);
		self::assertSame(expected: 52.1601, actual: $stored[0]['gpsLocation']['lat']);
		self::assertSame(
			expected: 'not_applicable',
			actual: $stored[0]['transcriptionStatus'],
			message: 'A photo is not waiting to be transcribed.',
		);
	}//end testAPhotoLandsOnTheInspection()

	/**
	 * A photo over the compression target is refused, and nothing is stored.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#task-8
	 */
	public function testAPhotoOverTheTargetIsRefused(): void {
		$response = $this->controller(
			params: ['type' => 'photo', 'byteSize' => (3 * 1024 * 1024)]
		)->capture(inspectionRef: 'inspection-1');

		self::assertSame(expected: Http::STATUS_UNPROCESSABLE_ENTITY, actual: $response->getStatus());
		self::assertStringContainsString(
			needle: '2 MB',
			haystack: (string)$response->getData()['error'],
			message: 'The validator\'s own sentence travels: a photo too big and a memo too long are different things to fix.',
		);
		self::assertSame(expected: [], actual: $this->store->all(schema: 'fieldEvidence'));
	}//end testAPhotoOverTheTargetIsRefused()

	/**
	 * A voice memo is queued for transcription, and the queue wrote it down.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#Task-9
	 */
	public function testAVoiceMemoIsQueuedForTranscription(): void {
		$response = $this->controller(
			params: ['type' => 'voice_memo', 'localBlobRef' => 'idb://memo-1', 'durationSeconds' => 45]
		)->capture(inspectionRef: 'inspection-1');

		self::assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		self::assertSame(
			expected: TranscriptionService::STATUS_QUEUED,
			actual: $response->getData()['transcriptionStatus'],
			message: 'A memo nobody queued is a memo nobody transcribes.',
		);

		$stored = $this->store->all(schema: 'fieldEvidence');
		self::assertCount(
			expectedCount: 1,
			haystack: $stored,
			message: 'ONE MEMO IS ONE RECORD. persist() passed no uuid, so each transition created '
				. 'another row and the inspection showed four pieces of evidence where an inspector recorded one.',
		);
		self::assertSame(
			expected: TranscriptionService::STATUS_QUEUED,
			actual: $stored[0]['transcriptionStatus'],
			message: 'And the queue persisted it, which it could not do while field_evidence_schema resolved to nothing.',
		);
		self::assertSame(
			expected: 'queued',
			actual: $stored[0]['transcriptionStatus'],
			message: 'The literal, because the schema has to declare the value the constant holds.',
		);
	}//end testAVoiceMemoIsQueuedForTranscription()

	/**
	 * Every status the services write is a value the schema declares.
	 *
	 * The schema declared `pending`, `syncing`, `synced`, `failed` and
	 * `not_applicable`, which is the syncQueue's vocabulary with a default
	 * appended: it answers whether a row reached the server, not whether
	 * anybody has transcribed the memo. Four of the six values the service
	 * writes were undeclared, so an enum nothing could satisfy sat in front of
	 * a service nothing called.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#Task-9
	 */
	public function testTheSchemaDeclaresEveryStatusTheServiceWrites(): void {
		$declared = json_decode(
			json: (string)file_get_contents(
				__DIR__ . '/../../../lib/Settings/register.d/40-mobiel-inspectie-offline.json'
			),
			associative: true,
		);
		$enum = $declared['components']['schemas']['fieldEvidence']['properties']['transcriptionStatus']['enum'];

		foreach (
			[
				TranscriptionService::STATUS_PENDING,
				TranscriptionService::STATUS_QUEUED,
				TranscriptionService::STATUS_RUNNING,
				TranscriptionService::STATUS_DONE,
				TranscriptionService::STATUS_FAILED,
				TranscriptionService::STATUS_FALLBACK,
			] as $status
		) {
			self::assertContains(
				needle: $status,
				haystack: $enum,
				message: 'An undeclared value is dropped in silence, so the record would stay on its last declared status for ever.',
			);
		}

		$properties = $declared['components']['schemas']['fieldEvidence']['properties'];
		self::assertArrayHasKey(
			key: 'transcriptionAttempts',
			array: $properties,
			message: 'The retry limit reads this count. Undeclared, it read back as zero and the memo retried for ever.',
		);
		self::assertArrayHasKey(key: 'durationSeconds', array: $properties);
	}//end testTheSchemaDeclaresEveryStatusTheServiceWrites()

	/**
	 * A capture with no fix and an inspection with no position carries no location.
	 *
	 * `classifyGps()` answers `lat: null` in that case, and the payload used to
	 * write it out. The schema declares lat and lon as numbers, so the nulls
	 * either fail validation or land as zeroes, and a photo at 0,0 is a photo
	 * in the Gulf of Guinea.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#task-7
	 */
	public function testACaptureWithNoPositionCarriesNone(): void {
		$this->store->seed(
			schema: 'fieldInspection',
			uuid: 'inspection-2',
			row: ['id' => 'inspection-2', 'caseRef' => 'case-1', 'status' => 'in_progress'],
		);

		$this->controller(params: ['type' => 'sketch'])->capture(inspectionRef: 'inspection-2');

		self::assertArrayNotHasKey(
			key: 'gpsLocation',
			array: $this->store->all(schema: 'fieldEvidence')[0],
			message: 'Nobody knows where this was taken, and saying 0,0 is worse than saying nothing.',
		);
	}//end testACaptureWithNoPositionCarriesNone()

	/**
	 * Somebody who may not write the case is refused, and nothing is stored.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#task-8
	 */
	public function testSomebodyElsesInspectionIsRefused(): void {
		$response = $this->controller(
			params: ['type' => 'photo'],
			mayWrite: false,
		)->capture(inspectionRef: 'inspection-1');

		self::assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());
		self::assertSame(expected: [], actual: $this->store->all(schema: 'fieldEvidence'));
	}//end testSomebodyElsesInspectionIsRefused()

	/**
	 * An inspection that does not exist gets the same answer as one that is not theirs.
	 *
	 * Distinguishing the two tells an outsider which inspection ids exist.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#task-8
	 */
	public function testAnUnknownInspectionIsRefusedTheSameWay(): void {
		$response = $this->controller(params: ['type' => 'photo'])->capture(inspectionRef: 'nope');

		self::assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());
	}//end testAnUnknownInspectionIsRefusedTheSameWay()

	/**
	 * An unauthenticated caller is told to sign in, not that this is not theirs.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#task-8
	 */
	public function testAnUnauthenticatedCallerIsToldToSignIn(): void {
		$response = $this->controller(params: ['type' => 'photo'], signedIn: false)
			->capture(inspectionRef: 'inspection-1');

		self::assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $response->getStatus());
	}//end testAnUnauthenticatedCallerIsToldToSignIn()

	/**
	 * An instance that has not imported the offline register says so.
	 *
	 * 503 and not 500: a device told it made a bad request discards a capture
	 * it cannot take again.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/mobiel-inspectie-offline/tasks.md#task-8
	 */
	public function testAnUnconfiguredInstanceSaysSo(): void {
		$response = $this->controller(params: ['type' => 'photo'], configured: false)
			->capture(inspectionRef: 'inspection-1');

		self::assertSame(expected: Http::STATUS_SERVICE_UNAVAILABLE, actual: $response->getStatus());
	}//end testAnUnconfiguredInstanceSaysSo()

	/**
	 * The controller under test, with the REAL validator and the REAL queue.
	 *
	 * Neither is doubled: the point of this suite is that they are consulted,
	 * and a double would let a controller that decided for itself pass.
	 *
	 * @param array<string, mixed> $params     What the request answers.
	 * @param bool                 $mayWrite   Whether the guard lets the caller write the case.
	 * @param bool                 $signedIn   Whether there is a session.
	 * @param bool                 $configured Whether the offline register is configured.
	 *
	 * @return FieldEvidenceController The controller.
	 */
	private function controller(
		array $params,
		bool $mayWrite = true,
		bool $signedIn = true,
		bool $configured = true,
	): FieldEvidenceController {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static function (string $key, mixed $default = null) use ($params): mixed {
				return ($params[$key] ?? $default);
			}
		);

		$session = $this->createMock(originalClassName: IUserSession::class);
		if ($signedIn === true) {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn('inspecteur');
			$session->method('getUser')->willReturn($user);
		} else {
			$session->method('getUser')->willReturn(null);
		}

		$guard = $this->createMock(originalClassName: CaseAccessGuard::class);
		$guard->method('hasCaseMutationAccess')->willReturn($mayWrite);

		$settings = $this->settings(configured: $configured);

		return new FieldEvidenceController(
			appName: 'dossiq',
			request: $request,
			metadata: new EvidenceMetadataService(),
			transcription: new TranscriptionService(
				settingsService: $settings,
				logger: new NullLogger(),
			),
			settings: $settings,
			accessGuard: $guard,
			userSession: $session,
		);
	}//end controller()

	/**
	 * A settings service answering the in-memory store and the slugs it holds.
	 *
	 * @param bool $configured Whether the offline schema keys resolve.
	 *
	 * @return SettingsService The settings service.
	 */
	private function settings(bool $configured): SettingsService {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default = '') use ($configured): string {
				$map = [
					'register' => 'dossiq',
					'field_inspection_schema' => 'fieldInspection',
					'field_evidence_schema' => 'fieldEvidence',
				];
				if ($configured === false && $key !== 'register') {
					return $default;
				}

				return ($map[$key] ?? $default);
			}
		);

		return $settings;
	}//end settings()
}//end class
