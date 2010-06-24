<?php

if (!extension_loaded('libevent'))
    dl("libevent.so");

abstract class EventHttpServer {

    /**
     *
     * @var resource $ServerSocket comet server socket resource
     * @var resource $CommandSocket Command Channel socket resource
     * @var array $Config
     * @var array $ClientData Client Data
     */
    private $CurrentConnected;
    private $BaseEvent, $ServerEvent, $CommandEvent;
    private $ChunkDataHeader, $ChunkDataFin;
    private $HttpHeaders;
    protected $Config;
    protected $ClientData;
    protected $ServerSocket, $CommandSocket;

    /**
     * callback virtual function when receiving event message from web server.
     * @param string $CustomDefineEvent
     */
    protected function ServerPush($CustomDefineEvent) {
        
    }

    /**
     * callback virtual function when a comet client socket is inited.
     * @param string $SocketName
     */
    protected function ClientInited($SocketName) {
        
    }

    /**
     * callback virtual function when a comet client socket is disconnected.
     * @param string $SocketName
     */
    protected function ClientDisconnected($SocketName) {
        
    }

    /**
     * Command Channel event callback
     * @param resorec $Socket
     * @param int $Events
     */
    public function evcb_CommandEvent($Socket, $Events) {
        $ClientSocketForCommand = @stream_socket_accept($this->CommandSocket);
        $Data = unserialize(@fread($ClientSocketForCommand, 4096));
        stream_set_blocking($ClientSocketForCommand, 0);
        @stream_socket_shutdown($ClientSocketForCommand, STREAM_SHUT_RDWR);
        @fclose($ClientSocketForCommand);
        switch ($Data['Command']) {
            case 'Push':
                $this->ServerPush($Data['Event']);
                break;
            case 'Exit':
                echo "Start Killall Client Socket!\n";
                foreach ($this->ClientData as $SockName => $Data) {
                    $this->ClientShutDown($SockName);
                }
                echo "Killed all Client Socket!\n";
                event_base_loopbreak($this->BaseEvent);
                break;
        }
    }

    /**
     * Send data to client. using EVHttpRecv().
     * @param <type> $SocketName
     * @param mixed $Data Data send to client.
     * @return boolean
     */
    protected function ClientWrite($SocketName, $Data) {
        $Data = json_encode($Data);
        $Command = "EVHttpRecv($Data);";
        $this->ClientData[$SocketName]['Fin'] = true;
        return event_buffer_write($this->ClientData[$SocketName]['Event'], sprintf("%x\r\n", strlen($Command)) . "$Command\r\n");
    }

    /**
     * get ClientData array.
     * @return array
     */
    protected function getClientDataArray() {
        return $this->ClientData;
    }

    /**
     * Send raw javascript to client.
     * @param resource $ClientEvent
     * @param string $Command js code
     * @return boolean
     */
    protected function ClientWriteScript($ClientEvent, $Command) {
        $Command = "$Command;";
        $this->ClientData[$SocketName]['Fin'] = true;
        return event_buffer_write($ClientEvent, sprintf("%x\r\n", strlen($Command)) . "$Command\r\n");
    }

    /**
     *
     * @param array $Config
     */
    public function __construct($Config=array()) {
        $DefaultConfig = array(
            'ServerHost' => '0.0.0.0',
            'ServerPort' => 8888,
            'CommandHost' => '0.0.0.0',
            'CommandPort' => 8889,
            'MaxConnected' => 1000,
            'ClientSocketTTL' => 60,
            'BaseDir' => './web',
            'ServerName' => '127.0.0.1:8889'
        );
        $this->Config = array_merge($DefaultConfig, $Config);
        if ($this->Config['BaseDir']{0} != '/')
            $this->Config['BaseDir'] = getcwd () . $this->Config['BaseDir'];
        $this->ChunkDataHeader = "HTTP/1.1 200 OK\r\n"
                . "Cache-Control: no-cache\r\n"
                . "Pragma: no-cache\r\n"
                . "Connection: close\r\n"
                . "Transfer-Encoding: chunked\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
        $Command = "EVHttpFin();";
        $this->ChunkDataFin = sprintf("%x\r\n", strlen($Command)) . "$Command\r\n";
        echo "Server Started.\n";
        $this->MaxConnected = 0;
        $this->CurrentConnected = 0;
    }

    private function SendCommand($Command, $Data=null) {
        $Socket = stream_socket_client('tcp://' . $this->Config['CommandHost'] . ':' . $this->Config['CommandPort']);
        fwrite($Socket, serialize(array('Command' => $Command, 'Data' => $Data)));
        fclose($Socket);
    }
    /**
     * Setup signal and becomes a daemon.
     */
    private function preInit() {
        $pid = pcntl_fork();
        if ($pid) //Parent Process
            die("Daemon Start with $pid\n");
        declare(ticks = 1);
        posix_setsid();
    }

