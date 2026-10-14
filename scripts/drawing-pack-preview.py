"""Contact sheets for visual inspection of the editor-rendered drawing pack."""
import json
from pathlib import Path
from PIL import Image, ImageDraw, ImageFont

root = Path(__file__).resolve().parents[1]
folder = root / '.runtime/drawing-pack'
items = json.loads((folder / 'rendered.json').read_text())['items']
font = ImageFont.truetype('/System/Library/Fonts/Supplemental/Arial.ttf', 17)
for family in ['catalog', 'sheet']:
    entries = [item for item in items if item['family'] == family]
    for page in range((len(entries) + 29) // 30):
        subset = entries[page * 30:(page + 1) * 30]
        image = Image.new('RGB', (1140, 1100), '#edf1ea')
        draw = ImageDraw.Draw(image)
        for index, item in enumerate(subset):
            x, y = index % 6 * 190 + 9, index // 6 * 220 + 9
            image.paste(Image.open(folder / item['image_file']).resize((172, 172)), (x, y))
            label = item['key'].replace('reference-sheet:', '')
            draw.text((x + 3, y + 181), label, fill='#34492c', font=font)
        image.save(folder / f'{family}-contact-{page + 1}.png')
print('Visual contact sheets written into', folder)
