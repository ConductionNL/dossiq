# Tasks — Beta surface alignment (Procest)

- [x] Read `appinfo/info.xml`, `src/manifest.json` nav, and `lib/Controller/` to build the
      canonical shipped-feature vocabulary.
- [x] Verify each product-page claim against code:
  - [x] VTH process template names (omgevingsvergunning/toezichtzaak/handhavingszaak/sloopmelding
        real; milieumelding/brandveiligheid/BAG-melding/RUD-controle fabricated)
  - [x] DocuDesk decision generation + signing + TMLO archival (archival real, signing/PDF mocked)
  - [x] ZaakAfhandelApp citizen portal integration (zero code coupling — fabricated)
  - [x] Windmill + n8n automation (n8n real, Windmill fabricated)
  - [x] Presidio PII redaction / LLM pipeline (fabricated; only listed as "Planned" in docs)
  - [x] Dashboard widget names vs. real `IWidget::getTitle()` strings
  - [x] CMMN 1.1 / ZGW API standards claims
- [x] Update `appinfo/info.xml` EN + NL descriptions: add VTH/bezwaar-beroep/WOO/dwangsom/map/
      appointments/kanban to the feature list; correct the CMMN/ZGW standards line.
- [x] Update `conduction-website/src/pages/apps/procest.mdx`: version/status, FeatureList,
      WidgetShelf widget names, RotatingCards wording, Showcase (n8n only, real AI feature),
      PairCard wording.
- [x] Update `conduction-website/i18n/nl/docusaurus-plugin-content-pages/apps/procest.mdx` with the
      equivalent Dutch changes.
- [x] Review `procest/docs/` (index.md, Features/README.md, ai-assisted-processing.md,
      Technical/architecture.md) for the same fabricated claims — found already honestly labelled
      (Implemented/Partial/Planned); no changes required.
- [x] Confirm `img/app.svg` matches the app-icon convention (white fill, 24×24 viewBox) — no
      mismatch found.
- [x] Write proposal.md documenting the canonical feature list and every reconciliation.
- [x] Write this tasks.md and the spec delta.
