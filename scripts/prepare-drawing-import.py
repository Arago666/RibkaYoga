"""Prepare local, revision-pinned imports from reviewed editor-native pose recipes."""
import argparse
import json
from pathlib import Path
import sqlite3
import uuid

root = Path(__file__).resolve().parents[1]
parser = argparse.ArgumentParser()
parser.add_argument('--database', required=True)
parser.add_argument('--collection', required=True)
parser.add_argument('--catalog-result')
args = parser.parse_args()
folder = root / '.runtime/drawing-pack'
inventory = json.loads((root / 'resources/imports/catalog-redraw/inventory.json').read_text())
layout = json.loads((root / 'resources/imports/spinal-arc-sheet/layout.json').read_text())
rendered = json.loads((folder / 'rendered.json').read_text())['items']
lookup = {(item['family'], item['key']): item for item in rendered}
connection = sqlite3.connect(f'file:{Path(args.database).resolve()}?mode=ro', uri=True)
connection.row_factory = sqlite3.Row
categories = {row['name']: row['id'] for row in connection.execute('SELECT id,name FROM catalog_categories')}
workspace = inventory['workspace_ids'][0]
collection = connection.execute('SELECT workspace_id,archived_at FROM asana_collections WHERE id=?', (args.collection,)).fetchone()
if not collection or collection['workspace_id'] != workspace or collection['archived_at']:
    raise ValueError('The target collection must be active in the inventory workspace.')

def category(cell):
    name = cell['name'].lower()
    key = cell['key']
    if any(word in name for word in ['стойка на', 'плуг', 'ногами за головой']):
        return categories['Перевёрнутые']
    if 'четвереньках' in name:
        return categories['На четвереньках']
    if any(word in name for word in ['планка', 'собака', 'обратный стол', 'восемь опор']) or key == 'sv-06':
        return categories['Опоры на руках']
    if 'лёжа' in name or 'кобра' in name or 'опорой на голову' in name:
        return categories['Лёжа']
    if 'сидя' in name or 'лодка' in name or 'поза ребёнка' in name:
        return categories['Сидя']
    return categories['Стоя']

def compact_note(value):
    """Keep sheet marks while removing prose used only to describe the source."""
    replacements = {
        'Пунктирная дуга справа на исходном листе.': 'Пунктирная дуга',
        'Пунктирная дуга на исходном листе.': 'Пунктирная дуга',
        'Пунктир под кистью на исходном листе.': 'Пунктир под кистью',
        'S; пунктир справа на исходном листе.': 'S · Пунктир справа',
        'На листе отдельное подчёркивание этой позы.': None,
        'СВ* — после этой строки на листе указана связка СВ, вынесенная в нижнюю строку.': 'СВ*',
    }
    return replacements.get(value, value)

if args.catalog_result:
    result = json.loads(Path(args.catalog_result).read_text())
    if result['dry_run'] or result['workspace_id'] != workspace:
        raise ValueError('A successful catalog result from this workspace is required.')
    cards = {entry['key']: entry['id'] for entry in result['items']}
    stable_id = lambda key: str(uuid.uuid5(uuid.NAMESPACE_URL, 'ribka-spinal-arc-sheet-v1:'+key))
    blocks = []
    for block in layout['blocks']:
        rows = []
        for row in block['rows']:
            notes = []
            if row.get('note'):
                notes.append(compact_note(row['note']))
            underlines = [
                '–'.join(map(str, [positions[0], positions[-1]])) if len(positions)>1 else str(positions[0])
                for positions in row.get('underlines', [])
            ]
            if underlines:
                notes.append('Подчёркнуто: '+'; '.join(underlines))
            cells = []
            for cell in row['cells']:
                caption = [compact_note(cell.get('note')), cell.get('after')]
                cells.append({
                    'id':stable_id('cell:'+cell['key']), 'asana_id':cards['sheet:'+cell['key']],
                    'note':' · '.join(value for value in caption if value) or None,
                    'group_key':stable_id('group:'+row['key']+':'+str(cell['group'])) if cell.get('group') else None,
                    'arrow_direction':None,
                })
            rows.append({'id':stable_id('row:'+row['key']),'note':' · '.join(value for value in notes if value) or None,'cells':cells})
        blocks.append({'id':stable_id('block:'+block['key']),'title':block['name'],'purpose':None,'rows':rows})
    manifest = {'format':'ribka-practice-sheet-v1','workspace_id':workspace,'title':layout['title'],'theme':None,'blocks':blocks}
    target=folder/'practice.json'
else:
    items=[]
    for card in inventory['cards']:
        if not card['is_reference_pose']:
            continue
        drawing=lookup['catalog',card['import_key']]
        items.append({'key':'catalog:'+card['import_key'],'id':card['id'],'expected_revision':card['revision'],
                      'image_file':drawing['image_file'],'source_file':drawing['source_file']})
    for block in layout['blocks']:
        for row in block['rows']:
            for cell in row['cells']:
                drawing=lookup['sheet',cell['key']]
                label=row['key']+'/'+str(cell['position'])
                items.append({'key':'sheet:'+cell['key'],'import_key':'spinal-arc-sheet:'+cell['key'],
                    'name':cell['name']+' · '+label,
                    'aliases':[cell['name'],'Лист 2 Х/Б', 'Строка '+row['key'], 'Лист '+label],
                    'category_ids':[category(cell)],'image_file':drawing['image_file'],'source_file':drawing['source_file']})
    manifest={'format':'ribka-drawing-pack-v1','workspace_id':workspace,'source':layout['title'],
              'collection_id':args.collection,'items':items}
    target=folder/'pack.json'

target.write_text(json.dumps(manifest,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
print('Prepared',target,'with',len(manifest.get('items',[])) or sum(len(row['cells']) for block in manifest['blocks'] for row in block['rows']),'cells/items')
