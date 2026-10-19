# Partner directory and web administration

The client-only frontend exposes `/partners`, six category pages and `/partners/admin`.
The admin page manages entries, fixed categories, HTTPS destinations, optional tracking URLs,
draft/active/inactive publication, position, relationship disclosure and region/network notes.
There is no CLI administration workflow. Click reports download from the web page as CSV.

## Deployment configuration

- Configure `PARTNER_ADMIN_TOKEN` in the backend secret/environment configuration with a
  cryptographically random value of at least 32 characters. Missing/short values disable every
  admin endpoint. This is an operator capability, not a multi-user account/role system.
  Rotate it through deployment configuration; never use a `NEXT_PUBLIC_` variable for it.
- Serve the frontend and API over HTTPS. The browser submits the token only in the
  `Authorization: Bearer` header. It stays in tab memory, masked by default, and clears on
  sign-out/reload. Disable browser credential saving for this operator token when prompted.
- `PARTNER_STORAGE_DIR` optionally overrides `%kernel.project_dir%/var/partners`.
  Keep it outside the web root, writable only by the PHP runtime and on a persistent local disk.
  Compose declares the `partner_data` named volume at `/app/var/partners`; configuration is
  prepared only, with no volume initialization or deployment performed by this change.
  For deployment outside Compose, mount a persistent directory explicitly and include it in backups.
- The initial catalog comes from `resources/partners.json`. First successful web save creates
  the persisted catalog; subsequent deployments preserve it instead of resetting to the seed.
  Back up catalog and aggregate files before changing storage paths.
- Allow only the intended frontend origins in the existing CORS configuration. Admin writes
  require `If-Match`; CORS now permits this header and exposes `ETag`.
- Apply ingress request/body limits and request-rate limits to `/v1/partner-clicks` and admin
  endpoints. Do not log Authorization headers or request bodies. Infrastructure access logs
  have their own IP/URL retention policy; the application counters do not read or persist these.

This implementation is for a **single PHP host / local persistent filesystem**. Separate hosts
must use a transactional shared backend before horizontal scaling; sharing this directory over
an arbitrary network filesystem is not a substitute for verified lock/rename semantics.
The file operations use bounded catalog cardinality, a per-day nonblocking writer lock and
atomic replacement. Contention/storage failure returns 503, and clicks are intentionally not
retried; totals are best effort, never suitable for billing or fraud attribution. The catalog
remains unchanged if validation, revision checks or a write fails.

Daily aggregates contain no visitor/event records. This change does not automatically delete
aggregate history; operators control aggregate retention and backup retention. Each CSV request
is bounded to 90 inclusive UTC days, with at most 1,000 service/placement rows per stored day.

## Web workflow

1. Open `/partners/admin`, enter the operator token and select **Unlock catalog**.
2. Select an existing listing or **Add listing**. New entries default to draft.
3. Choose category, relationship, publication status and position. Lower positions come first.
   Region/network notes are required. IDs are unique lowercase slugs and stay fixed in the editor.
   Use only public destination/tracking URLs; never include secrets, wallet addresses or user identifiers.
4. **Save & publish** makes active listings public; **Save listing** preserves hidden drafts or
   inactive entries. Use inactive to remove a listing from discovery without deleting its history.
5. If another administrator saved first, the API returns 412. Edits stay visible; copy needed
   changes, reload and review the current entry. Do not blindly replay an older full catalog.
6. Enter typed UTC dates and select **Download CSV**. An empty period returns a header-only CSV.
   Export failures leave the dates intact for retry. **Sign out** clears the operator token.

## HTTP contract

| Route | Access | Behavior |
|---|---|---|
| `GET /v1/partners` | Public | Categories and active ordered entries; at most 100 entries, short cache |
| `POST /v1/partner-clicks` | Public, explicit consent payload | 204 after daily aggregate increment |
| `GET /v1/admin/partners` | Bearer | Complete catalog, revision and quoted ETag; private/no-store |
| `PUT /v1/admin/partners` | Bearer + quoted `If-Match` | Replace `{ "entries": [...] }`; 428 missing revision, 412 stale, 422 invalid |
| `GET /v1/admin/partner-clicks.csv?from=YYYY-MM-DD&to=YYYY-MM-DD` | Bearer | Inclusive UTC CSV; 422 invalid/reversed/over-90-day range |

