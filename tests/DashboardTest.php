<?php

namespace HttpCallMonitor\Tests;

use Illuminate\Support\Facades\Gate;
use Orchestra\Testbench\Attributes\DefineEnvironment;

class DashboardTest extends TestCase
{
    private const URL = '/http-call-monitor';

    private function allowEveryone(): void
    {
        Gate::define('viewHttpCallMonitor', fn ($user = null) => true);
    }

    public function test_it_is_forbidden_outside_the_local_environment_by_default(): void
    {
        $this->get(self::URL)->assertForbidden();
    }

    public function test_it_is_open_in_the_local_environment(): void
    {
        $this->app['env'] = 'local';

        $this->get(self::URL)->assertOk();
    }

    public function test_the_app_can_define_its_own_access_rule(): void
    {
        $this->allowEveryone();

        $this->get(self::URL)->assertOk();
    }

    public function test_an_access_rule_requiring_a_user_blocks_guests(): void
    {
        Gate::define('viewHttpCallMonitor', fn ($user) => true);

        $this->get(self::URL)->assertForbidden();
    }

    public function test_it_shows_stats_and_recent_failures(): void
    {
        $this->allowEveryone();
        $this->seedCall('api.example.com', 200, 120);
        $this->seedCall('api.example.com', null, 5000, path: '/v1/charges', error: 'cURL error 28: Operation timed out');

        $this->get(self::URL)
            ->assertOk()
            ->assertSee('api.example.com')
            ->assertSee('50.0%')
            ->assertSee('120 ms')
            ->assertSee('/v1/charges')
            ->assertSee('cURL error 28: Operation timed out');
    }

    public function test_it_has_an_empty_state(): void
    {
        $this->allowEveryone();

        $this->get(self::URL)
            ->assertOk()
            ->assertSee('No calls recorded in this time window.')
            ->assertSee('No failures in this time window.');
    }

    public function test_it_uses_the_chosen_time_window(): void
    {
        $this->allowEveryone();
        $this->seedCall('recent.example.com', 200);
        $this->seedCall('earlier.example.com', 200, hoursAgo: 3);

        $this->get(self::URL.'?hours=1')
            ->assertSee('recent.example.com')
            ->assertDontSee('earlier.example.com');
    }

    public function test_an_unknown_time_window_falls_back_to_the_default(): void
    {
        $this->allowEveryone();
        $this->seedCall('earlier.example.com', 200, hoursAgo: 23);

        $this->get(self::URL.'?hours=999999')->assertOk()->assertSee('earlier.example.com');
    }

    public function test_values_from_third_parties_are_escaped(): void
    {
        $this->allowEveryone();
        $this->seedCall('api.example.com', null, path: '/<script>alert(1)</script>', error: '<img src=x onerror=alert(2)>');

        $this->get(self::URL)
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('<img src=x', false);
    }

    #[DefineEnvironment('disableDashboard')]
    public function test_it_can_be_turned_off(): void
    {
        $this->allowEveryone();

        $this->get(self::URL)->assertNotFound();
    }

    protected function disableDashboard($app): void
    {
        $app['config']->set('http-call-monitor.dashboard.enabled', false);
    }

    #[DefineEnvironment('moveDashboard')]
    public function test_its_path_can_be_changed(): void
    {
        $this->allowEveryone();

        $this->get('/ops/api-health')->assertOk();
    }

    protected function moveDashboard($app): void
    {
        $app['config']->set('http-call-monitor.dashboard.path', 'ops/api-health');
    }
}
