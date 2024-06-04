#!/usr/bin/php
<?php
$tmp_dir = "/tmp/";      //folder for live values -> needs / at the end!!!
$debug = 0;

require("phpMQTT.php");
$host = "192.168.x.x";     // MQTT server
$port = 1883;
$username = "rio";
$password = "password";

$mqtt = new phpMQTT($host, $port, "ClientID".rand());

$sh_jkbms = shmop_open(0x501, "c", 0777, 32);      //shmop for preparing BMS cmd
if (!$sh_jkbms) {echo "Couldn't create shared memory segment\n";}

$blecommand = "/root/venv/mppsolar/bin/jkbms -p C8:47:8C:zz:yy:xx -P JK02";    //pull values from JK bms
$bmsout = shell_exec($blecommand);

$topic = "BMS_A/status";    // mqtt topic
$message = "online";
sendmqtt($topic, $message);

$zelle=array();
$total_volt=0;
$alle_zellen="";
//cell voltage
for( $i = 1; $i <= 16; $i++)
        {
        if($i < 10) $i = "0".$i;
        $suche = "voltage_cell$i";
        $zellev[$i] =  substr($bmsout,strpos($bmsout,$suche)+21,17);
        $alle_zellen = $alle_zellen." ".$i.":".number_format(substr($zellev[$i],0,5),3,".","");
        if($debug) echo "Zelle $i : $zellev[$i] V\n";
        $topic = "Zelle_".$i;
        $message = number_format(substr($zellev[$i],0,5),3,".","");
        sendmqtt("BMS_A/Data/".$topic, $message);
        }
//cell resistance
for( $i = 1; $i <= 16; $i++)
        {
        if($i < 10) $i = "0".$i;
        $suche = "resistance_cell$i";
        $zeller[$i] =  substr($bmsout,strpos($bmsout,$suche)+21,15);
        if($debug) echo "Zelle $i : $zeller[$i] mOhm\n";
        }
//average voltage
        $suche = "average_cell_voltage";
        $avg_volt = substr($bmsout,strpos($bmsout,$suche)+21,15);
        if($debug) echo "AVG_VOLT: $avg_volt V\n";
//delta_cell_voltage
        $suche = "delta_cell_voltage";
        $delta_volt = substr($bmsout,strpos($bmsout,$suche)+21,6);
        if($debug) echo "DELTA_VOLT: $delta_volt V\n";
        $topic = "Delta_Cell_Voltage";
        $message = $delta_volt;
        sendmqtt("BMS_A/Data/".$topic, $message);
//power
        $suche = "battery_power";
        $batt_power = substr($bmsout,strpos($bmsout,$suche)+21,15);
        if($debug) echo "BATT_POWER: $batt_power\n";
        $topic = "Battery_Power";
        $message = $batt_power;
        sendmqtt("BMS_A/Data/".$topic, $message);
//TotalVoltage
        $total_volt = array_sum($zellev);
        if($debug) echo "TOTAL_VOLT: $total_volt V\n";
        $topic = "Battery_Voltage";
        $message = $total_volt;
        sendmqtt("BMS_A/Data/".$topic, $message);
if($avg_volt < 2.0 || $avg_volt > 4 )
        {
        if($debug) echo "Illegal values received!\n";
        exit;
        }
//charge
        $suche = "current_charge";
        $charge = substr($bmsout,strpos($bmsout,$suche)+21,15);
        if($debug) echo "CHG_CURR: $charge A\n";
//discharge
        $suche = "current_discharge";
        $discharge = substr($bmsout,strpos($bmsout,$suche)+21,15);
        if($debug) echo "CHG_DISCURR: $discharge A\n";
		$charge_current = $charge - $discharge;
        $topic = "Charge_Current";
        $message = $charge_current;
        sendmqtt("BMS_A/Data/".$topic, $message);
// percent
        $suche = "percent_remain";
        $percent = substr($bmsout,strpos($bmsout,$suche)+21,15);
        $topic = "Percent_Remain";
        $message = $percent;
        sendmqtt("BMS_A/Data/".$topic, $message);
