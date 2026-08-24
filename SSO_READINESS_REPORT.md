# SSO Readiness Report — Star One (Central SSO) vs 4 Legacy Products

**Date:** 2026-08-24 · **Scope:** Payload-parity audit of `sso-laravel` adapters vs legacy login endpoints, plus real-data integration & test plan.

---

## Verdict: ~95% READY (all 4 products synced & smoke-tested)

> **UPDATE 2026-08-24 — Integration executed.** Staging DBs imported (`src_stellar`, `src_sfa`, `src_saathi`, `src_link` on 127.0.0.1:3307) and real production data synced via the new `php artisan sso:sync-legacy` command (app/Console/Commands/SyncLegacyUsers.php):
>
> | Product | Synced | Skipped phones | Notes |
> |---|---|---|---|
> | star_steller | 2,700 (147 TEs + 2,553 engineers) | 0 | filters: acedns='Y' / status='ACTIVE' |
> | star_sfa | 835 employees | 188 | dirty Excel/dual numbers filtered out |
> | star_saathi | 66,973 dealer identities | 1,126 | shared-phone policy: first identity wins (`--refresh` to overwrite) |
> | star_link | 42,029 (270 BDEs + ~41.8K masons) | 0 | roles 1+2 only per legacy login rule; full users.* snapshot, dealers/TE relations resolved |
>
> End-to-end smoke tests with real users PASSED for all FOUR products: Stellar (TE JSON), SFA (XML), Saathi (encrypted hex), Star Link (JSON incl. mason_category=GOLD computed from points, te parent object, 113-dealer array). A real TE whose phone also exists in SFA received both products from one token — cross-product identity works. Note: `password`/`remember_token` are deliberately NOT replicated into launch payloads even though legacy leaked them via users.* — clients never read them.


| Product | Payload Parity | Real Data Available | Blockers |
|---|---|---|---|
| **Star SFA** | ✅ Exact match | ✅ 2,519 employees | None |
| **Star Saathi** | ✅ Match (superset of verifyotp) | ✅ 78,623 customers | changepassword/broker dumps empty (defaults OK) |
| **Star Stellar** | ⚠️ 1 gap (profile image URL) | ✅ 285 TEs + 3,771 engineers | Populate image URLs in metadata |
| **Star Link** | ❌ Incomplete (~40 fields in legacy) | ❌ **users table dump MISSING** | Must export `users` table |

---

## 1. Per-Product Audit

### 1.1 Star SFA — `emplogin-check-6.0.1.php` ✅
Legacy success = XML `<recordset><data>` with exactly 6 CDATA fields:
`emp_code, emp_name, sale_access, newpassword, deviceid, acedns`.

`StarSfaAdapter` emits **exactly these 6 fields, same order, same Content-Type** (`text/xml; charset=utf-8`). ✔

Notes for the team:
- Legacy is password+deviceid login (emp_code + newpassword + deviceid). SSO replaces the credential step centrally; the XML hands back `newpassword`/`deviceid` so the app continues as before.
- Legacy error strings ('6', '0', '4/name', 'NOT LICENSED USER', 'NOT VALID USER') are device-binding/licence gates — now enforced centrally via product access. Device binding is **not** re-enforced at launch; confirm SFA team accepts this.

### 1.2 Star Saathi — `checkloginnew_v2` / `verifyotp` ✅
- **Encryption verified byte-compatible** with `Apicommonfunction::encrypt()`: key `0123456789abcdef`, IV `fedcba9876543210`, rijndael-128-CBC (= AES-128-CBC), mcrypt zero-padding, bin2hex output. The adapter's openssl + manual zero-pad implementation matches — decrypted by legacy Java/Kotlin clients unchanged.
- `process_message: "OTP IS SUCCESSFULLY VERIFIED."` matches legacy `verifyotp` verbatim.
- Adapter payload = full field set (matches `checkloginnew_v2` shape incl. broker/belong_dealer/is_survey_form_submitted) and is a **superset** of `verifyotp`'s 9 fields — extra keys are ignored by typical parsers, but flag to Saathi team to confirm their parser tolerates extras.
- Data gaps in dump: saathi `changepassword` + `broker_master` exported with 0 rows, `user_details` absent. Acceptable because legacy auto-inserts `changepassword` defaults on first login (`newpassword='1234'`) and adapter defaults align. Brokers unsupported until broker_master data arrives (0 rows exist anyway).

