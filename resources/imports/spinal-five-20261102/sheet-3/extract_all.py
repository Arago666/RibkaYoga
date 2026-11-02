"""Exact, reproducible crops of the owner's sheets 3–5; no pose generation.

Run with Pillow. Original pixel bounds are recorded in crops.json. The only
image processing is white padding, whitespace trimming and proportional resize.
"""
from pathlib import Path
import json
import shutil
from PIL import Image, ImageDraw, ImageFont, ImageChops

BASE = Path(__file__).resolve().parent.parent
INPUT = Path('/Users/Arago/Downloads/Telegram Desktop')

NAMES = {
 'seat': ('Удобная поза сидя', ['Сукхасана', 'Sukhasana'], False),
 'side': ('Боковой наклон сидя', [], False),
 'staff': ('Поза посоха', ['Дандасана', 'Dandasana'], False),
 'upseat': ('Вытяжение рук вверх сидя', ['Парватасана сидя'], False),
 'kneehug': ('Равновесие сидя с коленями у груди', [], True),
 'boat': ('Поза лодки', ['Навасана', 'Navasana'], False),
 'boatfold': ('Сгибание ног в равновесии сидя', [], True),
 'halfboat': ('Полулодка', ['Ардха Навасана', 'Ardha Navasana'], False),
 'supineknees': ('Лёжа на спине с согнутыми коленями', [], False),
 'supinefeet': ('Лёжа на спине с ногами на полу', [], True),
 'supinewide': ('Лёжа на спине с поднятыми коленями', [], True),
 'legup': ('Подъём одной ноги лёжа', [], False),
 'walllegs': ('Ноги у стены лёжа', [], False),
 'walllegsbent': ('Сгибание ног у стены лёжа', [], False),
 'seatedcurl': ('Равновесие сидя с одной поднятой ногой', [], True),
 'behind': ('Сидя с опорой на руки сзади', [], False),
 'seatedtwist': ('Скручивание сидя с согнутыми ногами', [], True),
 'kneefold': ('Наклон вперёд на коленях', [], True),
 'table': ('Опора на руки и ноги с горизонтальным корпусом', [], True),
 'tablearm': ('Подъём руки на четвереньках', [], True),
 'child': ('Поза ребёнка', ['Баласана', 'Balasana'], False),
 'childside': ('Боковое вытяжение в позе ребёнка', [], True),
 'childblock': ('Поза ребёнка с опорой на блок', [], False),
 'crouch': ('Наклон в приседе', [], True),
 'cow': ('Прогиб на четвереньках', ['Битиласана', 'Bitilasana'], False),
 'cat': ('Округление спины на четвереньках', ['Марджариасана', 'Marjaryasana'], False),
 'cowmove': ('Движение корпуса на четвереньках', [], True),
 'cowtwist': ('Поворот корпуса на четвереньках', [], True),
 'eight': ('Восемь точек опоры', ['Аштанга Намаскара', 'Ashtanga Namaskara'], False),
 'eightblock': ('Восемь точек опоры с блоком', [], False),
 'cobra': ('Собака мордой вверх', ['Урдхва Мукха Шванасана', 'Urdhva Mukha Svanasana'], False),
 'lowcobra': ('Низкая кобра', ['Бхуджангасана', 'Bhujangasana'], False),
 'cobra_block': ('Прогиб лёжа с опорой на блок', [], True),
 'dog': ('Собака мордой вниз', ['Адхо Мукха Шванасана', 'Adho Mukha Svanasana'], False),
 'doghead': ('Собака мордой вниз с опорой на голову', [], True),
 'dogleg': ('Собака мордой вниз с поднятой ногой', [], False),
 'plank': ('Планка', ['Кумбхакасана', 'Phalakasana'], False),
 'lowplank': ('Низкая планка', ['Чатуранга Дандасана', 'Chaturanga Dandasana'], False),
 'plankknee': ('Планка с подтягиванием колена', [], True),
 'plankleg': ('Планка с поднятой ногой', [], False),
 'plankblock': ('Планка с опорой на блок', [], False),
 'plankbend': ('Планка с изменением положения таза', [], True),
 'plankfoot': ('Планка с движением стоп', [], True),
 'bow': ('Прогиб лёжа на животе с поднятыми руками', [], True),
 'bowstraight': ('Прогиб лёжа на животе с поднятой рукой', [], True),
 'bird': ('Баланс на одной ноге с наклоном корпуса', [], True),
 'birdknee': ('Баланс на одной ноге с согнутым коленом', [], True),
 'birdfold': ('Наклон на одной ноге', [], True),
 'fold': ('Наклон вперёд стоя', ['Уттанасана', 'Uttanasana'], False),
 'foldblock': ('Наклон вперёд стоя с опорой на блок', [], False),
 'foldwall': ('Перевёрнутая опора у стены', [], True),
 'wallhead': ('Стойка на голове у стены', [], True),
 'wallheadbend': ('Стойка на голове у стены с изменением ног', [], True),
 'stand': ('Поза горы', ['Тадасана', 'Tadasana'], False),
 'tree': ('Поза дерева', ['Врикшасана', 'Vrksasana'], False),
 'treevariation': ('Баланс стоя с согнутой ногой', [], True),
 'handshead': ('Стоя с руками за головой', [], False),
 'standingfold': ('Наклон корпуса стоя с руками за головой', [], True),
 'eagle': ('Баланс стоя с переплетёнными ногами', ['Гарудасана'], True),
 'warrior3': ('Равновесие с корпусом и ногой горизонтально', ['Вирабхадрасана III', 'Warrior III'], False),
 'warrior3fold': ('Равновесие на одной ноге с наклоном к бедру', [], True),
 'chair': ('Поза стула', ['Уткатасана', 'Utkatasana'], False),
 'chairlean': ('Присед с отклонением корпуса назад', [], True),
 'kneellean': ('Отклонение корпуса назад на коленях', [], True),
 'chairup': ('Поза стула с руками вверх', [], False),
 'squat': ('Глубокий присед', ['Маласана', 'Malasana'], False),
 'squatopen': ('Присед с раскрытием плеч', [], True),
 'squatbalance': ('Равновесие в приседе', [], True),
 'bridge': ('Прогиб назад стоя', [], True),
 'crow': ('Баланс на руках с согнутыми коленями', ['Подготовка к Бакасане'], True),
 'head': ('Стойка на голове', ['Ширшасана', 'Sirsasana'], True),
 'headvariation': ('Стойка на голове с согнутыми ногами', [], True),
 'knees': ('Колени к груди лёжа', ['Апанасана', 'Apanasana'], False),
 'happybaby': ('Движение согнутых ног лёжа на спине', [], True),
 'supineup': ('Лёжа на спине с поднятыми ногами', [], False),
 'plow': ('Поза плуга', ['Халасана', 'Halasana'], False),
 'plowleg': ('Переход в плуг с одной поднятой ногой', [], True),
 'shoulder': ('Стойка на плечах', ['Сарвангасана', 'Sarvangasana'], False),
 'twist': ('Скручивание лёжа на спине', [], True),
 'supine': ('Лёжа на спине с согнутой ногой', [], True),
 'lotus': ('Сидя со скрещёнными ногами', ['Падмасана'], True),
 'rest': ('Поза расслабления лёжа', ['Шавасана', 'Savasana'], False),
 'lungewall': ('Наклон корпуса к стене с опорой на блок, вариант 1', [], True),
 'lungewallblock': ('Наклон корпуса к стене с опорой на блок, вариант 2', [], True),
 'reverse': ('Обратная опора на руки с согнутыми ногами', [], True),
 'seatedarms': ('Сидя с вытянутыми вперёд руками', [], True),
 'seatedhalf': ('Сидя с одной вытянутой ногой', [], True),
 'trianglewall': ('Перевёрнутое положение у стены с блоком, ноги влево', [], True),
 'trianglewall2': ('Перевёрнутое положение у стены с блоком, ноги вправо', [], True),
 'supineblock': ('Поза плуга с блоком', [], True),
 'boatblock': ('Поза лодки с блоком', [], False),
 'standingleg': ('Стоя с одной поднятой ногой', [], True),
}

