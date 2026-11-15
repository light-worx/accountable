<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_signed_out_visitors_are_sent_to_the_login_page(): void
    {
        $this->get('/')->assertRedirect();
    }
}