### 1.3 Star Stellar — `ws_generate_otp…` / `ws_login_with_otp…` ⚠️
Field names match exactly for both TE and ENGINEER branches, including `the_te_id` being a JSON *string* — this actually matches legacy (mysqli returns strings). ✔

Gap: legacy prefixes profile images with server URL (`https://starstellar.com/te_profile_pic/<file>` or default `profile.png`); adapter returns `''`. **Fix during data sync**: store full URL into metadata attributes (`te_profile_image`, `e_profile_image`).

### 1.4 Star Link — `AuthController@sendOTP` + `login` ❌
Legacy login response `data` = `getProfile()` = **entire `users.*` row (~40 columns)** + `role_name` + `mason_category{name}` + conditional `te`/`mason`/`dealers` relations, keys alphabetically sorted (ksort).

Adapter emits only 16 curated fields. Any app UI reading e.g. `designation`, `address`, `pincode`, `dob`, `district`, `profile_pic`, `preferred_app_lang`, `parent`, `fcm_token` breaks.

Mitigation (no adapter rewrite needed): store the **full users row** per user into `user_product_metadata.attributes` for star_link — the adapter already merges unknown attribute keys into `data`. Remaining parity details after that:
- `points` must be a string ("0"), not int.
- `role_name` labels: 1→"BDE", 2→"Contractor", 3→"Dealer", 4→"RSSD" (note role 2 = Mason internally but label "Contractor").
- `te`: `{id,name,phone,emp_code}` when role=2 & parent set; `mason` object for roles 3/4; `dealers` array for role=2 (from mason_dealers pivot — we HAVE that dump).
- Key order ksorted (only matters if client does strict diffing — unlikely).

**🚫 BLOCKER: the starLink SQL folder has no `users` table dump** (only `mason_dealers` pivot, logs, etc.). Request `SELECT * FROM users` export from the StarLink prod DB before Link testing.

---

## 2. Real-Data Inventory (from product-db-structures)

| Source | Table | Rows | Phone column | Quality |
|---|---|---|---|---|
| Stellar | te_master | 285 | te_mobile_no | 100% clean 10-digit |
| Stellar | engineer_master | 3,771 | e_mobile | 100% clean 10-digit |
| SFA | employee_master (+changepassword) | 2,519 | phone_no | ~70% clean; rest blank / `1E+12` Excel junk / slash-duals |
| Saathi | customer_master | 78,623 | phone_no | 98.6% clean 10-digit |
| Link | mason_dealers pivot only | 103,901 | — | users table missing |

Security note for the meeting: legacy stores plaintext passwords (SFA changepassword) and leaks password hash + remember_token + aadhaar_no through getProfile — SSO removes these exposures going forward.

---

## 3. Integration Plan

### Phase A — Missing inputs (ask team)
1. **starLink `users` table .sql export** (required).
2. Optional: saathi `changepassword` + `broker_master` exports.
3. Confirm base URLs for image prefixes (starstellar.com) to embed in payloads.

### Phase B — Staging import
Import each dump into its own schema on the existing MariaDB (127.0.0.1:3307):
```sql
CREATE DATABASE src_stellar; CREATE DATABASE src_sfa; CREATE DATABASE src_saathi; CREATE DATABASE src_link;
-- then: mysql -P 3307 -u root -p src_stellar < starsaat_STELLAR.sql etc.
```

