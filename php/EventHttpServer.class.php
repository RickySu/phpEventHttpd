<?php
if (!extension_loaded('libevent'))
    dl("libevent.so");
require_once(dirname(__FILE__).'/EventHttpClientSocket.class.php');
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
    protected $Config;
    protected $ServerIndex=0;
    protected $ServerSocket, $CommandSocket,$ChildProcess;
    protected $ControlProcess=false,$AcceptIndex=0,$ServerEventDisabled=false;
    protected $SEMKey,$SEM,$SHMKey,$SHM;
    public function getBaseEvent(){
        return $this->BaseEvent;
    }
    public function getConfig(){
        return $this->Config;
    }
    /**
     * callback virtual function when receiving event message from web server.
     * @param string $CustomDefineEvent
     */
    protected function ServerPush($CustomDefineEvent) {
        
    }

    /**
     * callback virtual function when a comet client socket is inited.
     * @param ClientSocket $Socket
     * @param array $Headers
     * @param boolean
     */
    public function ClientInited(ClientSocket $Socket,$Headers) {
        return true;
    }

    /**
     * callback virtual function when a comet client socket is disconnected.
     * @param ClientSocket $Socket
     */
    public function ClientDisconnected(ClientSocket $Socket) {
        
    }

    /**
     * Command Channel event callback
     * @param resorec $Socket
     * @param int $Events
     */
    public function evcb_CommandEvent($Socket, $Events) {
        $ClientSocketForCommand = @stream_socket_accept($this->CommandSocket);
        $Data = json_decode($aaa=@fread($ClientSocketForCommand, 4096),true);
        stream_set_blocking($ClientSocketForCommand, 0);
        @stream_socket_shutdown($ClientSocketForCommand, STREAM_SHUT_RDWR);
        @fclose($ClientSocketForCommand);
        switch ($Data['Command']) {
            case 'Report':
                file_put_contents('/tmp/xdebug/'.$this->ServerIndex,$this->ServerIndex.':'.time().':'.$this->CurrentConnected."\n");
                break;
            case 'GC':
                $this->ClientGC();
                break;
            case 'Exit':
                echo "Start Killall Client Socket!\n";
                foreach ($this->ClientData as $SockName => $Data)
                   $this->ClientShutDown($SockName);
                echo "Killed all Client Socket!\n";            
                event_base_loopbreak($this->BaseEvent);
                shmop_close($this->SHM);
                break;
            case 'Push':
                $this->ServerPush($Data['Event']);
                break;
        }
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
            'MaxFork'=>2,
            'MaxConnected' => 1000,
            'ClientSocketTTL' => 60,
            'ClientGCPooling' => 60,
            'ClientTransTimeout'=>5,
            'BaseDir' => './web',
            'ServerName' => '127.0.0.1'
        );
        $this->Config = array_merge($DefaultConfig, $Config);
        if ($this->Config['BaseDir']{0} != '/')
            $this->Config['BaseDir'] = getcwd () . $this->Config['BaseDir'];
        echo "Server Started.\n";
        $this->MaxConnected = 0;
        $this->CurrentConnected = 0;
    }
    public function IncCurrentConnected(){
        $this->CurrentConnected++;
    }
    public function DecCurrentConnected(){
        $this->CurrentConnected--;
    }    
    private function SendCommand($Command, $Data=null) {
        for($i=1;$i<=$this->Config['MaxFork'];$i++){
            $CommandPort=$this->Config['CommandPort']+$i;
//            echo 'Send Command tcp://' . $this->Config['CommandHost'] . ":$CommandPort\n";            
            $Socket = stream_socket_client('tcp://' . $this->Config['CommandHost'] . ":$CommandPort" );
            fwrite($Socket, json_encode(array('Command' => $Command, 'Data' => $Data)));
            fclose($Socket);
        }
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
        $this->SEMKey=ftok(__FILE__,'m');
        $this->SEM=sem_get($this->SEMKey, 1,0600,-1);
        $this->SHMKey=ftok(__FILE__,'t');
        $this->SHM=shmop_open($this->SHMKey,"c",0600,1);
        $this->SHM=shmop_open($this->SHMKey,"w",0600,1);
        shmop_write($this->SHM,pack('C',0),0);
    }

    /**
     * Initialize
     */
    private function Init() {
        $this->preInit();
        $this->ServerSocket = stream_socket_server('tcp://' . $this->Config['ServerHost'] . ':' . $this->Config['ServerPort'], $errno, $errstr);
        stream_set_blocking($this->ServerSocket, 0); // no blocking
        $CommandPort=$this->Config['CommandPort'];
        for($i=0;$i<$this->Config['MaxFork'];$i++){
         $pid=pcntl_fork();         
         $CommandPort++;
         $this->ServerIndex=$i;
         if(!$pid) break;
         $this->ChildProcess[$i]=$pid;
        }
        if(!$pid) {
          $this->Config['CommandPort']=$CommandPort; 
          $this->ControlProcess=false;
          $this->Config['ServerName']=$this->Config['ServerName'].':'.$this->Config['CommandPort'];
          $this->CommandSocket = stream_socket_server('tcp://' . $this->Config['CommandHost'] . ':' . $this->Config['CommandPort'], $errno, $errstr);
          stream_set_blocking($this->CommandSocket, 0); // no blocking          
        }
        else {
          $this->ControlProcess=true;
        }

    }
    /**
     * Signal callback
     * @param int $Signal
     */
    public function evcb_Signal($hEvent, $Events, $Signal) {
      switch($Signal){
        case SIGALRM:
           $this->SendCommand('GC');
           break;
        case SIGUSR2:
           $this->SendCommand('Report');
           break;
        case SIGTERM:
        case SIGTRAP:
           $this->SendCommand('Exit');
           foreach($this->ChildProcess as $pid)
             pcntl_waitpid($status,$pid);             
           event_base_loopbreak($this->BaseEvent);
           sem_remove($this->SEM);
           shmop_delete($this->SHM);
           shmop_close($this->SHM);           
      }
    }

    /**
     * run Event
     */
    private function RunEvent() {
        $this->BaseEvent = event_base_new();
        $this->ServerEvent = event_new(); 
if($this->ControlProcess){
        $SIGUSR2_Event = event_new();
        event_set($SIGUSR2_Event,SIGUSR2, EV_SIGNAL | EV_PERSIST, array($this, 'evcb_Signal'), SIGUSR2);
        event_base_set($SIGUSR2_Event, $this->BaseEvent);
        event_add($SIGUSR2_Event);
        $SIGTRAP_Event = event_new();
        event_set($SIGTRAP_Event, SIGTRAP, EV_SIGNAL, array($this, 'evcb_Signal'), SIGTRAP);
        event_base_set($SIGTRAP_Event, $this->BaseEvent);
        event_add($SIGTRAP_Event);
        $SIGTERM_Event = event_new();
        event_set($SIGTERM_Event, SIGTERM, EV_SIGNAL, array($this, 'evcb_Signal'), SIGTERM);
        event_base_set($SIGTERM_Event, $this->BaseEvent);
        event_add($SIGTERM_Event);
        $SIGALRM_Event = event_new();
        event_set($SIGALRM_Event,SIGALRM, EV_SIGNAL | EV_PERSIST, array($this, 'evcb_Signal'), SIGALRM);
        event_base_set($SIGALRM_Event, $this->BaseEvent);
        event_add($SIGALRM_Event);
        pcntl_alarm($this->Config['ClientGCPooling']);
}
else{
        event_set($this->ServerEvent, $this->ServerSocket, EV_READ,
                array($this, 'evcb_doAccept'));
        event_base_set($this->ServerEvent, $this->BaseEvent);
        event_add($this->ServerEvent);
        $this->CommandEvent = event_new();
        event_set($this->CommandEvent, $this->CommandSocket, EV_READ | EV_PERSIST,
                array($this, 'evcb_CommandEvent'));
        event_base_set($this->CommandEvent, $this->BaseEvent);
        event_add($this->CommandEvent);
}

        event_base_loop($this->BaseEvent);

        echo "Do End Event!\n";
if($this->ControlProcess){
        event_free($SIGTRAP_Event);
        event_free($SIGTERM_Event);
        event_free($SIGALRM_Event);
        event_free($SIGUSR2_Event);        
}        
        event_free($this->ServerEvent);
        event_free($this->CommandEvent);
        event_base_free($this->BaseEvent);
        fclose($this->ServerSocket);
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
       event_add($this->ServerEvent);
/*
       $AcceptIndex=array_pop(unpack('C',shmop_read($this->SHM,0,1)));
       if($AcceptIndex!=$this->ServerIndex){
               //           echo "Ignore:".$this->ServerIndex."\n";
               usleep(10);
               return;
       }
       sem_acquire($this->SEM);
       $AcceptIndex=($AcceptIndex+1)%$this->Config['MaxFork'];
       shmop_write($this->SHM,pack('C',$AcceptIndex),0);
       sem_release($this->SEM);
*/       
       if($this->CurrentConnected >= $this->Config['MaxConnected'])
            return;
//       echo "\t\t:BeforeAccept\t".microtime(true)."\n";
       $ClientSocket = stream_socket_accept($this->ServerSocket,0);
       if(!$ClientSocket) {
//          echo "doAccept:".$this->Config['CommandPort']."->Not Connect!!!\n";
          return;
       }
#       echo stream_socket_get_name($ClientSocket, true);
//       echo ":StartAccept\t".microtime(true)."\n";
       $Client=new ClientSocket($this,$ClientSocket);        
//       echo $Client->getSocketName().":ClientSocketend\t".microtime(true)."\n";
//        echo "doAccept:".$this->Config['CommandPort']."->Client $Client connected.\n";
//        echo "Debug".$this->ServerIndex.' '.posix_getpid()."   ->end real_doAccept\n";
    }

    protected function ClientGC(){
       echo "ClientGC...\n";
//       print_r($this->ClientData);
//       echo "...";
       foreach ($this->ClientData as $SockName => $Client)
         $Client->CheckExpire();
    }
    protected function getClientSocket($SocketName){
       if($this->ClientData[$SocketName] instanceof ClientSocket){
           return $this->ClientData[$SocketName];
       }
       return false;
    }
    public function addToClientData(ClientSocket $Client){
       $this->ClientData[$Client->getSocketName()]=$Client;
    }
    public function removeFromClientData(ClientSocket $Client){
       unset($this->ClientData[$Client->getSocketName()]);
    }
}
