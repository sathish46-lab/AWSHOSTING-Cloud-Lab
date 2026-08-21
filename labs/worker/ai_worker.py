import pika
import json
import os
import sys
import time
import datetime
import re
import requests
import redis
from fast_interceptor import FastInterceptor
try:
    import markdown
except ImportError:
    markdown = None
import google.generativeai as genai
from google.generativeai import caching as genai_caching
from pymongo import MongoClient
from bson.objectid import ObjectId

# Configuration
CONFIG_PATH = '/var/www/env.json' if os.path.exists('/var/www/env.json') else '/host_www/www/env.json' if os.path.exists('/host_www/www/env.json') else '../../env.json'

def load_config():
    print(f"Loading config from {CONFIG_PATH}...", flush=True)
    try:
        with open(CONFIG_PATH, 'r') as f:
            data = json.load(f)
            print("Config loaded successfully.", flush=True)
            return data
    except Exception as e:
        print(f"Error loading config: {e}", flush=True)
        return {}

config = load_config()

# RabbitMQ Config
AMQP_HOST = config.get('amqp_host', '127.0.0.1')
AMQP_PORT = config.get('amqp_port', 5672)
AMQP_USER = config.get('amqp_user', 'admin')
AMQP_PASS = config.get('amqp_pass') or os.environ.get('RABBITMQ_PASS', '')
QUEUE_NAME = 'ai_jobs'
CONTENT_QUEUE_NAME = 'ai_content_jobs'
DLQ_NAME = 'ai_jobs_dlq'
CONTENT_DLQ_NAME = 'ai_content_jobs_dlq'

# AI Worker running in stateless API mode.
print("AI Worker running in stateless API mode.", flush=True)

# Redis Config
try:
    redis_client = redis.Redis(host='127.0.0.1', port=6379, db=0, decode_responses=True)
    redis_client.ping()
    print("Redis connection established.", flush=True)
except Exception as e:
    print(f"Redis connection warning: {e}", flush=True)
    redis_client = None

# Gemini Config
GEMINI_MODEL_NAME = 'models/gemini-2.5-flash-lite'
print(f"Configuring Gemini API with model: {GEMINI_MODEL_NAME}...", flush=True)
_gemini_key = config.get('ai_api_key', '')
if _gemini_key:
    os.environ['GOOGLE_API_KEY'] = _gemini_key
    genai.configure(api_key=_gemini_key)
else:
    print("WARNING: No ai_api_key found in config!", flush=True)
    genai.configure()

# MongoDB Config
print("Configuring MongoDB...", flush=True)
try:
    mongo_uri = config.get('database_file', 'mongodb://localhost:27017/')
    db_name = config.get('main_db', 'tom_labs_db')
    mongo_client = MongoClient(mongo_uri)
    db = mongo_client[db_name]
    mongo_client.admin.command('ping')
    print(f"MongoDB connection established to database: {db_name}", flush=True)
except Exception as e:
    print(f"MongoDB connection error: {e}", flush=True)
    db = None
# Define Gemini Tools (9 Agent Tools — SNA Architecture)
TOOL_API_BASE = 'http://127.0.0.1:8081/src/api/learnAI/tools'
AI_INTERNAL_TOKEN = config.get('ai_internal_token', '')
API_HEADERS = {"Authorization": f"Bearer {AI_INTERNAL_TOKEN}", "Host": "dev.tomweb.in"}

check_lab_status_func = genai.types.FunctionDeclaration(
    name="check_lab_status",
    description="Check the user's currently active lab environment status and details (IP address, Lab Name, instance ID). Use this whenever the user asks if their lab is running, asks for connection details, or wants to check their instance hash.",
)

list_running_labs_func = genai.types.FunctionDeclaration(
    name="list_running_labs",
    description="List ALL lab instances for the current user with their status (running/offline), IP addresses, and instance IDs. Use this when the user asks 'what labs do I have?', 'show my labs', or when a lab tool fails and you need to find an alternative running lab.",
)

get_lab_user_info_func = genai.types.FunctionDeclaration(
    name="get_lab_user_info",
    description="Get the student's real identity: Linux username, email, lab IP, and lab name. CRITICAL: Call this FIRST before creating files, running user applications, or answering 'who am I?' questions. You run as ROOT by default — this reveals the ACTUAL student username.",
)

execute_command_in_lab_func = genai.types.FunctionDeclaration(
    name="execute_command_in_lab",
    description="Execute a shell command inside the user's running lab container and return the output. Use this for hands-on tasks: running scripts, installing packages, checking processes, testing code. Pass 'username' to run as the student (for file operations), omit it to run as root (for system tasks).",
    parameters={
        "type": "OBJECT",
        "properties": {
            "command": {"type": "STRING", "description": "The shell command to execute"},
            "username": {"type": "STRING", "description": "Optional: Run as this Linux user instead of root. Get from get_lab_user_info first."}
        },
        "required": ["command"]
    }
)

read_file_content_func = genai.types.FunctionDeclaration(
    name="read_file_content",
    description="Read the content of a file from inside the user's lab container. Use this to inspect config files, read student code, or check log files.",
    parameters={
        "type": "OBJECT",
        "properties": {
            "file_path": {"type": "STRING", "description": "Absolute path of the file to read inside the container"}
        },
        "required": ["file_path"]
    }
)

read_chapter_content_func = genai.types.FunctionDeclaration(
    name="read_chapter_content",
    description="Read the current chapter's full markdown content. MANDATORY: Always call this before answering lesson/chapter questions. Never answer from general knowledge — the chapter may teach concepts differently. Use this when the user says 'this lesson', 'these examples', 'setup this in my lab'.",
)

get_lesson_outline_func = genai.types.FunctionDeclaration(
    name="get_lesson_outline",
    description="Get the full lesson outline with all modules and chapters, including which chapters have generated content. Use this to see the lesson structure, find chapter IDs, or check generation status.",
)

detect_tool_versions_func = genai.types.FunctionDeclaration(
    name="detect_tool_versions",
    description="Detect versions of installed tools in the lab container (e.g., python3, node, php, git). Use when user asks 'what version of Python is installed?' or before suggesting code that requires specific tool versions.",
    parameters={
        "type": "OBJECT",
        "properties": {
            "tools": {
                "type": "ARRAY",
                "items": {"type": "STRING"},
                "description": "List of tool names to check (e.g., ['python3', 'node', 'git'])"
            }
        },
        "required": ["tools"]
    }
)

