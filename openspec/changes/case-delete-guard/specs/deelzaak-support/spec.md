## REMOVED Requirements

### Requirement: Sub-case deletion protection

**Reason**: REQ-CM-35 answers this question, and answers it the other way. The
requirement asked the client to warn, unlink every child and then delete the
parent, which is a client deciding whether a case may go. A case with sub-cases
is now refused at the store, by name, so the unlink is a thing a handler does on
purpose before deleting rather than a step the delete performs on their behalf.
Keeping both would leave two rules disagreeing about the same delete, with only
the order of the guards deciding which one a person meets.

**Migration**: `src/modals/DeelzaakDeleteWarningModal.vue` is removed and the
sub-cases page takes the plain confirmation, which now shows the server's
refusal. Detaching sub-cases keeps its own door: `DELETE
/api/cases/{caseId}/deelzaken` and `DeelzaakService::unlinkSubCases()` are
untouched, so a handler who wants the children to survive still unlinks them,
and then the delete goes through.
