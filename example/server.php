<?php
require_once('../php/EventHttpServer.class.php');
class Server extends EventHttpServer{
    protected function ServerPush($Data){
        echo "Push\n";
        $ClientData=$this->getClientDataArray();
        foreach($ClientData as $Client)
            $this->ClientWrite($Client, $Data);
    }
}
$Server=new Server(array('ClientSocketTTL'=>10));
$Server->Run();