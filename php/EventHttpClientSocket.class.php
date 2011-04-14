<?php
class OutputFile{
  protected static $File;
  protected function __construct(){}
  /**
    * get file mime type
    * @param string $File file path
    * @return string meme type
  */
  private static function MimeInfo($File) {
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
  public static function get($File){
      if(isset(self::$File[$File])){
          return self::$File[$File];
      }      
      $Mime = self::MimeInfo($File);
      $Date = filemtime($File); 
      @$Size = filesize($File);
      $Data=file_get_contents($File);
      if($Data===false){
          return false;
      }
      self::$File[$File]=array(
          'Mime'=>$Mime,
          'Date'=>$Date,
          'Data'=>$Data,
          'Size'=>$Size,
      );
      return self::$File[$File];
  }
}
class ClientSocket{
    const ChunkDataHeader = <<<EOT
HTTP/1.1 200 OK\r
Cache-Control: no-cache\r
Pragma: no-cache\r
Connection: close\r
Transfer-Encoding: chunked\r
Content-Type: text/plain; charset=UTF-8\r\n\r\n1\r\n;\r
EOT;
    const ChunkDataFin="0\r\n\r\n";
    protected $Config;
    protected $BaseEvent,$BufferEvent;
    protected $Socket,$Name,$ExtraData=array();
    protected $DataFin=false,$Fin=false,$RawHeader='';
    protected $Expire;
    protected $Init=false;
    protected $Serevr;
    public function __construct(EventHttpServer $Server,$Socket){
        $this->Config=$Server->getConfig();
        $this->BaseEvent=$Server->getBaseEvent();
        $this->Socket=$Socket;
        $this->Name = stream_socket_get_name($Socket, true);
        $this->Expire=time()+$this->Config['ClientTransTimeout'];
        $this->Server=$Server;
        $this->InitSocket();
    }
    public function setExtraData($Key,$Data){
        $this->ExtraData[$Key]=$Data;
    }    
    public function getExtraData($Key){
        return $this->ExtraData[$Key];
    }
    protected function InitSocket(){
        stream_set_blocking($this->Socket, 0);
        stream_set_read_buffer($this->Socket, 4096);
        stream_set_write_buffer($this->Socket, 4096);        
        $this->BufferEvent = event_buffer_new($this->Socket, 
                    array($this, 'evcb_doReceive'),
                    array($this, 'evcb_WriteFin'), 
                    array($this, 'evcb_ClientBufferError'),
                    $this->Socket);
        if(!$this->BufferEvent){
//            echo "Buffer Event Error $this\n";
            @stream_socket_shutdown($this->Socket, STREAM_SHUT_RDWR);
            @fclose($this->Socket);            
        }
        event_buffer_watermark_set($this->BufferEvent, EV_WRITE, 1, 1);
        event_buffer_base_set($this->BufferEvent, $this->BaseEvent);
        event_buffer_enable($this->BufferEvent, EV_READ | EV_WRITE);
        $this->Server->IncCurrentConnected();
    }
    protected function Init(){
        $this->Init=true;
        $this->Server->addToClientData($this);
    }
    protected function isInit(){
        return $this->Init;
    }
    protected function Remove(){
        $this->Server->removeFromClientData($this);
    }
    public function __toString(){
        return $this->getSocketName();
    }
    public function getSocketName(){
        return $this->Name;
    }
    public function Shutdown($SendFin=true,$Force=false){
        if($this->isInit()&&(!$Force)){
            $this->Server->ClientDisconnected($this);
        }
        event_del($this->BufferEvent);
        event_buffer_free($this->BufferEvent);
        if($SendFin) {
            fwrite($this->Socket, self::ChunkDataFin);
        }
        $this->Remove();
//        echo "Client $this disconnect.\n";
        @stream_socket_shutdown($this->Socket, STREAM_SHUT_RDWR);
        @fclose($this->Socket);
        $this->Server->DecCurrentConnected();
    }

    /**
     * Send data to client. using EVHttpRecv().
     * @param mixed $Data Data send to client.
     * @return boolean
     */
    public function Write($Data=null) {
        if($Data!==null){
          $Data = json_encode($Data);
          $Command = "EVHttpRecv($Data);";
          $Command = sprintf("%x\r\n", strlen($Command)) . "$Command\r\n";
        }
        else
          $Command='';
        $this->DataFin= true;
        return event_buffer_write($this->BufferEvent, $Command.self::ChunkDataFin);
    }

