<?php
require_once('../php/EventHttp.class.php');
$Data=$_POST['data'];
$Message=new EventHttp();
$Message->Push($Data);