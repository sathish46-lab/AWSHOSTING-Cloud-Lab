#!/usr/bin/env python3
import sys
import os
import json

# Force Python to look in the tool directory for 'src'
BASE_DIR = os.path.dirname(os.path.realpath(__file__))
if BASE_DIR not in sys.path:
    sys.path.insert(0, BASE_DIR)

from src.Arguments import Arguments
from src.Lab import Lab

def print_help():
    print("\n🚀 Tom Labs Orchestrator CLI")
    print("Usage: labsctl <command> [options]\n")
    print("Commands:")
    print("  build <name:tag>    Build lab image.  Ex: labsctl build essentials:lab --no-cache")
    print("  deploy <name:tag>   Deploy for user.  Ex: labsctl deploy essentials:lab --user=sathish --hash=HASH")
    print("  remove <name>       Delete instance.  Ex: labsctl remove essentials --hash=HASH")
    print("  stop <name>         Stop instance.    Ex: labsctl stop essentials --hash=HASH")
    print("  start <name>        Start instance.   Ex: labsctl start essentials --hash=HASH")
    print("  shell <name>        Enter container.  Ex: labsctl shell essentials --hash=HASH")
    print("  stream --key=<k>    Live log stream.  Ex: labsctl stream --key=logs.HASH")
    print("  syncuser <user>     Fix permissions.  Ex: labsctl syncuser sathish")
    print("  ensure-codeserver   Check VS Code.    Ex: labsctl ensure-codeserver --hash=HASH")
    print("  quiz generate       AI Quiz Gen.      Ex: labsctl quiz generate --topic=ID --subtopic=ID --diff=hard")
    print("  apply-preferences   Hot-apply prefs.  Ex: labsctl apply-preferences --hash=HASH")
    print("  run-script          Run init.sh now.  Ex: labsctl run-script --hash=HASH --user=sathish")
    print("  list-images         List built labs.  Ex: labsctl list-images")
    print("  get-workers         Check active.     Ex: labsctl get-workers")
    print("")
    print("Challenge Commands:")
    print("  challenge build     Build CTF image.  Ex: labsctl challenge build sql_injection:lab")
    print("  challenge deploy    Deploy CTF lab.   Ex: labsctl challenge deploy --user=sathish --hash=HASH --challenge=sql_injection")
    print("  challenge stop      Stop CTF lab.     Ex: labsctl challenge stop --hash=HASH")
    print("  challenge start     Start CTF lab.    Ex: labsctl challenge start --hash=HASH")
    print("  challenge remove    Remove CTF lab.   Ex: labsctl challenge remove --hash=HASH\n")
    print("Instance Commands:")
    print("  instance build      Build instance.   Ex: labsctl instance build --user=sathish --hash=HASH")
    print("  instance deploy     Deploy instance.  Ex: labsctl instance deploy --user=sathish --hash=HASH")
    print("  instance stop       Stop instance.    Ex: labsctl instance stop --hash=HASH")
    print("  instance start      Start instance.   Ex: labsctl instance start --hash=HASH")
    print("  instance remove     Remove instance.  Ex: labsctl instance remove --hash=HASH\n")

def main():
    args = Arguments(sys.argv)
    
    if len(sys.argv) < 2 or args.hasFlag('help'):
        print_help()
        return

    # FIXED: Extract the hash from the CLI flags
    session_hash = args.getFlagValue('hash')
    
    # FIXED: Pass both args and the hash to the Lab manager
    lab_manager = Lab(args, session_hash)
    cmd = sys.argv[1]

    # Professional Routing based on your screenshots
    try:
        if cmd == 'build':
            lab_manager.build()
        elif cmd == 'deploy':
            lab_manager.deploy()
        elif cmd == 'remove':
            lab_manager.remove()
        elif cmd == 'stop':
            lab_manager.stop()
        elif cmd == 'start':
            lab_manager.start()
        elif cmd == 'shell':
            lab_manager.shell()
        elif cmd == 'stream':
            # Consolidated Stream Logic
            from src.Stream import Stream
            key = args.getFlagValue('key')
            if not key:
                print("❌ Error: Missing --key. Use --key=overview or --key=container_stats")
                return
            s = Stream(args, key)
            s.stream()
        elif cmd == 'info':
            lab_manager.info()
        elif cmd == 'syncuser':
            user = args.getFlagValue('user')
            if not user:
                # If run as "labsctl syncuser <username>"
                if len(sys.argv) > 2 and not sys.argv[2].startswith('-'):
                     user = sys.argv[2]
                
            if user:
                 lab_manager.sync_user(user)
            else:
                 print("Usage: labsctl syncuser <username> or labsctl syncuser --user=<username>")
        elif cmd == 'ensure-codeserver':
             lab_manager.ensure_codeserver()
        elif cmd == 'quiz':
             subcmd = sys.argv[2] if len(sys.argv) > 2 else ""
             if subcmd == 'generate':
                 from src.QuizEngine import QuizEngine
                 engine = QuizEngine(lab_manager.db)
                 topic_id = args.getFlagValue('topic')
                 subtopic_id = args.getFlagValue('subtopic')
                 diff = args.getFlagValue('diff') or 'normal'
                 job_id = args.getFlagValue('job')
                 
                 result = engine.generate_quiz(topic_id, subtopic_id, diff, job_id)
                 print(json.dumps(result))
             else:
                 print("Usage: labsctl quiz generate --topic=ID --subtopic=ID --diff=normal")
        elif cmd == 'instance':
            from src.Instance import Instance
            instance_manager = Instance(args, session_hash)
            sub = sys.argv[2] if len(sys.argv) > 2 else 'deploy'
            actions = {
                'build': instance_manager.build,
                'deploy': instance_manager.deploy,
                'stop': instance_manager.stop,
                'start': instance_manager.start,
                'remove': instance_manager.remove,
            }
            if sub in actions:
                actions[sub]()
            else:
                print(f"Unknown instance command: {sub}")
                print("Usage: labsctl instance <build|deploy|stop|start|remove> --hash=HASH --user=USER")
        elif cmd == 'challenge':
            # Challenge CTF Lab Routing
            from src.Challenge import Challenge
            challenge_mgr = Challenge(args, session_hash)
            subcmd = sys.argv[2] if len(sys.argv) > 2 else ""

            if subcmd == 'build':
                challenge_mgr.build()
            elif subcmd == 'deploy':
                challenge_mgr.deploy()
            elif subcmd == 'stop':
                challenge_mgr.stop()
            elif subcmd == 'start':
                challenge_mgr.start()
            elif subcmd == 'remove':
                challenge_mgr.remove()
            else:
                print("Usage: labsctl challenge <build|deploy|stop|start|remove> [options]")
                print("  Ex:  labsctl challenge build sql_injection:lab")
                print("  Ex:  labsctl challenge deploy --user=sathish --hash=HASH --challenge=sql_injection")
        elif cmd == 'apply-preferences':
            lab_manager.apply_preferences()
        elif cmd == 'run-script':
            lab_manager.run_script()
        elif cmd == 'list-images':
              lab_manager.list_images()
        elif cmd == 'get-workers':
              lab_manager.get_workers()
        else:
            print(f"❌ Unknown command: {cmd}")
        

    except Exception as e:
        print(f"⚠️ Error: {str(e)}")

if __name__ == "__main__":
    main()