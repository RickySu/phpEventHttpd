<?php
require_once('../php/EventHttpServer.class.php');
class Server extends EventHttpServer{
    protected function ServerPush($Data){
        echo "Push\n";
        $ClientData=$this->getClientDataArray();
        foreach($ClientData as $SocketName => $Client)
            $this->ClientWrite($SocketName, $Data);
    }
}
$Server=new Server();
$Server->Run();