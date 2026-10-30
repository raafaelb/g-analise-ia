<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_login_page_is_available(): void
    {
        $this->get('/login')->assertOk();
    }
}
