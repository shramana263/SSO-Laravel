<?php

namespace App\Services;

use App\Models\User;
use App\Models\Product;
use App\Models\UserProductAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LegacyUserSyncService
{
    private const SAATHI_SHIP_TO_PARTY = ['ship to party', 'shiptoparty'];

    /**
     * Just-In-Time (JIT) sync: Query all 4 legacy product databases for a specific mobile number
     * and provision/update central SSO users, product access, and metadata on the fly.
     */
    public function syncUserByMobile(string $rawPhone): ?User
    {
        $phone = $this->normalizePhone($rawPhone);
        if (!$phone) {
            return null;
        }

        $phoneVariations = [
            $phone,
            "+91{$phone}",
            "91{$phone}",
            "0{$phone}",
        ];

        $foundAny = false;
        $matchedRoles = []; // product_slug => [ 'role' => string, 'attributes' => array, 'name' => string, 'email' => ?string ]
        $userName = $phone;
        $userEmail = null;

        // 1. Check Star Stellar (TE & Site Engineer)
        try {
            $stellarData = $this->checkStellar($phoneVariations);
            if ($stellarData) {
                $foundAny = true;
                $matchedRoles['star_steller'] = $stellarData;
                $userName = $stellarData['name'] ?: $userName;
                $userEmail = $stellarData['email'] ?: $userEmail;
            }
        } catch (\Throwable $e) {
            Log::warning("JIT Stellar lookup error for phone {$phone}: " . $e->getMessage());
        }

        // 2. Check Star SFA (Employee)
        try {
            $sfaData = $this->checkSfa($phoneVariations);
            if ($sfaData) {
                $foundAny = true;
                $matchedRoles['star_sfa'] = $sfaData;
                $userName = $sfaData['name'] ?: $userName;
                $userEmail = $sfaData['email'] ?: $userEmail;
            }
        } catch (\Throwable $e) {
            Log::warning("JIT SFA lookup error for phone {$phone}: " . $e->getMessage());
        }

        // 3. Check Star Saathi (Dealer / Retailer / Broker)
        try {
            $saathiData = $this->checkSaathi($phoneVariations);
            if ($saathiData) {
                $foundAny = true;
                $matchedRoles['star_saathi'] = $saathiData;
                $userName = $saathiData['name'] ?: $userName;
                $userEmail = $saathiData['email'] ?: $userEmail;
            }
        } catch (\Throwable $e) {
            Log::warning("JIT Saathi lookup error for phone {$phone}: " . $e->getMessage());
        }

        // 4. Check Star Link (BDE / Contractor)
        try {
            $linkData = $this->checkLink($phoneVariations);
            if ($linkData) {
                $foundAny = true;
                $matchedRoles['star_link'] = $linkData;
                $userName = $linkData['name'] ?: $userName;
                $userEmail = $linkData['email'] ?: $userEmail;
            }
        } catch (\Throwable $e) {
            Log::warning("JIT Link lookup error for phone {$phone}: " . $e->getMessage());
        }

        if (!$foundAny) {
            return null;
        }

        // Upsert into Central users table
        $user = User::updateOrCreate(
            ['mobile_number' => $phone],
            [
                'name'       => $userName,
                'email'      => $userEmail,
                'status'     => true,
            ]
        );

        $now = now();

        // Provision/Update product access and metadata
        foreach ($matchedRoles as $slug => $data) {
            $product = Product::firstOrCreate(
                ['slug' => $slug],
                [
                    'name'      => ucwords(str_replace('_', ' ', $slug)),
                    'is_active' => true,
                ]
            );

            // Upsert User Product Access
            DB::table('user_product_access')->updateOrInsert(
                [
                    'user_id'    => $user->id,
                    'product_id' => $product->id,
                ],
                [
                    'role_name'  => mb_substr($data['role'], 0, 100),
                    'is_primary' => false,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }

        return $user->fresh(['productAccess.product']);
    }

    // -------------------------------------------------------------------------
    // Individual Product Checkers
    // -------------------------------------------------------------------------

    private function checkStellar(array $phoneVariations): ?array
    {
        $src = $this->source('src_stellar');
        $baseUrl = rtrim(env('STAR_STELLAR_URL', 'https://starstellar.com'), '/');

        // Check TE
        $te = $src->table('te_master')
            ->whereIn('te_mobile_no', $phoneVariations)
            ->where('acedns', 'Y')
            ->first();

        if ($te) {
            $phone = $this->normalizePhone($te->te_mobile_no);
            return [
                'role'  => 'TE',
                'name'  => $te->te_name ?? '',
                'email' => $te->te_email ?? null,
                'attributes' => [
                    'user_type'        => 'TE',
                    'the_te_id'        => (string) $te->te_id,
                    'the_te_name'      => $te->te_name ?? '',
                    'the_te_code'      => $te->te_code ?? '',
                    'the_te_mobile_no' => $phone,
                    'the_te_email'     => $te->te_email ?? '',
                    'te_profile_image' => $baseUrl . '/te_profile_pic/' . (($te->te_profile_image ?? '') !== '' ? $te->te_profile_image : 'profile.png'),
                ],
            ];
        }

        // Check Site Engineer
        $en = $src->table('engineer_master')
            ->whereIn('e_mobile', $phoneVariations)
            ->where('status', 'ACTIVE')
            ->first();

        if ($en) {
            $phone = $this->normalizePhone($en->e_mobile);
            return [
                'role'  => 'Site Engineer',
                'name'  => $en->e_name ?? '',
                'email' => $en->e_email ?? null,
                'attributes' => [
                    'user_type'        => 'ENGINEER',
                    'the_engineer_id'  => (string) $en->eid,
                    'e_name'           => $en->e_name ?? '',
                    'e_mobile'         => $phone,
                    'te_code'          => $en->te_code ?? '',
                    'e_email'          => $en->e_email ?? '',
                    'e_dob'            => $en->e_dob ?? '',
                    'e_dom'            => $en->e_dom ?? '',
                    'e_address'        => $en->e_address ?? '',
                    'e_pin'            => $en->e_pin ?? '',
                    'e_state'          => $en->e_state ?? '',
                    'e_city_town'      => $en->e_city_town ?? '',
                    'e_profile_image'  => $baseUrl . '/en_profile_pic/' . (($en->e_profile_image ?? '') !== '' ? $en->e_profile_image : 'profile.png'),
                ],
            ];
        }

        return null;
    }

    private function checkSfa(array $phoneVariations): ?array
    {
        $src = $this->source('src_sfa');

        $emp = $src->table('employee_master as e')
            ->join('changepassword as c', 'e.emp_code', '=', 'c.emp_code')
            ->whereIn('e.phone_no', $phoneVariations)
            ->whereRaw("UPPER(e.acedns) = 'Y'")
            ->select(
                'e.emp_code', 'e.emp_name', 'e.sale_access', 'e.acedns',
                'e.email', 'e.phone_no', 'c.newpassword', 'c.deviceid', 'c.is_licensed'
            )
            ->first();

        if ($emp) {
            $attributes = [
                'emp_code'    => $emp->emp_code,
                'emp_name'    => $emp->emp_name ?? '',
                'sale_access' => $emp->sale_access ?? 'Primary',
                'newpassword' => $emp->newpassword ?? '1234',
                'deviceid'    => $emp->deviceid ?? '',
                'acedns'      => $emp->acedns ?? 'Y',
            ];
            if ($emp->is_licensed !== null) {
                $attributes['is_licensed'] = $emp->is_licensed;
            }

            return [
                'role'       => 'Employee',
                'name'       => $emp->emp_name ?? '',
                'email'      => $emp->email ?: null,
                'attributes' => $attributes,
            ];
        }

        return null;
    }

    private function checkSaathi(array $phoneVariations): ?array
    {
        $src = $this->source('src_saathi');

        // Check customer_master
        $cust = $src->table('customer_master')
            ->whereIn('phone_no', $phoneVariations)
            ->whereRaw("UPPER(acedns) = 'Y'")
            ->orderByRaw("CASE WHEN LOWER(cust_type) LIKE '%ship to party%' OR LOWER(cust_type) LIKE '%shiptoparty%' THEN 1 ELSE 0 END ASC")
            ->first();

        if ($cust && !$this->isShipToParty($cust->cust_type ?? '')) {
            $bdCode = '';
            $bdDns = '';
            $bdName = '';
            if (!empty($cust->rds_tag)) {
                $dealer = $src->table('customer_master')
                    ->where('customer_code', $cust->rds_tag)
                    ->first(['dns_customer_code', 'customer_name']);
                if ($dealer) {
                    $bdCode = (string) $cust->rds_tag;
                    $bdDns = (string) $dealer->dns_customer_code;
                    $bdName = (string) $dealer->customer_name;
                }
            }

            return [
                'role'  => strtolower($cust->cust_type ?: 'dealer'),
                'name'  => $cust->customer_name ?? '',
                'email' => $cust->email ?: null,
                'attributes' => [
                    'user_type'                => strtolower($cust->cust_type ?: 'dealer'),
                    'emp_code'                 => $cust->customer_code,
                    'customer_code'            => $cust->customer_code,
                    'dns_emp_code'             => $cust->dns_customer_code ?? $cust->customer_code,
                    'emp_name'                 => $cust->customer_name ?? '',
                    'sale_access'              => 'PRIMARY',
                    'newpassword'              => '1234',
                    'deviceid'                 => '',
                    'phonenumber'              => $this->normalizePhone($cust->phone_no),
                    'acedns'                   => 'Y',
                    'broker_id'                => '',
                    'dns_broker_id'            => '',
                    'contact_person'           => '',
                    'mail_id'                  => $cust->email ?? '',
                    'brokerage_cost'           => '',
                    'state_code'               => $cust->state_code ?? '',
                    'belong_dealer_code'       => $bdCode,
                    'belong_dealer_dns_code'   => $bdDns,
                    'belong_dealer_name'       => $bdName,
                    'is_survey_form_submitted' => 'NO',
                ],
            ];
        }

        // Check broker_master
        if ($src->getSchemaBuilder()->hasTable('broker_master')) {
            $broker = $src->table('broker_master')
                ->whereIn('phone_no', $phoneVariations)
                ->whereRaw("UPPER(acedns) = 'Y'")
                ->first();

            if ($broker) {
                $phone = $this->normalizePhone($broker->phone_no);
                return [
                    'role'  => 'broker',
                    'name'  => $broker->broker_name ?? '',
                    'email' => $broker->mail_id ?: null,
                    'attributes' => [
                        'user_type'                => 'broker',
                        'emp_code'                 => (string) ($broker->dns_broker_id ?: $broker->broker_id),
                        'dns_emp_code'             => (string) ($broker->dns_broker_id ?: $broker->broker_id),
                        'emp_name'                 => $broker->broker_name ?? '',
                        'sale_access'              => 'PRIMARY',
                        'newpassword'              => '',
                        'deviceid'                 => '',
                        'phonenumber'              => $phone,
                        'acedns'                   => $broker->acedns ?? 'Y',
                        'broker_id'                => (string) ($broker->broker_id ?? ''),
                        'dns_broker_id'            => (string) ($broker->dns_broker_id ?? ''),
                        'contact_person'           => (string) ($broker->contact_person ?? ''),
                        'mail_id'                  => $broker->mail_id ?? '',
                        'brokerage_cost'           => (string) ($broker->brokerage_cost ?? ''),
                        'state_code'               => (string) ($broker->state_code ?? ''),
                        'belong_dealer_code'       => '',
                        'belong_dealer_dns_code'   => '',
                        'belong_dealer_name'       => '',
                        'is_survey_form_submitted' => 'NO',
                    ],
                ];
            }
        }

        return null;
    }

    private function checkLink(array $phoneVariations): ?array
    {
        $src = $this->source('src_link');
        $linkBase = rtrim(env('STAR_LINK_URL', ''), '/');

        $u = $src->table('users')
            ->whereIn('phone', $phoneVariations)
            ->whereIn('role', [1, 2])
            ->where('status', 1)
            ->first();

        if ($u) {
            $phone = $this->normalizePhone($u->phone);
            $role = (int) $u->role;
            $roleName = $role === 1 ? 'BDE' : 'Contractor';

            $attributes = [];
            foreach ((array) $u as $k => $v) {
                if (in_array($k, ['password', 'remember_token'], true)) {
                    continue;
                }
                $attributes[$k] = $v;
            }
            $attributes['phone'] = $phone;
            $attributes['role_name'] = $roleName;

            // Categories
            $categories = $src->table('mason_categories')->get()->all();
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
            $attributes['aadhaar_doc_link'] = ($linkBase && !empty($u->aadhaar_doc)) ? $linkBase . '/public/aadhaar/' . $u->aadhaar_doc : null;
            $attributes['voter_doc_link'] = ($linkBase && !empty($u->voter_doc)) ? $linkBase . '/' . $u->voter_doc : null;

            if ($role === 2) {
                $dealers = $src->table('mason_dealers as md')
                    ->join('users as d', 'd.id', '=', 'md.dealer_id')
                    ->where('md.mason_id', $u->id)
                    ->select('d.id', 'd.name', 'd.phone', 'd.emp_code')
                    ->get()
                    ->toArray();
                $attributes['dealers'] = array_map(fn($d) => (array)$d, $dealers);
                $attributes['te'] = null;
                if (!empty($u->parent)) {
                    $p = $src->table('users')->where('id', (int) $u->parent)->first(['id', 'name', 'phone', 'emp_code']);
                    $attributes['te'] = $p ? (array) $p : null;
                }
            } else {
                $attributes['dealers'] = [];
                $attributes['te'] = null;
            }

            return [
                'role'       => $roleName,
                'name'       => $u->name ?? '',
                'email'      => $u->email ?: null,
                'attributes' => $attributes,
            ];
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

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

    public function normalizePhone(?string $raw): ?string
    {
        if (!$raw) {
            return null;
        }
        $raw = trim($raw);
        if (str_contains($raw, '/')) {
            $raw = trim(explode('/', $raw)[0]);
        }
        $digits = preg_replace('/\D/', '', $raw);
        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            $digits = substr($digits, 2);
        }

        return (strlen($digits) === 10 && $digits[0] >= '6') ? $digits : null;
    }

    private function source(string $database)
    {
        $base = config('database.connections.mariadb');
        config(["database.connections.{$database}" => array_merge($base, ['database' => $database])]);
        DB::purge($database);

        return DB::connection($database);
    }
}
