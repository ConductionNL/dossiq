<?php

/**
 * Bindings of the Kernel contracts to their Service implementations
 *
 * @category  Service
 * @package   OCA\Dossiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Composition root wiring, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\Kernel\Contracts\AanvullingsverzoekServiceInterface;
use OCA\Dossiq\Kernel\Contracts\AccrualNoticeServiceInterface;
use OCA\Dossiq\Kernel\Contracts\AcknowledgementServiceInterface;
use OCA\Dossiq\Kernel\Contracts\AdmissibilityJudgementInterface;
use OCA\Dossiq\Kernel\Contracts\AdviceServiceInterface;
use OCA\Dossiq\Kernel\Contracts\AdvisoryCommitteeServiceInterface;
use OCA\Dossiq\Kernel\Contracts\AiServiceInterface;
use OCA\Dossiq\Kernel\Contracts\AutomaticActionFlowMigratorInterface;
use OCA\Dossiq\Kernel\Contracts\BerichtenboxServiceInterface;
use OCA\Dossiq\Kernel\Contracts\BeschikkingGenerationServiceInterface;
use OCA\Dossiq\Kernel\Contracts\BeschikkingServiceInterface;
use OCA\Dossiq\Kernel\Contracts\BesluitMigrationServiceInterface;
use OCA\Dossiq\Kernel\Contracts\BezwaarAuditTrailInterface;
use OCA\Dossiq\Kernel\Contracts\CaseAccessGuardInterface;
use OCA\Dossiq\Kernel\Contracts\CaseAccessLinkServiceInterface;
use OCA\Dossiq\Kernel\Contracts\CaseAccessPurposeServiceInterface;
use OCA\Dossiq\Kernel\Contracts\CaseAttentionMarkerServiceInterface;
use OCA\Dossiq\Kernel\Contracts\CaseCompletenessInterface;
use OCA\Dossiq\Kernel\Contracts\CaseCopyServiceInterface;
use OCA\Dossiq\Kernel\Contracts\CaseCustodyChainInterface;
use OCA\Dossiq\Kernel\Contracts\CaseDateNormaliserInterface;
use OCA\Dossiq\Kernel\Contracts\CaseDefinitionExportServiceInterface;
use OCA\Dossiq\Kernel\Contracts\CaseDestructionServiceInterface;
use OCA\Dossiq\Kernel\Contracts\CaseEmailMatchServiceInterface;
use OCA\Dossiq\Kernel\Contracts\CaseEmailServiceInterface;
use OCA\Dossiq\Kernel\Contracts\CaseFlowActionsInterface;
use OCA\Dossiq\Kernel\Contracts\CaseLifecycleServiceInterface;
use OCA\Dossiq\Kernel\Contracts\CaseLinkSharesInterface;
use OCA\Dossiq\Kernel\Contracts\CaseMergeServiceInterface;
use OCA\Dossiq\Kernel\Contracts\CasePlanProjectionServiceInterface;
use OCA\Dossiq\Kernel\Contracts\CasePriorityServiceInterface;
use OCA\Dossiq\Kernel\Contracts\CaseReassignmentServiceInterface;
use OCA\Dossiq\Kernel\Contracts\CaseRebindServiceInterface;
use OCA\Dossiq\Kernel\Contracts\CaseRecordImportInterface;
use OCA\Dossiq\Kernel\Contracts\CaseRecycleServiceInterface;
use OCA\Dossiq\Kernel\Contracts\CaseRelationServiceInterface;
use OCA\Dossiq\Kernel\Contracts\CaseReviewerAccessInterface;
use OCA\Dossiq\Kernel\Contracts\CaseRouterInterface;
use OCA\Dossiq\Kernel\Contracts\CaseSeatsInterface;
use OCA\Dossiq\Kernel\Contracts\CaseSharingServiceInterface;
use OCA\Dossiq\Kernel\Contracts\CaseSplitExecutorInterface;
use OCA\Dossiq\Kernel\Contracts\CaseTakeoverRequestInterface;
use OCA\Dossiq\Kernel\Contracts\CaseTeamMigrationInterface;
use OCA\Dossiq\Kernel\Contracts\CaseTermsServiceInterface;
use OCA\Dossiq\Kernel\Contracts\CaseTimelineInterface;
use OCA\Dossiq\Kernel\Contracts\CaseTransferServiceInterface;
use OCA\Dossiq\Kernel\Contracts\CaseTypeCopyServiceInterface;
use OCA\Dossiq\Kernel\Contracts\CaseTypeResolverInterface;
use OCA\Dossiq\Kernel\Contracts\CaseVersionMoveInterface;
use OCA\Dossiq\Kernel\Contracts\ChannelIntakeInterface;
use OCA\Dossiq\Kernel\Contracts\CommitteeDelegationServiceInterface;
use OCA\Dossiq\Kernel\Contracts\ComplaintServiceInterface;
use OCA\Dossiq\Kernel\Contracts\ConflictResolutionServiceInterface;
use OCA\Dossiq\Kernel\Contracts\ConsultationServiceInterface;
use OCA\Dossiq\Kernel\Contracts\ContactMomentServiceInterface;
use OCA\Dossiq\Kernel\Contracts\ContractDecisionDelegationServiceInterface;
use OCA\Dossiq\Kernel\Contracts\CreateTaskHandlerInterface;
use OCA\Dossiq\Kernel\Contracts\DataSubjectRequestCaseInterface;
use OCA\Dossiq\Kernel\Contracts\DeadlineComplianceCalculatorInterface;
use OCA\Dossiq\Kernel\Contracts\DeadlineExtensionServiceInterface;
use OCA\Dossiq\Kernel\Contracts\DeadlinePauseServiceInterface;
use OCA\Dossiq\Kernel\Contracts\DeadlineReportingServiceInterface;
use OCA\Dossiq\Kernel\Contracts\DecisionServiceInterface;
use OCA\Dossiq\Kernel\Contracts\DeelzaakServiceInterface;
use OCA\Dossiq\Kernel\Contracts\DemoCaseloadSeedDataServiceInterface;
use OCA\Dossiq\Kernel\Contracts\DemoDataServiceInterface;
use OCA\Dossiq\Kernel\Contracts\DependentTermOfferInterface;
use OCA\Dossiq\Kernel\Contracts\DocumentProjectionServiceInterface;
use OCA\Dossiq\Kernel\Contracts\DocumentRedactionServiceInterface;
use OCA\Dossiq\Kernel\Contracts\DsoCaseServiceInterface;
use OCA\Dossiq\Kernel\Contracts\EngineTaskGatewayInterface;
use OCA\Dossiq\Kernel\Contracts\HearingServiceInterface;
use OCA\Dossiq\Kernel\Contracts\InboundMailIntakeInterface;
use OCA\Dossiq\Kernel\Contracts\IncidentStoreInterface;
use OCA\Dossiq\Kernel\Contracts\IntakeLogInterface;
use OCA\Dossiq\Kernel\Contracts\KpiAggregationServiceInterface;
use OCA\Dossiq\Kernel\Contracts\LeaverHandoverServiceInterface;
use OCA\Dossiq\Kernel\Contracts\LhsMatrixDecisionTableMigratorInterface;
use OCA\Dossiq\Kernel\Contracts\MandaatCheckServiceInterface;
use OCA\Dossiq\Kernel\Contracts\MandaatEscalatieServiceInterface;
use OCA\Dossiq\Kernel\Contracts\MandaatImportServiceInterface;
use OCA\Dossiq\Kernel\Contracts\MenuCaseTypesServiceInterface;
use OCA\Dossiq\Kernel\Contracts\MilestoneScheduleInterface;
use OCA\Dossiq\Kernel\Contracts\NotePushInterface;
use OCA\Dossiq\Kernel\Contracts\OutcomeCascadeInterface;
use OCA\Dossiq\Kernel\Contracts\PackageInstallerInterface;
use OCA\Dossiq\Kernel\Contracts\PauseChaseServiceInterface;
use OCA\Dossiq\Kernel\Contracts\PayoutServiceInterface;
use OCA\Dossiq\Kernel\Contracts\ProcessingTimeReportServiceInterface;
use OCA\Dossiq\Kernel\Contracts\ProcessMiningServiceInterface;
use OCA\Dossiq\Kernel\Contracts\ProgrammeConsumerInterface;
use OCA\Dossiq\Kernel\Contracts\PublicationServiceInterface;
use OCA\Dossiq\Kernel\Contracts\RefusalOutcomeInterface;
use OCA\Dossiq\Kernel\Contracts\RetiredNodeRewriterInterface;
use OCA\Dossiq\Kernel\Contracts\RoleResolverServiceInterface;
use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Kernel\Contracts\ShippedConfigurationServiceInterface;
use OCA\Dossiq\Kernel\Contracts\ShippedFlowAdoptionInterface;
use OCA\Dossiq\Kernel\Contracts\StatusTransitionServiceInterface;
use OCA\Dossiq\Kernel\Contracts\SubsidieServiceInterface;
use OCA\Dossiq\Kernel\Contracts\SubstitutionServiceInterface;
use OCA\Dossiq\Kernel\Contracts\TaskAttachmentServiceInterface;
use OCA\Dossiq\Kernel\Contracts\TemplateLibraryServiceInterface;
use OCA\Dossiq\Kernel\Contracts\TenantAuthenticationServiceInterface;
use OCA\Dossiq\Kernel\Contracts\TenantBillingServiceInterface;
use OCA\Dossiq\Kernel\Contracts\TenantMigrationServiceInterface;
use OCA\Dossiq\Kernel\Contracts\TenantOnboardingServiceInterface;
use OCA\Dossiq\Kernel\Contracts\TenantQuotaServiceInterface;
use OCA\Dossiq\Kernel\Contracts\TermijnServiceInterface;
use OCA\Dossiq\Kernel\Contracts\TermijnTimerServiceInterface;
use OCA\Dossiq\Kernel\Contracts\TimelineKindsInterface;
use OCA\Dossiq\Kernel\Contracts\WOOAnonymisationAssistServiceInterface;
use OCA\Dossiq\Kernel\Contracts\WOODocumentAssessmentServiceInterface;
use OCA\Dossiq\Kernel\Contracts\WooPublicationServiceInterface;
use OCA\Dossiq\Kernel\Contracts\WorkflowDefinitionServiceInterface;
use OCA\Dossiq\Kernel\Contracts\WorkflowLifecycleGuardInterface;
use OCA\Dossiq\Kernel\Contracts\WorkflowTemplateFlowMigratorInterface;
use OCA\Dossiq\Kernel\Contracts\WorkQueueServiceInterface;
use OCA\Dossiq\Kernel\Contracts\ZaakdossierServiceInterface;
use OCA\Dossiq\Kernel\Contracts\ZgwServiceInterface;
use OCA\Dossiq\Service\Access\CaseReviewerAccess;
use OCA\Dossiq\Service\Actions\AutomaticActionFlowMigrator;
use OCA\Dossiq\Service\Bezwaar\AdvisoryCommitteeService;
use OCA\Dossiq\Service\Bezwaar\BezwaarAuditTrail;
use OCA\Dossiq\Service\Bezwaar\CommitteeDelegationService;
use OCA\Dossiq\Service\Bezwaar\DecisionService;
use OCA\Dossiq\Service\Bezwaar\HearingService;
use OCA\Dossiq\Service\CaseDefinition\PackageInstaller;
use OCA\Dossiq\Service\Cases\CaseSplitExecutor;
use OCA\Dossiq\Service\Cases\IncidentStore;
use OCA\Dossiq\Service\CaseType\CaseVersionMove;
use OCA\Dossiq\Service\Custody\CaseCustodyChain;
use OCA\Dossiq\Service\Custody\CaseTakeoverRequest;
use OCA\Dossiq\Service\Doorlooptijd\DeadlineComplianceCalculator;
use OCA\Dossiq\Service\Email\InboundMailIntake;
use OCA\Dossiq\Service\Email\IntakeLog;
use OCA\Dossiq\Service\Flow\CaseFlowActions;
use OCA\Dossiq\Service\Flow\RetiredNodeRewriter;
use OCA\Dossiq\Service\Flow\ShippedFlowAdoption;
use OCA\Dossiq\Service\Gdpr\DataSubjectRequestCase;
use OCA\Dossiq\Service\Import\CaseRecordImport;
use OCA\Dossiq\Service\Intake\AdmissibilityJudgement;
use OCA\Dossiq\Service\Intake\CaseCompleteness;
use OCA\Dossiq\Service\Intake\ChannelIntake;
use OCA\Dossiq\Service\Milestone\MilestoneSchedule;
use OCA\Dossiq\Service\Money\AccrualNoticeService;
use OCA\Dossiq\Service\Money\PayoutService;
use OCA\Dossiq\Service\Notes\NotePush;
use OCA\Dossiq\Service\Pause\PauseChaseService;
use OCA\Dossiq\Service\People\CaseSeats;
use OCA\Dossiq\Service\People\LeaverHandoverService;
use OCA\Dossiq\Service\Pipelinq\ProgrammeConsumer;
use OCA\Dossiq\Service\Recycle\CaseDestructionService;
use OCA\Dossiq\Service\Recycle\CaseRecycleService;
use OCA\Dossiq\Service\Redaction\DocumentRedactionService;
use OCA\Dossiq\Service\Relation\OutcomeCascade;
use OCA\Dossiq\Service\Reporting\ProcessingTimeReportService;
use OCA\Dossiq\Service\Routing\CaseRouter;
use OCA\Dossiq\Service\Routing\RefusalOutcome;
use OCA\Dossiq\Service\Sharing\CaseAccessLinkService;
use OCA\Dossiq\Service\Sharing\CaseLinkShares;
use OCA\Dossiq\Service\Starter\ShippedConfigurationService;
use OCA\Dossiq\Service\Subsidie\SubsidieService;
use OCA\Dossiq\Service\Sync\ConflictResolutionService;
use OCA\Dossiq\Service\Task\EngineTaskGateway;
use OCA\Dossiq\Service\Task\TaskAttachmentService;
use OCA\Dossiq\Service\Team\CaseTeamMigration;
use OCA\Dossiq\Service\Term\DependentTermOffer;
use OCA\Dossiq\Service\Timeline\CaseTimeline;
use OCA\Dossiq\Service\Timeline\TimelineKinds;
use OCA\Dossiq\Service\Transitions\CreateTaskHandler;
use OCA\Dossiq\Service\Vth\LhsMatrixDecisionTableMigrator;
use OCA\Dossiq\Service\Workflow\WorkflowLifecycleGuard;
use OCA\Dossiq\Service\Workflow\WorkflowTemplateFlowMigrator;
use OCA\Dossiq\Service\Zaakdossier\DocumentProjectionService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Nextcloud autowires concrete classes but not interfaces, so every contract
 * in OCA\Dossiq\Kernel\Contracts that a caller type-hints needs an alias to
 * the class that implements it. An alias resolves to the same shared instance
 * as the implementation itself.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 *
 * @spec exclude Composition root wiring, no functional requirement.
 */
