"""Deterministic extraction of the figures supplied by the owner (not pose generation).

Usage: python scripts/extract-reference-cards.py PATH_TO_ORIGINAL_SCREENSHOT
Requires Pillow. Keeps the original geometry, white padding and an enlarged PNG for UI use.
"""
from pathlib import Path
from collections import Counter
import json
import shutil
import sys
from PIL import Image, ImageDraw, ImageFont

ROOT = Path(__file__).resolve().parents[1]
OUTPUT = ROOT / 'resources/imports/reference-sheet'
OUTPUT.mkdir(parents=True, exist_ok=True)
(OUTPUT / 'assets').mkdir(exist_ok=True)
source = Path(sys.argv[1]).resolve()
im = Image.open(source).convert('RGB')
if im.size != (588, 1280):
    raise SystemExit('This crop map is for the supplied 588 x 1280 screenshot only.')
shutil.copyfile(source, OUTPUT / 'source.png')

# Each tuple: editable name, search aliases, whether the miniature is ambiguous.
rows = [
 ('1.1', (505, 533), [
  ('Сукхасана', ['Удобная поза', 'Поза со скрещёнными ногами', 'Sukhasana'], False),
  ('Боковой наклон сидя', ['Наклон корпуса в сторону'], True),
  ('Дандасана', ['Поза посоха', 'Dandasana'], False),
  ('Руки вверх сидя', ['Парватасана сидя', 'Вытяжение рук'], True),
 ]),
 ('1.2', (534, 558), [
  ('Навасана с согнутыми ногами', ['Поза лодки', 'Navasana'], True),
  ('Навасана', ['Поза лодки', 'Navasana'], False),
  ('Ардха Навасана', ['Полулодка', 'Ardha Navasana'], False),
  ('Лёжа с согнутыми коленями', ['Подготовка лёжа на спине'], True),
  ('Ноги вверх лёжа', ['Урдхва Прасарита Падасана'], True),
  ('Навасана', ['Поза лодки', 'Navasana'], False),
  ('Дандасана', ['Поза посоха', 'Dandasana'], False),
  ('Скручивание сидя', ['Ардха Матсиендрасана', 'Seated twist'], True),
  ('Баласана', ['Поза ребёнка', 'Balasana'], False),
  ('Положение на четвереньках', ['Бхарманасана', 'Поза стола'], False),
 ]),
 ('1.3', (559, 589), [
  ('Наклон в приседе', ['Подготовка к балансу на руках'], True),
  ('Положение на четвереньках', ['Бхарманасана', 'Поза стола'], False),
  ('Битиласана', ['Поза коровы', 'Прогиб на четвереньках', 'Bitilasana'], False),
  ('Марджариасана', ['Поза кошки', 'Округление спины', 'Marjaryasana'], False),
  ('Уттана Шишосана', ['Поза щенка', 'Вытяжение на коленях', 'Puppy pose'], True),
  ('Аштанга Намаскара', ['Восемь точек опоры', 'Ashtanga Namaskara'], False),
  ('Подъём груди из восьми опор', ['Переход из Аштанга Намаскары'], True),
  ('Урдхва Мукха Шванасана', ['Собака мордой вверх', 'Upward facing dog'], False),
  ('Адхо Мукха Шванасана', ['Собака мордой вниз', 'Downward facing dog'], False),
 ]),
 ('2.1', (602, 627), [
  ('Шалабхасана', ['Поза саранчи', 'Locust pose', 'Salabhasana'], False),
  ('Бхуджангасана — низкая кобра', ['Поза кобры', 'Bhujangasana'], True),
  ('Шалабхасана — подъём груди', ['Поза саранчи', 'Locust pose'], True),
  ('Урдхва Мукха Шванасана', ['Собака мордой вверх', 'Upward facing dog'], False),
  ('Баласана', ['Поза ребёнка', 'Balasana'], False),
  ('Уттанасана', ['Наклон стоя', 'Uttanasana'], True),
 ]),
 ('2.2', (628, 653), [
  ('Пхалакасана', ['Планка', 'Phalakasana', 'Plank'], False),
  ('Чатуранга Дандасана', ['Низкая планка', 'Chaturanga Dandasana'], False),
  ('Аштанга Намаскара', ['Восемь точек опоры', 'Ashtanga Namaskara'], False),
  ('Планка с опорой на колени', ['Переход из упора на коленях'], True),
  ('Урдхва Мукха Шванасана', ['Собака мордой вверх', 'Upward facing dog'], False),
  ('Пхалакасана', ['Планка', 'Phalakasana'], False),
  ('Адхо Мукха Шванасана', ['Собака мордой вниз', 'Downward facing dog'], False),
  ('Уттанасана', ['Наклон стоя', 'Uttanasana'], True),
 ]),
 ('2.3', (651, 682), [
  ('Адхо Мукха Шванасана', ['Собака мордой вниз', 'Downward facing dog'], False),
  ('Планка с округлённой спиной', ['Переход из собаки в планку'], True),
  ('Эка Пада Адхо Мукха Шванасана', ['Собака на трёх опорах', 'Собака с поднятой ногой'], False),
  ('Колено к плечу в упоре', ['Подтягивание колена в планке'], True),
  ('Боковой упор', ['Васиштхасана', 'Боковая планка'], True),
  ('Васиштхасана с поднятой ногой', ['Боковая планка', 'Vasisthasana'], False),
  ('Выпад с раскрытием корпуса', ['Боковое вытяжение в выпаде'], True),
  ('Баласана', ['Поза ребёнка', 'Balasana'], False),
 ]),
 ('2.4', (684, 706), [
  ('Уттанасана', ['Наклон стоя', 'Uttanasana'], True),
 ]),
 ('3.1', (719, 747), [
  ('Тадасана', ['Поза горы', 'Tadasana'], False),
  ('Врикшасана', ['Поза дерева', 'Vrksasana', 'Tree pose'], False),
  ('Наклон стоя с согнутыми коленями', ['Подготовка к балансу стоя'], True),
  ('Наклон с вытяжением рук', ['Вытяжение корпуса стоя'], True),
  ('Гарудасана', ['Поза орла', 'Garudasana'], True),
 ]),
 ('3.2', (748, 776), [
  ('Уттхита Хаста Падангуштхасана', ['Баланс с поднятой ногой', 'Utthita Hasta Padangusthasana'], True),
  ('Баланс стоя с отведённой ногой', ['Уттхита Хаста Падангуштхасана Б'], True),
  ('Баланс стоя с отведённой ногой — разворот', ['Поворот корпуса в балансе'], True),
 ]),
 ('3.3', (777, 807), [
  ('Уттхита Хаста Падангуштхасана', ['Баланс с поднятой ногой', 'Utthita Hasta Padangusthasana'], True),
  ('Вирабхадрасана III', ['Поза воина 3', 'Warrior III'], False),
  ('Ардха Чандрасана', ['Поза полумесяца', 'Half moon pose'], False),
  ('Паривритта Ардха Чандрасана', ['Скрученный полумесяц', 'Revolved half moon'], True),
  ('Маласана', ['Глубокий присед', 'Поза гирлянды', 'Malasana'], True),
 ]),
 ('4.1', (815, 852), [
  ('Гарудасана', ['Поза орла', 'Garudasana'], True),
  ('Прогиб стоя', ['Ардха Чакрасана', 'Standing backbend'], True),
  ('Бакасана', ['Поза журавля', 'Поза вороны', 'Bakasana'], False),
  ('Присед с раскрытием коленей', ['Маласана', 'Раскрытие тазобедренных'], True),
  ('Урдхва Дханурасана', ['Мост', 'Поза колеса', 'Urdhva Dhanurasana'], False),
  ('Ширшасана', ['Стойка на голове', 'Sirsasana', 'Headstand'], False),
 ]),
 ('5.1', (856, 889), [
  ('Апанасана', ['Колени к груди', 'Apanasana'], True),
  ('Карнапидасана', ['Колени к ушам', 'Karnapidasana'], True),
  ('Сарвангасана', ['Стойка на плечах', 'Берёзка', 'Sarvangasana'], False),
  ('Халасана', ['Поза плуга', 'Halasana'], False),
  ('Падмасана', ['Поза лотоса', 'Сидя со скрещёнными ногами', 'Padmasana'], True),
  ('Шавасана', ['Поза покоя', 'Savasana'], False),
 ]),
]