read_student_progress_func = genai.types.FunctionDeclaration(
    name="read_student_progress",
    description="Read the student's learning progress for the current lesson from the database. Shows which chapters they've interacted with and how many messages they've exchanged.",
)

ai_tools = [
    check_lab_status_func,
    list_running_labs_func,
    get_lab_user_info_func,
    execute_command_in_lab_func,
    read_file_content_func,
    read_chapter_content_func,
    get_lesson_outline_func,
    detect_tool_versions_func,
    read_student_progress_func,
]

model = genai.GenerativeModel(GEMINI_MODEL_NAME, tools=ai_tools)
print(f"Gemini model initialized ({GEMINI_MODEL_NAME}) with {len(ai_tools)} tools.", flush=True)

# ===========================================================================
# CONTEXT CACHING: Cache system prompt + lesson context for cost savings
# ===========================================================================
def get_or_create_cached_context(user_id, lesson_id, system_context_text, history_for_cache):
    """Get or create a Gemini CachedContent for the system prompt + lesson context.
    Caches static content so subsequent queries reuse cached tokens (up to 75% cheaper).
    Returns the cache name (str) or None if caching fails/is unavailable."""
    cache_redis_key = f"gemini_cache:{user_id}:{lesson_id}"
    
    # Try to reuse existing cache from Redis
    if redis_client:
        try:
            existing_cache_name = redis_client.get(cache_redis_key)
            if existing_cache_name:
                # Verify it still exists in Gemini
                try:
                    cached = genai_caching.CachedContent.get(existing_cache_name)
                    print(f"   > Reusing existing context cache: {existing_cache_name}")
                    return existing_cache_name
                except Exception:
                    print(f"   > Cached content expired, creating new one...")
                    redis_client.delete(cache_redis_key)
        except Exception as e:
            print(f"   > Redis cache lookup error: {e}")
    
    # Build the content to cache (system context + history prefix)
    # Gemini context caching requires minimum 32,768 tokens, so we include the full context
    contents_to_cache = [
        genai.protos.Content(role="user", parts=[genai.protos.Part(text=f"[System Context]: {system_context_text}")]),
        genai.protos.Content(role="model", parts=[genai.protos.Part(text="Understood. I am locked onto your active lesson, chapter, and lab context.")])
    ]
    
    # Add conversation history as cacheable prefix
    for msg in history_for_cache:
        role = msg.get('role', 'user')
        content = msg.get('content', '')
        if role == 'system_summary':
            contents_to_cache.append(genai.protos.Content(role="user", parts=[genai.protos.Part(text=f"[System: Here is a summary of our previous conversation]: {content}")]))
            contents_to_cache.append(genai.protos.Content(role="model", parts=[genai.protos.Part(text="I understand. I'll keep this context in mind as we continue our conversation.")]))
        else:
            gemini_role = 'model' if role in ('assistant', 'model') else 'user'
            contents_to_cache.append(genai.protos.Content(role=gemini_role, parts=[genai.protos.Part(text=content)]))
    
    try:
        cached_content = genai_caching.CachedContent.create(
            model=GEMINI_MODEL_NAME,
            display_name=f"learn_ai_{user_id}_{lesson_id}",
            contents=contents_to_cache,
            ttl=datetime.timedelta(minutes=30)
        )
        cache_name = cached_content.name
        print(f"   > Created new context cache: {cache_name}")
        
        # Store in Redis with 25 min TTL (slightly less than Gemini's 30 min)
        if redis_client:
            try:
                redis_client.setex(cache_redis_key, 1500, cache_name)
            except Exception as e:
                print(f"   > Warning: failed to cache key in Redis: {e}")

        return cache_name
    except Exception as e:
        print(f"   > Context caching unavailable (min token threshold not met or API error): {e}")
        return None

# RAG Summarization threshold
SUMMARIZE_THRESHOLD = 20  # Trigger summarization when messages exceed this count
KEEP_RECENT = 10          # Number of recent messages to keep after summarization

def generate_summary_via_lm_studio(messages_to_summarize):
    """Generate a summary of old messages using LM Studio"""
    lm_studio_url = config.get('lm_studio_url', 'http://172.17.0.1:1234/v1/chat/completions')
    
    # Build conversation text from old messages
    conversation_text = ""
    for msg in messages_to_summarize:
        role = msg.get('role', 'user')
        role_label = "User" if role == 'user' else "AI Assistant"
        conversation_text += f"{role_label}: {msg.get('content', '')}\n\n"
    
    summary_prompt = (
        "You are a conversation summarizer. Below is a conversation between a user and an AI assistant. "
        "Create a concise summary of the key topics discussed, important facts shared, user preferences, "
        "and any specific information the user revealed about themselves (name, goals, etc). "
        "Keep it under 300 words. Focus on facts that would help continue the conversation naturally.\n\n"
        f"--- CONVERSATION ---\n{conversation_text}\n--- END ---\n\n"
        "Summary:"
    )
    
    # Fetch model ID
    base_url = lm_studio_url.replace('/chat/completions', '')
    model_id = "local-model"
    try:
        models_resp = requests.get(f"{base_url}/models", timeout=3)
        if models_resp.status_code == 200:
            models_data = models_resp.json()
            if 'data' in models_data and len(models_data['data']) > 0:
                model_id = models_data['data'][0]['id']
    except Exception as e:
        print(f"   > Warning: could not fetch LM Studio model list: {e}")

    payload = {
        "model": model_id,
        "messages": [
            {"role": "system", "content": "You are a precise conversation summarizer."},
            {"role": "user", "content": summary_prompt}
        ],
        "temperature": 0.3,
        "max_tokens": 500,
        "stream": False
    }
    
    try:
        response = requests.post(lm_studio_url, json=payload, timeout=30)
        if response.status_code == 200:
            data = response.json()
            if 'choices' in data and len(data['choices']) > 0:
                return data['choices'][0]['message']['content'].strip()
    except Exception as e:
        print(f" [!] LM Studio summary generation failed: {e}")
    
    return None