final class ContractBindings {

	/**
	 * Alias every contract to its implementation.
	 *
	 * @param IRegistrationContext $context Registration context
	 *
	 * @return void
	 *
	 * @spec exclude Composition root wiring, no functional requirement.
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerServiceAlias(AanvullingsverzoekServiceInterface::class, AanvullingsverzoekService::class);
		$context->registerServiceAlias(AccrualNoticeServiceInterface::class, AccrualNoticeService::class);
		$context->registerServiceAlias(AcknowledgementServiceInterface::class, AcknowledgementService::class);
		$context->registerServiceAlias(AdmissibilityJudgementInterface::class, AdmissibilityJudgement::class);
		$context->registerServiceAlias(AdviceServiceInterface::class, AdviceService::class);
		$context->registerServiceAlias(AdvisoryCommitteeServiceInterface::class, AdvisoryCommitteeService::class);
		$context->registerServiceAlias(AiServiceInterface::class, AiService::class);
		$context->registerServiceAlias(AutomaticActionFlowMigratorInterface::class, AutomaticActionFlowMigrator::class);
		$context->registerServiceAlias(BerichtenboxServiceInterface::class, BerichtenboxService::class);
		$context->registerServiceAlias(BeschikkingGenerationServiceInterface::class, BeschikkingGenerationService::class);
		$context->registerServiceAlias(BeschikkingServiceInterface::class, BeschikkingService::class);
		$context->registerServiceAlias(BesluitMigrationServiceInterface::class, BesluitMigrationService::class);
		$context->registerServiceAlias(BezwaarAuditTrailInterface::class, BezwaarAuditTrail::class);
		$context->registerServiceAlias(CaseAccessGuardInterface::class, CaseAccessGuard::class);
		$context->registerServiceAlias(CaseAccessLinkServiceInterface::class, CaseAccessLinkService::class);
		$context->registerServiceAlias(CaseAccessPurposeServiceInterface::class, CaseAccessPurposeService::class);
		$context->registerServiceAlias(CaseAttentionMarkerServiceInterface::class, CaseAttentionMarkerService::class);
		$context->registerServiceAlias(CaseCompletenessInterface::class, CaseCompleteness::class);
		$context->registerServiceAlias(CaseCopyServiceInterface::class, CaseCopyService::class);
		$context->registerServiceAlias(CaseCustodyChainInterface::class, CaseCustodyChain::class);
		$context->registerServiceAlias(CaseDateNormaliserInterface::class, CaseDateNormaliser::class);
		$context->registerServiceAlias(CaseDefinitionExportServiceInterface::class, CaseDefinitionExportService::class);
		$context->registerServiceAlias(CaseDestructionServiceInterface::class, CaseDestructionService::class);
		$context->registerServiceAlias(CaseEmailMatchServiceInterface::class, CaseEmailMatchService::class);
		$context->registerServiceAlias(CaseEmailServiceInterface::class, CaseEmailService::class);
		$context->registerServiceAlias(CaseFlowActionsInterface::class, CaseFlowActions::class);
		$context->registerServiceAlias(CaseLifecycleServiceInterface::class, CaseLifecycleService::class);
		$context->registerServiceAlias(CaseLinkSharesInterface::class, CaseLinkShares::class);
		$context->registerServiceAlias(CaseMergeServiceInterface::class, CaseMergeService::class);
		$context->registerServiceAlias(CasePlanProjectionServiceInterface::class, CasePlanProjectionService::class);
		$context->registerServiceAlias(CasePriorityServiceInterface::class, CasePriorityService::class);
		$context->registerServiceAlias(CaseReassignmentServiceInterface::class, CaseReassignmentService::class);
		$context->registerServiceAlias(CaseRebindServiceInterface::class, CaseRebindService::class);
		$context->registerServiceAlias(CaseRecordImportInterface::class, CaseRecordImport::class);
		$context->registerServiceAlias(CaseRecycleServiceInterface::class, CaseRecycleService::class);
		$context->registerServiceAlias(CaseRelationServiceInterface::class, CaseRelationService::class);
		$context->registerServiceAlias(CaseReviewerAccessInterface::class, CaseReviewerAccess::class);
		$context->registerServiceAlias(CaseRouterInterface::class, CaseRouter::class);
		$context->registerServiceAlias(CaseSeatsInterface::class, CaseSeats::class);
		$context->registerServiceAlias(CaseSharingServiceInterface::class, CaseSharingService::class);
		$context->registerServiceAlias(CaseSplitExecutorInterface::class, CaseSplitExecutor::class);
		$context->registerServiceAlias(CaseTakeoverRequestInterface::class, CaseTakeoverRequest::class);
		$context->registerServiceAlias(CaseTeamMigrationInterface::class, CaseTeamMigration::class);
		$context->registerServiceAlias(CaseTermsServiceInterface::class, CaseTermsService::class);
		$context->registerServiceAlias(CaseTimelineInterface::class, CaseTimeline::class);
		$context->registerServiceAlias(CaseTransferServiceInterface::class, CaseTransferService::class);
		$context->registerServiceAlias(CaseTypeCopyServiceInterface::class, CaseTypeCopyService::class);
		$context->registerServiceAlias(CaseTypeResolverInterface::class, CaseTypeResolver::class);
		$context->registerServiceAlias(CaseVersionMoveInterface::class, CaseVersionMove::class);
		$context->registerServiceAlias(ChannelIntakeInterface::class, ChannelIntake::class);
		$context->registerServiceAlias(CommitteeDelegationServiceInterface::class, CommitteeDelegationService::class);
		$context->registerServiceAlias(ComplaintServiceInterface::class, ComplaintService::class);
		$context->registerServiceAlias(ConflictResolutionServiceInterface::class, ConflictResolutionService::class);
		$context->registerServiceAlias(ConsultationServiceInterface::class, ConsultationService::class);
		$context->registerServiceAlias(ContactMomentServiceInterface::class, ContactMomentService::class);
		$context->registerServiceAlias(ContractDecisionDelegationServiceInterface::class, ContractDecisionDelegationService::class);
		$context->registerServiceAlias(CreateTaskHandlerInterface::class, CreateTaskHandler::class);
		$context->registerServiceAlias(DataSubjectRequestCaseInterface::class, DataSubjectRequestCase::class);
		$context->registerServiceAlias(DeadlineComplianceCalculatorInterface::class, DeadlineComplianceCalculator::class);
		$context->registerServiceAlias(DeadlineExtensionServiceInterface::class, DeadlineExtensionService::class);
		$context->registerServiceAlias(DeadlinePauseServiceInterface::class, DeadlinePauseService::class);
		$context->registerServiceAlias(DeadlineReportingServiceInterface::class, DeadlineReportingService::class);
		$context->registerServiceAlias(DecisionServiceInterface::class, DecisionService::class);
		$context->registerServiceAlias(DeelzaakServiceInterface::class, DeelzaakService::class);
		$context->registerServiceAlias(DemoCaseloadSeedDataServiceInterface::class, DemoCaseloadSeedDataService::class);
		$context->registerServiceAlias(DemoDataServiceInterface::class, DemoDataService::class);
		$context->registerServiceAlias(DependentTermOfferInterface::class, DependentTermOffer::class);
		$context->registerServiceAlias(DocumentProjectionServiceInterface::class, DocumentProjectionService::class);
		$context->registerServiceAlias(DocumentRedactionServiceInterface::class, DocumentRedactionService::class);
		$context->registerServiceAlias(DsoCaseServiceInterface::class, DsoCaseService::class);
		$context->registerServiceAlias(EngineTaskGatewayInterface::class, EngineTaskGateway::class);
		$context->registerServiceAlias(HearingServiceInterface::class, HearingService::class);
		$context->registerServiceAlias(InboundMailIntakeInterface::class, InboundMailIntake::class);
		$context->registerServiceAlias(IncidentStoreInterface::class, IncidentStore::class);
		$context->registerServiceAlias(IntakeLogInterface::class, IntakeLog::class);
		$context->registerServiceAlias(KpiAggregationServiceInterface::class, KpiAggregationService::class);
		$context->registerServiceAlias(LeaverHandoverServiceInterface::class, LeaverHandoverService::class);
		$context->registerServiceAlias(LhsMatrixDecisionTableMigratorInterface::class, LhsMatrixDecisionTableMigrator::class);
		$context->registerServiceAlias(MandaatCheckServiceInterface::class, MandaatCheckService::class);
		$context->registerServiceAlias(MandaatEscalatieServiceInterface::class, MandaatEscalatieService::class);
		$context->registerServiceAlias(MandaatImportServiceInterface::class, MandaatImportService::class);
		$context->registerServiceAlias(MenuCaseTypesServiceInterface::class, MenuCaseTypesService::class);
		$context->registerServiceAlias(MilestoneScheduleInterface::class, MilestoneSchedule::class);
		$context->registerServiceAlias(NotePushInterface::class, NotePush::class);
		$context->registerServiceAlias(OutcomeCascadeInterface::class, OutcomeCascade::class);
		$context->registerServiceAlias(PackageInstallerInterface::class, PackageInstaller::class);
		$context->registerServiceAlias(PauseChaseServiceInterface::class, PauseChaseService::class);
		$context->registerServiceAlias(PayoutServiceInterface::class, PayoutService::class);
		$context->registerServiceAlias(ProcessMiningServiceInterface::class, ProcessMiningService::class);
		$context->registerServiceAlias(ProcessingTimeReportServiceInterface::class, ProcessingTimeReportService::class);
		$context->registerServiceAlias(ProgrammeConsumerInterface::class, ProgrammeConsumer::class);
		$context->registerServiceAlias(PublicationServiceInterface::class, PublicationService::class);
		$context->registerServiceAlias(RefusalOutcomeInterface::class, RefusalOutcome::class);
		$context->registerServiceAlias(RetiredNodeRewriterInterface::class, RetiredNodeRewriter::class);
		$context->registerServiceAlias(RoleResolverServiceInterface::class, RoleResolverService::class);
		$context->registerServiceAlias(SettingsServiceInterface::class, SettingsService::class);
		$context->registerServiceAlias(ShippedConfigurationServiceInterface::class, ShippedConfigurationService::class);
		$context->registerServiceAlias(ShippedFlowAdoptionInterface::class, ShippedFlowAdoption::class);
		$context->registerServiceAlias(StatusTransitionServiceInterface::class, StatusTransitionService::class);
		$context->registerServiceAlias(SubsidieServiceInterface::class, SubsidieService::class);
		$context->registerServiceAlias(SubstitutionServiceInterface::class, SubstitutionService::class);
		$context->registerServiceAlias(TaskAttachmentServiceInterface::class, TaskAttachmentService::class);
		$context->registerServiceAlias(TemplateLibraryServiceInterface::class, TemplateLibraryService::class);
		$context->registerServiceAlias(TenantAuthenticationServiceInterface::class, TenantAuthenticationService::class);
		$context->registerServiceAlias(TenantBillingServiceInterface::class, TenantBillingService::class);
		$context->registerServiceAlias(TenantMigrationServiceInterface::class, TenantMigrationService::class);
		$context->registerServiceAlias(TenantOnboardingServiceInterface::class, TenantOnboardingService::class);
		$context->registerServiceAlias(TenantQuotaServiceInterface::class, TenantQuotaService::class);
		$context->registerServiceAlias(TermijnServiceInterface::class, TermijnService::class);
		$context->registerServiceAlias(TermijnTimerServiceInterface::class, TermijnTimerService::class);
		$context->registerServiceAlias(TimelineKindsInterface::class, TimelineKinds::class);
		$context->registerServiceAlias(WOOAnonymisationAssistServiceInterface::class, WOOAnonymisationAssistService::class);
		$context->registerServiceAlias(WOODocumentAssessmentServiceInterface::class, WOODocumentAssessmentService::class);
		$context->registerServiceAlias(WooPublicationServiceInterface::class, WooPublicationService::class);
		$context->registerServiceAlias(WorkQueueServiceInterface::class, WorkQueueService::class);
		$context->registerServiceAlias(WorkflowDefinitionServiceInterface::class, WorkflowDefinitionService::class);
		$context->registerServiceAlias(WorkflowLifecycleGuardInterface::class, WorkflowLifecycleGuard::class);
		$context->registerServiceAlias(WorkflowTemplateFlowMigratorInterface::class, WorkflowTemplateFlowMigrator::class);
		$context->registerServiceAlias(ZaakdossierServiceInterface::class, ZaakdossierService::class);
		$context->registerServiceAlias(ZgwServiceInterface::class, ZgwService::class);
	}//end register()
}//end class
