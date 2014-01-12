<?php

class MinecraftPingException extends Exception {
    // And that is how the cookie crumbles.
}

class MinecraftPing {
    private $socket;
    private $server_ip;
    private $server_port;
    private $timeout;

    public function __construct($ip, $port = 25565, $timeout = 2) {
        $this->server_ip = $ip;
        $this->server_port = (int) $port;
        $this->timeout = (int) $timeout;

        $this->connect();
    }

    public function __destruct() {
        $this->close();
    }

    public function close() {
        if ($this->socket !== null) {
            socket_close($this->socket);

            $this->socket = null;
        }
    }

    public function connect() {
        $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        socket_set_option($this->socket, SOL_SOCKET, SO_SNDTIMEO, array('sec' => $this->timeout, 'usec' => 0));
        socket_set_option($this->socket, SOL_SOCKET, SO_RCVTIMEO, array('sec' => $this->timeout, 'usec' => 0));

        if ($this->socket === false || @socket_connect($this->socket, $this->server_ip, $this->server_port) === false)
            throw new MinecraftPingException('Failed to connect or create a socket.');
    }

    public function query() {
        $length = strlen($this->server_ip);
        $data = pack('cccca*', hexdec($length), 0, 0x04, $length, $this->server_ip) . pack('nc', $this->server_port, 0x01);

        socket_send($this->socket, $data, strlen($data), 0); // Handshake.
        socket_send($this->socket, "\x01\x00", 2, 0); // Status ping.

        $length = $this->read_var_int(); // Full packet length.

        if ($length < 10)
            return false;

        socket_read($this->socket, 1); // Packet type, in server ping it's 0.

        $length = $this->read_var_int(); // String length.

        $data = socket_read($this->socket, $length, PHP_NORMAL_READ); // and finally the json string

        if ($data === false) {
            throw new MinecraftPingException('Server didn\'t return any data.');
        }

        $data = json_decode($data, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            if (function_exists('json_last_error_msg'))
                throw new MinecraftPingException(json_last_error_msg());

            throw new MinecraftPingException('JSON parsing failed.');
        }

        return $data;
    }

    public function query_old_pre17() {
        socket_send($this->socket, "\xFE\x01", 2, 0);
        $len = socket_recv($this->socket, $data, 512, 0);

        if ($len < 4 || $data[0] !== "\xFF")
            return false;

        $data = substr($data, 3); // Strip packet header (kick message packet and short length).
        $data = iconv('UTF-16BE', 'UTF-8', $data);

        // Are we dealing with Minecraft 1.4+ server?
        if ($data[1] === "\xA7" && $data[2] === "\x31") {
            $data = explode("\x00", $data);

            return array(
                'hostname'   => $data[3],
                'players'    => intval($data[4]),
                'maxplayers' => intval($data[5]),
                'protocol'   => intval($data[1]),
                'version'    => $data[2]
           );
        }

        $data = explode("\xA7", $data);

        return array(
            'hostname'   => substr($data[0], 0, -1),
            'players'    => isset($data[1]) ? intval($data[1]) : 0,
            'maxplayers' => isset($data[2]) ? intval($data[2]) : 0,
            'protocol'   => 0,
            'version'    => '1.3'
        );
    }

    private function read_var_int() {
        $i = 0;
        $j = 0;

        while(true) {
            $k = @socket_read($this->socket, 1);

            if ($k === false)
                return 0;

            $k = ord($k);

            $i |= ($k & 0x7F) << $j++ * 7;

            if ($j > 5)
                throw new MinecraftPingException('VarInt too big.');

            if (($k & 0x80) != 128)
                break;
        }

        return $i;
    }
}