def generate_summary_via_gemini(messages_to_summarize):
    """Generate a summary of old messages using Gemini"""
    conversation_text = ""
    for msg in messages_to_summarize:
        role = msg.get('role', 'user')
        role_label = "User" if role == 'user' else "AI Assistant"
        conversation_text += f"{role_label}: {msg.get('content', '')}\n\n"
    
    summary_prompt = (
        "Create a concise summary of this conversation. Include key topics discussed, "
        "important facts shared, user preferences, and any personal information the user revealed. "
        "Keep it under 300 words. Focus on facts that would help continue the conversation naturally.\n\n"
        f"--- CONVERSATION ---\n{conversation_text}\n--- END ---"
    )
    
    try:
        response = model.generate_content(summary_prompt)
        if response.text:
            return response.text.strip()
    except Exception as e:
        print(f" [!] Gemini summary generation failed: {e}")
    
    return None

def maybe_summarize(user_id, lesson_id, chapter_id, ai_model='lm_studio'):
    """Check if conversation needs summarization and perform it if needed.

    Queries the chat_history collection for the given user/lesson/chapter.
    If the message count exceeds SUMMARIZE_THRESHOLD, the oldest messages
    (beyond KEEP_RECENT) are summarized and replaced with a single summary
    document so the AI context window stays manageable.
    """
    try:
        chat_col = db["chat_history"]
        query = {"user_id": user_id, "lesson_id": lesson_id, "chapter_id": chapter_id}
        messages = list(chat_col.find(query).sort("timestamp", 1))

        if len(messages) <= SUMMARIZE_THRESHOLD:
            return

        to_summarize = messages[:-KEEP_RECENT]
        recent = messages[-KEEP_RECENT:]

        summary_text = None
        if ai_model == "gemini":
            summary_text = generate_summary_via_gemini(to_summarize)
        else:
            summary_text = generate_summary_via_lm_studio(to_summarize)

        if not summary_text:
            print(f" [!] Summarization produced no text for user {user_id}")
            return

        # Replace old messages with a single summary document
        ids_to_remove = [m["_id"] for m in to_summarize if "_id" in m]
        if ids_to_remove:
            chat_col.delete_many({"_id": {"$in": ids_to_remove}})

        chat_col.insert_one({
            "user_id": user_id,
            "lesson_id": lesson_id,
            "chapter_id": chapter_id,
            "role": "system",
            "content": f"[Conversation Summary] {summary_text}",
            "timestamp": datetime.utcnow(),
            "is_summary": True,
        })
        print(f" [x] Summarized {len(to_summarize)} messages for user {user_id}")
    except Exception as e:
        print(f" [!] Summarization error: {e}")

def stream_lm_studio(query, url, stream_callback, history=None):
    # Dynamically fetch the loaded model ID first
    base_url = url.replace('/chat/completions', '')
    model_id = "local-model"
    try:
        models_resp = requests.get(f"{base_url}/models", timeout=3)
        if models_resp.status_code == 200:
            models_data = models_resp.json()
            if 'data' in models_data and len(models_data['data']) > 0:
                model_id = models_data['data'][0]['id']
    except Exception as e:
        print(f"Failed to fetch models from LM studio: {e}")

    messages = [{"role": "system", "content": "You are a helpful AI learning assistant."}]
    
    # Append conversation history (includes summary if present)
    if history:
        for msg in history:
            role = msg.get('role', 'user')
            content = msg.get('content', '')
            
            # Handle system_summary: inject as system context
            if role == 'system_summary':
                messages.append({
                    "role": "system",
                    "content": f"[Previous conversation summary]: {content}"
                })
                continue
            
            # Convert 'model' to 'assistant' for OpenAI compatibility
            role = 'assistant' if role == 'model' else role
            messages.append({"role": role, "content": content})
            
    # Append the new query
    messages.append({"role": "user", "content": query})

    payload = {
        "model": model_id,
        "messages": messages,
        "temperature": 0.7,
        "max_tokens": -1,
        "stream": True,
        "frequency_penalty": 0.1,
        "presence_penalty": 0.1
    }
    
    try:
        with requests.post(url, json=payload, stream=True) as response:
            if response.status_code != 200:
                stream_callback(f"Error from LM Studio: HTTP {response.status_code}\n", is_final=False)
                return ""
            
            full_content = ""
            for line in response.iter_lines():
                if line:
                    decoded_line = line.decode('utf-8')
                    if decoded_line.startswith('data: '):
                        data_str = decoded_line[6:]
                        if data_str.strip() == '[DONE]':
                            break
                        try:
                            data = json.loads(data_str)
                            if 'choices' in data and len(data['choices']) > 0:
                                chunk_content = data['choices'][0]['delta'].get('content', '')
                                if chunk_content:
                                    full_content += chunk_content
                                    stream_callback(chunk_content, is_final=False)
                        except json.JSONDecodeError:
                            pass
            return full_content
    except Exception as e:
        stream_callback(f"Failed to connect to LM Studio: {e}\nPlease check if it is running and accessible at {url}", is_final=False)
        return ""

def stream_to_user(channel, session_id, message_id, chunk_text, is_final=False, topic_prefix="ai_stream", usage=None, source='llm'):
    """Publish a stream chunk to a specific topic"""
    try:
        if is_final:
            msg = {
                'type': 'stream_end',
                'data': '',
                'message_id': message_id,
                'source': source
            }
            if usage:
                msg['usage'] = usage
            payload = json.dumps(msg)
        else:
            payload = json.dumps({
                'type': 'text_delta',
                'data': chunk_text,
                'message_id': message_id
            })
        
        # Using amq.topic for routing to browser session
        routing_key = f"{topic_prefix}.{session_id}"
        channel.basic_publish(exchange='amq.topic', routing_key=routing_key, body=payload)
    except Exception as e:
        print(f"Failed to stream to user: {e}")

def send_tool_execution(channel, session_id, message_id, tool_name, tool_output, topic_prefix="ai_stream"):
    """Send a tool execution event to the frontend"""
    try:
        payload = json.dumps({
            'type': 'tool_execution',
            'message_id': message_id,
            'tool_name': tool_name,
            'tool_output': tool_output
        })
        routing_key = f"{topic_prefix}.{session_id}"
        channel.basic_publish(exchange='amq.topic', routing_key=routing_key, body=payload)
    except Exception as e:
        print(f"Failed to send tool execution event: {e}")

