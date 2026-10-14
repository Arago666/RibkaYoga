"""Build side-by-side source/renderer pages for manual pose review."""
import argparse
import json
from pathlib import Path
from PIL import Image, ImageDraw, ImageFont, ImageOps

root = Path(__file__).resolve().parents[1]
parser = argparse.ArgumentParser()
parser.add_argument('--folder', default=str(root / '.runtime/drawing-pack'))
args = parser.parse_args()
folder = Path(args.folder)
rendered = json.loads((folder / 'rendered.json').read_text())['items']
reference = json.loads((root / 'resources/imports/reference-sheet/manifest.json').read_text())
original_cards = {item['source_key']: item for item in reference['items']}
layout = json.loads((root / 'resources/imports/spinal-arc-sheet/layout.json').read_text())
sheet_cells = {cell['key']: cell for block in layout['blocks'] for row in block['rows'] for cell in row['cells']}
sheet = Image.open(root / 'resources/imports/spinal-arc-sheet/source.png').convert('RGB')
font = ImageFont.load_default(size=15)

def pose_bounds(image):
    mask = image.convert('L').point(lambda value: 255 if value < 190 else 0)
    bounds = mask.getbbox()
    return image.crop(bounds) if bounds else image

for family in ['catalog', 'sheet']:
    drawings = [item for item in rendered if item['family'] == family]
    for page in range((len(drawings) + 11) // 12):
        comparison = Image.new('RGB', (1320, 1020), '#edf1ea')
        label = ImageDraw.Draw(comparison)
        for index, item in enumerate(drawings[page * 12:(page + 1) * 12]):
            if family == 'catalog':
                original = Image.open(root / 'resources/imports/reference-sheet' / original_cards[item['key']]['image']).convert('RGB')
            else:
                x, y, width, height = sheet_cells[item['key']]['bbox']
                original = sheet.crop((max(0, x - 3), max(0, y - 3), x + width + 3, y + height + 3))
            redraw = Image.open(folder / item['image_file']).convert('RGB')
            x, y = (index % 3) * 440 + 10, (index // 3) * 255 + 7
            label.text((x, y), item['key'].replace('reference-sheet:', ''), fill='#273926', font=font)
            for column, picture in enumerate([original, redraw]):
                panel = Image.new('RGB', (205, 205), 'white')
                contained = ImageOps.contain(pose_bounds(picture), (180, 180))
                panel.paste(contained, ((205 - contained.width) // 2, (205 - contained.height) // 2))
                comparison.paste(panel, (x + column * 214, y + 23))
                label.text((x + column * 214, y + 231), 'source' if column == 0 else 'editor', fill='#273926', font=font)
        comparison.save(folder / f'{family}-comparison-{page + 1}.png')
print('Manual comparison pages written to', folder)
