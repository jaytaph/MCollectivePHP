<?php

// Crude Ruby Marshal decoder
include "marshal.php";

// Simple configuration
$config['collectives'] = array("mcollective");  // Add more subcollectives if available
$config['psk'] = "unset";
$config['host'] = "192.168.1.125";
$config['port'] = 6163;
$config['user'] = "mcollective";
$config['pass'] = "marionette";


// Connect to stomp server (activeMQ)
try {
    $stomp = new Stomp("tcp://".$config['host'].":".$config['port'], $config['user'], $config['pass']);
    print "STOMP SESSION ID: ".$stomp->getSessionId()."\n";
} catch (StompException $e) {
    die ("Connection failed: ". $e->getMessage()."\n");
}

// Register to all collective queues
foreach ($config['collectives'] as $collective) {
    $stomp->subscribe("/topic/${collective}.discovery.command");
}

while (true) {
    $frame = $stomp->readFrame();
    if (! $frame) continue; // Timeout

    $a = array();
    try {
        $m = new Marshal();
        $a = $m->load($frame->body);    // Decode frame body

        // Check if hash is ok
        if ($a['hash'] != md5($a['body'] . $config['psk'])) {
            print "Incorrect hash on received message. Ignoring";
            continue;
        }

        // Decode inner body
        $a['body'] = $m->load($a['body']);

    } catch(MarshalException $e) {
        print "Exception: ".$e->getMessage()."\n";
    }
    $frame->body = $a;

    if ($frame->body['body'] == "ping") {
        do_ping_reply($stomp, $frame);
    }
}
unset($stomp);
exit;


// Return a ping reply (pong)
function do_ping_reply(Stomp $stomp, StompFrame $frame) {
    $m = new Marshal();

    // Set message
    $msg = array();
    $msg['msgtime'] = round(microtime(true) * 1000);
    $msg['requestid'] = $frame->body['requestid'];      // Set requestid to match the incoming request id
    $msg['body'] = $m->dump("pong");                    // Body must be marshalled
    $msg['senderid'] = "hostname.".rand().".com";       // Just our (fake) hostname
    $msg['senderagent'] = "discovery";
    $msg['msgtarget'] = "/topic/mcollective.discovery.reply";

    // Calculate hash from marhsalled body + psk
    global $config;
    $msg['hash'] = md5($msg['body'] . $config['psk']);
    $body = $m->dump($msg);


    // Set headers
    $headers = array();
    $headers['content-type'] = "text/plain; charset=UTF-8";
    $headers['message-id'] = $stomp->getSessionId();
    $headers['destination'] = "/topic/mcollective.discovery.reply";     // This might already get set by stomp->send
    $headers['timestamp'] = round(microtime(true) * 1000);
    $headers['expires'] = 0;
    $headers['content-length'] = strlen($body);
    $headers['priority'] = 0;

    // Send stomp message
    $stomp->send("/topic/mcollective.discovery.reply", $body, $headers);
}
