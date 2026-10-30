<?php

/**
 * The Adviseringsbeheer settings reach advice requests: the default term at
 * creation, the due-soon list and the bottleneck report, and the endpoints
 * that serve them.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/consultation-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Dossiq\Controller\ConsultationReportController;
use OCA\Dossiq\Service\AdviceDelegationService;
use OCA\Dossiq\Service\Consultation\ConsultationDependencyGraph;
use OCA\Dossiq\Service\Consultation\ConsultationReports;
use OCA\Dossiq\Service\Consultation\ConsultationRepository;
use OCA\Dossiq\Service\Consultation\ConsultationTerms;
use OCA\Dossiq\Service\ConsultationService;
use OCA\Dossiq\Service\Obligations\ObligationDeclaration;
use OCA\Dossiq\Service\Obligations\ObligationService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * An object store that keeps what is saved and answers searches by status.
 */
class SettingsReadersObjectStore {

	/**
	 * The stored rows.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $rows = [];

	/**
	 * The last saved payload.
	 *
	 * @var array<string, mixed>
	 */
	public array $saved = [];

	/**
	 * Search rows; a `status` filter narrows them.
	 *
	 * @param string               $register The register.
	 * @param string               $schema   The schema.
	 * @param array<string, mixed> $filters  The filters.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
		return array_values(array_filter($this->rows, static fn (array $row): bool => isset($filters['status']) === false || ($row['status'] ?? '') === $filters['status']));
	}//end searchObjectsBySlug()

	/**
	 * Save an object.
	 *
	 * @param array<string, mixed> $object   The payload.
	 * @param string               $register The register.
	 * @param string               $schema   The schema.
	 *
	 * @return array<string, mixed>
	 */
	public function saveObject(array $object, string $register, string $schema): array {
		$this->saved = $object;

		return $object + ['id' => 'cn-new'];
	}//end saveObject()

	/**
	 * Patch an object.
	 *
	 * @param string               $objectId The id.
	 * @param array<string, mixed> $data     The changes.
	 * @param string               $register The register.
	 * @param string               $schema   The schema.
	 *
	 * @return array<string, mixed>
	 */
	public function patchObject(string $objectId, array $data, string $register, string $schema): array {
		return $data + ['id' => $objectId];
	}//end patchObject()
}//end class

/**
 * Each Adviseringsbeheer setting has a reader that advice requests reach.
 */
class ConsultationSettingsReadersTest extends TestCase {
	use MakesCaseDateNormaliser;

	/**
	 * The service over a store, with these settings, on 24 October 2026.
	 *
	 * @param SettingsReadersObjectStore $store  The object store.
	 * @param array<string, string>      $config The settings.
	 *
	 * @return ConsultationService
	 */
	private function service(SettingsReadersObjectStore $store, array $config = []): ConsultationService {
		$config += ['register' => 'dossiq', 'consultation_schema' => 'consultation'];
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($store);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($config[$key] ?? $default)
		);
		$logger = $this->createMock(LoggerInterface::class);
		$delegation = $this->createMock(AdviceDelegationService::class);
		$delegation->method('raiseAdviceDecision')->willReturn('decision-1');
		$repository = new ConsultationRepository($settings, $logger);

		return new ConsultationService(
			settingsService: $settings,
			logger: $logger,
			adviceDelegation: $delegation,
			repository: $repository,
			dependencyGraph: new ConsultationDependencyGraph($repository),
			dates: $this->caseDates(),
			obligations: new ObligationService(settingsService: $settings, declaration: new ObligationDeclaration(), dates: $this->caseDates(), logger: $logger),
			terms: new ConsultationTerms(settingsService: $settings, today: new DateTimeImmutable('2026-10-24')),
		);
	}//end service()

	/**
	 * The two reports over the given store.
	 *
	 * @param SettingsReadersObjectStore $store  The store.
	 * @param array<string, string>      $config The app config.
	 *
	 * @return ConsultationReports
	 */
	private function reports(SettingsReadersObjectStore $store, array $config = []): ConsultationReports {
		$config += ['register' => 'dossiq', 'consultation_schema' => 'consultation'];
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($store);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($config[$key] ?? $default)
		);

		return new ConsultationReports(
			repository: new ConsultationRepository($settings, $this->createMock(LoggerInterface::class)),
			terms: new ConsultationTerms(settingsService: $settings, today: new DateTimeImmutable('2026-10-24')),
		);
	}//end reports()

	/**
	 * An advice request created without a deadline gets the default term.
	 *
	 * @spec openspec/specs/consultation-management/spec.md#requirement-an-advice-request-without-a-deadline-gets-the-default-term
	 *
	 * @return void
	 */
	public function testCreateGivesTheDefaultTerm(): void {
		$store = new SettingsReadersObjectStore();
		$this->service($store, ['consultation_default_deadline_days' => '21'])->createConsultation(data: [
			'parentCase' => 'case-1',
			'adviceAuthority' => 'Welstandscommissie',
			'questionFormulation' => 'Past de gevel?',
		]);

		self::assertSame('2026-11-14', $store->saved['latestResponseDate']);
	}//end testCreateGivesTheDefaultTerm()

	/**
	 * The due-soon list and the bottleneck report read the stored requests.
	 *
	 * @return void
	 */
	public function testDueSoonAndBottlenecksReadTheStoredRequests(): void {
		$store = new SettingsReadersObjectStore();
		$store->rows = [
			['id' => 'a', 'status' => 'open', 'latestResponseDate' => '2026-10-26', 'adviceAuthority' => 'Brandweer', 'createdAt' => date('Y-m-d') . 'T09:00:00+00:00'],
			['id' => 'b', 'status' => 'in_handling', 'latestResponseDate' => '2026-11-30', 'adviceAuthority' => 'Brandweer', 'createdAt' => date('Y-m-d') . 'T09:00:00+00:00'],
		];
		$service = $this->reports($store);

		$due = $service->getDueSoonConsultations();
		self::assertSame(['a'], array_column($due['results'], 'id'));
		self::assertSame(5, $due['warningOffsetDays']);
		self::assertSame(2, $service->getBottlenecks()['bodies'][0]['total']);

		$none = $service->getDueSoonConsultations(mayRead: static fn (array $consultation): bool => false);
		self::assertSame([], $none['results'], 'A filter that keeps nothing lists nothing');
	}//end testDueSoonAndBottlenecksReadTheStoredRequests()

	/**
	 * Both lists have an endpoint, routed before the `{id}` route.
	 *
	 * @return void
	 */
	public function testTheEndpointsAreRoutedBeforeTheIdRoute(): void {
		$routes = (string)file_get_contents(__DIR__ . '/../../../appinfo/routes.php');
		$show = strpos($routes, "'consultation#show'");
		foreach (['dueSoon' => '/api/consultations/due-soon', 'bottlenecks' => '/api/consultations/bottlenecks'] as $method => $url) {
			$at = strpos($routes, "'consultationReport#" . $method . "'");
			self::assertNotFalse($at);
			self::assertLessThan($show, $at);
			self::assertStringContainsString($url, substr($routes, $at, 120));
			self::assertTrue(method_exists(ConsultationReportController::class, $method));
		}
	}//end testTheEndpointsAreRoutedBeforeTheIdRoute()
}//end class
