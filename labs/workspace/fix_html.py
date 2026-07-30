import os
from pymongo import MongoClient
import markdown

mongo_user = os.environ.get('MONGO_USER', '')
mongo_pass = os.environ.get('MONGO_PASS', '')
mongo_host = os.environ.get('MONGO_HOST', 'TomCloudLab_mongodb')
mongo_port = os.environ.get('MONGO_PORT', '27017')
MONGO_URI = f"mongodb://{mongo_user}:{mongo_pass}@{mongo_host}:{mongo_port}/tom_labs_db?authSource=admin" if mongo_user and mongo_pass else None
if not MONGO_URI:
    raise SystemExit("MONGO_USER/MONGO_PASS env vars not set")
client = MongoClient(MONGO_URI)
db = client['tom_labs_db']

chapters = db.ai_chapters.find()
for chapter in chapters:
    if 'content' in chapter and chapter['content']:
        rendered_html = markdown.markdown(
            chapter['content'],
            extensions=['fenced_code', 'codehilite', 'tables', 'nl2br', 'sane_lists']
        )
        db.ai_chapters.update_one(
            {'_id': chapter['_id']},
            {'$set': {'content_html': rendered_html}}
        )
        print(f"Fixed chapter: {chapter.get('title', 'Unknown')}")
print("Done!")
