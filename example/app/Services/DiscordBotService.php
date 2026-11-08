<?php

namespace App\Services;

use App\Models\DiscordBotSetting;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Console\Command;

class DiscordBotService
{
    private const API_BASE = 'https://discord.com/api/v10';

    private const GATEWAY_HOST = 'gateway.discord.gg';

    private const GATEWAY_PORT = 443;

    // Discord Gateway opcodes
    private const OP_DISPATCH = 0;

    private const OP_HEARTBEAT = 1;

    private const OP_IDENTIFY = 2;

    private const OP_HELLO = 10;

    private const OP_HEARTBEAT_ACK = 11;

    // Guild Members intent (privileged – must be enabled in the Discord Dev Portal)
    private const INTENT_GUILD_MEMBERS = 1 << 1;

    private Client $http;

    private DiscordBotSetting $settings;

    public function __construct(DiscordBotSetting $settings)
    {
        $this->settings = $settings;
        $this->http = new Client([
            'base_uri' => self::API_BASE,
            'headers' => [
                'Authorization' => 'Bot '.$settings->bot_token,
                'Content-Type' => 'application/json',
                'User-Agent' => 'EVEIndustrialistBot/1.0',
            ],
            'timeout' => 10,
        ]);
    }

    /**
     * Send a welcome message to the configured channel for the given user.
     *
     * @throws RequestException
     */
    public function sendWelcomeMessage(string $userId, string $username): void
    {
        if (! $this->settings->welcome_channel_id) {
            return;
        }

        $message = str_replace('{user}', "<@{$userId}>", $this->settings->welcome_message);

        $this->http->post('/channels/'.$this->settings->welcome_channel_id.'/messages', [
            'json' => ['content' => $message],
        ]);
    }

    /**
     * Update the bot's username and avatar via the Discord REST API.
     *
     * @throws RequestException
     */
    public function updateBotProfile(): void
    {
        $payload = [];

        if ($this->settings->bot_name) {
            $payload['username'] = $this->settings->bot_name;
        }

        if ($this->settings->bot_avatar_url) {
            $imageData = $this->fetchImageAsDataUri($this->settings->bot_avatar_url);
            if ($imageData) {
                $payload['avatar'] = $imageData;
            }
        }

        if (! empty($payload)) {
            $this->http->patch('/users/@me', ['json' => $payload]);
        }
    }

