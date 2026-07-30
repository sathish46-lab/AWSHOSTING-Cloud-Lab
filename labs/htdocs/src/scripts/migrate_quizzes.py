import json
import pymongo
import os

# Connect to MongoDB via environment variables
mongo_user = os.environ.get('MONGO_USER', '')
mongo_pass = os.environ.get('MONGO_PASS', '')
mongo_host = os.environ.get('MONGO_HOST', '127.0.0.1')
mongo_port = os.environ.get('MONGO_PORT', '27018')

if not mongo_user or not mongo_pass:
    raise SystemExit("MONGO_USER/MONGO_PASS env vars not set")

mongo_uri = f"mongodb://{mongo_user}:{mongo_pass}@{mongo_host}:{mongo_port}/?authSource=admin"

try:
    client = pymongo.MongoClient(mongo_uri, serverSelectionTimeoutMS=5000)
    client.server_info() # Force connection check
    db = client.tom_labs_db
    print(f"Connected to MongoDB via {mongo_host}:{mongo_port}")
except Exception as e:
    print(f"Failed to connect: {e}")
    raise
    except Exception as e:
        print(f"❌ Error: Could not connect to MongoDB: {e}")
        exit(1)

# Load quiz_topics.json
json_path = "/Users/sathish/Development/local_dev_lab/labs/htdocs/src/data/quiz_topics.json"
with open(json_path, 'r') as f:
    data = json.load(f)

# Import Categories and Subtopics
db.quiz_categories.delete_many({})
db.quiz_subtopics.delete_many({})

for section, categories in data.items():
    print(f"Migrating section: {section}...")
    for cat in categories:
        cat_id = cat['id']
        subtopics = cat.get('subtopics', [])
        
        # Store Category
        db.quiz_categories.insert_one({
            "_id": cat_id,
            "section": section,
            "title": cat['title'],
            "desc": cat['desc'],
            "slug": cat['title'].lower().replace(' ', '-')
        })
        
        # Store Subtopics
        for sub in subtopics:
            db.quiz_subtopics.insert_one({
                "_id": sub['id'],
                "category_id": cat_id,
                "title": sub['title'],
                "desc": sub['desc'],
                "slug": sub['title'].lower().replace(' ', '-')
            })

print("✅ Quiz topics and subtopics migrated to MongoDB successfully.")
