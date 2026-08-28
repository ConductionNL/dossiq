## 1. Confirm the data side

- [ ] 1.1 Confirm what backs a voorstel today — `VoorstelDetail.vue`
      exists and resolves one, so the read path is already there. Reuse
      it; do not introduce a second source.
- [ ] 1.2 Establish the lifecycle values the filters must cover. The old
      test comments assumed "Actief / Afgerond / Alle"; that is a guess
      from a test, not a contract, and should be checked against the
      register before it is built.

## 2. Build the index

- [ ] 2.1 Add the view under `src/views/voorstellen/`, with its heading
      and create control.
- [ ] 2.2 Point the `/voorstellen` page entry in `src/manifest.json` at
      it, replacing the fall-through to the generic index.
- [ ] 2.3 Add the lifecycle filters from 1.2.
- [ ] 2.4 Add the Dutch empty state.

## 3. Wire creation

- [ ] 3.1 The create control opens the voorstel creation flow and returns
      to the list with the new item present.

## 4. Un-skip the three e2e tests

- [ ] 4.1 `renders with heading and create button`
- [ ] 4.2 `has filter tabs`
- [ ] 4.3 `shows Dutch empty state`
- [ ] 4.4 Replace their skip reason, which currently blames a deployment.
      Whatever remains true at that point should be stated plainly — and
      if they pass, the guard goes rather than the reason changing.