SHEETS = {}
def sheet(num, title):
    SHEETS[num] = {'sheet': num, 'title': title, 'rows': []}
def row(num, label, y1, y2, items, note=None, groups=None, afters=None, marks=None):
    cells = []
    for i, item in enumerate(items, 1):
        code, x1, x2 = item[:3]
        bounds = [x1, y1, x2, y2] if len(item) == 3 else [x1, item[3], x2, item[4]]
        text = code[1:] if code.startswith('@') else None
        name, aliases, uncertain = (text, [], False) if text else NAMES[code]
        cells.append({'key': f's{num}-r{label.replace(".", "-")}-c{i:02d}', 'bbox': bounds,
                      'name': name, 'aliases': aliases, 'needs_review': uncertain,
                      'after': (afters or {}).get(i, '→' if i < len(items) else None),
                      'mark': (marks or {}).get(i), 'group': (groups or {}).get(i),
                      'kind': 'text' if text else 'asana', 'text': text,
                      'image': None if text else f'assets/{label.replace(".", "-")}-{i:02d}.png'})
    SHEETS[num]['rows'].append({'label': label, 'block': int(label.split('.')[0]), 'cells': cells, 'note': note})
def g(*ranges):
    return {i: f'g{n}' for n, (a,b) in enumerate(ranges,1) for i in range(a,b+1)}

