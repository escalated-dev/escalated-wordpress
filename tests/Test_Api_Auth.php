<?php

/**
 * Tests for the general JSON API auth endpoints (WP-native auth + API tokens).
 */

use Escalated\Api\Auth_Controller;
use Escalated\Models\ApiToken;

class Test_Api_Auth extends WP_UnitTestCase
{
    private function controller(): Auth_Controller
    {
        return new Auth_Controller;
    }

    private function login_request(string $username, string $password): WP_REST_Request
    {
        $request = new WP_REST_Request('POST', '/escalated/v1/auth/login');
        $request->set_param('username', $username);
        $request->set_param('password', $password);

        return $request;
    }

    public function test_login_issues_token_for_valid_credentials()
    {
        self::factory()->user->create([
            'user_login' => 'pat',
            'user_pass' => 'secret123',
            'user_email' => 'pat@example.com',
        ]);

        $response = $this->controller()->login($this->login_request('pat', 'secret123'));

        $this->assertNotWPError($response);
        $data = $response->get_data();
        $this->assertNotEmpty($data['token']);
        $this->assertSame('pat@example.com', $data['user']['email']);
    }

    public function test_login_rejects_invalid_credentials()
    {
        self::factory()->user->create(['user_login' => 'pat3', 'user_pass' => 'secret123']);

        $response = $this->controller()->login($this->login_request('pat3', 'wrong'));

        $this->assertWPError($response);
        $this->assertSame(401, $response->get_error_data()['status']);
    }

    public function test_login_requires_credentials()
    {
        $response = $this->controller()->login($this->login_request('', ''));

        $this->assertWPError($response);
        $this->assertSame(422, $response->get_error_data()['status']);
    }

    public function test_me_returns_user_and_logout_revokes_token()
    {
        self::factory()->user->create([
            'user_login' => 'pat4',
            'user_pass' => 'secret123',
            'user_email' => 'pat4@example.com',
        ]);
        $token = $this->controller()->login($this->login_request('pat4', 'secret123'))->get_data()['token'];

        $me = new WP_REST_Request('GET', '/escalated/v1/auth/me');
        $me->set_header('Authorization', 'Bearer '.$token);
        $this->assertSame('pat4@example.com', $this->controller()->me($me)->get_data()['user']['email']);

        $logout = new WP_REST_Request('POST', '/escalated/v1/auth/logout');
        $logout->set_header('Authorization', 'Bearer '.$token);
        $this->assertTrue($this->controller()->logout($logout)->get_data()['success']);
        $this->assertNull(ApiToken::find_by_token($token));
    }
}
