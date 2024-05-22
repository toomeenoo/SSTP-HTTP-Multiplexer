<?php

class SSTPMultiplexer {

    private $listenSocket = null;
    private $isPrimaryProcess = true;
    private $config = null;

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
            'listen_socket_wait_s'  => 60,
            'listen_loop_wait_ms'   =>  5,
            'data_loop_wait_ms'     => 10,
            'data_firstread_ms'     =>  1
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
                    }
                }
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
            while(!($data = stream_get_contents($connection))) usleep(1000*$this->config->data_firstread_ms);

            // Determine HTTP method from recieved data
            if(preg_match('/^([^ ]+)/', $data, $m)){
                $httpMethod = $m[1];
            }

            // Detect if it's http or SSTP, and open connection as client to desired target
            if($httpMethod == 'SSTP_DUPLEX_POST'){
                $this->logWrite("SSTP: $httpMethod ".stream_socket_get_name($connection, true));
                $target = stream_socket_client("tls://".$this->config->sstp_target, $ec, $em, 2, STREAM_CLIENT_CONNECT, self::unverifiedContext());
            }else{
                $this->logWrite("HTTP: $httpMethod ".stream_socket_get_name($connection, true));
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
                    $v  = stream_copy_to_stream($target, $connection);
                    $v += stream_copy_to_stream($connection, $target);
                    
                    // If there is no traffic, give cpu some rest 
                    if(!$v) usleep(10000);
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
            return false;
        } else {
            $this->isPrimaryProcess = false;
            return true;
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
            pcntl_wait($status);
        }
    }
}

$configFile = __DIR__.DIRECTORY_SEPARATOR.'config.ini';
if(file_exists('/etc/sstpmultipler/config.ini'))
    $configFile = '/etc/sstpmultipler/config.ini';

new SSTPMultiplexer(parse_ini_file($configFile, false) ?? []);