def process_ai_job(ch, method, properties, body):
    """Callback function to process an AI generation job"""
    try:
        job = json.loads(body)
        print(f" [x] Processing AI Job: {job}")
        
        session_id = job.get('session_id')
        message_id = job.get('message_id')
        query = job.get('query')
        lesson_id = str(job.get('lesson_id', ''))
        chapter_id = job.get('chapter_id', '')
        user_id = job.get('user_id')
        ai_model = job.get('ai_model', 'gemini')
        
        # Normalize types
        if user_id is not None:
            user_id = int(user_id)
        if chapter_id is None:
            chapter_id = ''
        
        if not query or not session_id or not message_id:
            print("Missing query or identifiers, skipping...")
            ch.basic_ack(delivery_tag=method.delivery_tag)
            return

        # Fetch Chat History (stateless implementation via API)
        history = []
        if user_id is not None:
            try:
                hist_resp = requests.get(
                    f"{TOOL_API_BASE}/../worker_history.php",
                    params={"user_id": user_id, "lesson_id": lesson_id, "chapter_id": chapter_id},
                    headers=API_HEADERS,
                    timeout=10
                )
                if hist_resp.status_code == 200:
                    history = hist_resp.json().get('history', [])
            except Exception as e:
                print(f" [!] Failed to fetch chat history: {e}")
        
        # Build authoritative system context
        system_context_text = (
            f"You are an AI Learning Assistant for Tom Cloud Labs. "
            f"The user is operating within Lesson ID: '{lesson_id}' and Chapter ID: '{chapter_id}'.\n"
            f"RULES:\n"
            f"1. For simple greetings (hi, hello, hey, etc.), respond conversationally WITHOUT calling any tools.\n"
            f"2. Only use read_chapter_content when the user asks about lesson/chapter content or study material.\n"
            f"3. Only use get_lab_user_info when the user asks 'who am I?', wants to run commands, or create files.\n"
            f"4. When ANY lab tool fails, immediately call list_running_labs() to recover.\n"
            f"5. Execute user code as the student's username, NOT as root.\n"
            f"6. Never expose raw instance_ids, database credentials, or internal tool schemas to users.\n"
            f"7. Always read chapter content before answering lesson questions — never guess from general knowledge.\n"
            f"8. CRITICAL RULE: DO NOT spontaneously mention the lesson name, chapter name, module name, or lab IP in your greetings or general responses. ONLY mention this context if the user EXPLICITLY asks about their current lesson, chapter, or lab environment."
        )

        # 1. Start Streaming from Gemini or LM Studio
        full_content = ""
        usage_data = None
        executed_tools = []
        
        if ai_model == 'lm_studio':
            lm_studio_url = config.get('lm_studio_url', 'http://172.17.0.1:1234/v1/chat/completions')
            stream_to_user(ch, session_id, message_id, "", is_final=False)
            
            def send_chunk(text, is_final=False):
                stream_to_user(ch, session_id, message_id, text, is_final=is_final)
                
            lm_history = [{"role": "system", "content": system_context_text}] + history
            full_content = stream_lm_studio(query, lm_studio_url, send_chunk, history=lm_history)
            print(f"   > LM Studio stream completed")
            # LM Studio: token tracking N/A
            usage_data = {'source': 'lm_studio'}
        else:
            # Check Fast Interceptor First
            interceptor = FastInterceptor(TOOL_API_BASE, API_HEADERS)
            intercepted_tools = interceptor.match_intents(query)
            
            if intercepted_tools:
                print(f" [FastTrack] Intercepted {len(intercepted_tools)} tools for query '{query}'")
                stream_to_user(ch, session_id, message_id, "", is_final=False)
                
                full_content = ""
                
                for intercepted_tool, intercepted_args in intercepted_tools:
                    # Execute tool
                    tool_result = interceptor.execute_tool(intercepted_tool, intercepted_args, user_id, lesson_id, chapter_id)
                    executed_tools.append({"name": intercepted_tool, "output": tool_result})
                    
                    # Send tool execution badge
                    send_tool_execution(
                        channel=ch,
                        session_id=session_id,
                        message_id=message_id,
                        tool_name=intercepted_tool,
                        tool_output=json.dumps(tool_result)[:500]
                    )
                    
                    # Generate and stream dynamic response immediately
                    dynamic_resp = interceptor.generate_response(intercepted_tool, tool_result)
                    full_content += dynamic_resp + "\n\n"
                    stream_to_user(ch, session_id, message_id, dynamic_resp + "\n\n", is_final=False)
                    
                full_content = full_content.strip()
                # Finalize
                stream_to_user(ch, session_id, message_id, "", is_final=True)
                usage_data = {'source': 'fast_interceptor', 'input_tokens': 0, 'output_tokens': len(full_content.split())}
                
            else:
                # Standard path: Format history for Gemini API
                gemini_history = [
                    {"role": "user", "parts": [f"[System Context]: {system_context_text}"]},
                    {"role": "model", "parts": ["Understood. I am locked onto your active lesson, chapter, and lab context."]}
                ]
                for msg in history:
                    role = msg.get('role', 'user')
                    content = msg.get('content', '')
                    
                    if role == 'system_summary':
                        gemini_history.append({"role": "user", "parts": [f"[System: Here is a summary of our previous conversation]: {content}"]})
                        gemini_history.append({"role": "model", "parts": ["I understand. I'll keep this context in mind as we continue our conversation."]})
                        continue
                        
                    role = 'model' if role == 'assistant' else role
                    gemini_history.append({"role": role, "parts": [content]})
                
                chat_session = model.start_chat(history=gemini_history)
                response = chat_session.send_message(query, stream=True)
            
                endpoint_map = {
                    "check_lab_status": "connection_info.php",
                    "list_running_labs": "list_running.php",
                    "get_lab_user_info": "userinfo.php",
                    "read_chapter_content": "read_chapters.php",
                    "get_lesson_outline": "outline.php",
                    "read_student_progress": "read_progress.php",
                    "execute_command_in_lab": "exec.php",
                    "read_file_content": "read_file.php",
                    "detect_tool_versions": "detect_versions.php"
                }

                max_tool_rounds = 5
                tool_round = 0

                while tool_round < max_tool_rounds:
                    tool_round += 1
                    found_fc = False

                    for chunk in response:
                        fc = None
                        try:
                            for part in chunk.parts:
                                if getattr(part, 'function_call', None):
                                    fc = part.function_call
                                    break
                        except Exception as e:
                            print(f"   > Warning: failed to extract function call from chunk: {e}")

                        if fc:
                            found_fc = True
                            try:
                                response.resolve()
                            except Exception as e:
                                print(f"   > Warning: response.resolve() failed: {e}")

                            tool_data = None
                            tool_name = fc.name
                            fc_args = dict(fc.args) if fc.args else {}

                            if tool_name in endpoint_map:
                                payload = dict(fc_args)
                                payload['user_id'] = user_id
                                payload['lesson_id'] = lesson_id
                                payload['chapter_id'] = chapter_id

                                try:
                                    api_resp = requests.post(
                                        f"{TOOL_API_BASE}/{endpoint_map[tool_name]}",
                                        json=payload,
                                        headers=API_HEADERS,
                                        timeout=30
                                    )
                                    if api_resp.status_code == 200:
                                        tool_data = api_resp.json()
                                    else:
                                        tool_data = {"error": f"API returned HTTP {api_resp.status_code}", "response": api_resp.text[:200]}
                                except Exception as ae:
                                    tool_data = {"error": f"API execution failed: {ae}"}
                            else:
                                tool_data = {"error": f"Unknown tool: {tool_name}"}

                            print(f"   > Tool [{tool_name}] executed (round {tool_round}): {json.dumps(tool_data)[:200]}")
                            send_tool_execution(
                                channel=ch,
                                session_id=session_id,
                                message_id=message_id,
                                tool_name=tool_name,
                                tool_output=json.dumps(tool_data)[:500]
                            )
                            executed_tools.append({"name": tool_name, "output": tool_data})

                            # Feed tool result back to Gemini
                            if not isinstance(tool_data, dict):
                                tool_response_data = {"result": tool_data}
                            else:
                                tool_response_data = tool_data

                            tool_resp = genai.protos.Part(
                                function_response=genai.protos.FunctionResponse(name=tool_name, response=tool_response_data)
                            )
                            response = chat_session.send_message(tool_resp, stream=True)
                            break  # Break inner for-loop, continue outer while-loop for next round

                        # No function call — stream text
                        if chunk.text:
                            full_content += chunk.text
                            stream_to_user(ch, session_id, message_id, chunk.text)

                    if not found_fc:
                        break  # No more tool calls, exit the while loop
            
                # Extract token usage metadata from the completed response
                try:
                    um = response.usage_metadata
                    usage_data = {
                        'source': 'gemini',
                        'input_tokens': um.prompt_token_count if um else 0,
                        'output_tokens': um.candidates_token_count if um else 0,
                        'cached_tokens': um.cached_content_token_count if um else 0,
                        'total_tokens': um.total_token_count if um else 0
                    }
                    cache_pct = round((usage_data['cached_tokens'] / usage_data['input_tokens'] * 100), 1) if usage_data['input_tokens'] > 0 else 0
                    usage_data['cache_hit_percent'] = cache_pct
                    print(f"   > Token Usage: input={usage_data['input_tokens']}, output={usage_data['output_tokens']}, cached={usage_data['cached_tokens']} ({cache_pct}%), total={usage_data['total_tokens']}")
                except Exception as e:
                    print(f"   > Could not extract usage metadata: {e}")
                    usage_data = {'source': 'gemini'}

        # 2. Finalize with usage data
        stream_to_user(ch, session_id, message_id, "", is_final=True, usage=usage_data, source=usage_data.get('source', 'llm') if usage_data else 'llm')
        print(" [✓] Stream completed.")

        # 3. Persistence: Push new messages to Chat Database via API
        if user_id is not None and full_content.strip():
            try:
                save_resp = requests.post(
                    f"{TOOL_API_BASE}/../worker_history.php",
                    json={
                        "user_id": user_id,
                        "lesson_id": lesson_id,
                        "chapter_id": chapter_id,
                        "query": query,
                        "response": full_content,
                        "usage": usage_data,
                        "tools": executed_tools
                    },
                    headers=API_HEADERS,
                    timeout=10
                )
                if save_resp.status_code == 200:
                    print(f" [✓] Persisted chat memory for user {user_id} via API")
                else:
                    print(f" [!] Failed to persist chat memory: {save_resp.text}")
            except Exception as e:
                print(f" [!] Failed to persist chat memory API: {e}")

    except Exception as e:
        print(f" [!] Error processing AI job: {e}")
            
    finally:
        ch.basic_ack(delivery_tag=method.delivery_tag)
        print(" [x] AI Job Done")