    /**
     * Fetch a remote image and convert it to a Discord-compatible data URI.
     */
    private function fetchImageAsDataUri(string $url): ?string
    {
        try {
            $plain = new Client(['timeout' => 10]);
            $response = $plain->get($url);
            $contentType = $response->getHeaderLine('Content-Type') ?: 'image/png';
            $body = (string) $response->getBody();

            return 'data:'.$contentType.';base64,'.base64_encode($body);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Connect to the Discord Gateway and process events until a SIGTERM/SIGINT signal is received.
     */
    public function run(Command $command): void
    {
        $socket = $this->connectGateway($command);
        if (! $socket) {
            return;
        }

        // Set the read timeout once; readFrame() uses it for non-blocking polls.
        stream_set_timeout($socket, 0, 50000); // 50 ms

        $running = true;

        // Allow a graceful shutdown via Ctrl+C / SIGTERM when pcntl is available.
        if (function_exists('pcntl_signal')) {
            $handler = static function () use (&$running) {
                $running = false;
            };
            pcntl_signal(SIGTERM, $handler);
            pcntl_signal(SIGINT, $handler);
        }

        $heartbeatInterval = 0;
        $lastHeartbeat = 0.0;
        $sequence = null;

        while ($running) {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            $elapsed = microtime(true) - $lastHeartbeat;
            if ($heartbeatInterval > 0 && $elapsed >= $heartbeatInterval / 1000) {
                $this->sendFrame($socket, json_encode(['op' => self::OP_HEARTBEAT, 'd' => $sequence]));
                $lastHeartbeat = microtime(true);
            }

            $frame = $this->readFrame($socket);

            if ($frame === null) {
                continue;
            }

            $payload = json_decode($frame['payload'], true);
            if (! is_array($payload)) {
                continue;
            }

            if (isset($payload['s'])) {
                $sequence = $payload['s'];
            }

            switch ($payload['op']) {
                case self::OP_HELLO:
                    $heartbeatInterval = $payload['d']['heartbeat_interval'] ?? 41250;
                    $lastHeartbeat = microtime(true);
                    $this->sendFrame($socket, json_encode(['op' => self::OP_HEARTBEAT, 'd' => $sequence]));
                    $this->identify($socket);
                    $command->info('Connected to Discord Gateway. Listening for member events…');
                    break;

                case self::OP_DISPATCH:
                    $eventName = $payload['t'] ?? '';
                    if ($eventName === 'GUILD_MEMBER_ADD') {
                        $userId = $payload['d']['user']['id'] ?? null;
                        $username = $payload['d']['user']['username'] ?? 'Unknown';
                        if ($userId) {
                            try {
                                $this->sendWelcomeMessage($userId, $username);
                                $command->info("Welcomed user {$username} ({$userId}).");
                            } catch (\Throwable $e) {
                                $command->error('Failed to send welcome message: '.$e->getMessage());
                            }
                        }
                    }
                    break;

                case self::OP_HEARTBEAT:
                    // Server requests an immediate heartbeat
                    $this->sendFrame($socket, json_encode(['op' => self::OP_HEARTBEAT, 'd' => $sequence]));
                    break;
            }
        }

        $command->info('Discord bot shutting down.');
        fclose($socket);
    }

    /**
     * Open an SSL WebSocket connection to the Discord Gateway.
     *
     * @return resource|null
     */
    private function connectGateway(Command $command)
    {
        $socket = @stream_socket_client(
            'ssl://'.self::GATEWAY_HOST.':'.self::GATEWAY_PORT,
            $errno,
            $errstr,
            30,
            STREAM_CLIENT_CONNECT
        );

        if (! $socket) {
            $command->error("Failed to connect to Discord Gateway: {$errstr} ({$errno})");

            return null;
        }

        // WebSocket upgrade handshake
        $key = base64_encode(random_bytes(16));
        $handshake = implode("\r\n", [
            'GET /?v=10&encoding=json HTTP/1.1',
            'Host: '.self::GATEWAY_HOST,
            'Upgrade: websocket',
            'Connection: Upgrade',
            'Sec-WebSocket-Key: '.$key,
            'Sec-WebSocket-Version: 13',
            '',
            '',
        ]);

        fwrite($socket, $handshake);

        // Read HTTP response headers
        $response = '';
        while (! str_contains($response, "\r\n\r\n")) {
            $chunk = fread($socket, 4096);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $response .= $chunk;
        }

        if (! str_contains($response, '101')) {
            $command->error('WebSocket handshake failed.');
            fclose($socket);

            return null;
        }

        return $socket;
    }

    /**
     * Send an IDENTIFY payload to authenticate the bot.
     *
     * @param  resource  $socket
     */
    private function identify($socket): void
    {
        $payload = [
            'op' => self::OP_IDENTIFY,
            'd' => [
                'token' => $this->settings->bot_token,
                'intents' => self::INTENT_GUILD_MEMBERS,
                'properties' => [
                    'os' => PHP_OS,
                    'browser' => 'EVEIndustrialistBot',
                    'device' => 'EVEIndustrialistBot',
                ],
            ],
        ];

        $this->sendFrame($socket, json_encode($payload));
    }

    /**
     * Read a single WebSocket frame from the socket.
     *
     * @param  resource  $socket
     */
    private function readFrame($socket): ?array
    {
        $header = $this->readBytes($socket, 2);
        if ($header === null) {
            return null;
        }

        $opcode = ord($header[0]) & 0x0F;
        $masked = (ord($header[1]) & 0x80) !== 0;
        $payloadLen = ord($header[1]) & 0x7F;

        if ($payloadLen === 126) {
            $extended = $this->readBytes($socket, 2);
            if ($extended === null) {
                return null;
            }
            $payloadLen = unpack('n', $extended)[1];
        } elseif ($payloadLen === 127) {
            $extended = $this->readBytes($socket, 8);
            if ($extended === null) {
                return null;
            }
            $payloadLen = unpack('J', $extended)[1];
        }

        $maskKey = '';
        if ($masked) {
            $maskKey = $this->readBytes($socket, 4) ?? '';
        }

        $payload = $payloadLen > 0 ? ($this->readBytes($socket, $payloadLen) ?? '') : '';

        if ($masked && $maskKey !== '') {
            for ($i = 0; $i < strlen($payload); $i++) {
                $payload[$i] = chr(ord($payload[$i]) ^ ord($maskKey[$i % 4]));
            }
        }

        return ['opcode' => $opcode, 'payload' => $payload];
    }

    /**
     * Reliably read exactly $length bytes from the socket, returning null on timeout/EOF.
     *
     * @param  resource  $socket
     */
    private function readBytes($socket, int $length): ?string
    {
        $buffer = '';
        while (strlen($buffer) < $length) {
            $chunk = fread($socket, $length - strlen($buffer));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($socket);
                if ($meta['timed_out']) {
                    return null;
                }
                if (feof($socket)) {
                    return null;
                }

                // No data yet; keep waiting
                continue;
            }
            $buffer .= $chunk;
        }

        return $buffer;
    }

    /**
     * Send a masked WebSocket text frame.
     *
     * @param  resource  $socket
     */
    private function sendFrame($socket, string $payload, int $opcode = 0x01): void
    {
        $payloadLen = strlen($payload);
        $mask = random_bytes(4);

        $frame = chr(0x80 | $opcode);

        if ($payloadLen <= 125) {
            $frame .= chr(0x80 | $payloadLen);
        } elseif ($payloadLen <= 65535) {
            $frame .= chr(0x80 | 126).pack('n', $payloadLen);
        } else {
            $frame .= chr(0x80 | 127).pack('J', $payloadLen);
        }

        $frame .= $mask;

        for ($i = 0; $i < $payloadLen; $i++) {
            $frame .= chr(ord($payload[$i]) ^ ord($mask[$i % 4]));
        }

        fwrite($socket, $frame);
    }
}