    /**
     * parsing http headers
     * @param string $RawHeader
     * @param resorce $ClientSocket
     * @return boolean return true if it is a valid http header.
     */
    protected function extraceHttpHeader($RawHeader) {
        $this->RawHeader.=str_replace("\r", '', $RawHeader);
        if (isset($this->RawHeader{1024})) {
            $this->Shutdown(false);
            return false;
        }
        if (($Pos=strpos($this->RawHeader, "\n\n")) === false)
            return false;
            
        $RawHeaders = explode("\n",substr($this->RawHeader,0,$Pos));
        unset($this->RawHeader);
        foreach ($RawHeaders as $Index => $RawHeader) {
            $RawHeader = trim($RawHeader);
            if ($Index == 0) {
                list($Method, $File, $Protocal) = explode(' ', $RawHeader);
                list($File,$RawParams)=explode('?',$File);
                parse_str($RawParams,$Param);
                $HttpHeaders['_Request_'] = array('Method' => strtoupper(trim($Method)), 'File' => trim($File), 'Protocal' => trim($Protocal),'Param'=>$Param);
                continue;
            }
            list($Key, $Data) = explode(':', $RawHeader);
            $Key = trim($Key);
            $Data = trim($Data);
            if ($Key == '' || $Data == '') continue;
            $HttpHeaders[strtolower($Key)] = $Data;
        }
        return $HttpHeaders;
    }
    /**
     * a buffer event callback when socket status is prepared for reading.
     * @param resorce $BufferEvent
     * @param resorce $ClientSocket
     */
    public function evcb_doReceive($BufferEvent, $Socket) {
//        echo $this->getSocketName().":StartRead\t".microtime(true)."\n";    
        $data = event_buffer_read($BufferEvent, 4096);
        if ($data !== false && $data != '') {
            if (!$this->CheckExpire())
                return;
            if ($this->isInit())
                return;
            $Headers=$this->extraceHttpHeader($data);
            if($Headers===false) return;
            if ($this->ProcessRequest($Headers)) {
                $this->Init();
                if(!$this->Server->ClientInited($this,$Headers)){
//                  echo "Session Verify Failed!\n";
                  $this->Shutdown(false,true);                  
                }
            }
        }
    }

    /**
     * Check client socket is expired and shutwodn socket
     * @return boolean return true if socket is expire
     */
    public function CheckExpire() {
        if ($this->Expire > time()){
            return true;
        }
        if($this->isInit()){
            $this->Write();
        }
        //$this->ClientShutDown($SocketName, $this->IsClientInit($ClientSocket));
        return false;
    }

    /**
     *
     * @param array $Headers
     * @return boolean return true if this is a comet request.
     */
    private function ProcessRequest($Headers) {
        $Request = $Headers['_Request_'];
        switch ($Request['Method']) {
            case 'GET':
            case 'POST':
            case 'PUT':
            case 'DELETE':
                if (preg_match('/^\/comet\/?/i', $Request['File'])) {
                    event_buffer_timeout_set($this->BufferEvent, $this->Config['ClientSocketTTL'],$this->Config['ClientTransTimeout']);
                    $this->Expire=time()+$this->Config['ClientSocketTTL'];
                    event_buffer_write($this->BufferEvent, self::ChunkDataHeader);
                    return true;
                }
                break;
            default:
                $this->Shutdown(false);
                return false;
        }
        $this->OutputFile($Headers);
        return false;
    }

    /**
     * send static file content to client.
     */
    public function OutputFile($Headers) {
        $Request = $Headers['_Request_'];
        if ($Request['File'] == '/')
            $File = 'index.html';
        else
            $File=preg_replace('/\.{2,}/', '', substr($Request['File'], 1));
        $File = $this->Config['BaseDir'] . "/$File";
        $this->OutputFileContent($File);            

    }

    protected function OutputFileContent($File){
//        echo $this->getSocketName().":Output\t\t".microtime(true)."\n";
        if(($FileContent=OutputFile::get($File))===false){
            return $this->Send404Error();
        }
        $Mime = $FileContent['Mime'];
        $Date = $FileContent['Date'];
        $Size = $FileContent['Size'];
        $Data = "HTTP/1.1 200\r\n"
                . "Content-Type: $Mime\r\n"
                . "Connection: close\r\n"
                . "Date: " . gmdate('D, j M Y H:i:s', $Date) . ' GMT' . "\r\n"
                . "Cache-control: max-age=86400\r\n"
                . "Content-Length: $Size\r\n\r\n";
        event_buffer_write($this->BufferEvent, $Data.$FileContent['Data']);
        $this->DataFin = true;
    }
    /**
     * a buffer event callback while write buffer is empty.
     */
    public function evcb_WriteFin() {
//        echo "Debug".$this->ServerIndex."   -> WriteFin\n";    
        if ($this->Fin) {
            $this->Shutdown();
            return;
        }
        if ($this->DataFin) {
            $this->Shutdown(false);
            return;
        }
    }

    /**
     * Send 404 error to client.
     * @param <type> $BufferEvent
     * @param <type> $SocketName
     * @return boolean
     */
    private function Send404Error() {
        $Message = "File not found!";
        $Data = "HTTP/1.0 404 Not Found\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n"
                . "Connection: close\r\n"
                . "Content-Length: " . strlen($Message) . "\r\n\r\n$Message";
        event_buffer_write($this->BufferEvent, $Data);
        $this->DataFin = true;
        return true;
    }

    /**
     * event buffer error callback. maybe timeout...
     * @param resorce $BufferEvent
     * @param int $Events
     * @param resorce $ClientSocket
     */
    public function evcb_ClientBufferError($BufferEvent, $Events, $Socket) {
        //echo "Debug".$this->ServerIndex."   -> Buffer Error $SocketName\n";        
        if ($this->DataFin) {
            $this->Shutdown(false);
            return;    
        }
        $this->DataFin=true;
        if($this->IsInit())
            $this->Write();
    }
}