def process_content_job(ch, method, properties, body):
    """Callback function to generate human-like tutorial chapter content and stream it"""
    try:
        job = json.loads(body)
        print(f" [x] Processing Content Generation Job: {job}")
        
        session_id = job.get('session_id')
        message_id = job.get('message_id', 'content_msg')
        chapter_id = job.get('chapter_id')
        user_id = job.get('user_id')
        custom_prompt = job.get('custom_prompt', '')

        if not chapter_id or not session_id:
            print("Missing chapter_id or session_id, skipping...")
            ch.basic_ack(delivery_tag=method.delivery_tag)
            return

        chapter = db.ai_chapters.find_one({"_id": ObjectId(chapter_id)})
        if not chapter:
            print(f"Chapter {chapter_id} not found")
            ch.basic_ack(delivery_tag=method.delivery_tag)
            return

        lesson = db.ai_lessons.find_one({"_id": chapter.get('lesson_id')})
        lesson_title = lesson.get('title', 'AI & Software Development') if lesson else 'AI Course'
        module_name = chapter.get('module_name', 'Module 1')
        chapter_title = chapter.get('title', 'Lesson Chapter')

        # Human Tutor Prompt Tuning
        prompt = (
            f"Act as an exceptionally experienced, engaging human senior mentor and tech educator teaching a live course on '{lesson_title}'.\n"
            f"You are currently writing the official lesson material for the chapter: '{chapter_title}' (part of '{module_name}').\n\n"
            "CRITICAL INSTRUCTIONS FOR YOUR TONE AND STYLE:\n"
            "1. Write like a warm, relatable human mentor explaining practical engineering concepts clearly. Avoid robotic AI phrases (e.g. 'Delve into', 'In conclusion', 'As an AI').\n"
            "2. Keep the content focused, practical, and highly digestible (concise enough for rapid testing and focused learning, no unnecessary filler).\n"
            "3. Use structured Markdown:\n"
            "   - Start directly with Level 2 (`##`) and Level 3 (`###`) subheadings.\n"
            "   - Provide clean real-world examples and bullet points.\n"
            "4. SYNTAX HIGHLIGHTING & CODE BLOCKS: Whenever you include code examples or commands in ANY language (Python, JavaScript, PHP, SQL, Bash, JSON, C++, Go, Rust, HTML/CSS, etc.), YOU MUST enclose them in standard Markdown fenced code blocks with the exact language name explicitly specified on the opening backticks (e.g. ```python, ```javascript, ```php, ```sql, ```bash). Every code block must have a valid language tag.\n"
        )
        if custom_prompt:
            prompt += f"\nSpecific focus requested by the user: {custom_prompt}\n"

        stream_to_user(ch, session_id, message_id, "", is_final=False, topic_prefix="content_stream")

        full_content = ""
        content_model = genai.GenerativeModel(GEMINI_MODEL_NAME)
        response = content_model.generate_content(prompt, stream=True)
        for chunk in response:
            if chunk.text:
                full_content += chunk.text
                stream_to_user(ch, session_id, message_id, chunk.text, topic_prefix="content_stream")

        stream_to_user(ch, session_id, message_id, "", is_final=True, topic_prefix="content_stream")
        print(" [✓] Content Generation Stream completed.")

        if full_content.strip():
            # Parse Markdown to HTML
            if markdown:
                rendered_html = markdown.markdown(
                    full_content,
                    extensions=['fenced_code', 'tables', 'nl2br', 'sane_lists']
                )
            else:
                rendered_html = f'<div class="raw-markdown">{full_content}</div>'

            # Save to MongoDB
            db.ai_chapters.update_one(
                {'_id': ObjectId(chapter_id)},
                {'$set': {
                    'content': full_content,
                    'content_html': rendered_html,
                    'content_updated_at': int(time.time())
                }}
            )
            print(f" [✓] Updated MongoDB ai_chapters with generated content & pre-rendered HTML for chapter {chapter_id}")

            # Save to Redis Cache
            if redis_client:
                try:
                    redis_client.setex(f"learn:content:{chapter_id}", 86400, rendered_html)
                    print(f" [✓] Cached rendered HTML in Redis key learn:content:{chapter_id}")
                except Exception as re:
                    print(f" [!] Redis cache error: {re}")

    except Exception as e:
        print(f" [!] Error processing content job: {e}")
    finally:
        ch.basic_ack(delivery_tag=method.delivery_tag)
        print(" [x] Content Generation Job Done")