### Phase C — Sync command `php artisan sso:sync-legacy`
One upsert-per-mobile flow per product (upsert on `users.mobile_number`; add `user_product_access` + `user_product_metadata` rows if absent):

- **StellarSyncer** (te_master where acedns='Y'; engineer_master where status='ACTIVE'):
  attributes TE: `{user_type:'TE', the_te_id:String(te_id), the_te_name, the_te_code, the_te_mobile_no, the_te_email, te_profile_image:<URL prefix+file or default>}`
  attributes ENGINEER: `{user_type:'ENGINEER', the_engineer_id:String(eid), e_name, e_mobile, te_code, e_email, e_dob, e_dom, e_address, e_pin, e_state, e_city_town, e_profile_image:<URL>}`
- **SfaSyncer** (employee_master JOIN changepassword ON emp_code; skip blank/junk phones; dual `a/b` → take first):
  attributes `{emp_code, emp_name, sale_access, newpassword, deviceid, acedns}` (+ store is_licensed)
- **SaathiSyncer** (customer_master LEFT JOIN changepassword ON customer_code; resolve belong_dealer_* via rds_tag → customer_master lookup):
  attributes `{user_type: LOWER(cust_type), emp_code/customer_code, dns_emp_code:dns_customer_code, emp_name:customer_name, sale_access:'PRIMARY', newpassword ?? '1234', deviceid ?? '', phonenumber, acedns, mail_id:email, state_code, belong_dealer_code/dns_code/name, is_survey_form_submitted:'NO'}`
- **LinkSyncer** (once users dump arrives; role IN (1,2), status=1): snapshot full row → attributes + compute dealers array from mason_dealers pivot.

Phone normalization rules: strip non-digits, drop empties/`1E+12`/non-10-digit, dedupe within source, log skipped rows to a report table/file. Cross-product collisions (same phone in 2 products) are handled naturally: one central user, multiple access rows.

### Phase D — Pre-demo sanity
```bash
php artisan sso:sync-legacy            # run all
php artisan sso:sync-legacy --product=star_steller   # individually
```
Compare counts: synced vs source rows; review skipped-phone log.

---

## 4. Demo/Test Plan (real-data proof)

1. **Pick real users** per persona: 1 TE (acedns=Y), 1 engineer ACTIVE, 1 SFA employee w/ clean phone, 1 saathi Dealer (acedns='Y'), plus negatives: acedns='N' user, INACTIVE engineer.
2. **Happy path per product** (EchoAPI collection): send-otp → verify-otp (token saved) → launch/{product}.
   - SFA: assert XML equals legacy field set; compare against live `https://sfa.starcement.co.in/emplogin-check-6.0.1.php` output side-by-side.
   - Saathi: copy hex → `php decrypt-saathi.php <hex>` → show decrypted JSON next to legacy decrypted payload.
   - Stellar: diff keys against `ws_login_with_otp…` sample.
   - Link: diff keys against live `/api/v1/auth/login` sample (after blocker resolved).
3. **Negative tests**: launch without access → 403 "Unauthorized panel access"; wrong OTP → 401 INVALID_OTP; unknown mobile → 404 User not found; bad token → 401 Unauthenticated.
4. **Consistency proof**: same mobile across products → one token, allowed_products list correct, each launch returns that product's exact legacy shape.

## 5. Open Decisions for the Team
1. Link payload: accept superset strategy (full users.* in metadata) — needs users-table dump.
2. Saathi parser tolerance for extra fields (adapter returns checkloginnew_v2 superset at verifyotp stage).
3. Device binding (SFA/Saathi) intentionally bypassed at launch — confirm acceptable.
4. Per-product licence gating (`acedds N` / INACTIVE) currently returns success if access row exists — recommend enforcing in adapters/middleware before production (return legacy-style error text).
5. JWT algo HS256 shared-secret OK for UAT; move to RS256 for production per README checklist.
