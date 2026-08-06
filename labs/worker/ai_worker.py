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
CONFIG_PATH = '/host_www/www/env.json' if os.path.exists('/host_www/www/env.json') else '../../env.json'

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
GEMINI_MODEL_NAME = 'models/gemini-flash-latest'
print("Configuring Gemini API...", flush=True)
genai.configure(api_key=config.get('ai_api_key'))

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
            except Exception:
                pass
        
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
    except Exception:
        pass
    
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
    """Check if conversation needs summarization and perform it if needed"""
    try:
        pass # Database interaction removed. 
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
                        except Exception:
                            pass

                        if fc:
                            found_fc = True
                            try:
                                response.resolve()
                            except Exception:
                                pass

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
            
            channel.basic_consume(queue=QUEUE_NAME, on_message_callback=process_ai_job)
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