sheet(3, '3 Х/С — Дуга позвоночника. Стена')
row(3,'1.1',90,135,[('seat',98,132),('side',182,231),('staff',262,315),('upseat',354,400)])
row(3,'1.2',137,191,[('boat',96,130),('boatfold',183,216),('halfboat',252,319),('supineknees',347,395),('supinefeet',426,480),('supinewide',520,568),('legup',599,647),('walllegs',692,734),('walllegsbent',777,816),('seatedcurl',865,908),('boat',954,994)],groups=g((8,9),(10,11)),afters={8:'↔',10:'↔'})
row(3,'1.3',201,245,[('behind',85,140),('seatedtwist',176,220),('kneefold',264,307),('table',341,395),('tablearm',429,480),('child',519,568),('childside',599,647),('crouch',688,731),('child',783,827),('table',852,907),('crouch',953,991)],note='Подчёркнуто: 5–6; 7–10',groups=g((7,8)),afters={7:'↔'})
row(3,'1.4',262,306,[('cow',94,141),('child',162,219),('eight',251,325),('lowcobra',330,390),('cow',432,483),('child',507,569),('eight',588,646),('plank',687,743),('plankfoot',763,833),('lowplank',852,909),('@СВ',946,995)],groups=g((1,2),(3,4),(5,7)),afters={1:'↔',3:'↔',5:'=',6:'=',8:'↔'})
row(3,'2.1',340,389,[('dog',91,137),('plank',176,228),('cobra',253,315),('lowcobra',337,399),('bow',430,487),('bowstraight',510,569),('dog',597,649),('doghead',692,734),('plank',766,824)],marks={1:'S',2:'S',3:'S'},groups=g((5,6),(7,9)),afters={5:'↔',7:'=',8:'='})
row(3,'2.2',403,447,[('dog',91,138),('bird',179,226),('birdknee',250,313),('@Табата',340,412),('birdfold',437,490),('birdfold',523,570),('birdfold',608,663),('birdfold',703,763)],note='Подчёркнуто: 1–3. Табата: четыре пронумерованных варианта. Короткие зелёные подписи над первыми двумя рисунками неразборчивы.',afters={4:None,5:None,6:None,7:None})
row(3,'2.3',457,500,[('fold',107,133),('foldwall',175,225),('foldwall',254,307),('@10 Т.Ш.',340,419)],groups=g((2,3)),afters={2:'↔'})
row(3,'3.1',533,584,[('stand',103,127),('tree',184,214),('handshead',270,298),('handshead',330,365),('eagle',437,473),('eagle',527,557),('warrior3',616,662)],groups=g((4,6)),afters={4:'=',5:'='})
row(3,'4.1',630,675,[('chair',106,138),('chairlean',186,220),('kneellean',271,306),('crouch',354,383),('squat',445,475),('bridge',520,563),('crow',609,652),('headvariation',698,730),('crow',777,818)],groups=g((1,3),(5,6),(7,9)),afters={1:'=',2:'=',5:'↔',7:'=',8:'='})
row(3,'4.2',677,731,[('wallhead',107,141),('wallheadbend',184,220)],groups=g((1,2)),afters={1:'↔'})
row(3,'5.1',769,821,[('knees',101,142),('happybaby',179,219),('supineup',251,305),('plow',340,388),('shoulder',442,475),('plow',514,572),('twist',603,643),('supine',691,745)],groups=g((3,4)),afters={3:'↔'})
row(3,'5.2',836,881,[('lotus',100,134),('rest',176,241)])
row(3,'6.1',945,990,[('cow',93,140),('plank',174,229),('eight',258,312),('eight',330,390),('cobra',423,472),('cow',502,559),('dog',594,642)],note='Расшифровка обозначения СВ из нижней части исходного листа. Это справочная связка, а не дополнительный основной блок.')

