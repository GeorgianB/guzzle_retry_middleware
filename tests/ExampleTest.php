<?php

namespace GuzzleRetry;

use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

/**
 * Class ExampleTest
 * @package GuzzleRetry
 */
class ExampleTest extends \PHPUnit_Framework_TestCase
{
    public function testInstantiation()
    {
        $handler = new MockHandler();
        $obj = new GuzzleRetryAfterMiddleware($handler);
        $this->assertInstanceOf(GuzzleRetryAfterMiddleware::class, $obj);
    }

    /**
     * @dataProvider testRetryOccursWhenStatusCodeMatchesProvider
     * @param Response $response
     * @param bool $retryShouldOccur
     */
    public function testRetryOccursWhenStatusCodeMatches(Response $response, $retryShouldOccur)
    {
        $retryOccurred = false;

        $stack = HandlerStack::create(new MockHandler([
            $response,
            new Response(200, [], 'All Good')
        ]));

        $stack->push(GuzzleRetryAfterMiddleware::factory([
            'default_retry_multiplier' => 0,
            'on_retry_callback' => function() use (&$retryOccurred) {
                $retryOccurred = true;
            }
        ]));

        $client = new Client(['handler' => $stack]);
        $response = $client->request('GET', '/');

        $this->assertEquals($retryShouldOccur, $retryOccurred);
        $this->assertEquals('All Good', (string) $response->getBody());
    }

    public function testRetryOccursWhenStatusCodeMatchesProvider()
    {
        return [
            [new Response(429, [], 'back off'),       true],
            [new Response(503, [], 'back off buddy'), true],
            [new Response(200, [], 'All Good'),       false]
        ];
    }

    public function testRetriesFailAfterSpecifiedLimit()
    {
        $retryCount = 0;

        // Build 10 responses with 429 headers
        $responses = array_fill(0, 10, new Response(429, [], 'Wait'));
        $stack = HandlerStack::create(new MockHandler($responses));

        $stack->push(GuzzleRetryAfterMiddleware::factory([
            'max_retry_attempts'       => 5, // Allow only 5 attempts
            'default_retry_multiplier' => 0,
            'on_retry_callback' => function($num) use (&$retryCount) {
                $retryCount = $num;
            }
        ]));

        $client = new Client(['handler' => $stack]);

        try {
            $client->request('GET', '/');
        }
        catch (ClientException $e) {
            $this->assertEquals(5, $retryCount);
        }
    }

    public function testDefaultOptionsCanBeSetInGuzzleClientConstructor()
    {
        $numRetries = 0;

        // Build 2 responses with 429 headers and one good one
        $responses = [
            new Response(429, [], 'Wait'),
            new Response(429, [], 'Wait...'),
            new Response(200, [], 'Good')
        ];

        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(GuzzleRetryAfterMiddleware::factory());

        $client = new Client([
            'handler' => $stack,

            // set some defaults in Guzzle..
            'default_retry_multiplier' => 0,
            'max_retry_attempts'       => 2,
            'on_retry_callback'        => function($retryCount) use (&$numRetries) {
                $numRetries = $retryCount;
            }
        ]);

        $response = $client->request('GET', '/');
        $this->assertEquals(2, $numRetries);
        $this->assertEquals('Good', (string) $response->getBody());
    }

    public function testOptionsCanBeSetInRequest()
    {
        $numRetries = 0;

        // Build 2 responses with 429 headers and one good one
        $responses = [
            new Response(429, [], 'Wait'),
            new Response(429, [], 'Wait...'),
            new Response(200, [], 'Good')
        ];

        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(GuzzleRetryAfterMiddleware::factory([
            'max_retry_attempts' => 1
        ]));

        $client = new Client(['handler' => $stack]);

        // Override default settings in request
        $response = $client->request('GET', '/', [
            'default_retry_multiplier' => 0,
            'max_retry_attempts'       => 2,
            'on_retry_callback'        => function($retryCount) use (&$numRetries) {
                $numRetries = $retryCount;
            }
        ]);

        $this->assertEquals(2, $numRetries);
        $this->assertEquals('Good', (string) $response->getBody());
    }

    public function testDelayDerivedFromDateIfServerProvidesValidRetryAfterDateHeader()
    {
        $calculatedDelay = null;

        $retryAfter = Carbon::now()
            ->addSeconds(2)
            ->format(GuzzleRetryAfterMiddleware::DATE_FORMAT);

        $responses = [
            new Response(429, ['Retry-After' => $retryAfter], 'Wait'),
            new Response(200, [], 'Good')
        ];

        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(GuzzleRetryAfterMiddleware::factory([
            'on_retry_callback' => function($numRetries, $delay) use (&$calculatedDelay) {
                $calculatedDelay = $delay;
            }
        ]));

        $client = new Client(['handler' => $stack]);
        $client->request('GET', '/');

        $this->assertTrue($calculatedDelay > 1 && $calculatedDelay < 3);
    }

    public function testDelayDerivedFromSecondsIfServerProvidesValidRetryAfterSecsHeader()
    {
        $calculatedDelay = null;

        $responses = [
            new Response(429, ['Retry-After' => 3], 'Wait'),
            new Response(200, [], 'Good')
        ];

        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(GuzzleRetryAfterMiddleware::factory([
            'on_retry_callback' => function($numRetries, $delay) use (&$calculatedDelay) {
                $calculatedDelay = $delay;
            }
        ]));

        $client = new Client(['handler' => $stack]);
        $client->request('GET', '/');

        $this->assertEquals(3, $calculatedDelay);
    }

    public function testDefaultDelayOccursIfServerProvidesInvalidRetryAfterHeader()
    {
        $calculatedDelay = null;

        $responses = [
            new Response(429, ['Retry-After' => 'nope-lol3'], 'Wait'),
            new Response(200, [], 'Good')
        ];

        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(GuzzleRetryAfterMiddleware::factory([
            'default_retry_multiplier' => 1.5,
            'on_retry_callback' => function($numRetries, $delay) use (&$calculatedDelay) {
                $calculatedDelay = $delay;
            }
        ]));

        $client = new Client(['handler' => $stack]);
        $client->request('GET', '/');

        $this->assertEquals(1.5, $calculatedDelay);
    }

    public function testDelayDoesNotOccurIfNoRetryAfterHeaderAndOptionSetToIgnore()
    {
        $retryOccurred = false;

        $responses = [
            new Response(429, [], 'Wait'),
            new Response(200, [], 'Good')
        ];

        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(GuzzleRetryAfterMiddleware::factory([
            'retry_only_if_retry_after_header' => true,
            'on_retry_callback' => function() use (&$retryOccurred) {
                $retryOccurred = true;
            }
        ]));

        $client = new Client(['handler' => $stack]);

        try {
            $client->request('GET', '/');
        } catch (BadResponseException $e) {
            // pass..
        }

        $this->assertFalse($retryOccurred);
    }

    public function testRetryMultiplierWorksAsExpected()
    {
        $delayTimes = [];

        $responses = [
            new Response(429, [], 'Wait'),
            new Response(429, [], 'Wait'),
            new Response(429, [], 'Wait'),
            new Response(200, [], 'Good')
        ];

        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(GuzzleRetryAfterMiddleware::factory([
            'default_retry_multiplier' => 0.5,
            'on_retry_callback' => function($numRetries, $delay) use (&$delayTimes) {
                $delayTimes[] = $delay;
            }
        ]));

        $client = new Client(['handler' => $stack]);
        $client->request('GET', '/');

        $this->assertEquals([0.5 * 1, 0.5 * 2, 0.5 * 3], $delayTimes);
    }
}
