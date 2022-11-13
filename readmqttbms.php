<?php
$debug=0;
$sh_bms1 = shmop_open(0x4801, "c", 0777, 57); // create a shared memory object for the final string
if (!$sh_bms1) {
    echo "Couldn't open shared memory segment\n";
}

shmop_write($sh_bms1,"^D054BMS################################################", 0); // fill shmop initially

$client = new Mosquitto\Client();
$client->onConnect('connect');
$client->onDisconnect('disconnect');
$client->onSubscribe('subscribe');
$client->onMessage('message');
$client->connect("192.168.x.y", 1883, 60); // mqtt server+port
$client->subscribe('BMS_A/#', 1); // mqtt topic to subscribe

while (true) {
        $client->loop();
        $readfull = shmop_read($sh_bms1, 0, 56);
        echo $readfull."\n\n";
        usleep(200);
}

$client->disconnect();
unset($client);

function connect($r) {
}
function subscribe() {
}

function message($message) {
        global $debug,$sh_bms1;
        if(strpos($message->topic, "Battery_Voltage")) {
                $bmsvoltage = (int)round(($message->payload)*10);
                if($debug) echo "VOLT: $bmsvoltage \n";
                shmop_write($sh_bms1, paddings($bmsvoltage,4), 8);
                }
        if(strpos($message->topic, "Percent_Remain")){
                $bmsperc = $message->payload;
                if($debug) echo "PERC: $bmsperc \n";
                shmop_write($sh_bms1, ",".paddings($bmsperc,3), 12);
                }
        if(strpos($message->topic, "Charge_Current")){
                $bmscurr = $message->payload;
                if($debug) echo "STROM: $bmscurr \n";
                $bmscurr = substr(round($bmscurr),-4);
                $bmscurr = str_replace("-","0",$bmscurr);
                if($bmscurr < 0) $bmsdir = 1;
                if($bmscurr >=0) $bmsdir = 0;
                shmop_write($sh_bms1, ",".$bmsdir.",".paddings($bmscurr,4), 16);
                }
        //BMS warning code, tbd
        shmop_write($sh_bms1, ",00", 23); // static
        //BMS force charge, tbd
        shmop_write($sh_bms1, ",0", 26); //static
        //BMS cv voltage, tbd
        shmop_write($sh_bms1, ",0552", 28); //3,45Vx16 static
        //BMS float voltag, tbd
        shmop_write($sh_bms1, ",0552", 33); //3,45Vx16 static
        //BMS MaxChgCurr, tbd
        shmop_write($sh_bms1, ",1500", 38); //150A static

        //BMS BatStopDiscFlag
        if(strpos($message->topic, "Discharge")){
                $bmsdischargeflag = $message->payload;
                if($bmsdischargeflag == "on") $bmsdischarge=0;
                if($bmsdischargeflag == "off") $bmsdischarge=1;
                shmop_write($sh_bms1, ",".$bmsdischarge, 43);
        }
        //BMS BatStopChaFlag
        if(strpos($message->topic, "Charge") && ($message->payload == "on" || $message->payload == "off")) {
                $bmschargeflag = $message->payload;
                if($bmschargeflag == "on") $bmscharge = 0;
                if($bmschargeflag == "off") $bmscharge = 1;
                shmop_write($sh_bms1, ",".$bmscharge, 45);
        }
        //BMS CutOffVoltage, tbd
        shmop_write($sh_bms1, ",0504", 47); // 3,15Vx16 static
        //BMS MaxDisChgCurr, tbd
        shmop_write($sh_bms1, ",1500", 52); // 150A static
}

function disconnect() {
        echo "Disconnected cleanly\n";
}
function paddings($wert,$leng){
        $neg = "-";
        $pos = strpos($wert, $neg);
        if(!(strpos($wert, $neg)===false)){
                $ohne = substr($wert,1,(strlen($wert-1)));
                $auff =  str_pad($ohne,$leng,'0',STR_PAD_LEFT);
                $final = substr_replace($auff,$neg,0,1);
        }
else    {
                $final = (str_pad($wert,$leng,'0',STR_PAD_LEFT));
        }
        return($final);
}
?>
