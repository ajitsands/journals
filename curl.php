<?php

echo "Server IP: " . $_SERVER['SERVER_ADDR'] . "<br>";

$ip = gethostbyname("api.starstarcommunications.com");

echo "Resolved IP: " . $ip . "<br>";

$fp = fsockopen($ip, 80, $errno, $errstr, 10);

if (!$fp) {
    echo "Connection Failed: $errno - $errstr";
} else {
    echo "Connection Success";
    fclose($fp);


}