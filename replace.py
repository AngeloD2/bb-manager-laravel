import os

replacements = {
    'MediaFolder': 'MediaLoop',
    'media_folder': 'media_loop',
    'FolderController': 'LoopController',
    'FolderResource': 'LoopResource',
    'media_folders': 'media_loops',
    'folder_id': 'loop_id',
    'parent_folder_id': 'parent_loop_id',
    'is_folder': 'is_loop',
    'folder': 'loop',
    'Folder': 'Loop',
    'folders': 'loops',
    'Folders': 'Loops',
    'TokenManager': 'SlotManager',
    'token_spent': 'slot_spent',
    'tokens_spent': 'slots_spent',
    'max_daily_tokens': 'max_daily_slots',
    'play_tokens_remaining': 'play_slots_remaining',
    'tokens': 'slots',
    'Tokens': 'Slots',
    'token': 'slot',
    'Token': 'Slot',
}

# we need to be careful with 'folder' -> 'loop' as it might match inside other words, but let's do a simple replace
# actually, let's use word boundaries for the common words
import re

def replace_in_file(filepath):
    with open(filepath, 'r', encoding='utf-8') as f:
        content = f.read()
    
    new_content = content
    # Order matters: replace longer/specific strings first
    ordered_keys = [
        'MediaFolderResource', 'MediaFolder', 'media_folders', 'FolderController', 
        'parent_folder_id', 'folder_id', 'TokenManagerService', 'TokenManager',
        'max_daily_tokens', 'play_tokens_remaining', 'tokens_spent_today', 
        'token_spent', 'tokens_spent', 'deductToken'
    ]
    
    specific_replacements = {
        'MediaFolderResource': 'MediaLoopResource',
        'MediaFolder': 'MediaLoop',
        'media_folders': 'media_loops',
        'FolderController': 'LoopController',
        'parent_folder_id': 'parent_loop_id',
        'folder_id': 'loop_id',
        'TokenManagerService': 'SlotManagerService',
        'TokenManager': 'SlotManager',
        'max_daily_tokens': 'max_daily_slots',
        'play_tokens_remaining': 'play_slots_remaining',
        'tokens_spent_today': 'slotsSpentToday',
        'token_spent': 'slot_spent',
        'tokens_spent': 'slots_spent',
        'deductToken': 'deductSlot'
    }
    
    for k in ordered_keys:
        new_content = new_content.replace(k, specific_replacements[k])
        
    # Regex replacements for standalone words
    word_replacements = {
        'Folder': 'Loop',
        'folder': 'loop',
        'Folders': 'Loops',
        'folders': 'loops',
        'Token': 'Slot',
        'token': 'slot',
        'Tokens': 'Slots',
        'tokens': 'slots'
    }
    
    for k, v in word_replacements.items():
        new_content = re.sub(r'\b' + k + r'\b', v, new_content)
        
    if new_content != content:
        with open(filepath, 'w', encoding='utf-8') as f:
            f.write(new_content)
        print(f"Updated {filepath}")

for root, dirs, files in os.walk('app'):
    for file in files:
        if file.endswith('.php'):
            replace_in_file(os.path.join(root, file))

for root, dirs, files in os.walk('routes'):
    for file in files:
        if file.endswith('.php'):
            replace_in_file(os.path.join(root, file))

for root, dirs, files in os.walk('tests'):
    for file in files:
        if file.endswith('.php'):
            replace_in_file(os.path.join(root, file))

