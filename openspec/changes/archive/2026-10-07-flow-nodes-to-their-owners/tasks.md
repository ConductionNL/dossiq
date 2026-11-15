# Tasks: flow-nodes-to-their-owners

- [x] 1. Retired-node table: a row per removed type, naming its replacement and its translation (`lib/Service/Flow/RetiredNodeMap.php`)
- [x] 2. Config translation per type, refusing what cannot be carried faithfully (`RetiredNodeTranslator`, `RetiredDocumentSteps`, `RetiredTemplateSyntax`, `UnmappableStep`)
- [x] 3. Rewriter chains a two-step translation and leaves a refused step in place; the repair logs it (`RetiredNodeRewriter`, `RewriteRetiredFlowNodes`)
- [x] 4. Remove the nodes, their handlers and their registrations; keep setStatus, createSubCase, createTask, askPerson, ensureCommittee, besluitvormingPublish, resumeTerm
- [x] 5. Declared transition actions and task effects of a retired type run as the replacement (`RetiredActionRunner`, `SideEffectDispatcher`, `TaskEffects`, `TaskDeclarationValidator`)
- [x] 6. Seeded case flow, automatic-action migration and its command use the owners' steps
- [x] 7. File a flow-sent mail on the case (`FlowEmailSentListener`, `CaseEmailService::recordSentEmail`)
- [x] 8. File a Filinq document in the dossier (`DocumentGeneratedListener`, `GeneratedDocumentFiler`)
- [x] 9. Generate document button asks Filinq by event and refuses naming Filinq when nobody answers (`CaseDocumentGenerationService`)
- [x] 10. A `decidiq-flow` decision on a dossiq case becomes its besluit (`DecisionConcludedListener`, `CaseObjectReference`)
- [x] 11. Docs, l10n, stubs and tests
