import os
import pika

AMQP_HOST = os.environ.get('RABBITMQ_HOST', '127.0.0.1')
AMQP_PORT = int(os.environ.get('RABBITMQ_PORT', '5672'))
AMQP_USER = os.environ.get('RABBITMQ_USER', 'admin')
AMQP_PASS = os.environ.get('RABBITMQ_PASS', '')

credentials = pika.PlainCredentials(AMQP_USER, AMQP_PASS)
parameters = pika.ConnectionParameters(host=AMQP_HOST, port=AMQP_PORT, credentials=credentials)
connection = pika.BlockingConnection(parameters)
channel = connection.channel()

# Unfortunately pika doesn't have "list_exchanges".
# But we know the user's hash is e1fc0310649f0d765f95d79272cccfa5 from previous context
# And "stress_test_..." exchanges.
# It's safer to just iterate and delete the KNOWN one that causes issues.
target_exchange = "logs_e1fc0310649f0d765f95d79272cccfa5"

try:
    channel.exchange_delete(exchange=target_exchange)
    print(f"Deleted {target_exchange}")
except:
    print(f"{target_exchange} not found or in use")

# Delete stress test exchanges if possible (optional, but good for cleanup)
# Since we don't know all UUIDs, we skip them. They are durable=True so they persist :(
# But for the USER's test case, deleting the main one is enough.

connection.close()