sheet(4, '4 С/М — Дуга позвоночника. Стена, йога-блоки, носки')
row(4,'1.1',88,138,[('seat',111,148),('side',196,264),('staff',292,356),('upseat',398,452)])
row(4,'1.2',151,200,[('kneehug',109,150),('boat',205,246),('@Табата',294,382),('legup',403,434),('supineknees',490,549),('seatedcurl',592,634),('boatblock',692,736)],groups=g((6,7)),afters={6:'↔'})
row(4,'1.3',215,271,[('behind',104,161),('seatedtwist',199,246),('side',293,340),('seatedtwist',405,443),('behind',493,552),('crouch',590,633),('table',680,735),('reverse',785,841)],groups=g((2,3),(5,6)),afters={2:'↔',5:'↔'})
row(4,'1.4',278,335,[('tablearm',108,157),('childblock',202,247),('cow',301,352),('child',396,449),('plank',491,550),('eight',586,645),('eightblock',679,737),('eightblock',774,836),('plankleg',868,929),('plank',958,1031),('@СВ',1066,1122)],groups=g((1,2),(3,5),(7,8),(9,10)),afters={1:'↔',3:'=',4:'=',7:'↔',9:'↔'})
row(4,'2.1',378,428,[('dog',104,158),('plank',197,260),('cobra',296,362),('lowcobra',389,451),('bow',490,557),('halfboat',587,647),('cobra_block',683,747),('foldblock',790,837)],marks={1:'S',2:'S',3:'S'},groups=g((5,6),(7,8)),afters={5:'↔',7:'↔'},note='Между 5 и 6 в исходнике стоит обозначение «4WD»; значение не расшифровано.')
row(4,'2.2',443,490,[('dog',106,159),('bird',200,254),('birdknee',292,353),('squatbalance',392,447)])
row(4,'2.3',496,554,[('chairup',108,139),('fold',214,247),('@10 Т.Ш.',291,372),('foldwall',408,460),('foldwall',496,551)],marks={1:'S'},groups=g((4,5)),afters={4:'↔'})
row(4,'3.1',592,650,[('stand',112,140),('tree',210,242),('handshead',308,339),('handshead',405,440),('standingfold',499,548),('warrior3fold',597,647),('squatbalance',689,739)],groups=g((4,5),(6,7)),afters={4:'↔',6:'↔'},note='Подчёркнуто: 1–5; 6–7')
row(4,'4.1',696,752,[('squatopen',102,150),('chair',216,254),('chairlean',310,347),('kneellean',406,443),('squat',501,534),('bridge',595,641),('crow',687,742),('crow',790,844),('crow',882,933)],groups=g((2,4),(5,6)),afters={2:'=',3:'=',5:'↔',7:'/',8:'↔'})
row(4,'4.2',769,813,[('headvariation',105,139)])
row(4,'5.1',870,920,[('knees',109,153),('happybaby',205,249),('plow',296,351),('plowleg',394,442),('shoulder',498,535),('plow',583,640),('twist',683,733),('supine',781,841)],groups=g((3,4)),afters={3:'↔'})
row(4,'5.2',932,979,[('lotus',110,143),('rest',194,267)])