    /**
     * Initialize
     */
    private function Init() {
        $this->preInit();
        $this->CommandSocket = stream_socket_server('tcp://' . $this->Config['CommandHost'] . ':' . $this->Config['CommandPort'], $errno, $errstr);
        stream_set_blocking($this->CommandSocket, 0); // no blocking
        $this->ServerSocket = stream_socket_server('tcp://' . $this->Config['ServerHost'] . ':' . $this->Config['ServerPort'], $errno, $errstr);
        stream_set_blocking($this->ServerSocket, 0); // no blocking
    }
    /**
     * Signal callback
     * @param int $Signal
     */
    public function evcb_Signal($Signal) {        
        echo "Start Killall Client Socket!\n";
        foreach ($this->ClientData as $SockName => $Data)
            $this->ClientShutDown($SockName);
        echo "Killed all Client Socket!\n";
        event_base_loopbreak($this->BaseEvent);
    }

    /**
     * run Event
     */
    private function RunEvent() {
        $this->BaseEvent = event_base_new();
        $this->ServerEvent = event_new();

        $SIGTRAP_Event = event_new();
        event_set($SIGTRAP_Event, SIGTRAP, EV_SIGNAL | EV_PERSIST, array($this, 'evcb_Signal'), SIGTRAP);
        event_base_set($SIGTRAP_Event, $this->BaseEvent);
        event_add($SIGTRAP_Event);
        $SIGTERM_Event = event_new();
        event_set($SIGTERM_Event, SIGTERM, EV_SIGNAL | EV_PERSIST, array($this, 'evcb_Signal'), SIGTERM);
        event_base_set($SIGTERM_Event, $this->BaseEvent);
        event_add($SIGTERM_Event);

        event_set($this->ServerEvent, $this->ServerSocket, EV_READ | EV_PERSIST,
                array($this, 'evcb_doAccept'));
        event_base_set($this->ServerEvent, $this->BaseEvent);
        event_add($this->ServerEvent);

        $this->CommandEvent = event_new();
        event_set($this->CommandEvent, $this->CommandSocket, EV_READ | EV_PERSIST,
                array($this, 'evcb_CommandEvent'));
        event_base_set($this->CommandEvent, $this->BaseEvent);
        event_add($this->CommandEvent);
        event_base_loop($this->BaseEvent);
        echo "Do End Event!\n";
        event_free($SIGTRAP_Event);
        event_free($SIGTERM_Event);
        event_free($this->ServerEvent);
        event_free($this->CommandEvent);
        event_base_free($this->BaseEvent);
    }

    /**
     * EventHttpServer Main Loop
     */
    public function Run() {
        $this->Init();
        $this->RunEvent();
    }

    /**
     * callback event while receiving client connecting request
     * @param resorce $Socket
     * @param int $Events
     */
    public function evcb_doAccept($Socket, $Events) {
        $ClientSocket = @stream_socket_accept($this->ServerSocket);
        if ($this->CurrentConnected >= $this->Config['MaxConnected'])
            return;
        $this->CurrentConnected++;
        echo "Client " . stream_socket_get_name($ClientSocket, true) . " connected.\n";
        stream_set_blocking($ClientSocket, 0);
        $BufferEvent = event_buffer_new($ClientSocket, array($this, 'evcb_doReceive'), array($this, 'evcb_WriteFin'), array($this, 'evcb_ClientBufferError'), $ClientSocket);
        event_buffer_timeout_set($BufferEvent, $this->Config['ClientSocketTTL'], $this->Config['ClientSocketTTL']);
        event_buffer_watermark_set($BufferEvent, EV_WRITE, 1, 1);
        event_buffer_base_set($BufferEvent, $this->BaseEvent);
        event_buffer_enable($BufferEvent, EV_READ | EV_WRITE);
        event_add($BufferEvent);
        $this->ClientDataSet($ClientSocket, $BufferEvent);
    }

    /**
     * Setup Client Data
     */
    private function ClientDataSet($ClientSocket, $Event) {
        $SocketName = stream_socket_get_name($ClientSocket, true);
        $this->ClientData[$SocketName] = array('Socket' => $ClientSocket, 'Event' => $Event, 'Expire' => time() + $this->Config['ClientSocketTTL']);
    }

    /**
     * Initialize a comet client socket
     * @param resorce $ClientSocket
     */
    protected function ClientInit($ClientSocket) {
        $SocketName = stream_socket_get_name($ClientSocket, true);
        $this->ClientData[$SocketName]['Init'] = true;
        $this->ClientData[$SocketName]['Cookie'] = $this->HttpHeaders['cookie'];
    }

