<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\User;
use App\Services\Sso\Adapters\StarSfaAdapter;
use App\Services\Sso\Adapters\StarSaathiAdapter;
use App\Services\Sso\Adapters\StarStellerAdapter;
use App\Services\Sso\Adapters\StarLinkAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ProductAdapterDynamicFetchTest extends TestCase
{
    use RefreshDatabase;

    public function test_star_sfa_adapter_uses_attributes_and_defaults()
    {
        $user = User::create([
            'mobile_number' => '9800000001',
            'name' => 'SFA User',
            'emp_code' => 'SFA100',
            'status' => true,
        ]);

        $adapter = new StarSfaAdapter();
        $response = $adapter->formatResponse($user, [
            'emp_code' => 'SFA100',
            'sale_access' => 'Primary',
            'newpassword' => '1234',
        ]);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('<emp_code><![CDATA[SFA100]]></emp_code>', $response->getContent());
        $this->assertStringContainsString('<emp_name><![CDATA[SFA User]]></emp_name>', $response->getContent());
    }

    public function test_star_saathi_adapter_uses_attributes_and_defaults()
    {
        $user = User::create([
            'mobile_number' => '9800000002',
            'name' => 'Saathi User',
            'emp_code' => 'SAATHI100',
            'status' => true,
        ]);

        $adapter = new StarSaathiAdapter();
        $response = $adapter->formatResponse($user, [
            'customer_code' => 'SAATHI100',
            'user_type' => 'dealer',
        ]);

        $this->assertEquals(200, $response->getStatusCode());
        $decrypted = $adapter->decrypt($response->getContent());
        $data = json_decode($decrypted, true);

        $this->assertEquals('SAATHI100', $data['customer_code']);
        $this->assertEquals('Saathi User', $data['emp_name']);
        $this->assertEquals('dealer', $data['user_type']);
    }

    public function test_star_steller_adapter_te_and_engineer_response()
    {
        $user = User::create([
            'mobile_number' => '9800000003',
            'name' => 'Stellar User',
            'emp_code' => 'STELLAR100',
            'status' => true,
        ]);

        $adapter = new StarStellerAdapter();

        // Test TE
        $teResp = $adapter->formatResponse($user, [
            'user_type' => 'TE',
            'the_te_id' => 'TE_99',
            'the_te_code' => 'STELLAR100',
        ]);
        $teData = $teResp->getData(true);
        $this->assertEquals('TE', $teData['user_type']);
        $this->assertEquals('TE_99', $teData['the_te_id']);

        // Test Engineer
        $enResp = $adapter->formatResponse($user, [
            'user_type' => 'ENGINEER',
            'the_engineer_id' => 'ENG_99',
        ]);
        $enData = $enResp->getData(true);
        $this->assertEquals('ENGINEER', $enData['user_type']);
        $this->assertEquals('ENG_99', $enData['the_engineer_id']);
    }

    public function test_star_link_adapter_response()
    {
        $user = User::create([
            'mobile_number' => '9800000004',
            'name' => 'Link User',
            'email' => 'link@starone.com',
            'status' => true,
        ]);

        $adapter = new StarLinkAdapter();
        $response = $adapter->formatResponse($user, [
            'role' => 2,
            'role_name' => 'Contractor',
            'city' => 'Guwahati',
        ]);

        $data = $response->getData(true);
        $this->assertTrue($data['status']);
        $this->assertEquals(2, $data['data']['role']);
        $this->assertEquals('Contractor', $data['data']['role_name']);
        $this->assertEquals('Guwahati', $data['data']['city']);
        $this->assertNotEmpty($data['access_token']);
    }
}