sheet(5, '5 С/Б — Дуга позвоночника. Стена, йога-блоки, носки')
row(5,'1.1',87,136,[('seat',108,142),('side',196,259),('staff',280,336),('upseat',365,414),('side',491,530)])
row(5,'1.2',145,198,[('kneehug',101,139),('boat',202,238),('legup',290,323),('supineknees',373,424),('supineknees',471,517),('halfboat',546,616),('supinewide',644,696),('legup',736,792),('supineblock',104,145,205,254),('boatblock',199,236,205,254),('supineblock',284,340,205,254),('boatblock',386,424,205,254),('walllegs',468,519,201,256),('walllegsbent',565,610,201,256)],marks={3:'S'},groups=g((5,6),(9,10),(11,12),(13,14)),afters={5:'↔',9:'↔',10:'/',11:'↔',13:'↔'},note='Строка 1.2 исходного листа продолжается на следующей визуальной строке. Четырнадцать позиций сохранены по порядку.')
row(5,'1.3',268,315,[('behind',103,158),('seatedtwist',205,251),('side',283,340),('kneefold',383,430),('lungewall',470,519),('lungewallblock',561,616),('table',647,704),('reverse',741,792),('crouch',841,889)],marks={4:'S'},groups=g((5,6)),afters={5:'↔'})
row(5,'1.4',327,382,[('cow',103,155),('cow',197,247),('child',280,340),('plank',364,427),('cowmove',469,523),('cowtwist',559,617),('plankblock',649,707),('childblock',738,793),('eightblock',819,890),('@СВ',930,974)],groups=g((2,4),(5,6),(7,9)),afters={2:'=',3:'=',5:'↔',7:'=',8:'='})
row(5,'2.1',432,477,[('dog',102,153),('plank',192,249),('cobra',280,339),('lowcobra',373,435),('bowstraight',459,521),('cobra',557,615),('bowstraight',648,709),('halfboat',737,795)],marks={1:'S',2:'S',3:'S'},groups=g((6,8)),afters={6:'=',7:'↔'})
row(5,'2.2',491,538,[('dog',105,157),('bird',200,252),('birdknee',288,343),('plankknee',358,434),('crow',469,534),('seatedarms',563,609),('squatbalance',659,690),('@СВ',741,784)],groups=g((5,7)),afters={5:'=',6:'/'})
row(5,'2.3',547,597,[('fold',110,140),('fold',206,235),('@10 Т.Ш.',284,353),('plankleg',369,429),('plankleg',458,515),('foldwall',573,612),('trianglewall',653,710),('trianglewall2',746,798)],groups=g((4,5),(7,8)),afters={4:'↔',6:'/',7:'↔'})
row(5,'3.1',637,688,[('stand',113,139),('treevariation',206,240),('handshead',301,333),('standingleg',396,437),('squatbalance',472,522),('seatedhalf',566,621),('squatbalance',663,699),('standingleg',746,793)],groups=g((4,8)),afters={4:'=',5:'=',6:'=',7:'='})
row(5,'4.1',714,782,[('squat',103,139),('chair',203,241),('chairlean',293,333),('kneellean',388,427),('squat',483,514),('bridge',575,619),('crow',655,705),('plank',751,811),('plankleg',849,884),('birdfold',936,994)],groups=g((2,4),(8,10)),afters={2:'=',3:'=',8:'=',9:'='})
row(5,'4.2',785,842,[('plank',101,162),('cobra',196,254),('head',297,328)],groups=g((1,3)),afters={1:'=',2:'='})
row(5,'5.1',895,945,[('knees',106,151),('happybaby',200,246),('plow',283,339),('plowleg',391,425),('boat',472,515)],groups=g((4,5)),afters={4:'↔'})
row(5,'5.2',956,998,[('lotus',109,143),('rest',190,266)])

