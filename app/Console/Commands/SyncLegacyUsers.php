<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncLegacyUsers extends Command
{
    protected $signature = 'sso:sync-legacy {--product=* : star_steller, star_sfa, star_saathi, star_link} {--refresh : Overwrite metadata for already-synced users}';

    protected $description = 'Sync legacy product users from staging source DBs into the SSO database';

    private array $userMap = [];
    private array $accessSet = [];
    private array $metaSet = [];
    private array $stats = [];

    public function handle(): int
    {
        $syncers = [
            'star_steller' => 'syncStellar',
            'star_sfa' => 'syncSfa',
            'star_saathi' => 'syncSaathi',
            'star_link' => 'syncLink',
        ];

        $only = array_filter((array) $this->option('product'));
        if ($only) {
            foreach ($only as $slug) {
                if (!isset($syncers[$slug])) {
                    $this->error("Unknown product [$slug]. Available: " . implode(', ', array_keys($syncers)));

                    return self::FAILURE;
                }
            }
            $syncers = array_intersect_key($syncers, array_flip($only));
        }

        $this->preloadMaps();

        foreach ($syncers as $slug => $method) {
            $this->info("=== Syncing [{$slug}] ===");
            $this->stats = ['processed' => 0, 'users_created' => 0, 'skipped_phone' => 0, 'dupe_source' => 0];
            $this->{$method}($this->product($slug));
            $this->table(['metric', 'count'], collect($this->stats)->map(fn ($v, $k) => [$k, $v])->values()->all());
        }

        return self::SUCCESS;
    }

    // ---------------------------------------------------------------- Stellar

    private function syncStellar(object $product): void
    {
        $src = $this->source('src_stellar');
        $baseUrl = rtrim(env('STAR_STELLAR_URL', 'https://starstellar.com'), '/');

        foreach ($src->table('te_master')->where('acedns', 'Y')->cursor() as $te) {
            $phone = $this->normalizePhone($te->te_mobile_no);
            if (!$phone) {
                $this->bump('skipped_phone');
                continue;
            }

            $attributes = [
                'user_type' => 'TE',
                'the_te_id' => (string) $te->te_id,
                'the_te_name' => $te->te_name ?? '',
                'the_te_code' => $te->te_code ?? '',
                'the_te_mobile_no' => $phone,
                'the_te_email' => $te->te_email ?? '',
                'te_profile_image' => $baseUrl . '/te_profile_pic/' . (($te->te_profile_image ?? '') !== '' ? $te->te_profile_image : 'profile.png'),
            ];

            $userId = $this->ensureUser($phone, $te->te_name ?? '', $te->te_email ?? null);
            $this->ensureAccessAndMeta($userId, $product->id, 'TE', $attributes);
        }

        foreach ($src->table('engineer_master')->where('status', 'ACTIVE')->cursor() as $en) {
            $phone = $this->normalizePhone($en->e_mobile);
            if (!$phone) {
                $this->bump('skipped_phone');
                continue;
            }

            $attributes = [
                'user_type' => 'ENGINEER',
                'the_engineer_id' => (string) $en->eid,
                'e_name' => $en->e_name ?? '',
                'e_mobile' => $phone,
                'te_code' => $en->te_code ?? '',
                'e_email' => $en->e_email ?? '',
                'e_dob' => $en->e_dob ?? '',
                'e_dom' => $en->e_dom ?? '',
                'e_address' => $en->e_address ?? '',
                'e_pin' => $en->e_pin ?? '',
                'e_state' => $en->e_state ?? '',
                'e_city_town' => $en->e_city_town ?? '',
                'e_profile_image' => $baseUrl . '/en_profile_pic/' . (($en->e_profile_image ?? '') !== '' ? $en->e_profile_image : 'profile.png'),
            ];

            $userId = $this->ensureUser($phone, $en->e_name ?? '', $en->e_email ?? null);
            $this->ensureAccessAndMeta($userId, $product->id, 'Site Engineer', $attributes);
        }
    }

    // -------------------------------------------------------------------- SFA

    private function syncSfa(object $product): void
    {
        $src = $this->source('src_sfa');

        $rows = $src->table('employee_master as e')
            ->join('changepassword as c', 'e.emp_code', '=', 'c.emp_code')
            ->whereRaw("UPPER(e.acedns) = 'Y'")
            ->select(
                'e.emp_code', 'e.emp_name', 'e.sale_access', 'e.acedns',
                'e.email', 'e.phone_no', 'c.newpassword', 'c.deviceid', 'c.is_licensed'
            )
            ->cursor();

        foreach ($rows as $emp) {
            $phone = $this->normalizePhone($emp->phone_no);
            if (!$phone) {
                $this->bump('skipped_phone');
                continue;
            }

            $attributes = [
                'emp_code' => $emp->emp_code,
                'emp_name' => $emp->emp_name ?? '',
                'sale_access' => $emp->sale_access ?? 'Primary',
                'newpassword' => $emp->newpassword ?? '1234',
                'deviceid' => $emp->deviceid ?? '',
                'acedns' => $emp->acedns ?? 'Y',
            ];
            if ($emp->is_licensed !== null) {
                $attributes['is_licensed'] = $emp->is_licensed;
            }

            $userId = $this->ensureUser($phone, $emp->emp_name ?? '', $emp->email ?: null);
            $this->ensureAccessAndMeta($userId, $product->id, 'Employee', $attributes);
        }
    }

    // ----------------------------------------------------------------- Saathi

    /**
     * cust_type substrings (lowercase) that identify Ship-to-Party aliases.
     * These customers already have a primary Dealer/Sub-dealer entry in
     * customer_master.  We skip them entirely so the primary entry is the
     * only one that lands in user_product_access / user_product_metadata.
     */
    private const SAATHI_SHIP_TO_PARTY = ['ship to party', 'shiptoparty'];

    private function isShipToParty(string $custType): bool
    {
        $lower = strtolower(trim($custType));
        foreach (self::SAATHI_SHIP_TO_PARTY as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }
        return false;
    }

    private function syncSaathi(object $product): void
    {
        $src = $this->source('src_saathi');
        static $dealerLookup = [];

        $resolveDealer = function (?string $code) use ($src, &$dealerLookup): array {
            if (!$code) {
                return ['', '', ''];
            }
            if (!isset($dealerLookup[$code])) {
                $row = $src->table('customer_master')
                    ->where('customer_code', $code)
                    ->first(['dns_customer_code', 'customer_name']);
                $dealerLookup[$code] = $row ? [(string) $row->dns_customer_code, (string) $row->customer_name] : ['', ''];
            }
            return [$code, $dealerLookup[$code][0], $dealerLookup[$code][1]];
        };

        // Order by cust_type so that Dealer/Sub-dealer rows are processed BEFORE
        // any Ship-to-Party rows.  If both share a phone number, the primary role
        // is written first and the Ship-to-Party row is skipped entirely.
        $rows = $src->table('customer_master')
            ->whereRaw("UPPER(acedns) = 'Y'")
            ->select('customer_id', 'customer_code', 'dns_customer_code', 'customer_name', 'phone_no', 'email', 'cust_type', 'rds_tag', 'state_code', 'sms_otp')
            ->orderByRaw("CASE WHEN LOWER(cust_type) LIKE '%ship to party%' OR LOWER(cust_type) LIKE '%shiptoparty%' THEN 1 ELSE 0 END ASC")
            ->cursor();

        foreach ($rows as $cust) {
            $phone = $this->normalizePhone($cust->phone_no);
            if (!$phone) {
                $this->bump('skipped_phone');
                continue;
            }

            // STRICT RULE: Ship-to-Party roles must NEVER get their own SSO entry.
            // They always have a corresponding Dealer/Sub-dealer row that will
            // (or has already) been processed for the same phone number.
            if ($this->isShipToParty($cust->cust_type ?? '')) {
                $this->bump('skipped_ship_to_party');
                continue;
            }

            [$bdCode, $bdDns, $bdName] = $resolveDealer($cust->rds_tag ?: null);

            $attributes = [
                'user_type' => strtolower($cust->cust_type ?: 'dealer'),
                'emp_code' => $cust->customer_code,
                'customer_code' => $cust->customer_code,
                'dns_emp_code' => $cust->dns_customer_code ?? $cust->customer_code,
                'emp_name' => $cust->customer_name ?? '',
                'sale_access' => 'PRIMARY',
                'newpassword' => '1234',
                'deviceid' => '',
                'phonenumber' => $phone,
                'acedns' => 'Y',
                'broker_id' => '',
                'dns_broker_id' => '',
                'contact_person' => '',
                'mail_id' => $cust->email ?? '',
                'brokerage_cost' => '',
                'state_code' => $cust->state_code ?? '',
                'belong_dealer_code' => $bdCode,
                'belong_dealer_dns_code' => $bdDns,
                'belong_dealer_name' => $bdName,
                'is_survey_form_submitted' => 'NO',
            ];

            $userId = $this->ensureUser($phone, $cust->customer_name ?? '', $cust->email ?: null);

            // Pass overwrite=true so that if a stale Ship-to-Party row somehow
            // exists for this user+product (from a previous sync run), the dealer
            // row replaces it immediately.
            $this->ensureAccessAndMeta($userId, $product->id, strtolower($cust->cust_type ?: 'dealer'), $attributes, overwriteExisting: true);
        }
    }

    // ------------------------------------------------------------------- Link

    private function syncLink(object $product): void
    {
        $src = $this->source('src_link');
        static $teCache = [];

        // Legacy AuthController only allows role 1 (TE/BDE) and 2 (Mason) to log in; active accounts only
        $categories = $src->table('mason_categories')->get()->all();

        // mason_id => [{id,name,phone,emp_code}, ...] (compact dealer cards, same shape legacy apps consume)
        $dealersByMason = [];
        foreach (
            $src->table('mason_dealers as md')
                ->join('users as d', 'd.id', '=', 'md.dealer_id')
                ->select('md.mason_id', 'd.id', 'd.name', 'd.phone', 'd.emp_code')
                ->cursor() as $l
        ) {
            $dealersByMason[(int) $l->mason_id][] = [
                'id' => (int) $l->id,
                'name' => $l->name,
                'phone' => $l->phone,
                'emp_code' => $l->emp_code,
            ];
        }
        $this->line('  preloaded dealer links for ' . count($dealersByMason) . ' masons');

        $linkBase = rtrim(env('STAR_LINK_URL', ''), '/');

        foreach ($src->table('users')->whereIn('role', [1, 2])->where('status', 1)->cursor() as $u) {
            $phone = $this->normalizePhone($u->phone);
            if (!$phone) {
                $this->bump('skipped_phone');
                continue;
            }

            $role = (int) $u->role;
            $roleName = $role === 1 ? 'BDE' : 'Contractor';

            // Full users.* snapshot like legacy getProfile(), minus secrets we refuse to expose
            $attributes = [];
            foreach ((array) $u as $k => $v) {
                if (in_array($k, ['password', 'remember_token'], true)) {
                    continue;
                }
                $attributes[$k] = $v;
            }
            $attributes['phone'] = $phone;
            $attributes['role_name'] = $roleName;

            // Appended accessor: full category row matched by points range, or null.
            // Legacy uses !empty($points): null/''/0/"0" all mean NO category (even though MEMBER starts at 0).
            $category = null;
            if (!empty($u->points)) {
                foreach ($categories as $c) {
                    if ((int) $c->from_point <= (int) $u->points && (int) $c->to_point >= (int) $u->points) {
                        $category = (array) $c;
                        break;
                    }
                }
            }
            $attributes['mason_category'] = $category;

            // Appended doc links need the product domain to be meaningful
            $attributes['aadhaar_doc_link'] = ($linkBase && $u->aadhaar_doc) ? $linkBase . '/public/aadhaar/' . $u->aadhaar_doc : null;
            $attributes['voter_doc_link'] = ($linkBase && $u->voter_doc) ? $linkBase . '/' . $u->voter_doc : null;

            if ($role === 2) {
                $attributes['dealers'] = $dealersByMason[(int) $u->id] ?? [];
                $attributes['te'] = null;
                if (!empty($u->parent)) {
                    $pid = (int) $u->parent;
                    if (!array_key_exists($pid, $teCache)) {
                        $p = $src->table('users')->where('id', $pid)->first(['id', 'name', 'phone', 'emp_code']);
                        $teCache[$pid] = $p ? [
                            'id' => (int) $p->id,
                            'name' => $p->name,
                            'phone' => $p->phone,
                            'emp_code' => $p->emp_code,
                        ] : null;
                    }
                    $attributes['te'] = $teCache[$pid];
                }
            } else {
                $attributes['dealers'] = [];
                $attributes['te'] = null;
            }

            $userId = $this->ensureUser($phone, $u->name ?? '', $u->email ?: null);
            $this->ensureAccessAndMeta($userId, $product->id, $roleName, $attributes);
        }
    }

    // ------------------------------------------------------------- shared ops

    private function preloadMaps(): void
    {
        foreach (DB::table('users')->select('id', 'mobile_number')->get() as $u) {
            $this->userMap[$u->mobile_number] = $u->id;
        }
        foreach (DB::table('user_product_access')->select('user_id', 'product_id')->get() as $a) {
            $this->accessSet["{$a->user_id}:{$a->product_id}"] = true;
        }
        foreach (DB::table('user_product_metadata')->select('user_id', 'product_id')->get() as $m) {
            $this->metaSet["{$m->user_id}:{$m->product_id}"] = true;
        }
    }

    private function ensureUser(string $phone, string $name, ?string $email): int
    {
        if (isset($this->userMap[$phone])) {
            return $this->userMap[$phone];
        }

        $now = now();
        $id = (int) DB::table('users')->insertGetId([
            'mobile_number' => $phone,
            'name' => $name ?: $phone,
            'email' => $email,
            'emp_code' => null, // product-specific codes stay in metadata (global UNIQUE constraint)
            'status' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->userMap[$phone] = $id;
        $this->bump('users_created');

        return $id;
    }

    private function ensureAccessAndMeta(int $userId, int $productId, string $roleName, array $attributes, bool $overwriteExisting = false): void
    {
        $key = "{$userId}:{$productId}";

        if (!isset($this->accessSet[$key])) {
            $now = now();
            DB::table('user_product_access')->insert([
                'user_id'    => $userId,
                'product_id' => $productId,
                'role_name'  => mb_substr($roleName, 0, 100),
                'is_primary' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->accessSet[$key] = true;
        } else {
            // Always update the role_name — this allows a dealer row processed
            // after a stale ship-to-party row to overwrite the wrong role.
            DB::table('user_product_access')
                ->where('user_id', $userId)->where('product_id', $productId)
                ->update(['role_name' => mb_substr($roleName, 0, 100)]);
        }

        if (!isset($this->metaSet[$key])) {
            $now = now();
            DB::table('user_product_metadata')->insert([
                'user_id'    => $userId,
                'product_id' => $productId,
                'attributes' => json_encode($attributes),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->metaSet[$key] = true;
        } elseif ($overwriteExisting || $this->option('refresh')) {
            // $overwriteExisting: dealer row replacing a previously-written ship-to-party row.
            // --refresh flag: full re-sync requested by the operator.
            DB::table('user_product_metadata')
                ->where('user_id', $userId)->where('product_id', $productId)
                ->update(['attributes' => json_encode($attributes)]);
        } else {
            $this->bump('shared_phone_kept_first');
        }

        $this->bump('processed');
    }

    private function normalizePhone(?string $raw): ?string
    {
        if (!$raw) {
            return null;
        }
        $raw = trim($raw);
        if (str_contains($raw, '/')) { // dual numbers like "8013413800/7001318152"
            $raw = trim(explode('/', $raw)[0]);
        }
        $digits = preg_replace('/\D/', '', $raw);
        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            $digits = substr($digits, 2);
        }

        return (strlen($digits) === 10 && $digits[0] >= '6') ? $digits : null;
    }

    private function product(string $slug): object
    {
        $p = DB::table('products')->where('slug', $slug)->first();
        if (!$p) {
            $now = now();
            DB::table('products')->insert(['slug' => $slug, 'name' => ucwords(str_replace('_', ' ', $slug)), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);

            return DB::table('products')->where('slug', $slug)->first();
        }

        return $p;
    }

    private function bump(string $key): void
    {
        $this->stats[$key] = ($this->stats[$key] ?? 0) + 1;

        $done = $this->stats['processed'] + $this->stats['skipped_phone'];
        if ($done % 10000 === 0) {
            $this->line("  ... {$done} rows");
        }
    }

    private function source(string $database)
    {
        $base = config('database.connections.mariadb');
        config(["database.connections.{$database}" => array_merge($base, ['database' => $database])]);
        DB::purge($database);

        return DB::connection($database);
    }
}