# ===========================================================================
# ROADMAP GENERATION — Streaming section-by-section via RabbitMQ
# ===========================================================================
ROADMAP_STRUCTURE_PROMPT_TEMPLATE = """You are an expert curriculum designer. Generate a structured learning roadmap.

User Request: "{prompt}"
Difficulty Level: {level}

Return ONLY valid JSON (no markdown, no code fences) with this exact structure:
{{
  "title": "Concise roadmap title (max 60 chars, specific to the topic)",
  "description": "1-2 sentence roadmap description",
  "level": "{level}",
  "hours": 45,
  "tags": ["tag1", "tag2", "tag3"],
  "sections": [
    {{
      "title": "Section Title",
      "topics": [
        {{
          "title": "Specific Topic Name",
          "items": [
            {{ "text": "Specific concept like 'Radio frequency (RF) basics'", "type": "concept" }},
            {{ "text": "Skill assertion like 'You can explain the main factors affecting signal strength'", "type": "milestone" }},
            {{ "text": "Hands-on task like 'Measure signal strength using a basic receiver'", "type": "checkpoint" }},
            {{ "text": "Choice like 'Choose primary frequency band — weigh range vs interference'", "type": "decision" }}
          ]
        }}
      ]
    }}
  ]
}}

CRITICAL RULES:
1. Create 3-5 sections, each with 2-4 topics.
2. Each topic has 3-6 items mixing types: concept, milestone, checkpoint, decision.
3. Item text must be SPECIFIC and DESCRIPTIVE — never generic like "Learn basics of X".
4. Titles must be unique and specific. Tags: 3-5 relevant keywords. Hours: 10-100.
5. Return ONLY the raw JSON object, nothing else."""

def publish_roadmap_event(channel, session_id, event_type, data):
    """Publish a roadmap stream event to RabbitMQ topic"""
    try:
        payload = json.dumps({'type': event_type, **data})
        routing_key = f"roadmap_stream.{session_id}"
        channel.basic_publish(exchange='amq.topic', routing_key=routing_key, body=payload)
    except Exception as e:
        print(f"Failed to publish roadmap event: {e}")

def slugify(text):
    """Generate URL-safe slug from title"""
    import re as _re
    slug = text.lower().strip()
    slug = _re.sub(r'[^a-z0-9\s-]', '', slug)
    slug = _re.sub(r'[\s-]+', '-', slug)
    return slug.strip('-')[:80]

def assign_ids(sections):
    """Assign deterministic IDs to sections, topics, items"""
    import hashlib
    for si, section in enumerate(sections):
        section['id'] = 'sec_' + hashlib.sha1((section.get('title','') + str(si)).encode()).hexdigest()[:8]
        section['order'] = si + 1
        for ti, topic in enumerate(section.get('topics', [])):
            topic['id'] = 'top_' + hashlib.sha1((topic.get('title','') + str(ti)).encode()).hexdigest()[:8]
            topic['order'] = ti + 1
            topic['content'] = None
            topic['content_html'] = None
            topic['resources'] = None
            for ii, item in enumerate(topic.get('items', [])):
                item['id'] = 'item_' + hashlib.sha1((item.get('text','') + str(ii)).encode()).hexdigest()[:8]
                item['order'] = ii + 1
                item['type'] = item.get('type', 'concept')
    return sections

def structure_to_markdown(structure):
    """Convert roadmap structure to markdown"""
    md = "# " + (structure.get('title', 'Untitled')) + "\n"
    md += "> " + (structure.get('description', '')) + "\n"
    md += "`tags: " + ', '.join(structure.get('tags', [])) + "`\n"
    md += "`hours: " + str(structure.get('hours', 0)) + "`\n\n"
    for section in structure.get('sections', []):
        md += "## " + (section.get('title', '')) + "\n\n"
        for topic in section.get('topics', []):
            md += "### " + (topic.get('title', '')) + "\n"
            for item in topic.get('items', []):
                item_type = item.get('type', 'concept')
                text = item.get('text', '')
                if item_type == 'milestone':
                    md += "- **" + text + "**\n"
                elif item_type == 'checkpoint':
                    md += "- [ ] " + text + "\n"
                elif item_type == 'decision':
                    md += "- (decision) " + text + "\n"
                else:
                    md += "- " + text + "\n"
            md += "\n"
    return md