# Final manual QA adjustments. Bounds exclude neighbouring brackets/annotations;
# isolated S letters are masked only where their rectangle overlaps the crop.
FIXES = {
 3: {
  ('1.2',3): [252,151,319,183],
  ('1.3',7): [607,213,647,240],
  ('1.4',3): [255,273,313,298],
  ('1.4',8): [687,281,743,298],
  ('1.4',9): [763,274,827,301],
  ('2.1',2): [176,365,228,385],
  ('2.1',3): [253,365,315,385],
  ('2.1',5): [434,348,487,385],
  ('2.1',6): [510,348,565,385],
  ('2.1',7): [603,351,649,385],
  ('2.1',9): [766,355,821,385],
  ('2.2',5): [437,416,490,441],
  ('2.2',6): [523,417,570,441],
  ('3.1',4): [352,531,386,584],
  ('5.1',3): [258,776,305,815],
 },
 4: {
  ('1.4',7): [679,297,745,324],
  ('1.4',8): [759,298,826,325],
  ('1.4',9): [896,293,938,325],
  ('2.1',2): [197,401,260,423],
  ('2.1',3): [296,400,362,423],
  ('4.1',6): [585,705,641,744],
 },
 5: {
  ('1.2',3): [290,145,318,193],
  ('1.3',4): [383,282,430,311],
  ('1.4',7): [649,338,712,373],
  ('1.4',8): [734,345,794,373],
  ('1.4',9): [831,344,890,374],
  ('2.1',2): [192,444,249,470],
  ('2.1',3): [280,444,344,470],
  ('2.1',5): [459,428,528,473],
  ('2.1',6): [562,440,615,471],
  ('2.1',7): [648,428,716,473],
  ('2.3',4): [376,554,429,597],
  ('3.1',3): [294,627,333,680],
  ('4.1',6): [567,737,619,774],
  ('4.1',8): [752,738,811,774],
  ('4.1',10): [936,738,987,775],
 },
}
EXCLUDES = {
  (3,'2.1',1): [[91,340,103,370]],
  (3,'2.1',2): [[176,340,197,369]],
  (3,'1.4',3): [[255,294,260,298]],
  (3,'1.4',9): [[763,274,773,284]],
  (4,'2.1',1): [[104,378,114,401]],
  (4,'1.4',8): [[759,297,768,307]],
  (5,'2.1',1): [[102,432,113,449]],
  (5,'2.1',2): [[192,432,218,449]],
  (5,'2.2',4): [[358,490,369,514]],
}
FIXES[3].update({
 ('1.3',5): [429,201,480,242], ('1.3',7): [605,211,650,243],
 ('1.3',8): [688,201,734,245], ('1.3',9): [780,201,827,243],
 ('1.4',2): [162,262,222,306], ('1.4',3): [255,273,316,301],
 ('1.4',5): [432,262,486,306], ('1.4',8): [687,278,743,301],
 ('2.1',2): [176,361,228,385], ('2.1',3): [253,361,315,385],
 ('2.1',5): [431,348,490,385], ('2.1',6): [510,348,568,385],
 ('4.2',1): [107,674,141,731], ('5.1',5): [442,765,475,821],
})
FIXES[4].update({
 ('1.3',2): [199,215,249,271], ('1.3',4): [402,215,443,271],
 ('1.4',5): [483,278,553,335], ('1.4',6): [586,278,648,335],
 ('1.4',7): [679,297,748,324], ('1.4',8): [759,298,830,325],
 ('1.4',9): [893,293,938,325],
 ('2.1',2): [197,398,260,423], ('2.1',3): [293,397,362,423],
 ('2.1',6): [577,378,649,428], ('2.2',1): [103,443,159,490],
 ('2.2',2): [200,443,254,493], ('2.3',1): [105,496,139,554],
 ('2.3',4): [408,493,460,554], ('2.3',5): [493,493,554,554],
 ('4.1',9): [879,696,933,752], ('5.1',5): [498,867,535,920],
 ('5.1',8): [778,870,841,920],
})
FIXES[5].update({
 ('1.1',5): [488,87,530,136], ('1.2',7): [644,145,699,198],
 ('1.2',13): [468,198,519,256], ('1.2',14): [565,198,610,256],
 ('1.3',2): [202,268,251,315], ('1.3',5): [468,268,523,315],
 ('1.4',1): [100,327,155,382], ('1.4',3): [277,327,340,382],
 ('1.4',4): [364,327,430,382], ('1.4',5): [466,327,523,382],
 ('1.4',8): [734,345,805,373],
 ('2.1',2): [185,441,249,470], ('2.1',3): [280,441,344,470],
 ('2.1',6): [562,440,626,471], ('2.1',7): [645,428,716,473],
 ('2.2',1): [102,491,157,538], ('2.2',2): [197,491,252,538],
 ('2.2',3): [288,488,343,538], ('2.2',7): [659,488,690,538],
 ('2.3',4): [376,554,435,597], ('2.3',5): [458,547,518,600],
 ('3.1',2): [206,634,240,688], ('3.1',4): [396,634,437,688],
 ('4.1',8): [752,738,811,774], ('4.1',10): [922,738,987,775],
 ('4.2',1): [98,785,162,842],
})
EXCLUDES.update({
 (3,'1.3',5): [[429,240,433,245]],
 (3,'1.4',3): [[255,294,260,302]],
 (3,'1.4',8): [[687,297,693,302]],
 (3,'2.1',3): [[253,340,292,368]],
 (3,'2.1',5): [[431,348,435,353]],
 (4,'2.1',2): [[197,378,219,402]],
 (4,'2.1',3): [[293,378,327,403]],
 (4,'1.4',5): [[549,278,553,335],[545,278,553,294],[545,321,553,335]],
 (4,'2.1',6): [[645,378,650,428],[642,378,650,391],[642,421,650,428],[577,378,581,400]],
 (4,'2.3',5): [[550,493,555,554]],
 (4,'2.3',1): [[105,496,111,542]],
 (5,'1.2',6): [[546,145,551,168]],
 (5,'1.3',4): [[411,268,430,286]],
 (5,'2.1',2): [[185,432,218,449]],
 (5,'2.1',3): [[280,432,312,446]],
})
for n,data in SHEETS.items():
    for r in data['rows']:
        for i,c in enumerate(r['cells'],1):
            if (r['label'],i) in FIXES.get(n,{}):
                c['bbox'] = FIXES[n][(r['label'],i)]
            if (n,r['label'],i) in EXCLUDES:
                c['excluded_annotation_rectangles'] = EXCLUDES[(n,r['label'],i)]
            if (n,r['label'],i) in [(4,'1.2',4),(5,'1.2',3)]:
                c['name'], c['aliases'], c['needs_review'] = NAMES['seatedcurl']
            if r['label'] == '2.1' and i == 3:
                c['name'], c['aliases'], c['needs_review'] = 'Подъём корпуса и рук лёжа на животе', [], True
            adjusted_names = {
                (3,'6.1',3): 'Вытяжение в упоре на колени и руки',
                (3,'1.4',4): 'Волнообразное вытяжение в упоре с опущенной головой',
                (4,'1.4',10): 'Низкий упор на руки и стопы',
                (5,'1.4',8): 'Наклонный упор с опорой на блок',
                (4,'1.3',3): 'Движение корпуса сидя с согнутыми ногами',
                (5,'1.3',3): 'Круговое движение корпуса сидя',
                (5,'2.1',6): 'Подъём корпуса и рук лёжа на животе',
                (5,'4.1',9): 'Перевёрнутая опора с одной ногой вверх',
                (3,'6.1',6): 'Наклонный упор с округлением корпуса',
            }
            if (n,r['label'],i) in adjusted_names:
                c['name'],c['aliases'],c['needs_review'] = adjusted_names[(n,r['label'],i)],[],True

