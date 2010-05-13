<?php
if (!extension_loaded('libevent')) dl("libevent.so");
abstract class EventHttpServer {
    private $CurrentConnected;
    private $Config;
    private $BaseEvent;
    private $ServerEvent,$CommandEvent;
    private $ChunkDataHeader,$InitScriptData,$ChunkDataFin;
    private $ClientDataHead,$ClientDataTail,$ClientData;
    private $HttpHeaders;
    public function CommandEvent($FD,$Events) {
        $ClientSocket=@stream_socket_accept($this->CommandSocket);
        $Data=unserialize(@fread($ClientSocket,4096));
        stream_set_blocking($ClientSocket,0);
        @stream_socket_shutdown($ClientSocket,STREAM_SHUT_RDWR);
        @fclose($ClientSocket);
        switch($Data['Command']) {
            case 'Push':
                $this->ServerPush($Data['Data']);
                break;
            case 'Exit':
                echo "Start Killall Client Socket!\n";
                while($this->ClientDataHead) $this->ClientShutDown($this->ClientDataHead);
                echo "Killed all Client Socket!\n";
                event_base_loopbreak($this->BaseEvent);
                break;
        }
    }
    /**
     *
     * @param mixed $Data
     */
    protected function ServerPush($Data) {

    }
    /**
     *
     * @param <type> $Client
     * @param <type> $Data
     * @return <type>
     */
    protected function ClientWrite($Client,$Data) {
        $Data=json_encode($Data);
        $Command="EVHttpRecv($Data);";
        $SocketName=stream_socket_get_name($Client['Socket'],true);
        $this->ClientData[$SocketName]['Fin']=true;
        return event_buffer_write($Client['Event'],sprintf("%x\r\n",strlen($Command))."$Command\r\n");
    }
    protected function getClientDataArray() {
        return $this->ClientData;
    }
    /*
    private function RemoveExpireSocket() {
        while($this->ClientDataHead) {
            $SocketData=$this->ClientData[$this->ClientDataHead];
            if($SocketData['Expire']>time()) break;
            $this->ClientShutDown($this->ClientDataHead);
        }
    }*/
    protected function ClientWriteScript($ClientEvent,$Command) {
        $Command="$Command;";
        return event_buffer_write($ClientEvent,sprintf("%x\r\n",strlen($Command))."$Command\r\n");
    }
    public function __construct($Config=array()) {
        $DefaultConfig=array(
                'ServerHost' => '0.0.0.0',
                'ServerPort' => 8888,
                'CommandHost'=> '127.0.0.1',
                'CommandPort'=> 8889,
                'MaxConnected'=>1000,
                'ClientSocketTTL'=>60,
                'ClientSocketTTLPool'=>30,
                'BaseDir'=>'./web',
        );
        $this->Config=array_merge($DefaultConfig,$Config);
        $this->ChunkDataHeader = "HTTP/1.1 200 OK\r\n"
                . "Cache-Control: no-cache\r\n"
                . "Pragma: no-cache\r\n"
                . "Connection: close\r\n"
                . "Transfer-Encoding: chunked\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
        $Command="EVHttpFin();";
        $this->ChunkDataFin=sprintf("%x\r\n",strlen($Command))."$Command\r\n";
        echo "Server Started.\n";
        $this->MaxConnected=0;
        $this->CurrentConnected=0;
        $this->ClientDataHead=false;
        $this->ClientDataTail=false;
    }
    private function SendCommand($Command,$Data=null) {
        $Socket=stream_socket_client('tcp://'.$this->Config['CommandHost'].':'.$this->Config['CommandPort']);
        fwrite($Socket,serialize(array('Command'=>$Command,'Data'=>$Data)));
        fclose($Socket);
    }
    public function SignalFunction($Signal) {
        switch($Signal) {
            case SIGALRM:
                echo "Alarm!\n";
                $this->SendCommand('Alarm');
                pcntl_alarm($this->Config['ClientSocketTTLPool']);
                break;
            case SIGTERM:
            case SIGTRAP:
                echo "End!!!\n";
                $this->SendCommand('Exit');
            case SIGCHLD:
                pcntl_wait($Status);
                die;
        }
    }
    private function Prepare() {
        $pid = pcntl_fork();
        if($pid)
            die("Daemon Start with $pid\n");
        declare(ticks = 1);
        posix_setsid();
        $pid=pcntl_fork();
        if(!$pid) return;
        //Parent
        //if(!pcntl_signal(SIGALRM,array($this,'SignalFunction'))) die("Signal SIGALRM Error\n");
        if(!pcntl_signal(SIGTERM,array($this,'SignalFunction'))) die("Signal SIGTERM Error\n");
        if(!pcntl_signal(SIGTRAP,array($this,'SignalFunction'))) die("Signal SIGTRAP Error\n");
        if(!pcntl_signal(SIGCHLD,array($this,'SignalFunction'))) die("Signal SIGCHLD Error\n");
        /*if($this->Config['ClientSocketTTL']>0)
            pcntl_alarm($this->Config['ClientSocketTTLPool']);
         * 
        */
        echo "Parent Start!\n";
        while(true) usleep(100000);

    }
    private function Connect() {
        $this->CommandSocket = stream_socket_server('tcp://'.$this->Config['CommandHost'].':'.$this->Config['CommandPort'],$errno,$errstr);
        stream_set_blocking($this->CommandSocket,0); // no blocking
        $this->ServerSocket = stream_socket_server('tcp://'.$this->Config['ServerHost'].':'.$this->Config['ServerPort'],$errno,$errstr);
        stream_set_blocking($this->ServerSocket,0); // no blocking
    }
    public function Run() {
        $this->Prepare();
        $this->Connect();
        $this->BaseEvent = event_base_new();
        $this->ServerEvent = event_new();
        event_set($this->ServerEvent, $this->ServerSocket, EV_READ | EV_PERSIST,
                array($this,'doAccept'));
        event_base_set($this->ServerEvent,$this->BaseEvent);
        event_add($this->ServerEvent);

        $this->CommandEvent = event_new();
        event_set($this->CommandEvent, $this->CommandSocket, EV_READ | EV_PERSIST,
                array($this,'CommandEvent'));
        event_base_set($this->CommandEvent,$this->BaseEvent);
        event_add($this->CommandEvent);
        event_base_loop($this->BaseEvent);
        echo "Do End Event!\n";
        event_free($this->ServerEvent);
        event_free($this->CommandEvent);
        event_base_free($this->BaseEvent);
    }

// client connect
    public function doAccept($Socket,$Events) {
        $ClientSocket=@stream_socket_accept($this->ServerSocket);
        if($this->CurrentConnected>=$this->Config['MaxConnected']) return;
        $this->CurrentConnected++;
        echo "Client " . stream_socket_get_name($ClientSocket,true) . " connected.\n";
        stream_set_blocking($ClientSocket,0);
        $BufferEvent=event_buffer_new($ClientSocket,array($this,'doReceive'),array($this,'WriteFin'),array($this,'OnClientBufferError'),$ClientSocket);
        event_buffer_timeout_set($BufferEvent,$this->Config['ClientSocketTTL'],$this->Config['ClientSocketTTL']);
        event_buffer_watermark_set($BufferEvent,EV_WRITE,1,1);
        event_buffer_base_set($BufferEvent,$this->BaseEvent);
        event_buffer_enable($BufferEvent,EV_READ|EV_WRITE);
        event_add($BufferEvent);
        $this->ClientQueueAdd($ClientSocket,$BufferEvent);
    }
    public function TimeoutEvent() {
        echo "Got Timeout\n";
    }
    private function ClientQueueAdd($ClientSocket,$Event) {
        $SocketName=stream_socket_get_name($ClientSocket,true);
        $this->ClientData[$SocketName]=array('Socket'=>$ClientSocket,'Event'=>$Event,'Expire'=>time()+$this->Config['ClientSocketTTL']);
        if(!$this->ClientDataHead) {
            $this->ClientDataHead=$SocketName;
            $this->ClientDataTail=$SocketName;
            return;
        }
        $this->ClientData[$SocketName]['Prev']=$this->ClientDataTail;
        $this->ClientDataTail=$SocketName;
        $this->ClientData[$this->ClientData[$SocketName]['Prev']]['Next']=$SocketName;
    }
    protected function ClientInit($ClientSocket) {
        $SocketName=stream_socket_get_name($ClientSocket,true);
        $this->ClientData[$SocketName]['Init']=true;
    }
    private function IsClientInit($ClientSocket) {
        $SocketName=stream_socket_get_name($ClientSocket,true);
        return $this->ClientData[$SocketName]['Init'];
    }
    private function ClientQueueRemove($SocketName) {
        $Data=$this->ClientData[$SocketName];
        if($Data['Prev']) $this->ClientData[$Data['Prev']]['Next']=$Data['Next'];
        if($Data['Next']) $this->ClientData[$Data['Next']]['Prev']=$Data['Prev'];
        if($this->ClientDataTail==$SocketName) $this->ClientDataTail=$Data['Prev'];
        if($this->ClientDataHead==$SocketName) $this->ClientDataHead=$Data['Next'];
        unset($this->ClientData[$SocketName]);
    }
    private function ClientShutDown($SocketName,$SendFin=true) {
        $Event=$this->ClientData[$SocketName]['Event'];
        $ClientSocket=$this->ClientData[$SocketName]['Socket'];
        event_buffer_free($Event);
        if($SendFin) {
            fwrite($ClientSocket,$this->ChunkDataFin);
            fwrite($ClientSocket,"0\r\n\r\n");
        }
        $this->ClientQueueRemove($SocketName);
        @stream_socket_shutdown($ClientSocket,STREAM_SHUT_RDWR);
        @fclose($ClientSocket);
        $this->CurrentConnected--;
        echo "Client " . stream_socket_get_name($ClientSocket,true) . " disconnect.\n";
    }
    private function extraceHttpHeader($RawHeader,$ClientSocket) {
        $SocketName=stream_socket_get_name($ClientSocket,true);
        $this->ClientData[$SocketName]['RawHeaders'].=str_replace("\r",'',$RawHeader);
        if(strlen($this->ClientData[$SocketName]['RawHeaders'])>1024) {
            $this->ClientShutDown($SocketName,false);
            return false;
        }
        if(strpos($this->ClientData[$SocketName]['RawHeaders'],"\n\n")===false) return false;
        $RawHeaders=explode("\n",$this->ClientData[$SocketName]['RawHeaders']);
        unset($this->ClientData[$SocketName]['RawHeaders']);
        foreach($RawHeaders as $Index => $RawHeader) {
            $RawHeader=trim($RawHeader);
            if($Index==0) {
                list($Method,$File,$Protocal)=explode(' ',$RawHeader);
                $HttpHeaders['_Request_']=array('Method'=>strtoupper(trim($Method)),'File'=>trim($File),'Protocal'=>trim($Protocal));
                continue;
            }
            list($Key,$Data)=explode(':',$RawHeader);
            $Key=trim($Key);
            $Data=trim($Data);
            if($Key==''||$Data=='') continue;
            $HttpHeaders[strtolower($Key)]=$Data;
        }
        $RawCookies=explode(';',$HttpHeaders['cookie']);
        foreach($RawCookies as $RawCookie) {
            list($Key,$Data)=explode('=',$RawCookie);
            $Key=trim($Key);
            $Data=trim($Data);
            if($Key==''||$Data=='') continue;
            $Cookies[$Key]=$Data;
        }
        $HttpHeaders['cookie']=$Cookies;
        $this->HttpHeaders=$HttpHeaders;
        $this->ParseCookie();
        return true;
    }
    private function ParseCookie() {
        $SessionName=ini_get('session.name');
        $_COOKIE=$this->HttpHeaders['cookie'];
        session_id($this->HttpHeaders['cookie'][$SessionName]);
        session_start();
    }
// client send request , and server will response data
    public function doReceive($BufferEvent,$ClientSocket) {
        $SocketName=stream_socket_get_name($ClientSocket,true);
        $data=event_buffer_read($BufferEvent,4096);
        if($data !== false && $data != '') {
            if(!$this->CheckExpire($ClientSocket)) return;
            if($this->IsClientInit($ClientSocket)) return;
            if(!$this->extraceHttpHeader($data,$ClientSocket)) return;
            if($this->ProcessRequest($BufferEvent,$SocketName))
                $this->ClientInit($ClientSocket);
        }
    }
    private function CheckExpire($ClientSocket) {
        $SocketName=stream_socket_get_name($ClientSocket,true);
        $this->ClientData[$SocketName];
        if($this->ClientData[$SocketName]['Expire']>time()) return true;
        $this->ClientShutDown($SocketName,$this->IsClientInit($ClientSocket));
        return false;
    }
    private function ProcessRequest($BufferEvent,$SocketName) {
        $Request=$this->HttpHeaders['_Request_'];
        switch($Request['Method']) {
            case 'GET':
            case 'POST':
            case 'PUT':
            case 'DELETE':
                if(preg_match('/^\/comet\/?$/i',$Request['File'])) {
                    echo "comet\n";
                    event_buffer_write($BufferEvent,$this->ChunkDataHeader);
                    return true;
                }
                break;
            default:
                $this->ClientShutDown($SocketName,false);
                return false;
        }
        echo "output file\n";
        $this->OutputFile($BufferEvent,$SocketName);
        return false;
    }
    private function MimeInfo($File) {
        $Info = pathinfo($File);
        switch(strtolower($Info['basename'])) {
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
    public function OutputFile($BufferEvent,$SocketName) {
        $Request=$this->HttpHeaders['_Request_'];
        $File=preg_replace('/\.{2,}/','',substr($Request['File'],1));
        $File=getcwd().'/'.$this->Config['BaseDir']."/$File";
        if(!file_exists($File))
            return $this->Send404Error($BufferEvent,$SocketName);
        $Data="HTTP/1.1 200\r\n"
                ."Content-Type: ".$this->MimeInfo($File)."\r\n"
                ."Connection: close\r\n"
                ."Content-Length: ".filesize($File)."\r\n\r\n";
        echo $Data;
        event_buffer_write($BufferEvent,$Data);
        $hFile=fopen($File,'r');
        while(!feof($hFile)) {
            $Data=fread($hFile,4096);
            event_buffer_write($BufferEvent,$Data);
        }
        fclose($hFile);
        $this->ClientData[$SocketName]['DataFin']=true;
    }
    public function WriteFin($BufferEvent,$ClientSocket) {
        $SocketName=stream_socket_get_name($ClientSocket,true);
        if($this->ClientData[$SocketName]['Fin']) {
            event_buffer_free($BufferEvent);
            $this->ClientShutDown($SocketName);
            return;
        }
        if($this->ClientData[$SocketName]['DataFin']) {
            event_buffer_free($BufferEvent);
            $this->ClientShutDown($SocketName,false);
            return;
        }
    }
    private function Send404Error($BufferEvent,$SocketName) {
        $Message="File not found!";
        $Data="HTTP/1.0 404 Not Found\r\n"
                ."Content-Type: text/plain; charset=UTF-8\r\n"
                ."Connection: close\r\n"
                ."Content-Length: ".strlen($Message)."\r\n\r\n$Message";
        event_buffer_write($BufferEvent,$Data);
        $this->ClientData[$SocketName]['DataFin']=true;
        return true;
    }
    public function OnClientBufferError($BufferEvent,$Events,$ClientSocket) {
        $SocketName=stream_socket_get_name($ClientSocket,true);
        $this->ClientShutDown($SocketName,$this->IsClientInit($ClientSocket));
    }
}
