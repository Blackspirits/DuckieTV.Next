<?php

namespace Tests\Feature\Controllers;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RemainingTorrentClientSettingsContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_remaining_client_views_are_live_and_not_placeholders(): void
    {
        $contracts = [
            'aria2' => ['server', 'port', 'token'],
            'ktorrent' => ['server', 'port', 'username', 'password'],
            'rtorrent' => ['server', 'port', 'path'],
            'tixati' => ['server', 'port', 'use_auth', 'username', 'password'],
            'ttorrent' => ['server', 'port', 'use_auth', 'username', 'password'],
            'utorrentwebui' => ['server', 'port', 'use_auth', 'username', 'password'],
        ];

        foreach ($contracts as $section => $fields) {
            $response = $this->get(route('settings.show', $section));

            $response->assertOk()
                ->assertSee('data-section="torrent"', false)
                ->assertDontSee('Work in Progress', false);

            foreach ($fields as $field) {
                $response->assertSee('name="'.$section.'.'.$field.'"', false);
            }
        }

        $this->get(route('settings.show', 'rtorrent'))
            ->assertDontSee('rtorrent.use_auth', false);
    }

    public function test_runtime_backed_settings_persist_for_each_remaining_client(): void
    {
        $payloads = [
            'aria2' => [
                'aria2.server' => 'http://127.0.0.1',
                'aria2.port' => 16800,
                'aria2.token' => 'secret-token',
            ],
            'ktorrent' => [
                'ktorrent.server' => 'http://127.0.0.1',
                'ktorrent.port' => 18080,
                'ktorrent.username' => 'duckie',
                'ktorrent.password' => 'secret',
            ],
            'rtorrent' => [
                'rtorrent.server' => 'http://127.0.0.1',
                'rtorrent.port' => 18081,
                'rtorrent.path' => '/RPC2',
            ],
            'tixati' => [
                'tixati.server' => 'http://127.0.0.1',
                'tixati.port' => 18888,
                'tixati.use_auth' => true,
                'tixati.username' => 'duckie',
                'tixati.password' => 'secret',
            ],
            'ttorrent' => [
                'ttorrent.server' => 'http://127.0.0.1',
                'ttorrent.port' => 11080,
                'ttorrent.use_auth' => true,
                'ttorrent.username' => 'duckie',
                'ttorrent.password' => 'secret',
            ],
            'utorrentwebui' => [
                'utorrentwebui.server' => 'http://127.0.0.1',
                'utorrentwebui.port' => 18082,
                'utorrentwebui.use_auth' => true,
                'utorrentwebui.username' => 'duckie',
                'utorrentwebui.password' => 'secret',
            ],
        ];

        foreach ($payloads as $payload) {
            $this->postJson(route('settings.update', 'torrent'), $payload)
                ->assertOk()
                ->assertJson(['success' => true]);

            foreach ($payload as $key => $value) {
                $actual = settings()->get($key);
                if (is_bool($value)) {
                    $this->assertSame($value, (bool) $actual);
                } elseif (is_int($value)) {
                    $this->assertSame($value, (int) $actual);
                } else {
                    $this->assertSame($value, $actual);
                }
            }
        }
    }

    public function test_remaining_client_ports_are_bounded(): void
    {
        foreach (['aria2', 'ktorrent', 'rtorrent', 'tixati', 'ttorrent', 'utorrentwebui'] as $prefix) {
            foreach ([0, 65536] as $port) {
                $this->postJson(route('settings.update', 'torrent'), [
                    $prefix.'.port' => $port,
                ])->assertUnprocessable();
            }
        }
    }

    public function test_dead_rtorrent_auth_flag_cannot_be_mutated(): void
    {
        settings('rtorrent.use_auth', false);

        $this->postJson(route('settings.update', 'torrent'), [
            'rtorrent.use_auth' => true,
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertFalse((bool) settings()->get('rtorrent.use_auth'));
    }
}