    /**
     * Check Client Socket is inited
     * @param resorce $ClientSocket
     * @return boolean return true if ClientSocket is inited.
     */
    private function IsClientInit($ClientSocket) {
        $SocketName = stream_socket_get_name($ClientSocket, true);
        return $this->ClientData[$SocketName]['Init'];
    }

    /**
     * remove Client connection data.
     * @param string $SocketName
     */
    private function ClientDataRemove($SocketName) {
        unset($this->ClientData[$SocketName]);
    }

    /**
     * shutdown a client socket.
     * @param string $SocketName
     * @param boolean $SendFin send EVHttpFin(); js code to client.
     */
    protected function ClientShutDown($SocketName, $SendFin=true) {
        $Event = $this->ClientData[$SocketName]['Event'];
        $ClientSocket = $this->ClientData[$SocketName]['Socket'];
        if ($this->IsClientInit($ClientSocket))
            $this->ClientDisconnected($SocketName);
        event_buffer_free($Event);
        if ($SendFin) {
            fwrite($ClientSocket, $this->ChunkDataFin);
            fwrite($ClientSocket, "0\r\n\r\n");
        }
        $this->ClientDataRemove($SocketName);
        @stream_socket_shutdown($ClientSocket, STREAM_SHUT_RDWR);
        @fclose($ClientSocket);
        $this->CurrentConnected--;
        echo "Client " . stream_socket_get_name($ClientSocket, true) . " disconnect.\n";
    }

    /**
     * parsing http headers
     * @param string $RawHeader
     * @param resorce $ClientSocket
     * @return boolean return true if it is a valid http header.
     */
    private function extraceHttpHeader($RawHeader, $ClientSocket) {
        $SocketName = stream_socket_get_name($ClientSocket, true);
        $this->ClientData[$SocketName]['RawHeaders'].=str_replace("\r", '', $RawHeader);
        if (strlen($this->ClientData[$SocketName]['RawHeaders']) > 1024) {
            $this->ClientShutDown($SocketName, false);
            return false;
        }
        if (strpos($this->ClientData[$SocketName]['RawHeaders'], "\n\n") === false)
            return false;
        $RawHeaders = explode("\n", $this->ClientData[$SocketName]['RawHeaders']);
        unset($this->ClientData[$SocketName]['RawHeaders']);
        foreach ($RawHeaders as $Index => $RawHeader) {
            $RawHeader = trim($RawHeader);
            if ($Index == 0) {
                list($Method, $File, $Protocal) = explode(' ', $RawHeader);
                $HttpHeaders['_Request_'] = array('Method' => strtoupper(trim($Method)), 'File' => trim($File), 'Protocal' => trim($Protocal));
                continue;
            }
            list($Key, $Data) = explode(':', $RawHeader);
            $Key = trim($Key);
            $Data = trim($Data);
            if ($Key == '' || $Data == '')
                continue;
            $HttpHeaders[strtolower($Key)] = $Data;
        }
        $RawCookies = explode(';', $HttpHeaders['cookie']);
        foreach ($RawCookies as $RawCookie) {
            list($Key, $Data) = explode('=', $RawCookie);
            $Key = trim($Key);
            $Data = trim($Data);
            if ($Key == '' || $Data == '')
                continue;
            $Cookies[$Key] = $Data;
        }
        $HttpHeaders['cookie'] = $Cookies;
        $this->HttpHeaders = $HttpHeaders;
        $this->ParseCookie();
        return true;
    }

    /**
     * Parse Cookies.
     */
    private function ParseCookie() {
        $SessionName = ini_get('session.name');
        $this->HttpHeaders['cookie'];
    }

    /**
     * a buffer event callback when socket status is prepared for reading.
     * @param resorce $BufferEvent
     * @param resorce $ClientSocket
     */
    public function evcb_doReceive($BufferEvent, $ClientSocket) {
        $SocketName = stream_socket_get_name($ClientSocket, true);
        $data = event_buffer_read($BufferEvent, 4096);
        if ($data !== false && $data != '') {
            if (!$this->CheckExpire($ClientSocket))
                return;
            if ($this->IsClientInit($ClientSocket))
                return;
            if (!$this->extraceHttpHeader($data, $ClientSocket))
                return;
            if ($this->ProcessRequest($BufferEvent, $SocketName)) {
                $this->ClientInit($ClientSocket);
                $this->ClientInited($SocketName);
            }
        }
    }

