<?php
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use PhpAmqpLib\Message\AMQPMessage;

class RabbitClient {
    private $connection;
    private $channel;
    private $exchange;

    public function __construct($exchange = 'amq.topic') {
        $this->exchange = $exchange;
        
        $host = get_config('amqp_host') ?? '127.0.0.1';
        $port = get_config('amqp_port') ?? 5672;
        $user = get_config('amqp_user') ?? (getenv('RABBITMQ_USER') ?: 'admin');
        $pass = get_config('amqp_pass') ?? getenv('RABBITMQ_PASS');
        if (empty($pass)) {
            throw new \RuntimeException("RABBITMQ_PASS not configured");
        }

        // T2: Add connection timeout to prevent hanging on unreachable RabbitMQ
        $this->connection = new AMQPStreamConnection(
            $host, $port, $user, $pass, '/',
            false,  // insist
            AMQPChannel::METHOD_PROTOCOL_091,  // channel_rpc_timeout
            'rabbitmq-con-',  // consumer_tag
            false,  // auto_decode
            null,   // stream_context
            5.0     // read_write_timeout (seconds)
        );
        $this->channel = $this->connection->channel();

        // Only declare if targeting a custom exchange
        if (!in_array($exchange, ['amq.topic', 'amq.fanout', 'amq.direct', ''])) {
            $this->channel->exchange_declare($this->exchange, AMQPExchangeType::FANOUT, false, false, true);
        }
    }

    public function sendMessage($message, $routingKey = '') {
        try {
            if (is_array($message) || is_object($message)) {
                $message = json_encode($message);
            }
            $msg = new AMQPMessage($message, ['delivery_mode' => AMQPMessage::DELIVERY_MODE_NON_PERSISTENT]);
            $this->channel->basic_publish($msg, $this->exchange, $routingKey);
        } catch (\Exception $e) {
            error_log("RabbitMQ Send Failed: " . $e->getMessage());
        }
    }

    /**
     * Publish a message directly to a specific queue
     */
    public function sendToQueue($queueName, $message) {
        try {
            if (is_array($message) || is_object($message)) {
                $message = json_encode($message);
            }
            // Ensure queue exists
            $this->channel->queue_declare($queueName, false, true, false, false);
            
            $msg = new AMQPMessage($message, ['delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT]);
            $this->channel->basic_publish($msg, '', $queueName);
        } catch (\Exception $e) {
            error_log("RabbitMQ Queue Push Failed: " . $e->getMessage());
            throw $e; // Rethrow so API knows it failed
        }
    }
    public function __destruct() {
        $this->channel->close();
        $this->connection->close();
    }
}