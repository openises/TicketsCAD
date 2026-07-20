<?php
/**
 * NewUI v4.0 - WebSocket Client Connector
 *
 * A simple ReactPHP-based WebSocket client connector compatible with
 * guzzlehttp/psr7 v2 and ratchet/rfc6455 v0.3.  Replaces ratchet/pawl's
 * Connector to avoid version incompatibilities.
 *
 * Usage:
 *   $connector = new WebSocketConnector($loop);
 *   $connector('wss://zello.io/ws')->then(
 *       function (WebSocket $ws) { ... },
 *       function (\Exception $e) { ... }
 *   );
 */

namespace NewUI\Proxy;

use Ratchet\RFC6455\Handshake\ClientNegotiator;
use Ratchet\Client\WebSocket;
use React\EventLoop\LoopInterface;
use React\Socket\ConnectorInterface;
use React\Socket\ConnectionInterface;
use React\Promise\Deferred;
use GuzzleHttp\Psr7\Message;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\RequestInterface;

class WebSocketConnector
{
    /** @var LoopInterface */
    private $loop;

    /** @var ConnectorInterface */
    private $connector;

    /** @var ClientNegotiator */
    private $negotiator;

    public function __construct(LoopInterface $loop, ConnectorInterface $connector = null)
    {
        $this->loop = $loop;

        if ($connector === null) {
            // Explicitly set CA bundle for TLS — ReactPHP's async streams
            // may not pick up php.ini's openssl.cafile on Windows/XAMPP
            $tls = [];
            $caFile = ini_get('openssl.cafile');
            if ($caFile && file_exists($caFile)) {
                $tls['cafile'] = $caFile;
                \plog("[WS-Connector] Using CA bundle: {$caFile}");
            } else {
                // Try common XAMPP locations
                $candidates = [
                    'C:\\xampp\\8.2.4\\apache\\bin\\curl-ca-bundle.crt',
                    'C:\\xampp\\apache\\bin\\curl-ca-bundle.crt',
                    dirname(PHP_BINARY) . '\\extras\\ssl\\cacert.pem',
                ];
                foreach ($candidates as $path) {
                    if (file_exists($path)) {
                        $tls['cafile'] = $path;
                        \plog("[WS-Connector] Using CA bundle: {$path}");
                        break;
                    }
                }
            }

            $connector = new \React\Socket\Connector([
                'timeout' => 20,
                'tls'     => $tls ?: true,
            ], $this->loop);
        }

        $this->connector  = $connector;
        // ClientNegotiator with no args — compatible with rfc6455 ^0.3
        $this->negotiator = new ClientNegotiator();
    }

    /**
     * Connect to a WebSocket URL.
     *
     * @param  string $url          ws:// or wss:// URL
     * @param  array  $subProtocols Optional sub-protocols
     * @param  array  $headers      Additional headers
     * @return \React\Promise\PromiseInterface  Resolves with Ratchet\Client\WebSocket
     */
    public function __invoke($url, array $subProtocols = [], array $headers = [])
    {
        try {
            $request = $this->generateRequest($url, $subProtocols, $headers);
            $uri     = $request->getUri();
        } catch (\Exception $e) {
            $deferred = new Deferred();
            $deferred->reject($e);
            return $deferred->promise();
        }

        $secure = (substr($url, 0, 3) === 'wss');
        $port   = $uri->getPort() ?: ($secure ? 443 : 80);
        $scheme = $secure ? 'tls' : 'tcp';

        $uriString  = $scheme . '://' . $uri->getHost() . ':' . $port;
        \plog("[WS-Connector] TCP connecting to {$uriString}...");
        $connecting = $this->connector->connect($uriString);
        $negotiator = $this->negotiator;

        $futureWsConn = new Deferred(function ($_, $reject) use ($url, $connecting) {
            $reject(new \RuntimeException(
                'Connection to ' . $url . ' cancelled during handshake'
            ));
            $connecting->then(function (ConnectionInterface $connection) {
                $connection->close();
            });
            $connecting->cancel();
        });

        $connecting->then(
            function (ConnectionInterface $conn) use ($request, $subProtocols, $futureWsConn, $negotiator) {
                \plog("[WS-Connector] TCP/TLS connected, sending WebSocket upgrade...");
                $earlyClose = function () use ($futureWsConn) {
                    \plog("[WS-Connector] Connection closed before handshake!");
                    $futureWsConn->reject(new \RuntimeException('Connection closed before handshake'));
                };

                $stream = $conn;
                $stream->on('close', $earlyClose);

                $futureWsConn->promise()->then(function () use ($stream, $earlyClose) {
                    $stream->removeListener('close', $earlyClose);
                });

                $buffer = '';
                $headerParser = function ($data) use ($stream, &$headerParser, &$buffer, $futureWsConn, $request, $subProtocols, $negotiator) {
                    $buffer .= $data;
                    if (strpos($buffer, "\r\n\r\n") === false) {
                        return;
                    }

                    $stream->removeListener('data', $headerParser);
                    \plog("[WS-Connector] Got HTTP response, validating handshake...");

                    // GuzzleHttp\Psr7 v2 API
                    $response = Message::parseResponse($buffer);

                    if (!$negotiator->validateResponse($request, $response)) {
                        $futureWsConn->reject(new \DomainException(Message::toString($response)));
                        $stream->close();
                        return;
                    }

                    $acceptedProtocol = $response->getHeader('Sec-WebSocket-Protocol');
                    if ((count($subProtocols) > 0) && 1 !== count(array_intersect($subProtocols, $acceptedProtocol))) {
                        $futureWsConn->reject(new \DomainException('Server did not respond with an expected Sec-WebSocket-Protocol'));
                        $stream->close();
                        return;
                    }

                    $futureWsConn->resolve(new WebSocket($stream, $response, $request));

                    $futureWsConn->promise()->then(function (WebSocket $conn) use ($stream) {
                        $stream->emit('data', [$conn->response->getBody()->getContents(), $stream]);
                    });
                };

                $stream->on('data', $headerParser);
                // GuzzleHttp\Psr7 v2 API
                $stream->write(Message::toString($request));
            },
            function (\Exception $e) use ($futureWsConn) {
                \plog("[WS-Connector] TCP/TLS connection failed: " . $e->getMessage());
                $futureWsConn->reject($e);
            }
        );

        return $futureWsConn->promise();
    }

    /**
     * Build the WebSocket upgrade request.
     */
    private function generateRequest($url, array $subProtocols, array $headers)
    {
        // GuzzleHttp\Psr7 v2 API
        $uri    = Utils::uriFor($url);
        $scheme = $uri->getScheme();

        if (!in_array($scheme, ['ws', 'wss'])) {
            throw new \InvalidArgumentException(sprintf('Cannot connect to invalid URL (%s)', $url));
        }

        $uri = $uri->withScheme($scheme === 'wss' ? 'HTTPS' : 'HTTP');

        $headers += ['User-Agent' => 'NewUI-ZelloProxy/1.0'];

        $request = array_reduce(
            array_keys($headers),
            function (RequestInterface $request, $header) use ($headers) {
                return $request->withHeader($header, $headers[$header]);
            },
            $this->negotiator->generateRequest($uri)
        );

        if (!$request->getHeader('Origin')) {
            $request = $request->withHeader(
                'Origin',
                str_replace('ws', 'http', $scheme) . '://' . $uri->getHost()
            );
        }

        if (count($subProtocols) > 0) {
            $protocols = implode(',', $subProtocols);
            if ($protocols !== '') {
                $request = $request->withHeader('Sec-WebSocket-Protocol', $protocols);
            }
        }

        return $request;
    }
}