    /**
     * Check client socket is expired and shutwodn socket
     * @return boolean return true if socket is expire
     */
    private function CheckExpire($ClientSocket) {
        $SocketName = stream_socket_get_name($ClientSocket, true);
        $this->ClientData[$SocketName];
        if ($this->ClientData[$SocketName]['Expire'] > time())
            return true;
        $this->ClientShutDown($SocketName, $this->IsClientInit($ClientSocket));
        return false;
    }

    /**
     *
     * @param resource $BufferEvent 
     * @param string $SocketName
     * @return boolean return true if this is a comet request.
     */
    private function ProcessRequest($BufferEvent, $SocketName) {
        $Request = $this->HttpHeaders['_Request_'];
        switch ($Request['Method']) {
            case 'GET':
            case 'POST':
            case 'PUT':
            case 'DELETE':
                if (preg_match('/^\/comet\/?/i', $Request['File'])) {
                    echo "comet\n";
                    event_buffer_write($BufferEvent, $this->ChunkDataHeader);
                    return true;
                }
                break;
            default:
                $this->ClientShutDown($SocketName, false);
                return false;
        }
        $this->OutputFile($BufferEvent, $SocketName);
        return false;
    }

    /**
     * get file mime type
     * @param string $File file path
     * @return string meme type
     */
    private function MimeInfo($File) {
        $Info = pathinfo($File);
        switch (strtolower($Info['extension'])) {
            case 'html':
            case 'htm':
                return 'text/html';
            case 'txt':
            case 'js':
                return 'text/plain';
            case 'jpg':
            case 'jpeg':
                return 'image/jpeg';
            case 'gif':
                return 'image/gif';
            case 'png':
                return 'image/png';
            case 'gif':
                return 'image/gif';
            case 'swf':
                return 'application/x-shockwave-flash';
        }
    }

    /**
     * send static file content to client.
     * @param resorce $BufferEvent
     * @param string $SocketName
     */
    public function OutputFile($BufferEvent, $SocketName) {
        $Request = $this->HttpHeaders['_Request_'];
        if ($Request['File'] == '/')
            $File = 'index.html';
        else
            $File=preg_replace('/\.{2,}/', '', substr($Request['File'], 1));
        $File = $this->Config['BaseDir'] . "/$File";
        echo "$File\n";
        if (!file_exists($File))
            return $this->Send404Error($BufferEvent, $SocketName);
        $Date = filemtime($File);
        $Data = "HTTP/1.1 200\r\n"
                . "Content-Type: " . $this->MimeInfo($File) . "\r\n"
                . "Connection: close\r\n"
                . "Date: " . gmdate('D, j M Y H:i:s', $Date) . ' GMT' . "\r\n"
                . "Cache-control: max-age=86400\r\n"
                . "Content-Length: " . filesize($File) . "\r\n\r\n";
        echo $Data;
        event_buffer_write($BufferEvent, $Data);
        $hFile = fopen($File, 'r');
        while (!feof($hFile)) {
            $Data = fread($hFile, 4096);
            event_buffer_write($BufferEvent, $Data);
        }
        fclose($hFile);
        $this->ClientData[$SocketName]['DataFin'] = true;
    }

    /**
     * a buffer event callback while write buffer is empty.
     * @param resorce $BufferEvent
     * @param resorce $ClientSocket
     */
    public function evcb_WriteFin($BufferEvent, $ClientSocket) {
        $SocketName = stream_socket_get_name($ClientSocket, true);
        if ($this->ClientData[$SocketName]['Fin']) {
            event_buffer_free($BufferEvent);
            $this->ClientShutDown($SocketName);
            return;
        }
        if ($this->ClientData[$SocketName]['DataFin']) {
            event_buffer_free($BufferEvent);
            $this->ClientShutDown($SocketName, false);
            return;
        }
    }

    /**
     * Send 404 error to client.
     * @param <type> $BufferEvent
     * @param <type> $SocketName
     * @return boolean
     */
    private function Send404Error($BufferEvent, $SocketName) {
        $Message = "File not found!";
        $Data = "HTTP/1.0 404 Not Found\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n"
                . "Connection: close\r\n"
                . "Content-Length: " . strlen($Message) . "\r\n\r\n$Message";
        event_buffer_write($BufferEvent, $Data);
        $this->ClientData[$SocketName]['DataFin'] = true;
        return true;
    }

    /**
     * event buffer error callback. maybe timeout...
     * @param resorce $BufferEvent
     * @param int $Events
     * @param resorce $ClientSocket
     */
    public function evcb_ClientBufferError($BufferEvent, $Events, $ClientSocket) {
        $SocketName = stream_socket_get_name($ClientSocket, true);
        $this->ClientShutDown($SocketName, $this->IsClientInit($ClientSocket));
    }

}