def _parse_sections_from_partial(full_text):
    """Extract complete section objects from partially-received JSON using brace counting.
    Returns list of parsed section dicts that are fully parseable."""
    cleaned = re.sub(r'^```(?:json)?\s*|\s*```$', '', full_text.strip())
    sections = []
    sections_match = re.search(r'"sections"\s*:\s*\[', cleaned)
    if not sections_match:
        return sections
    start = sections_match.end()
    depth = 0
    obj_start = None
    in_string = False
    escape_next = False
    i = start
    while i < len(cleaned):
        c = cleaned[i]
        if escape_next:
            escape_next = False
            i += 1
            continue
        if c == '\\' and in_string:
            escape_next = True
            i += 1
            continue
        if c == '"' and not escape_next:
            in_string = not in_string
            i += 1
            continue
        if in_string:
            i += 1
            continue
        if c == '{':
            if depth == 0:
                obj_start = i
            depth += 1
        elif c == '}':
            depth -= 1
            if depth == 0 and obj_start is not None:
                try:
                    obj = json.loads(cleaned[obj_start:i+1])
                    if isinstance(obj, dict) and obj.get('title'):
                        sections.append(obj)
                except json.JSONDecodeError:
                    pass
                obj_start = None
        elif c == ']' and depth == 0:
            break
        i += 1
    return sections


def process_roadmap_gen_job(ch, method, properties, body):
    """Process a roadmap generation job with live incremental card rendering.
    
    Events emitted:
      section_start  — new section detected (title + index)
      topic_start    — new topic/card detected (title + section/topic indices)
      topic_item     — new item discovered in a topic
      completed      — roadmap done, redirect to view page
    """
    job_id = None
    session_id = None
    try:
        job = json.loads(body)
        if job.get('type') != 'roadmap_generation':
            return

        print(f" [x] Processing Roadmap Generation Job: {job.get('job_id', '?')}", flush=True)

        job_id = job.get('job_id')
        user_id = job.get('user_id')
        username = job.get('username', '')
        email = job.get('email', '')
        prompt = job.get('prompt', '')
        level = job.get('level', 'Beginner')
        visibility = job.get('visibility', 'private')
        session_id = job.get('session_id', job_id)

        if not job_id or not prompt:
            print("Missing job_id or prompt, skipping...")
            ch.basic_ack(delivery_tag=method.delivery_tag)
            return

        publish_roadmap_event(ch, session_id, 'progress', {
            'percentage': 5, 'message': 'Analyzing your topic...', 'title': 'Generating...'
        })

        ai_prompt = ROADMAP_STRUCTURE_PROMPT_TEMPLATE.format(prompt=prompt, level=level)

        publish_roadmap_event(ch, session_id, 'progress', {
            'percentage': 10, 'message': 'AI is designing roadmap...',
        })

        full_text = ""
        emitted_section_titles = []   # track sections emitted during streaming (unused now, kept for compat)

        # Fallback model chain — switch model on each rate-limit retry
        # All verified to support JSON output mode
        fallback_models = [
            'models/gemini-2.5-flash-lite',   # 30 RPM, 1500 RPD
            'models/gemini-3.1-flash-lite',   # new gen, separate quota
            'models/gemini-2.5-flash',        # 10 RPM, 500 RPD
        ]
        current_model_idx = 0
        gen_model = genai.GenerativeModel(fallback_models[current_model_idx])

        # Retry with model fallback for rate limits (429)
        max_retries = len(fallback_models)
        for attempt in range(max_retries):
            try:
                response = gen_model.generate_content(
                    ai_prompt,
                    generation_config=genai.types.GenerationConfig(
                        temperature=0.7, max_output_tokens=4096,
                        response_mime_type='application/json',
                    ),
                    stream=True
                )

                chunk_count = 0
                for chunk in response:
                    if chunk.text:
                        full_text += chunk.text
                        chunk_count += 1

                        pct = min(10 + (chunk_count * 2), 55)
                        if chunk_count % 5 == 0:
                            publish_roadmap_event(ch, session_id, 'progress', {
                                'percentage': pct,
                                'message': f'Designing structure... ({chunk_count * 20} tokens)',
                            })
                break  # success, exit retry loop

            except Exception as gemini_err:
                err_str = str(gemini_err)
                if ('429' in err_str or 'quota' in err_str.lower() or 'rate' in err_str.lower()) and attempt < max_retries - 1:
                    current_model_idx += 1
                    next_model = fallback_models[current_model_idx]
                    gen_model = genai.GenerativeModel(next_model)
                    model_short = next_model.split('/')[-1]
                    print(f" [!] Rate limited on {fallback_models[attempt].split('/')[-1]}, switching to {model_short} (attempt {attempt+1}/{max_retries})", flush=True)
                    publish_roadmap_event(ch, session_id, 'progress', {
                        'percentage': 10,
                        'message': f'Rate limited — switching to {model_short}...',
                    })
                    time.sleep(2)  # brief pause before retry
                    continue
                if '429' in err_str or 'quota' in err_str.lower():
                    raise Exception("AI rate limit exceeded on all models. Please try again in a few minutes.")
                raise

        if not full_text.strip():
            raise Exception("Empty response from Gemini")

        full_text = re.sub(r'^```(?:json)?\s*|\s*```$', '', full_text.strip())
        structure = json.loads(full_text)

        if not structure.get('title') or not structure.get('sections'):
            raise Exception("Invalid structure from Gemini")

        structure['sections'] = assign_ids(structure['sections'])
        import hashlib as _hl
        structure['slug'] = slugify(structure['title']) + '-' + _hl.md5(str(time.time()).encode()).hexdigest()[:6]
        structure['level'] = level
        structure['hours'] = int(structure.get('hours', 20))
        structure['tags'] = structure.get('tags', [])
        structure['description'] = structure.get('description', '')

        # After streaming: emit topic_start + topic_item events grouped by section.
        # Section labels are created by the frontend when the first topic of each section arrives.
        # This ensures section N+1 label only appears AFTER section N's cards are done typing.
        total_sections = len(structure['sections'])
        for idx, section in enumerate(structure['sections']):
            section_title = section.get('title', '')
            sec_pct = 55 + int((idx / max(total_sections, 1)) * 30)

            for ti, topic in enumerate(section.get('topics', [])):
                topic_title = topic.get('title', '')
                publish_roadmap_event(ch, session_id, 'topic_start', {
                    'section_index': idx, 'topic_index': ti,
                    'section_title': section_title,
                    'total_sections': total_sections,
                    'topic': {'title': topic_title, 'id': topic.get('id', ''), 'order': ti + 1},
                    'percentage': sec_pct,
                    'message': f'Section {idx + 1}: {section_title}',
                })
                time.sleep(0.15)

                for item in topic.get('items', []):
                    publish_roadmap_event(ch, session_id, 'topic_item', {
                        'section_index': idx, 'topic_index': ti,
                        'item_index': 0, 'item': item,
                    })
                    time.sleep(0.15)

        publish_roadmap_event(ch, session_id, 'progress', {
            'percentage': 88, 'message': 'Finalizing roadmap...',
            'title': structure['title'],
        })

        md = structure_to_markdown(structure)
        total_items = sum(len(t.get('items', [])) for s in structure['sections'] for t in s.get('topics', []))

        from bson.objectid import ObjectId as _ObjectId
        roadmap_id = _ObjectId()
        roadmap_doc = {
            '_id': roadmap_id, 'slug': structure['slug'],
            'title': structure['title'],
            'description': structure.get('description', ''),
            'prompt': prompt, 'level': level,
            'hours': structure.get('hours', 20),
            'tags': structure.get('tags', []),
            'type': 'roadmap', 'visibility': visibility,
            'user_id': user_id, 'author': username, 'author_email': email,
            'sections': structure['sections'], 'markdown': md,
            'ai_model': GEMINI_MODEL_NAME, 'progress': 0,
            'checkpoints_total': total_items, 'checkpoints_completed': 0,
            'created_at': datetime.datetime.utcnow(),
            'updated_at': datetime.datetime.utcnow(),
        }
        db.ai_roadmaps.insert_one(roadmap_doc)
        roadmap_id_str = str(roadmap_id)

        db.ai_roadmap_jobs.update_one(
            {'request_id': job_id},
            {'$set': {'status': 'completed', 'roadmap_id': roadmap_id_str,
                      'slug': structure['slug'], 'percentage': 100,
                      'updated_at': datetime.datetime.utcnow()}}
        )

        publish_roadmap_event(ch, session_id, 'completed', {
            'slug': structure['slug'], 'roadmap_id': roadmap_id_str,
            'title': structure['title'], 'percentage': 100,
            'total_sections': total_sections,
            'message': 'Roadmap generated successfully!',
        })
        print(f" [+] Roadmap generated: {structure['title']} ({roadmap_id_str})")

    except json.JSONDecodeError as e:
        print(f" [!] JSON parse error: {e}")
        publish_roadmap_event(ch, session_id, 'error', {
            'message': 'Failed to parse AI response. Please try again.'
        })
        db.ai_roadmap_jobs.update_one(
            {'request_id': job_id},
            {'$set': {'status': 'failed', 'error_message': 'JSON parse error'}}
        )
    except Exception as e:
        print(f" [!] Error processing roadmap job: {e}")
        try:
            publish_roadmap_event(ch, session_id, 'error', {
                'message': f'Generation failed: {str(e)[:200]}'
            })
        except Exception:
            pass
        try:
            db.ai_roadmap_jobs.update_one(
                {'request_id': job_id},
                {'$set': {'status': 'failed', 'error_message': str(e)[:500]}}
            )
        except Exception:
            pass
    finally:
        ch.basic_ack(delivery_tag=method.delivery_tag)
        print(" [x] Roadmap Generation Job Done")

