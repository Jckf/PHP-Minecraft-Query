<?php

class MinecraftQueryException extends Exception {
    // And that is how the cookie crumbles.
}

class MinecraftQuery {
    private $socket;
    private $players;
    private $info;

    public function connect($ip, $port = 25565, $timeout = 3) {
        if (!is_int($timeout) || $timeout < 0)
            throw new InvalidArgumentException('Timeout must be an integer.');

        $this->socket = @fsockopen('udp://' . $ip, (int) $port, $errno, $errstr, $timeout);

        if ($errno || $this->socket === false)
            throw new MinecraftQueryException('Could not create socket: ' . $errstr);

        stream_set_timeout($this->socket, $timeout);
        stream_set_blocking($this->socket, true);

        try {
            $challenge = $this->get_challenge();
            
            $this->get_status($challenge);
        } catch (MinecraftQueryException $e) {
            // We catch this because we want to close the socket. Not very elegant.
            fclose($this->socket);

            throw new MinecraftQueryException($e->getMessage());
        }

        fclose($this->socket);
    }
    
    public function get_info() {
        return isset($this->info) ? $this->info : false;
    }
    
    public function get_players() {
        return isset($this->players) ? $this->players : false;
    }
    
    private function get_challenge() {
        $data = $this->write_data(0x09);

        if ($data === false)
            throw new MinecraftQueryException('Failed to receive challenge.');

        return pack('N', $data);
    }
    
    private function get_status($challenge) {
        $data = $this->write_data(0x00, $challenge . pack('c*', 0x00, 0x00, 0x00, 0x00));

        if (!$data)
            throw new MinecraftQueryException('Failed to receive status.');

        $last = '';
        $info = array();

        $data = substr($data, 11); // splitnum + 2 int
        $data = explode("\x00\x00\x01player_\x00\x00", $data);

        if (count($data) !== 2)
            throw new MinecraftQueryException('Failed to parse server\'s response.');

        $players = substr($data[1], 0, -2);
        $data    = explode("\x00", $data[0]);

        $keys = array(
            'hostname'   => 'HostName',
            'gametype'   => 'GameType',
            'version'    => 'Version',
            'plugins'    => 'Plugins',
            'map'        => 'Map',
            'numplayers' => 'Players',
            'maxplayers' => 'MaxPlayers',
            'hostport'   => 'HostPort',
            'hostip'     => 'HostIp'
        );

        foreach ($data as $key => $value) {
            if (~$key & 1) {
                if (!array_key_exists($value, $keys)) {
                    $last = false;
                    continue;
                }

                $last = $keys[$value];
                $info[$last] = '';
            } else if ($last != false) {
                $info[$last] = $value;
            }
        }

        // Ints
        $info['players']    = intval($info['players']);
        $info['maxplayers'] = intval($info['maxplayers']);
        $info['hostport']   = intval($info['hostport']);

        // Parse "plugins", if any.
        if ($info['plugins']) {
            $data = explode(': ', $info['plugins'], 2);

            $info['rawplugins'] = $info['plugins'];
            $info['software']   = $data[0];

            if (count($data) == 2)
                $info['plugins'] = explode('; ', $data[1]);
        } else {
            $info['software'] = 'Vanilla';
        }

        $this->info = $info;

        if ($players)
            $this->players = explode("\x00", $players);
    }

    private function write_data($command, $append = '') {
        $command = pack('c*', 0xFE, 0xFD, $command, 0x01, 0x02, 0x03, 0x04) . $append;
        $length  = strlen($command);

        if ($length !== fwrite($this->socket, $command, $length))
            throw new MinecraftQueryException('Failed to write on socket.');

        $data = fread($this->socket, 2048);

        if ($data === false)
            throw new MinecraftQueryException('Failed to read from socket.');

        if (strlen($data) < 5 || $data[0] != $command[2])
            return false;

        return substr($data, 5);
    }
}