// MOS Temp
        $suche = "mos_temp";
        $mos_temp = substr($bmsout,strpos($bmsout,$suche)+21,4);
        $topic = "MOS_Temp";
        $message = $mos_temp;
        sendmqtt("BMS_A/Data/".$topic, $message);
// T1 Temp
        $suche = "battery_t1";
        $temp1 = substr($bmsout,strpos($bmsout,$suche)+21,4);
        $topic = "Battery_T1";
        $message = $temp1;
        sendmqtt("BMS_A/Data/".$topic, $message);
// T2 Temp
        $suche = "battery_t2";
        $temp2 = substr($bmsout,strpos($bmsout,$suche)+21,4);
        $topic = "Battery_T2";
        $message = $temp2;
        sendmqtt("BMS_A/Data/".$topic, $message);
// Cycle_Count
        $suche = "cycle_count";
        $cycles =  substr($bmsout,strpos($bmsout,$suche)+21,15);
        $topic = "Cycle_Count";
        $message = $cycles;
        sendmqtt("BMS_A/Data/".$topic, $message);
// Balance_Current
        $suche = "balance_current";
        $balcur =  substr($bmsout,strpos($bmsout,$suche)+21,15);
        $topic = "Balance_Current";
        $message = $balcur;
        sendmqtt("BMS_A/Data/".$topic, $message);

// Send alarm if cell delte voltage is too big
$delta_volt = abs($delta_volt);
if($delta_volt > "0.2"){
        $message = "BATTERIEDELTA TOO BIG!!!";
        $picfilename = "/home/rio/BatterieDelta.jpg";
        shell_exec("/usr/local/bin/signal-cli -u +43664xxyyzz send -a $picfilename -m $message +43664xxyyff");
}
// log cell delta for meterN
write2file($tmp_dir."jkDELTA.txt",$delta_volt);
// log all data into file in ramdisk
$jkBATTV = number_format($total_volt, 4, '.', '');
write2file($tmp_dir."jkBATTV.txt",$jkBATTV);
// log live values into shared memory
$shwerte = $total_volt.",".substr($avg_volt,0,5).",".substr($delta_volt,0,5).",".substr($charge,0,5).",".substr($discharge,0,5);
shmop_write($sh_jkbms, $shwerte,0);
shmop_close($sh_jkbms);

$power_act = number_format(((floatval($charge)-floatval($discharge))*floatval($total_volt)),2,".","");
if($debug) echo "ACT_POWER: $power_act\n\n";

// log all values in temp file
$bms_dat = date("Ymd-H:i.s")." POW: ".$power_act." AVG: ".number_format(substr($avg_volt,0,5),3,".","")." DEL: ".number_format($delta_volt,3,".","")." TOT: ".number_format($total_volt,3,".","").$alle_zellen;
if($debug) echo "DEBUG: BMS Data $bms_dat \n";
write2file_log($tmp_dir."jkBMS.txt",$bms_dat);

//needed functions
function write2file($filename, $value)
{
        $fp2 = fopen($filename,"w");
        if(!$fp2 || !fwrite($fp2, (float) $value))
        {
                if(!$is_error_write)
                {
                        logging("Fehler beim Schreiben in die Datei $filename Start (weitere Meldungen werden unterdrueckt)!", true);
                }
                $is_error_write = true;
        }
        fclose($fp2);
}
function write2file_log($filename, $value)
{
        $fp2 = fopen($filename,"a");
        if(!$fp2 || !fwrite($fp2, $value."\n"))
        {
                if(!$is_error_write)
                {
                        logging("Fehler beim Schreiben in die _log Datei $filename Start (weitere Meldungen werden unterdrueckt)!", true);
                }
                $is_error_write = true;
        }
        fclose($fp2);
}
function sendmqtt($topic, $message)
{
global $mqtt,$username,$password;
if ($mqtt->connect(true,NULL,$username,$password)) {
        $mqtt->publish($topic, $message, 0);
        //$mqtt->close();
        }else{
        if($debug) echo "Fail or time out<br />";
        }
}
?>
