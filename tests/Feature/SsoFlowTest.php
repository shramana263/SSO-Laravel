<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Product;
use App\Models\Otp;
use App\Models\UserProductAccess;
use App\Models\UserProductMetadata;
use App\Services\Sso\Adapters\StarSaathiAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

class SsoFlowTest extends TestCase
{
    use RefreshDatabase;

    protected User $multiProductUser;
    protected User $singleProductUser;
    protected Product $sfaProduct;
    protected Product $saathiProduct;
    protected Product $linkProduct;
    protected Product $stellerProduct;

    protected function setUp(): void
    {
        parent::setUp();

        // Create the 4 products
        $this->sfaProduct = Product::create(['slug' => 'star_sfa', 'name' => 'Star SFA', 'is_active' => true]);
        $this->saathiProduct = Product::create(['slug' => 'star_saathi', 'name' => 'Star Saathi', 'is_active' => true]);
        $this->linkProduct = Product::create(['slug' => 'star_link', 'name' => 'Star Link', 'is_active' => true]);
        $this->stellerProduct = Product::create(['slug' => 'star_steller', 'name' => 'Star Stellar', 'is_active' => true]);

        // Create Multi-Product User (e.g. BDE)
        $this->multiProductUser = User::create([
            'mobile_number' => '9876543210',
            'name' => 'Priya BDE',
            'email' => 'priya@starone.com',
            'emp_code' => 'EMP1001',
            'status' => true,
        ]);

        // Map access for all 4 products for testing
        foreach ([$this->sfaProduct, $this->saathiProduct, $this->linkProduct, $this->stellerProduct] as $prod) {
            UserProductAccess::create([
                'user_id' => $this->multiProductUser->id,
                'product_id' => $prod->id,
                'role_name' => 'BDE',
            ]);
        }

        // Add metadata
        UserProductMetadata::create([
            'user_id' => $this->multiProductUser->id,
            'product_id' => $this->sfaProduct->id,
            'attributes' => ['emp_code' => 'EMP1001', 'sale_access' => 'Primary', 'deviceid' => 'DEV_SFA_1', 'newpassword' => '1234'],
        ]);
        UserProductMetadata::create([
            'user_id' => $this->multiProductUser->id,
            'product_id' => $this->saathiProduct->id,
            'attributes' => ['customer_code' => 'CUST1001', 'sale_access' => 'PRIMARY', 'deviceid' => 'DEV_SAATHI_1', 'user_type' => 'dealer'],
        ]);
        UserProductMetadata::create([
            'user_id' => $this->multiProductUser->id,
            'product_id' => $this->linkProduct->id,
            'attributes' => ['role' => 1, 'role_name' => 'BDE', 'city' => 'Guwahati'],
        ]);
        UserProductMetadata::create([
            'user_id' => $this->multiProductUser->id,
            'product_id' => $this->stellerProduct->id,
            'attributes' => ['user_type' => 'TE', 'the_te_id' => 'TE_101', 'the_te_code' => 'TE101', 'the_te_name' => 'Priya BDE'],
        ]);

        // Create Single Product User (e.g. Mason)
        $this->singleProductUser = User::create([
            'mobile_number' => '9123456789',
            'name' => 'Vikram Mason',
            'email' => 'vikram@starone.com',
            'emp_code' => 'EMP2002',
            'status' => true,
        ]);
        UserProductAccess::create([
            'user_id' => $this->singleProductUser->id,
            'product_id' => $this->linkProduct->id,
            'role_name' => 'Mason',
        ]);
        UserProductMetadata::create([
            'user_id' => $this->singleProductUser->id,
            'product_id' => $this->linkProduct->id,
            'attributes' => ['role' => 2, 'role_name' => 'Mason', 'city' => 'Kolkata'],
        ]);
    }

    public function test_send_otp_success_for_registered_user()
    {
        $response = $this->postJson('/api/v1/sso/send-otp', [
            'mobile_number' => '9876543210'
        ]);

        $response->assertStatus(200)
                 ->assertJson(['status' => true, 'message' => 'OTP sent successfully']);
    }

    public function test_send_otp_fails_for_non_existent_user()
    {
        $response = $this->postJson('/api/v1/sso/send-otp', [
            'mobile_number' => '0000000000'
        ]);

        $response->assertStatus(404)
                 ->assertJson(['status' => false, 'message' => 'User not found']);
    }

