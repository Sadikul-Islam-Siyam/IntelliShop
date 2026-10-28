<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();

        // Enforce dedicated MySQL testing database when MySQL is reachable; fallback to SQLite for local offline runs
        try {
            $host = env('DB_HOST', '127.0.0.1');
            $port = (int) env('DB_PORT', 3306);
            $conn = @fsockopen($host === 'mysql' ? '127.0.0.1' : $host, $port, $errno, $errstr, 0.2);
            if (is_resource($conn)) {
                fclose($conn);
                $app['config']->set('database.default', 'mysql');
                $app['config']->set('database.connections.mysql.host', $host === 'mysql' ? '127.0.0.1' : $host);
                $app['config']->set('database.connections.mysql.database', 'IntelliShop_testing');
            } else {
                $app['config']->set('database.default', 'sqlite');
                $app['config']->set('database.connections.sqlite.database', ':memory:');
            }
        } catch (\Throwable) {
            $app['config']->set('database.default', 'sqlite');
            $app['config']->set('database.connections.sqlite.database', ':memory:');
        }

        return $app;
    }
}