def main():
    while True:
        try:
            print(f"Connecting to RabbitMQ at {AMQP_HOST}...", flush=True)
            credentials = pika.PlainCredentials(AMQP_USER, AMQP_PASS)
            parameters = pika.ConnectionParameters(host=AMQP_HOST, port=AMQP_PORT, credentials=credentials)
            connection = pika.BlockingConnection(parameters)
            channel = connection.channel()
            print("RabbitMQ connection established.", flush=True)

            # Declare DLQs first
            channel.queue_declare(
                queue=DLQ_NAME,
                durable=True,
                arguments={'x-message-ttl': 604800000}  # 7 days
            )
            channel.queue_declare(
                queue=CONTENT_DLQ_NAME,
                durable=True,
                arguments={'x-message-ttl': 604800000}  # 7 days
            )
            
            # Declare main queues with DLQ routing
            channel.queue_declare(
                queue=QUEUE_NAME,
                durable=True,
                arguments={
                    'x-dead-letter-exchange': '',
                    'x-dead-letter-routing-key': DLQ_NAME,
                }
            )
            channel.queue_declare(
                queue=CONTENT_QUEUE_NAME,
                durable=True,
                arguments={
                    'x-dead-letter-exchange': '',
                    'x-dead-letter-routing-key': CONTENT_DLQ_NAME,
                }
            )
            
            # Fair dispatch
            channel.basic_qos(prefetch_count=1)
            
            print(f" [*] AI Worker waiting for jobs in '{QUEUE_NAME}' & '{CONTENT_QUEUE_NAME}'.", flush=True)
            
            def dispatch_ai_job(ch, method, properties, body):
                """Route ai_jobs messages to the correct handler"""
                try:
                    job = json.loads(body)
                    if job.get('type') == 'roadmap_generation':
                        process_roadmap_gen_job(ch, method, properties, body)
                    else:
                        process_ai_job(ch, method, properties, body)
                except Exception:
                    process_ai_job(ch, method, properties, body)
            
            channel.basic_consume(queue=QUEUE_NAME, on_message_callback=dispatch_ai_job)
            channel.basic_consume(queue=CONTENT_QUEUE_NAME, on_message_callback=process_content_job)
            channel.start_consuming()
            
        except pika.exceptions.AMQPConnectionError:
            print("Connection lost, retrying in 5s...")
            time.sleep(5)
        except Exception as e:
            print(f"Unexpected error: {e}")
            time.sleep(5)

if __name__ == '__main__':
    main()
