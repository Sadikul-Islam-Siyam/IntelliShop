<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();

        // Enforce dedicated MySQL testing database
        $app['config']->set('database.default', 'mysql');
        $app['config']->set('database.connections.mysql.database', 'IntelliShop_testing');

        return $app;
    }
}