The click body accepts only `partnerId`, `placement` (`directory` or `category`) and
`consent: true`. Unknown/inactive services are rejected. Extra dimensions are rejected, so page
URLs, asset/account identifiers and user data cannot be added through this endpoint. DNT/GPC
headers opt out. There are no redirect endpoints or arbitrary client-provided destinations.

The frontend checks optional-cookie consent plus DNT/GPC before both counting and selecting an
optional tracking destination. Declined/unavailable consent uses the ordinary website URL.
Requests omit credentials/referrers, time out and never block direct outbound navigation.
No cookie, visitor ID, IP, user agent, wallet address or full page URL is stored by these counters.

CSV columns: `day_utc,partner_id,placement,clicks`. Counters measure consented UI link activations,
including repeated activations and possible automation. They are not unique visitors, conversions,
verified human clicks, complete right-click/context-menu traffic or Sevio advertisement clicks.
Sevio creatives are cross-origin provider content; campaign reports remain provider-owned.

## Seed provenance

Reviewed against provider sources on 2026-10-19:

- [Freighter](https://freighter.app/) — wallet directory listing.
- [StellarX](https://www.stellarx.com/) — exchange directory listing.
- [MoneyGram Ramps](https://xramps.moneygram.com/) and its
  [Stellar guide](https://xramps.moneygram.com/ops/developer/guides/stellar) — ramp directory listing.
- [Stellar RPC documentation](https://developers.stellar.org/docs/data/apis/rpc) — infrastructure resource.
- [Stellar Lab](https://lab.stellar.org/) and [documentation](https://developers.stellar.org/docs/tools/lab) — developer tool.
- Sevio — pre-existing advertising integration, not an invented new commercial agreement.

The directory label does not establish an affiliate/commercial relationship. New affiliate URLs
or sponsored relationships require actual operator-provided terms and destinations; the seed
does not fabricate them. Review provider notes periodically; availability can change.

## Validation

`tests/Controller/PartnerControllerTest.php` exercises authorization, publication/order,
optimistic concurrency, unsafe URLs, input bounds, consent/privacy dimensions, CSV boundaries,
storage contention and corrupt-storage failure. It uses disposable filesystem data only,
without any application or remote database writes.

### Local verification, 2026-10-19

- Frontend: all 83 Node regression tests passed; project TypeScript check, scoped ESLint and
  production static build passed. No dependencies or lockfiles changed.
- Backend: 7 controller/service tests, 59 assertions passed; syntax checks and Symfony container
  lint passed. No production or remote database was used.
- Scoped premium UI audit: zero findings. A whole-application scan also found existing issues on
  unrelated screens; this change does not certify the entire application.
- Real Chrome + local Symfony API: rejected invalid token, unlocked catalog, rejected unsafe URL,
  exercised unsaved-edit dialog/Escape, created draft, published/hidden entries, verified empty
  categories, tested optional-cookie preference changes and direct versus tracking destinations.
- One declined click created no counter file. One accepted click produced exactly one daily
  aggregate, and the CSV downloaded through the admin UI contained that activation.
- Invalid CSV dates were rejected in the form. Corrupt temporary catalog storage produced the
  public error state; restoring it and using Retry loaded the catalog again.
- Desktop and 390×844 mobile layouts were inspected against the real local API. The measured
  document width was 390px with no horizontal overflow; public listings formed one column.
  Category selection worked with Arrow keys, and Tab moved focus to the next labeled field.
- Two independent authenticated browser sessions loaded the same revision. A save in the first
  session caused the second session's older save to return 412; edits stayed visible and saving
  was disabled. Discard/reload recovered the new revision, with initial dialog focus on Keep editing.
- Mobile cookie-preference changes were exercised. Native popup capture and full offline mode
  were not exhaustively exercised.

These checks were performed locally before release. The approved initial rollout target is
staging; production rollout, ingress configuration, provider availability and any new commercial
agreement remain separate operational steps. No remote database migration is required.
