<?php

namespace OpenDominion\Tests;

use Laravel\BrowserKitTesting\TestCase;
use Mail;

abstract class AbstractBrowserKitTestCase extends TestCase
{
    use CreatesApplication, CreatesData;

    /**
     * The base URL of the application.
     *
     * @var string
     */
    protected $baseUrl = 'http://localhost';

    protected function setUp()
    {
        parent::setUp();

//        Bus::fake();
//        Event::fake();
        Mail::fake();
//        Notification::fake();
//        Queue::fake();
    }
}
