import pika
import json
import psutil
import subprocess
import time
import os
import sys
import requests

class Stream:
    def __init__(self, args, key):
        self.args = args
        self.key = key
        self.rabbitmq_host = os.environ.get('RABBITMQ_HOST', 'localhost')
        self.user = os.environ.get('RABBITMQ_USER', 'admin')
        self.password = os.environ.get('RABBITMQ_PASS', '')
        
        # Management API URL
        self.api_url = f"http://{self.rabbitmq_host}:15672/api/exchanges/%2f/labs_{self.key}/bindings/source"
        
        try:
            credentials = pika.PlainCredentials(self.user, self.password)
            self.connection = pika.BlockingConnection(
                pika.ConnectionParameters(host=self.rabbitmq_host, credentials=credentials)
            )
            self.channel = self.connection.channel()
            
            self.exchange = f"labs_{self.key}"
            
            # This will now work once you delete the old exchange manually
            self.channel.exchange_declare(
                exchange=self.exchange, 
                exchange_type='fanout', 
                durable=True,      # Match PHP
                auto_delete=False  # Match PHP
            )
        except Exception as e:
            print(f"⚠️ RabbitMQ Init Error: {str(e)}")
            sys.exit(1)

    def get_online_count(self):
        """Queries the RabbitMQ API for the number of browser connections"""
        try:
            response = requests.get(self.api_url, auth=(self.user, self.password), timeout=1)
            if response.status_code == 200:
                return len(response.json())
            return 0
        except:
            return 0

    def get_overview_stats(self):
        """Collects Per-Core CPU, System RAM, Swap, and Load Average"""
        return {
            # percpu=True sends an array like [10.2, 5.5, 12.1, ...]
            "cpu": psutil.cpu_percent(interval=None, percpu=True), 
            "cores": psutil.cpu_count(),
            "mem": psutil.virtual_memory()._asdict(),
            "swap": psutil.swap_memory()._asdict(),
            "loadavg": os.getloadavg(),
            "online_users": self.get_online_count(),
            "timestamp": time.time()
        }

    def get_container_stats(self):
        """Parses docker stats into a list of JSON objects"""
        # Runs docker stats once to get current snapshop
        cmd = ["docker", "stats", "--no-stream", "--format", "{{json .}}"]
        result = subprocess.run(cmd, capture_output=True, text=True)
        stats = []
        for line in result.stdout.splitlines():
            if line.strip():
                stats.append(json.loads(line))
        return stats

    def stream(self):
        print(f"[*] Starting {self.key} stream to RabbitMQ fanout...")
        try:
            while True:
                data = {}
                if self.key == "overview":
                    data = self.get_overview_stats()
                    # Optional: Print timestamp for overview
                    # print(f"[*] Overview update published at {time.ctime()}")
                    
                elif self.key == "container_stats":
                    data = self.get_container_stats()
                    # PROFESSIONAL FIX: Print the total count of active containers/users
                    print(f"[*] Total: {len(data)}")
                
                # Publish to Fanout Exchange
                self.channel.basic_publish(
                    exchange=self.exchange,
                    routing_key='', # Ignored in fanout
                    body=json.dumps(data)
                )
                
                time.sleep(0.9) # Stream interval
        except KeyboardInterrupt:
            print("\n[*] Stream stopped by user.")
            self.connection.close()
        except Exception as e:
            print(f"⚠️ Stream Error: {str(e)}")

    def __del__(self):
        if hasattr(self, 'connection') and self.connection.is_open:
            self.connection.close()