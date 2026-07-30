import os
import pika
import json
import time
import uuid

# Configuration
AMQP_HOST = os.environ.get('RABBITMQ_HOST', '127.0.0.1')
AMQP_PORT = int(os.environ.get('RABBITMQ_PORT', '5672'))
AMQP_USER = os.environ.get('RABBITMQ_USER', 'admin')
AMQP_PASS = os.environ.get('RABBITMQ_PASS', '')
QUEUE_NAME = 'labs_jobs'

def stress_test():
    credentials = pika.PlainCredentials(AMQP_USER, AMQP_PASS)
    parameters = pika.ConnectionParameters(host=AMQP_HOST, port=AMQP_PORT, credentials=credentials)
    connection = pika.BlockingConnection(parameters)
    channel = connection.channel()
    
    channel.queue_declare(queue=QUEUE_NAME, durable=True)
    
    print("Starting Stress Test: Pushing 300 mock jobs...")
    
    for i in range(300):
        mock_job = {
            "action": "deploy",
            "lab": "essentials",
            "hash": f"stress_test_{uuid.uuid4().hex[:8]}",
            "user": f"user_{i}",
            # Use a dummy command that sleeps for 1s to simulate work but not kill the server
            "test_mode": True 
        }
        
        channel.basic_publish(
            exchange='',
            routing_key=QUEUE_NAME,
            body=json.dumps(mock_job),
            properties=pika.BasicProperties(
                delivery_mode=2,  # make message persistent
            ))
            
    print(" [x] Sent 300 jobs to queue.")
    connection.close()

if __name__ == "__main__":
    stress_test()
