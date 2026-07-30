import os
import pika
import subprocess
import json

# Configuration
AMQP_HOST = os.environ.get('RABBITMQ_HOST', '127.0.0.1')
AMQP_PORT = int(os.environ.get('RABBITMQ_PORT', '5672'))
AMQP_USER = os.environ.get('RABBITMQ_USER', 'admin')
AMQP_PASS = os.environ.get('RABBITMQ_PASS', '')

def cleanup():
    # We still use rabbitmqctl to LIST them because pika can't easily list exchanges
    try:
        output = subprocess.check_output(["rabbitmqctl", "list_exchanges", "--formatter", "json"], text=True)
        exchanges = json.loads(output)
    except Exception as e:
        print(f"Error listing exchanges: {e}")
        return

    # Connect via pika to delete
    try:
        credentials = pika.PlainCredentials(AMQP_USER, AMQP_PASS)
        parameters = pika.ConnectionParameters(host=AMQP_HOST, port=AMQP_PORT, credentials=credentials)
        connection = pika.BlockingConnection(parameters)
        channel = connection.channel()
    except Exception as e:
        print(f"Error connecting to RabbitMQ: {e}")
        return

    count = 0
    for ex in exchanges:
        name = ex.get('name')
        if name and name.startswith('logs_'):
            print(f"Deleting exchange: {name}")
            try:
                channel.exchange_delete(exchange=name)
                count += 1
            except Exception as e:
                print(f"Failed to delete {name}: {e}")

    connection.close()
    print(f"Successfully deleted {count} exchanges.")

if __name__ == "__main__":
    cleanup()
