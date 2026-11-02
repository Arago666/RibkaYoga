"""Prepare five practice manifests after importing the reviewed catalog.

Usage: python3 practices.py PATH_TO_SQLITE
This reads the database, writes manifests, and never changes database content.
"""
import json
import re
import sqlite3
import sys
import uuid
from pathlib import Path

ROOT = Path(__file__).resolve().parent
NAMESPACE = uuid.UUID('60871027-d82e-413c-8989-ab5aad406dfa')
TITLES = {1: 'Начальная настройка и подготовка', 2: 'Основная последовательность',
          3: 'Положения стоя и равновесие', 4: 'Продолжение последовательности', 5: 'Завершение', 6: 'СВ'}


def uid(value):
    return str(uuid.uuid5(NAMESPACE, value))


def parts(row):
    cells = row['cells']
    if len(cells) <= 12:
        return [(0, cells)]
    # The fifth original sheet wraps row 1.2 after its eighth position.
    # Keep that authored break and every pair of bracketed cells intact.
    if len(cells) == 14 and row['label'] == '1.2':
        return [(0, cells[:8]), (8, cells[8:])]
    raise ValueError(f"An explicit, group-safe continuation is required for {row['label']}")


def underline(note, offset, count):
    match = re.match(r'^Подчёркнуто:\s*([\d\s–;]+)', note or '')
    if not match:
        return None
    intervals = []
    for part in match.group(1).strip().split(';'):
        values = [int(v) for v in part.strip().split('–') if v.strip()]
        if not values:
            continue
        start, end = values[0], values[-1]
        a, b = max(start, offset + 1) - offset, min(end, offset + count) - offset
        if a <= b:
            intervals.append(str(a) if a == b else f'{a}–{b}')
    return 'Подчёркнуто: ' + '; '.join(intervals) if intervals else None


def build(database):
    db = sqlite3.connect(database)
    cards = dict(db.execute('select import_key,id from catalog_asanas').fetchall())
    mapping = json.loads((ROOT / 'occurrences.json').read_text())['mapping']
    report = []
    for number in range(1, 6):
        source = json.loads((ROOT / f'sheet-{number}/crops.json').read_text())
        blocks = {}
        row_map = []
        shifted = False
        for row in source['rows']:
            block_number = row['block']
            block = blocks.setdefault(block_number, {
                'id': uid(f'{number}:block:{block_number}'), 'title': TITLES[block_number],
                'purpose': 'Расшифровка связки СВ с исходного листа.' if block_number == 6 else None, 'rows': []})
            fragments = parts(row)
            for part_index, (offset, cells) in enumerate(fragments):
                row_id = uid(f"{number}:row:{row['label']}:{part_index}")
                note = underline(row.get('note'), offset, len(cells))
                if not note:
                    if len(fragments) > 1:
                        note = f"Строка {row['label']} исходного листа · часть {part_index + 1} из {len(fragments)}."
                    elif number == 5 and block_number == 1 and shifted:
                        note = f"Строка {row['label']} исходного листа."
                    elif not (row.get('note') or '').startswith('Подчёркнуто:'):
                        note = row.get('note')
                output = {'id': row_id, 'note': note, 'cells': []}
                for index, cell in enumerate(cells):
                    marks = [v for v in [cell.get('mark'), cell.get('after')] if v]
                    if number == 3 and row['label'] == '2.2' and offset + index >= 4:
                        marks.append(f'Табата {offset + index - 3}')
                    output['cells'].append({
                        'id': uid(cell['key']),
                        'asana_id': cards[mapping[cell['key']]] if cell['kind'] == 'asana' else None,
                        **({'source_image': f"sheet-{number}/{cell['image']}"} if cell['kind'] == 'asana' else {}),
                        'custom_name': cell.get('text') if cell['kind'] == 'text' else None,
                        'note': ' · '.join(marks) or None,
                        'group_key': uid(f"{number}:{row['label']}:{cell['group']}") if cell.get('group') else None,
                        'arrow_direction': None,
                    })
                # No bracket may be cut by the explicit continuation.
                groups = {}
                for i, cell in enumerate(output['cells']):
                    if cell['group_key']:
                        groups.setdefault(cell['group_key'], []).append(i)
                assert all(len(v) >= 2 and v == list(range(v[0], v[-1] + 1)) for v in groups.values())
                block['rows'].append(output)
                row_map.append({'source': row['label'], 'part': part_index + 1, 'row_id': row_id,
                                'source_positions': [offset + 1, offset + len(cells)]})
            if len(fragments) > 1:
                shifted = True
        manifest = {'format': 'ribka-practice-sheet-v1', 'title': source['title'],
                    'theme': source['title'].split(' — ', 1)[-1], 'blocks': list(blocks.values())}
        (ROOT / f'practice-{number}.json').write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + '\n')
        report.append({'sheet': number, 'rows': row_map, 'cells': sum(len(r['cells']) for b in manifest['blocks'] for r in b['rows'])})
    (ROOT / 'practice-provenance.json').write_text(json.dumps(report, ensure_ascii=False, indent=2) + '\n')
    print('Prepared five practices:', [r['cells'] for r in report])


if __name__ == '__main__':
    build(sys.argv[1])
