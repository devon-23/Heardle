<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use HeardLive\Http\Router;
use Tests\TestCase;

final class RouterTest extends TestCase
{
    public function testMatchesStaticAndParamRoutes(): void
    {
        $router = new Router();
        $router->get('/', fn () => 'home');
        $router->get('/events/{id}', fn () => 'event');

        $home = $router->match('GET', '/');
        $this->assertSame(200, $home['status']);
        $this->assertSame('home', ($home['handler'])());

        $event = $router->match('GET', '/events/42');
        $this->assertSame(['id' => '42'], $event['params']);
    }

    public function testTrailingSlashIsIgnoredAndParamsAreDecoded(): void
    {
        $router = new Router();
        $router->get('/artists/{name}', fn () => null);

        $match = $router->match('GET', '/artists/Death%20Cab/');
        $this->assertSame(['name' => 'Death Cab'], $match['params']);
    }

    public function testUnknownPathIs404(): void
    {
        $router = new Router();
        $router->get('/', fn () => null);

        $this->assertSame(404, $router->match('GET', '/nope')['status']);
        $this->assertSame(404, $router->match('GET', '/events/1/extra')['status']);
    }

    public function testWrongMethodIs405WithAllowedMethods(): void
    {
        $router = new Router();
        $router->post('/import', fn () => null);

        $match = $router->match('GET', '/import');
        $this->assertSame(405, $match['status']);
        $this->assertSame(['POST'], $match['allowed']);
    }

    public function testHeadFallsBackToGet(): void
    {
        $router = new Router();
        $router->get('/', fn () => null);

        $this->assertSame(200, $router->match('HEAD', '/')['status']);
    }
}