FONT = ImageFont.truetype('/System/Library/Fonts/Supplemental/Arial.ttf', 14)
SMALL = ImageFont.truetype('/System/Library/Fonts/Supplemental/Arial.ttf', 11)
for num, data in SHEETS.items():
    dest = BASE / f'sheet-{num}'
    (dest/'assets').mkdir(parents=True, exist_ok=True)
    shutil.copyfile(INPUT/f'1_{num}.jpg', dest/'source.jpg')
    source = Image.open(dest/'source.jpg').convert('RGB')
    data['source_size'] = list(source.size)
    data['source'] = 'source.jpg'
    data['processing'] = 'Original rectangular crop, white whitespace trim, proportional Lanczos resize on white 512×512. Isolated annotations excluded in explicitly recorded rectangles. No redrawing or generation.'
    cells = []
    for r in data['rows']:
        for cell in r['cells']:
            if cell['kind'] == 'text':
                continue
            crop = source.crop(cell['bbox'])
            for x1,y1,x2,y2 in cell.get('excluded_annotation_rectangles',[]):
                ImageDraw.Draw(crop).rectangle((x1-cell['bbox'][0],y1-cell['bbox'][1],x2-cell['bbox'][0],y2-cell['bbox'][1]),fill='white')
            # Pixel threshold affects whitespace detection only; keep original pixels unchanged.
            ink = crop.convert('L').point(lambda v: 255 if v < 215 else 0)
            bounds = ink.getbbox()
            if bounds:
                left,top,right,bottom = bounds
                crop = crop.crop((max(0,left-1),max(0,top-1),min(crop.width,right+1),min(crop.height,bottom+1)))
            crop.thumbnail((416,416), Image.Resampling.LANCZOS)
            # thumbnail does not enlarge, so explicitly scale the small source crop.
            scale = min(416/crop.width,416/crop.height)
            crop = crop.resize((round(crop.width*scale),round(crop.height*scale)),Image.Resampling.LANCZOS)
            card = Image.new('RGB',(512,512),'white')
            card.paste(crop,((512-crop.width)//2,(512-crop.height)//2))
            card.save(dest/cell['image'])
            cells.append(cell)
    (dest/'crops.json').write_text(json.dumps(data,ensure_ascii=False,indent=2)+'\n')
    for page,start in enumerate(range(0,len(cells),48),1):
        subset = cells[start:start+48]
        width, height = 1200, ((len(subset)+7)//8)*160
        contact = Image.new('RGB',(width,height),'#eef1ee')
        draw = ImageDraw.Draw(contact)
        for i,cell in enumerate(subset):
            x,y = (i%8)*150,(i//8)*160
            thumb = Image.open(dest/cell['image']).resize((118,118),Image.Resampling.LANCZOS)
            contact.paste(thumb,(x+16,y+2))
            draw.text((x+5,y+122),cell['key'],fill='black',font=SMALL)
            title=cell['name']
            for line in range(2):
                draw.text((x+5,y+136+line*11),title[line*20:(line+1)*20],fill='#314238',font=SMALL)
        contact.save(dest/f'contact-sheet-{page}.jpg',quality=94)
    print(num, sum(c['kind']=='asana' for r in data['rows'] for c in r['cells']), 'figures,', len(data['rows']), 'rows')
