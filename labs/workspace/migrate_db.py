import sys
import os
sys.path.append('/var/www/labs/worker')
from pymongo import MongoClient
from bson.objectid import ObjectId

mongo_user = os.environ.get('MONGO_USER', '')
mongo_pass = os.environ.get('MONGO_PASS', '')
mongo_host = os.environ.get('MONGO_HOST', '127.0.0.1')
mongo_port = os.environ.get('MONGO_PORT', '27017')
DB_URI = f"mongodb://{mongo_user}:{mongo_pass}@{mongo_host}:{mongo_port}/?authSource=admin" if mongo_user and mongo_pass else None
if not DB_URI:
    raise SystemExit("MONGO_USER/MONGO_PASS env vars not set")
client = MongoClient(DB_URI)
db = client['tom_labs_db']

cursor = db.ai_chat_history.find({"lesson_id": {"$exists": False}, "chapter_id": {"$exists": True}})
migrated = 0
for doc in cursor:
    chapter_id = doc.get('chapter_id')
    if not chapter_id:
        continue
    try:
        chapter = db.ai_chapters.find_one({"_id": ObjectId(chapter_id)})
        if chapter and 'lesson_id' in chapter:
            lesson_id = str(chapter['lesson_id'])
            db.ai_chat_history.update_one(
                {"_id": doc["_id"]},
                {"$set": {"lesson_id": lesson_id}}
            )
            migrated += 1
            print(f"Migrated chat {doc['_id']} to lesson_id {lesson_id}")
    except Exception as e:
        print(f"Error processing chapter {chapter_id}: {e}")

print(f"Total migrated: {migrated}")
