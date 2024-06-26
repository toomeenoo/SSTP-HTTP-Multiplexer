<?php

class SSTPMultiplexer {

    private $listenSocket = null;
    private $isPrimaryProcess = true;
    private $config = null;
    private $childProcesses = [];

    /**
     * Create and configure server
     * @param array $config Associative array of configurable options
     */
    public function __construct(array $config)
    {
        $this->config = (object) array_merge([
            // Default options:
            "listen_ip_port"   => '0.0.0.0:2443',
            "ssl_cert"         => "example_cert.pem",
            "ssl_pkey"         => "example_key.pem",
            "http_target"      => '127.0.0.1:443',
            "sstp_target"      => '127.0.0.1:1443',
            "log_stdio"        => 0,
            // Performance tweaks:
            'listen_socket_wait_s'  =>  2,
            'listen_loop_wait_ms'   =>  1,
            'data_loop_wait_ms'     => 10,
            'data_init_loop_ms'     =>  1,
            'init_data_wait_s'      =>  5
        ], $config);

        set_time_limit(0);

        // Initialize program
        $this->init();
    }

    /**
     * Write log message, if logging is enabled
     * @param string $message message to be logged
     */
    private function logWrite(string $message)
    {
        if($this->config->log_stdio){
            echo $message."\n";
        }
    }

    /**
     * Initialize listener for this server
     */
    private function init()
    {
        // Get context options ready
        $ctx = stream_context_create([
            "ssl" => [
                "local_cert"  => $this->config->ssl_cert,
                "local_pk"    => $this->config->ssl_pkey,
                "verify_peer" => false // We do not expect client to provide certificate
            ]
        ]);

        // Create socket
        $this->listenSocket = stream_socket_server("tcp://".$this->config->listen_ip_port, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);

        // Check for errors
        if (!$this->listenSocket) {
            $this->logWrite("Can not listen on socket: $errstr ($errno)");
            die();
        } else {
            $this->logWrite("Socket ready on ".$this->config->listen_ip_port);
            // Accept all connections
            while (true) {
                if($conn = @stream_socket_accept($this->listenSocket, $this->config->listen_socket_wait_s, $peerName)){
                    // And create child process to handle them 
                    if($this->fork()){
                        $this->logWrite("Connected: ".$peerName);
                        $this->handle($conn);
                        exit;
                    }
                }
                $this->collectExited();
                // Wait now, not to kill cpu
                usleep(1000*$this->config->listen_loop_wait_ms);
            }
        }
    }

    /**
     * Shortcut to creating unverified contexts
     * Only to be used on local, network, otherwise you may 
     * want to replace occurences of this call!
     * @return resource created stream context
     */
    private static function unverifiedContext()
    {
        return stream_context_create([
            "ssl" => [
                "verify_peer"        => false,
                "verify_peer_name"   => false,
            ]
        ]);
    }

    /**
     * Handle client connection
     * @param resource $connection
     */
    private function handle($connection){
        // Now we can enable crypto - after forking, to not break TLS session
        $crypto = stream_socket_enable_crypto($connection, true, STREAM_CRYPTO_METHOD_TLS_SERVER);

        if($crypto){
            // Now we can unblock stream - after TLS enbled - we need want to support async communication
            stream_set_blocking($connection, false);

            // Load some data - we expect that next packet will contain at least http method
            $data = false;
            $tmax = microtime(true) + $this->config->init_data_wait_s;
            while(!($data = stream_get_contents($connection))){
                if(microtime(true) > $tmax){
                    @fclose($connection);
                    return;
                }
                usleep(1000*$this->config->data_init_loop_ms);
            }

            // Determine HTTP method from recieved data
            if(preg_match('/^([^ ]+)/', $data, $m)){
                $httpMethod = $m[1];
            }

            // Detect if it's http or SSTP, and open connection as client to desired target
            if($httpMethod == 'SSTP_DUPLEX_POST'){
                $this->logWrite("SSTP: $httpMethod");
                $target = stream_socket_client("tls://".$this->config->sstp_target, $ec, $em, 2, STREAM_CLIENT_CONNECT, self::unverifiedContext());
            }else{
                $this->logWrite("HTTP: $httpMethod");
                $data = preg_replace("/\r\n/", "\r\nMultiplexer-Client: ".stream_socket_get_name($connection, true)."\r\n", $data, 1);
                $target = stream_socket_client("tls://".$this->config->http_target, $ec, $em, 2, STREAM_CLIENT_CONNECT, self::unverifiedContext());
            }

            // Check if we'we connected successfully
            if($target){
                // Client have already TLS enabled, so set no-block to allow async  
                stream_set_blocking($target, false);

                // Write initial data we've read so far
                fwrite($target, $data);

                // While connection is open ...
                while($target && $connection){
                    // Count traffic bytes
                    $v  = stream_copy_to_stream($target, $connection) ?? 0;
                    $v += stream_copy_to_stream($connection, $target);
                    
                    // If there is no traffic, give cpu some rest 
                    if(!$v){
                        if(stream_get_meta_data($target)['eof'] || stream_get_meta_data($connection)['eof']){
                            break;
                        }
                        usleep(10000);
                    }
                }

                //Close connection to target
                @fclose($target);

            }else{
                // If http, respond with html error
                if($httpMethod != 'SSTP_DUPLEX_POST'){
                    fwrite($connection, $this->http503());
                }
                $this->logWrite("Unable connect to client!");
            }

        }else{
            $this->logWrite("SSL FAILED: ".stream_socket_get_name($connection, true));
        }

        // Close connection to client 
        @fclose($connection);
    }