    public function test_verify_otp_returns_session_token_and_allowed_products()
    {
        // OTPs are always dynamically generated; fetch the fresh code from DB
        $this->postJson('/api/v1/sso/send-otp', ['mobile_number' => '9876543210']);
        $otp = Otp::where('mobile_number', '9876543210')->latest()->first()->otp_code;

        $response = $this->postJson('/api/v1/sso/verify-otp', [
            'mobile_number' => '9876543210',
            'otp' => $otp
        ]);

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'status',
                     'star_one_session_token',
                     'user' => ['id', 'name', 'mobile', 'emp_code'],
                     'allowed_products' => [
                         '*' => ['key', 'name', 'role']
                     ]
                 ]);

        $this->assertCount(4, $response->json('allowed_products'));
    }

    public function test_verify_otp_for_single_product_user()
    {
        $this->postJson('/api/v1/sso/send-otp', ['mobile_number' => '9123456789']);
        $otp = Otp::where('mobile_number', '9123456789')->latest()->first()->otp_code;

        $response = $this->postJson('/api/v1/sso/verify-otp', [
            'mobile_number' => '9123456789',
            'otp' => $otp
        ]);

        $response->assertStatus(200);
        $allowed = $response->json('allowed_products');
        $this->assertCount(1, $allowed);
        $this->assertEquals('star_link', $allowed[0]['key']);
    }

    public function test_launch_star_sfa_returns_legacy_xml()
    {
        $token = JWTAuth::fromUser($this->multiProductUser);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
                         ->post('/api/v1/sso/launch/star_sfa');

        $response->assertStatus(200);
        $this->assertStringContainsString('text/xml', $response->headers->get('Content-Type'));
        $content = $response->getContent();
        $this->assertStringContainsString('<recordset><data>', $content);
        $this->assertStringContainsString('<emp_code><![CDATA[EMP1001]]></emp_code>', $content);
        $this->assertStringContainsString('<emp_name><![CDATA[Priya BDE]]></emp_name>', $content);
        $this->assertStringContainsString('<newpassword><![CDATA[1234]]></newpassword>', $content);
        $this->assertStringContainsString('<acedns><![CDATA[Y]]></acedns>', $content);
    }

    public function test_launch_star_link_returns_legacy_json()
    {
        $token = JWTAuth::fromUser($this->multiProductUser);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
                         ->post('/api/v1/sso/launch/star_link');

        $response->assertStatus(200)
                 ->assertJson([
                     'status' => true,
                     'msg' => 'Log in successfull',
                     'data' => [
                         'name' => 'Priya BDE',
                         'phone' => '9876543210',
                         'role' => 1,
                         'role_name' => 'BDE',
                         'city' => 'Guwahati'
                     ]
                 ]);
        $this->assertNotEmpty($response->json('access_token'));
    }

    public function test_launch_star_saathi_returns_encrypted_hex_string()
    {
        $token = JWTAuth::fromUser($this->multiProductUser);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
                         ->post('/api/v1/sso/launch/star_saathi');

        $response->assertStatus(200);
        $hexString = $response->getContent();
        $this->assertNotEmpty($hexString);

        // Decrypt using StarSaathiAdapter to verify payload integrity
        $adapter = new StarSaathiAdapter();
        $decryptedJson = $adapter->decrypt($hexString);
        $payload = json_decode($decryptedJson, true);

        $this->assertIsArray($payload);
        $this->assertEquals('YES', $payload['process_status']);
        $this->assertEquals('OTP IS SUCCESSFULLY VERIFIED.', $payload['process_message']);
        $this->assertEquals('CUST1001', $payload['customer_code']);
        $this->assertEquals('Priya BDE', $payload['emp_name']);
        $this->assertEquals('PRIMARY', $payload['sale_access']);
        $this->assertEquals('Y', $payload['acedns']);
    }

    public function test_launch_star_steller_returns_legacy_te_json()
    {
        $token = JWTAuth::fromUser($this->multiProductUser);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
                         ->post('/api/v1/sso/launch/star_steller');

        $response->assertStatus(200)
                 ->assertJson([
                     'process_status' => 'YES',
                     'process_message' => 'Success.',
                     'user_type' => 'TE',
                     'the_te_id' => 'TE_101',
                     'the_te_name' => 'Priya BDE',
                     'the_te_code' => 'TE101',
                     'the_te_mobile_no' => '9876543210'
                 ]);
    }

    public function test_unauthorized_product_launch_blocked()
    {
        // singleProductUser only has star_link, not star_sfa
        $token = JWTAuth::fromUser($this->singleProductUser);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
                         ->post('/api/v1/sso/launch/star_sfa');

        $response->assertStatus(403)
                 ->assertJson(['status' => false, 'message' => 'Unauthorized panel access']);
    }
}
