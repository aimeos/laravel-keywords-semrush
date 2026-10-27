<?php

namespace Tests;

use Aimeos\KeywordsBridge\Drivers\Semrush;
use Aimeos\KeywordsBridge\Facades\Keywords;
use Aimeos\KeywordsBridge\Keyword;
use Aimeos\KeywordsBridge\ServiceProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;


class SemrushTest extends TestCase
{
    private const URL = 'https://api.semrush.com/*';


    protected function getPackageProviders($app)
    {
        return [ServiceProvider::class];
    }


    protected function defineEnvironment($app)
    {
        $app['config']->set('keywords-bridge.default', 'semrush');
        $app['config']->set('keywords-bridge.drivers.semrush', ['key' => 'apikey', 'database' => 'GB']);
    }


    public function testVolume(): void
    {
        Http::fake([self::URL => Http::response("Keyword;Search Volume\r\nlaravel cms;1900\r\nHeadless CMS;14800\r\nrare keyword;\r\n")]);

        $result = Keywords::volume(['Laravel  CMS', 'headless cms', 'rare keyword', 'unknown', ' ', 'laravel cms'], 'DE');

        $this->assertEquals([
            new Keyword('laravel cms', 1900),
            new Keyword('headless cms', 14800),
            new Keyword('rare keyword', null),
            new Keyword('unknown', null),
        ], $result);

        Http::assertSent(fn(Request $request) => str_starts_with($request->url(), 'https://api.semrush.com/?')
            && $request->method() === 'GET'
            && $request['type'] === 'phrase_these'
            && $request['key'] === 'apikey'
            && $request['phrase'] === 'laravel cms;headless cms;rare keyword;unknown'
            && $request['database'] === 'de'
            && $request['export_columns'] === 'Ph,Nq');
    }


    public function testVolumeDefaultDatabase(): void
    {
        Http::fake([self::URL => Http::response("Keyword;Search Volume\nlaravel cms;390\n")]);

        $this->assertEquals([new Keyword('laravel cms', 390)], Keywords::volume(['laravel cms']));

        Http::assertSent(fn(Request $request) => $request['database'] === 'uk');
    }


    public function testVolumeChunked(): void
    {
        Http::fake([self::URL => Http::response('ERROR 50 :: NOTHING FOUND')]);

        $keywords = array_map(fn($i) => "keyword $i", range(1, 150));
        $this->assertCount(150, Keywords::volume($keywords));

        Http::assertSentCount(2);
        Http::assertSent(fn(Request $request) => str_ends_with($request['phrase'], ';keyword 100'));
        Http::assertSent(fn(Request $request) => str_starts_with($request['phrase'], 'keyword 101;'));
    }


    public function testVolumeSemicolon(): void
    {
        Http::fake();

        $this->assertEquals([new Keyword('cms; laravel')], Keywords::volume(['cms; laravel']));
        Http::assertNothingSent();
    }


    public function testVolumeCountryInvalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Keywords::volume(['laravel cms'], 'mobile-us');
    }


    public function testRelated(): void
    {
        Http::fake([self::URL => Http::response("Keyword;Search Volume\nLaravel Admin Panel;2400\nlaravel headless cms;320\n")]);

        $this->assertEquals([
            new Keyword('laravel admin panel', 2400),
            new Keyword('laravel headless cms', 320),
        ], Keywords::related(' Laravel CMS ', 'gb', 5000));

        Http::assertSent(fn(Request $request) => $request['type'] === 'phrase_related'
            && $request['phrase'] === 'laravel cms'
            && $request['database'] === 'uk'
            && $request['display_sort'] === 'nq_desc'
            && $request['display_limit'] == 1000);
    }


    public function testRelatedNothingFound(): void
    {
        Http::fake([self::URL => Http::response("ERROR 50 :: NOTHING FOUND\n")]);

        $this->assertSame([], Keywords::related('laravel cms', 'us'));
    }


    public function testRelatedInvalid(): void
    {
        Http::fake();

        $this->assertSame([], Keywords::related('  '));
        $this->assertSame([], Keywords::related('cms; laravel'));
        Http::assertNothingSent();
    }


    public function testRequestError(): void
    {
        Http::fake([self::URL => Http::response("ERROR 132 :: API UNITS BALANCE IS ZERO\n")]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Semrush API request failed: ERROR 132 :: API UNITS BALANCE IS ZERO');
        Keywords::volume(['laravel cms']);
    }


    public function testRequestFailed(): void
    {
        Http::fake([self::URL => Http::response('', 403)]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Semrush API request failed: HTTP status 403');
        Keywords::volume(['laravel cms']);
    }


    public function testRequestConnection(): void
    {
        Http::fake([self::URL => fn() => throw new ConnectionException('cURL error 28 for https://api.semrush.com/?key=apikey')]);

        try {
            Keywords::volume(['laravel cms']);
            $this->fail('Exception expected');
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString('apikey', $e->getMessage());
        }
    }


    public function testRequestNotRetried(): void
    {
        Http::fake([self::URL => Http::response('ERROR 429 :: Too Many Requests', 429)]);

        try {
            Keywords::volume(['laravel cms'], 'us');
            $this->fail('Exception expected');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('ERROR 429 :: Too Many Requests', $e->getMessage());
        }

        Http::assertSentCount(1);
    }


    public function testConfigMissing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('[key]');
        new Semrush(['database' => 'us']);
    }


    public function testConfigDatabase(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Semrush(['key' => 'apikey', 'database' => 'us&x=1']);
    }
}
