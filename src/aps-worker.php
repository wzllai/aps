<?php
require_once dirname(__FILE__) . '/aps-functions.php';

/**
 */
class APSWorker {
    const VERSION = 'APS10';

    public function __construct($context, $endpoint) {
        $socket = new ZMQSocket($context, ZMQ::SOCKET_XREQ);
        $socket->setsockopt(ZMQ::SOCKOPT_LINGER, 0);
        $socket->connect($endpoint);
        $this->socket = $socket;

        $this->interval = 1000 * 1000;

        $this->interrupted = false;
    }

    public function run() {
        $this->send_hi_frames();

        $poll = new ZMQPoll();
        $poll->add($this->socket, ZMQ::POLL_IN);
    	while (!$this->interrupted) {
            $readable = $writeable = array();
    		$events = $poll->poll($readable, $writeable, $this->interval);
    		if ($events) {
            	$this->process();
            } else {
                $this->send_hi_frames();
            }
    	}
    }

    protected function send_hi_frames() {
        aps_send_frames($this->socket, array('', self::VERSION, chr(0x01)));
    }

    protected function process() {
    	$frames = aps_recv_frames($this->socket);
        list($envelope, $message) = aps_envelope_unwrap($frames);
        $version = array_shift($message);
        $command = array_shift($message);
        if ($command == 0x00) {
            $this->process_request($message);
        }
    }

    protected function process_request($message) {
        list($envelope, $message) = aps_envelope_unwrap($message);
        list($sequence, $timestamp, $expiry) = array_values(unpack('N*', array_shift($message)));

        $now = aps_millitime();
        if ($timestamp + $expiry > $now) {
            $this->send_reply_frames($envelope, $sequence, $now, 503, NULL);
            return;
        }

        $request = array_shift($message);
        if ($request === NULL) {
            $this->send_reply_frames($envelope, $sequence, $now, 400, NULL);
            return;
        }

        list($method, $params) = msgpack_unpack($request);

        $reply = call_user_func_array(array($this->delegate, $method), $params);

        $now = aps_millitime();
        $this->send_reply_frames($envelope, $sequence, $now, 200, $reply);
    }

    protected function send_reply_frames($envelope, $sequence, $timestamp, $status, $reply) {
        $frames = array_merge(array('', self::VERSION, chr(0x00)), $envelope);
        $frames[] = '';
        $frames[] = pack('N*', $sequence, $timestamp, $status);
        if ($reply !== NULL) {
            $frames[] = msgpack_pack($reply);
        }
        aps_send_frames($this->socket, $frames);
    }
}