    /**
     * Fork process and maintain hierarchy 
     */
    private function fork() : bool
    {
        $pid = pcntl_fork();
        if ($pid == -1) {
            die('Fork failure!');
        } else if ($pid) {
            $this->childProcesses[$pid] = true;
            return false;
        } else {
            $this->isPrimaryProcess = false;
            return true;
        }
    }

    /**
     * Collect status of exited child processes
     * so we do not let them hanging
     */
    private function collectExited(){
        if(count($this->childProcesses)){
            while (($pid = pcntl_wait($status, WNOHANG)) > 0) {
                unset($this->childProcesses[$pid]);
            }
        }
    }

    /**
     * HTTP - Html error response 
     */
    function http503(){
        $content = '<!DOCTYPE html><html lang="en">'
         .'<head><meta charset="UTF-8"><title>503 Service Unavailable</title></head>'
         .'<body>'
         .'    <h1>503 Service Unavailable</h1>'
         .'    <p>Can not connect to target service</p>'
         .'    <p><code>Generated '.date(DATE_ATOM).'</code></p>'
         .'    <p><small>PHP Multiplexer 1.0</small></p>'
         .'</body></html>';
        return join("\r\n", [
            "HTTP/1.1 200 OK",
            "Date: ".gmdate('D, d M Y H:i:s T'),
            "Content-Type: text/html",
            "Content-Length: ".strlen($content),
            "Server: PHP Multiplexer 1.0",
            "Accept-Ranges: bytes",
            "Connection: close",
            "",
            $content
        ]);
    }

    /**
     * Do not listen for connections anymore
     * Wait for all clients to disconnect
     */
    public function __destruct()
    {
        if($this->isPrimaryProcess){
            fclose($this->listenSocket);
            while(count($this->childProcesses)){
                $pid = pcntl_wait($status);
                if($pid >= 0){
                    unset($this->childProcesses[$pid]);
                }
            }
        }
    }
}

function parseCommandline(){
    $output = [];

    if(php_sapi_name() != 'cli')
        return $output;

    global $argc, $argv;
    $options = [
        '--listen-ip-port',
        '--ssl-cert',
        '--ssl-pkey',
        '--http-target',
        '--sstp-target',
        '--log-stdio',
        '--listen-socket-wait-s',
        '--listen-loop-wait-ms',
        '--data-loop-wait-ms',
        '--data-init-loop-ms',
        '--init-data-wait-s',
    ];

    if(in_array('--help', $argv)){
        echo "-- PHP Multiplexer 1.0 ----------------------\n";
        echo "Available commandline options:\n";
        echo "  ".join(" [config_value]\n  ", $options)." [config_value]\n";
        die();
    }

    $c = 1;
    while ($c < $argc) {
        $opt = $argv[$c];
        if(in_array($opt, $options)){
            if(isset($argv[$c+1])){
                $output[str_replace('-','_',substr($opt, 2))] = $argv[$c+1];
                $c += 2;
            }else{
                echo "Missing value for option $opt!\n";
                die();
            }
        }else{
            echo "Unknown commandline option $opt!\n";
            echo "Try --help\n";
            die();
        }
    }

    return $output;
}


$configFile = __DIR__.DIRECTORY_SEPARATOR.'config.ini';
if(file_exists('/etc/sstpmultipler/config.ini'))
    $configFile = '/etc/sstpmultipler/config.ini';

$configData = array_merge(parse_ini_file($configFile, false) ?? [], parseCommandline());

new SSTPMultiplexer($configData);
