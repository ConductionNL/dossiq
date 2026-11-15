# Proposal: case-ai-oversight-through-hermiq

## Why

hermiq#1172 makes AI oversight records readable only through their subject: GET
`/apps/hermiq/api/ai-oversight/subject?originApp=dossiq&subjectId=…` first asks OpenRegister whether the reader may open
the case. The AI-toezicht list read them from the OpenRegister list, which handlers can no longer read. hermiq also
records suggestions shown but not acted on, as status `unused`.

## What changes

`case-ai-activity` reads its rows through that endpoint (`content.endpointSource`, nextcloud-vue
object-list-endpoint-source), shows `unused` as "Not used" (Niet gebruikt), and carries the board's subtitle "Every
suggestion is recorded, also when nobody uses it" (nextcloud-vue widget subtitle).

## Not covered

The board's "Downloaden" (AI-toezicht role only) stays off: the list export reads OpenRegister, which the reader can no
longer read for these records. An export needs hermiq to serve one through the subject.
