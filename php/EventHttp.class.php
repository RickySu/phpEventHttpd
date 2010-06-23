<?php
class EventHttp {
    private $Config;
    public function __construct($Config=array()) {
        $DefaultConfig=array(
                'CommandHost'=> '127.0.0.1',
                'CommandPort'=> 8889,
        );
        $this->Config=array_merge($DefaultConfig,$Config);
    }
    public function Push($Data) {
        echo 'tcp://'.$this->Config['CommandHost'].':'.$this->Config['CommandPort'];
        $Socket=stream_socket_client('tcp://'.$this->Config['CommandHost'].':'.$this->Config['CommandPort']);
        fwrite($Socket,serialize(array('Command'=>'Push','Event'=>$Data)));
        fclose($Socket);
    }
}