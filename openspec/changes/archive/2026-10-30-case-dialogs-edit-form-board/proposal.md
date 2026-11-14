# Proposal: case-dialogs-edit-form-board

## Why

DqZaakDialogen draws Zaak bewerken with the line "De velden van de zaak. Status en termijn wijzigt u via Volgende stap."
under the title, Behandelaar right after Zaaktype, and the requester asked as "Verzoeker". The case edit form had no such
line, asked the assignee after the description as "Toegewezen aan", and the requester as "Aanvrager".

## What changes

- CaseDetail `editSubtitle`: "The case's fields. You change the status and the term through Next step." Needs nc-vue
  feat/detail-edit-form-subtitle (CnDetailPage `editSubtitle`); before that release the key is ignored.
- Edit form `fieldOverrides.assignee`: order 1.5 (after the case type, before the title), label "Handler" (Behandelaar).
- Edit form requester label "Applicant" (Verzoeker). The New case form keeps "Aanvrager" as DqNieuweZaak draws it.

- CaseDetail `editTitle`: "Edit case" (Zaak bewerken), the header button keeps "Bewerken". Needs nc-vue
  feat/detail-edit-form-title (CnDetailPage `editTitle`); before that release the key is ignored.