# Boundaries omit arrows and row numbers. Some brackets need narrower local crops.
columns = [(61,91),(101,131),(141,172),(181,213),(222,254),(261,292),(302,333),(341,375),(381,414),(420,455)]
overrides = {
 ('1.1',4):(188,505,207,533),
 ('1.2',8):(347,534,375,558), ('1.2',9):(389,534,414,558),
 ('1.3',2):(108,559,128,589), ('1.3',3):(143,559,169,589),
 ('1.3',4):(187,559,211,589), ('1.3',5):(226,559,250,589),
 ('1.2',5):(227,534,253,558), ('1.2',6):(266,534,289,558),
 ('2.1',2):(107,602,129,627), ('2.1',3):(141,602,168,627),
 ('2.2',8):(349,628,373,653),
 ('2.3',6):(266,651,292,682), ('2.3',7):(307,651,333,682), ('2.3',8):(347,651,375,682),
 ('3.1',4):(185,719,213,747), ('3.1',5):(224,719,249,747),
 ('4.1',1):(66,815,91,852), ('4.1',2):(102,815,126,852),
 ('4.1',4):(186,815,212,852), ('4.1',5):(222,815,250,852),
}
items, coordinates, thumbnails = [], [], []
duplicates = Counter()
for row, (top, bottom), cards in rows:
    for column, (base_name, aliases, uncertain) in enumerate(cards, 1):
        left, right = columns[column-1]
        box = overrides.get((row,column), (left,top,right,bottom))
        crop = im.crop(box)
        # Exact rectangular crop, uniform scaling and padding; no redrawing or contrast changes.
        crop.thumbnail((112,112), Image.Resampling.LANCZOS)
        scale = min(112/crop.width,112/crop.height)
        scaled = crop.resize((round(crop.width*scale),round(crop.height*scale)),Image.Resampling.LANCZOS)
        card = Image.new('RGB',(128,128),'white')
        card.paste(scaled,((128-scaled.width)//2,(128-scaled.height)//2))
        filename = f'{row.replace(".","-")}-{column:02d}.png'
        card.save(OUTPUT/'assets'/filename)
        duplicates[base_name] += 1
        name = base_name + (f' · рисунок {duplicates[base_name]}' if duplicates[base_name] > 1 else '')
        if uncertain:
            name += ' (уточнить)'
        description = f'Оригинальный рисунок из присланного листа. Строка {row}, позиция {column}. '
        description += ('Название предварительное: маленький рисунок не позволяет уверенно определить точную форму. Уточните его у преподавателя и отредактируйте карточку.' if uncertain else 'Название определено по схематичному изображению; его можно отредактировать.')
        items.append({'source_key':f'reference-sheet:{row}:{column}', 'name':name, 'description':description,
                      'aliases':aliases + [f'Лист {row} поза {column}'], 'image':f'assets/{filename}'})
        coordinates.append({'row':row,'position':column,'bounds':box,'file':filename,'name':name,'needs_review':uncertain})
        thumbnails.append((card,f'{row} / {column}'))

(OUTPUT/'manifest.json').write_text(json.dumps({'source':'Базовая последовательность — исходный лист пользователя','items':items},ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
(OUTPUT/'crops.json').write_text(json.dumps(coordinates,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
contact = Image.new('RGB',(10*145,8*157),'#eff2ed')
draw=ImageDraw.Draw(contact)
for i,(card,label) in enumerate(thumbnails):
    x=(i%10)*145+8; y=(i//10)*157+4
    contact.paste(card,(x,y)); draw.text((x+4,y+132),label,fill='black')
contact.save(OUTPUT/'contact-sheet.png')
print(f'Extracted {len(items)} figures; {sum(c["needs_review"] for c in coordinates)} names explicitly marked for review.')
