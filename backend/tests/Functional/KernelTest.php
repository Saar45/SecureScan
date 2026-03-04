<?php

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class KernelTest extends WebTestCase
{
    public function testKernelBoots(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/scans/recent');

        $response = $client->getResponse();
        self::assertTrue($response->isSuccessful() || $response->getStatusCode() === 401);
    }
}
