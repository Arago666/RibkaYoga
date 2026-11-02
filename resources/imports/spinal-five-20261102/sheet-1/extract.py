"""Reproduce exact crops of the owner's two source sheets. Requires Pillow.

No pose generation, tracing, geometry replacement, or colour recolouring is used.
Bounding boxes are manually reviewed against the supplied JPG files.
"""
from pathlib import Path
import json
import shutil
from PIL import Image, ImageDraw, ImageFont

PACK = Path(__file__).resolve().parent.parent
SOURCE = Path('/Users/Arago/Downloads/Telegram Desktop')

def a(name, box, after='→', group=None, mark=None, review=False, aliases=None, masks=None):
    return dict(name=name, bbox=list(box), after=after, group=group, mark=mark,
                needs_review=review, aliases=aliases or [], kind='asana', text=None,
                exclude_rectangles=masks or [])

def t(text, box=None, after=None, mark=None):
    return dict(name=text,bbox=list(box) if box else None,after=after,group=None,mark=mark,
                needs_review=False,aliases=[],kind='text',text=text,exclude_rectangles=[])

def row(label, cells, note=None):
    return dict(label=label, block=int(label[0]) if label[0].isdigit() else 6, cells=cells, note=note)

S1 = [
row('1.1',[
 a('Сукхасана',(125,157,161,206),aliases=['Удобная поза','Sukhasana']),
 a('Боковой наклон сидя',(231,171,295,210),review=True),
 a('Дандасана',(327,157,397,205),aliases=['Поза посоха','Dandasana']),
 a('Вытяжение рук вверх сидя',(452,151,491,208),None,aliases=['Парватасана сидя'],review=True),
]),
row('1.2',[
 a('Навасана',(124,237,167,280),aliases=['Поза лодки','Navasana']),
 a('Навасана с движением согнутых ног',(235,235,284,281),review=True),
 a('Ардха Навасана',(333,248,408,284),aliases=['Поза полулодки','Ardha Navasana']),
 a('Лёжа с согнутыми коленями',(440,249,495,286),review=True),
 a('Лодка с поднятой ногой',(561,235,603,284),'↔','g1',review=True),
 a('Лодка с согнутыми коленями',(666,234,712,283),None,'g1',review=True),
]),
row('1.3',[
 a('Сидя с опорой на руки и согнутыми ногами',(116,311,172,354),review=True),
 a('Движение корпуса сидя с согнутыми ногами',(229,311,285,356),review=True),
 a('Наклон вперёд сидя',(343,326,389,354),review=True),
 a('Наклон корпуса из положения сидя на пятках',(459,307,500,353),'↔','g1',review=True),
 a('Наклон на коленях с рукой за спиной',(560,309,602,354),'→','g1',review=True),
 a('Сгибание корпуса на коленях',(672,325,724,354),'↔','g2',review=True),
 a('Наклон на коленях с округлением спины',(775,311,820,355),'→','g2',review=True),
 a('Обратный стол с движением таза',(880,307,946,355),None,review=True),
]),
row('1.4',[
 a('Ардха Пурвоттанасана',(118,382,177,426),'↔','g1',aliases=['Обратный стол'],review=True),
 a('Положение на четвереньках',(224,382,279,425),'→','g1',aliases=['Бхарманасана','Поза стола']),
 a('Движение позвоночника на четвереньках',(333,380,396,426),review=True),
 a('Баласана с руками вперёд',(445,396,505,426),'=','g2',aliases=['Поза ребёнка','Balasana']),
 a('Положение на четвереньках',(552,385,611,426),'=','g2',aliases=['Бхарманасана','Поза стола']),
 a('Вытяжение из положения на четвереньках',(653,396,725,426),'→','g2',review=True),
 a('Вытяжение из положения на четвереньках',(765,398,840,427),'↔','g3',review=True),
 a('Аштанга Намаскара',(873,397,941,427),None,'g3',aliases=['Восемь точек опоры','Ashtanga Namaskara']),
]),
row('1.5',[
 a('Планка',(108,457,181,500),aliases=['Пхалакасана','Phalakasana']),
 a('Низкая планка',(218,469,293,499),review=True,aliases=['Чатуранга Дандасана']),
 a('Аштанга Намаскара',(328,469,402,500),aliases=['Восемь точек опоры','Ashtanga Namaskara']),
 a('Прогиб с коленями на полу',(437,457,509,500),review=True),
 a('Урдхва Мукха Шванасана',(551,449,623,500),aliases=['Собака мордой вверх','Urdhva Mukha Svanasana']),
 a('Переход в планку с округлением спины',(647,457,726,500),review=True,masks=[[647,466,655,480]]),
 a('Адхо Мукха Шванасана',(770,456,835,501),aliases=['Собака мордой вниз','Adho Mukha Svanasana']),
 t('СВ',(849,443,946,506)),
]),
row('2.1',[
 a('Адхо Мукха Шванасана',(120,569,178,607),mark='S',aliases=['Собака мордой вниз','Adho Mukha Svanasana'],masks=[[112,562,132,580],[112,581,127,584]]),
 a('Планка',(226,578,296,609),mark='S',aliases=['Пхалакасана','Phalakasana'],masks=[[235,558,256,584]]),
 a('Шалабхасана',(317,578,398,610),mark='S',aliases=['Поза саранчи','Salabhasana'],masks=[[347,559,367,584]]),
 a('Бхуджангасана',(423,577,506,610),aliases=['Поза кобры','Bhujangasana']),
 a('Шалабхасана с рукой вверх',(550,558,625,612),'↔','g1',review=True),
 a('Шалабхасана с руками вдоль тела',(648,575,720,612),'→','g1',aliases=['Поза саранчи']),
 t('СВ',(781,559,820,610)),
]),
row('2.2',[
 a('Адхо Мукха Шванасана',(111,639,179,682),aliases=['Собака мордой вниз','Adho Mukha Svanasana']),
 a('Переход из собаки мордой вниз в планку',(223,639,296,683),review=True),
 a('Адхо Мукха Шванасана с поднятой ногой',(333,619,395,682),aliases=['Трёхногая собака','Эка Пада Адхо Мукха Шванасана']),
 a('Выпад с наклоном корпуса',(442,630,505,684),review=True),
 a('Выпад с разворотом корпуса',(550,631,625,683),review=True),
 a('Выпад с рукой вверх',(645,620,726,684),review=True,masks=[[645,649,660,664]]),
 a('Вирабхадрасана I',(774,617,843,685),aliases=['Поза воина I','Virabhadrasana I'],review=True),
 a('Баласана',(879,652,942,685),None,aliases=['Поза ребёнка','Balasana']),
]),
row('2.3',[
 a('Наклон стоя с вытяжением рук',(128,699,163,750),review=True),
 t('10 Т.Ш.',(219,700,302,752)),
]),
row('3.1',[
 a('Тадасана',(134,798,159,860),aliases=['Поза горы','Tadasana']),
 a('Врикшасана',(240,795,273,860),aliases=['Поза дерева','Vrksasana']),
 a('Руки за спиной стоя',(342,795,379,861),review=True,aliases=['Руки Гомукхасаны']),
 a('Руки за спиной стоя',(453,795,492,861),'↔','g1',review=True,aliases=['Руки Гомукхасаны']),
 a('Присед с перекрёстом ног и рукой за спиной',(566,796,606,862),None,'g1',review=True),
]),
row('3.2',[
 a('Удержание ноги перед собой стоя',(134,878,173,937),review=True,aliases=['Уттхита Хаста Падангуштхасана']),
 a('Удержание ноги в сторону стоя',(220,877,283,937),review=True),
 a('Удержание ноги в сторону стоя — затемнённый вариант',(330,877,394,937),review=True),
 a('Наклон корпуса стоя под прямым углом',(448,885,512,938),None,review=True,aliases=['Ардха Уттанасана с руками вперёд']),
]),
row('4.1',[
 a('Уткатасана с руками вперёд',(129,994,174,1045),'=','g1',aliases=['Поза стула','Utkatasana']),
 a('Отклонение назад стоя на коленях',(230,994,269,1045),'=','g1',review=True),
 a('Отклонение назад с опорой на одно колено',(344,994,389,1046),'→','g1',review=True),
 a('Наклон вперёд на коленях с движением корпуса',(449,1003,514,1047),'/',review=True),
 a('Наклон вперёд на коленях с поворотом корпуса',(559,1002,616,1047),None,review=True),
]),
row('4.2',[
 a('Ширшасана у стены',(132,1048,174,1118),'↔','g1',aliases=['Стойка на голове у стены','Sirsasana']),
 a('Стойка у стены с опорой на руки',(231,1048,270,1118),None,'g1',review=True),
]),
row('5.1',[
 a('Апанасана',(133,1190,173,1227),aliases=['Колени к груди','Apanasana']),
 a('Положение лёжа с ногами за головой',(231,1186,279,1229),review=True),
 a('Сарвангасана',(349,1165,388,1231),aliases=['Стойка на плечах','Sarvangasana']),
 a('Халасана',(434,1185,502,1231),aliases=['Поза плуга','Halasana']),
 a('Сукхасана',(571,1174,612,1231),aliases=['Удобная поза','Sukhasana']),
 a('Шавасана',(666,1195,735,1231),None,aliases=['Поза расслабления','Savasana']),
]),
]

S2 = [
row('1.1',[
 a('Сукхасана',(108,98,138,144),aliases=['Удобная поза','Sukhasana']),
 a('Боковой наклон сидя',(194,115,250,147),review=True),
 a('Дандасана',(275,98,341,145),aliases=['Поза посоха','Dandasana']),
 a('Сидя со скрещёнными ногами и рукой над головой',(386,97,435,145),review=True),
 a('Вытяжение рук вверх сидя',(476,94,523,145),None,review=True,aliases=['Парватасана сидя']),
]),
row('1.2',[
 a('Навасана',(103,163,139,202),aliases=['Поза лодки','Navasana']),
 a('Навасана с движением ног',(190,159,247,204),review=True),
 a('Навасана с поднятой прямой ногой',(287,152,322,205),review=True),
 a('Лёжа с согнутыми коленями',(374,172,429,207),review=True),
 a('Лёжа с коленями к груди',(476,172,520,205),'↔','g1',aliases=['Апанасана']),
 a('Подтягивание колена лёжа с вытянутой второй ногой',(542,172,614,207),'→','g1',review=True),
 a('Лодка с поднятой ногой',(680,161,715,206),'↔','g2',review=True),
 a('Лодка с согнутыми коленями',(772,161,809,206),None,'g2',review=True),
]),
row('1.3',[
 a('Сидя с опорой на руки и согнутыми ногами',(99,229,152,269),review=True),
 a('Подъём таза сидя с согнутыми ногами',(189,229,245,270),review=True),
 a('Движение корпуса сидя с согнутыми ногами',(285,230,341,270),review=True),
 a('Наклон вперёд сидя',(378,240,435,269),review=True),
 a('Наклон на коленях с округлением спины',(474,230,514,269),review=True),
 a('Опора на руки и ноги с горизонтальным корпусом и опорой стоп',(557,228,610,270),'/','g1',review=True),
 a('Пурвоттанасана с опорой стоп',(642,228,712,270),'=','g1',review=True,aliases=['Обратная планка']),
 a('Положение на четвереньках с опорой коленей',(742,233,800,270),None,'g1',review=True),
], 'Подчёркнуто: 2–4; 5'),
row('1.4',[
 a('Положение на четвереньках с опорой ладоней',(101,293,151,330),'↔','g1',review=True),
 a('Баласана с опорой головы на блок',(186,298,243,331),'/','g1',review=True,aliases=['Поза ребёнка с блоком']),
 a('Адхо Мукха Шванасана с опорой головы',(286,296,341,332),'↔','g2',review=True),
 a('Наклон стоя с опорой головы на блок',(372,287,424,332),'→','g2',review=True),
 a('Аштанга Намаскара',(462,305,535,332),'↔','g3',aliases=['Восемь точек опоры','Ashtanga Namaskara']),
 a('Волнообразный переход на четвереньках',(551,305,612,333),'→','g3',review=True),
 a('Планка',(646,287,713,333),'=','g4',aliases=['Пхалакасана','Phalakasana']),
 a('Низкая планка',(735,300,803,332),'=','g4',review=True,aliases=['Чатуранга Дандасана']),
 a('Бхуджангасана — низкий вариант',(824,302,895,331),'→','g4',review=True,aliases=['Низкая кобра']),
 t('СВ',(943,282,989,332),mark='★'),
]),
row('2.1',[
 a('Адхо Мукха Шванасана',(94,386,148,422),mark='S',aliases=['Собака мордой вниз','Adho Mukha Svanasana'],masks=[[88,376,106,399]]),
 a('Планка с движением корпуса',(175,389,259,424),mark='S',review=True,masks=[[195,377,212,398]]),
 a('Шалабхасана',(272,396,339,425),mark='S',aliases=['Поза саранчи','Salabhasana'],masks=[[296,377,314,400]]),
 a('Бхуджангасана',(364,395,433,425),aliases=['Поза кобры','Bhujangasana']),
 a('Шалабхасана с согнутой ногой и рукой вверх',(469,379,537,426),'↔','g1',review=True,masks=[[469,379,476,406],[469,417,476,426]]),
 a('Шалабхасана с руками вдоль тела',(551,397,614,425),None,'g1',aliases=['Поза саранчи']),
]),
row('2.2',[
 a('Адхо Мукха Шванасана',(93,442,148,480),aliases=['Собака мордой вниз','Adho Mukha Svanasana']),
 a('Переход из собаки мордой вниз в планку',(181,441,252,481),review=True),
 a('Адхо Мукха Шванасана с поднятой ногой',(281,421,338,481),aliases=['Трёхногая собака','Эка Пада Адхо Мукха Шванасана']),
 a('Выпад с наклоном корпуса',(375,442,429,479),review=True),
 a('Выпад с разворотом корпуса',(453,431,530,478),review=True),
 a('Планка с опорой стоп',(554,444,617,478),'↔','g1',review=True),
 a('Подведение колена к груди в упоре',(650,442,693,478),None,'g1',review=True),
], 'Подчёркнуто: 1–5'),
row('2.3',[
 a('Наклон стоя с вытяжением рук',(112,500,141,544),review=True),
 a('Уттанасана',(204,498,231,544),aliases=['Наклон стоя','Uttanasana']),
 a('Наклон стоя с блоком под головой',(299,503,345,545),'↔','g1',review=True),
 a('Наклон стоя с согнутыми коленями и блоком',(370,504,423,544),'→','g1',review=True),
 a('Наклон стоя спиной к стене',(477,484,513,545),None,review=True),
]),
row('3.1',[
 a('Тадасана',(109,581,135,638),aliases=['Поза горы','Tadasana']),
 a('Врикшасана',(201,577,230,638),aliases=['Поза дерева','Vrksasana']),
 a('Руки за спиной стоя',(289,577,323,639),review=True,aliases=['Руки Гомукхасаны']),
 a('Удержание ноги перед собой стоя',(391,581,424,638),'=','g1',review=True,aliases=['Уттхита Хаста Падангуштхасана']),
 a('Присед на одной ноге с наклоном корпуса',(473,589,510,639),'=','g1',review=True),
 a('Баланс сидя с одной вытянутой ногой',(563,589,606,638),'→','g1',review=True),
 a('Наклон корпуса стоя под прямым углом',(644,591,708,639),None,review=True,aliases=['Ардха Уттанасана с руками вперёд']),
]),
row('4.1',[
 a('Отклонение назад стоя на коленях',(103,688,146,734),'↔','g1',review=True),
 a('Уткатасана с руками вперёд',(189,688,224,733),'→','g1',aliases=['Поза стула','Utkatasana']),
 a('Отклонение корпуса назад с опорой рукой',(284,688,334,735),'↔','g2',review=True),
 a('Подъём корпуса из положения на коленях',(381,688,418,730),'→','g2',review=True),
 a('Наклон вперёд на коленях с движением корпуса',(472,687,533,735),None,review=True),
]),
row('4.2',[
 a('Адхо Мукха Шванасана на предплечьях',(96,749,142,792),'/',aliases=['Поза дельфина'],review=True),
 a('Бхуджангасана',(190,755,255,793),'=','g1',aliases=['Поза кобры','Bhujangasana']),
 a('Ширшасана',(297,729,323,793),'=','g1',aliases=['Стойка на голове','Sirsasana']),
 a('Стойка на предплечьях',(381,727,414,794),None,'g1','★',review=True,aliases=['Пинча Маюрасана'],masks=[[399,727,414,745]]),
]),
row('5.1',[
 a('Апанасана',(105,852,145,885),aliases=['Колени к груди','Apanasana']),
 a('Скручивание лёжа с согнутой ногой',(193,846,244,888),review=True),
 a('Лёжа с согнутыми коленями',(286,847,331,885),review=True),
 a('Ноги вверх лёжа',(383,836,438,885),'↔','g1',aliases=['Урдхва Прасарита Падасана'],review=True,masks=[[380,879,388,886]]),
 a('Положение лёжа с ногами за головой',(477,852,524,884),'→','g1',review=True),
 a('Сарвангасана',(584,824,617,885),aliases=['Стойка на плечах','Sarvangasana']),
 a('Халасана',(662,854,722,887),aliases=['Поза плуга','Halasana']),
 a('Скручивание лёжа с согнутыми ногами',(759,853,813,885),None,review=True),
]),
row('5.2',[
 a('Сукхасана',(107,903,141,947),aliases=['Удобная поза','Sukhasana']),
 a('Шавасана',(190,916,261,947),None,aliases=['Поза расслабления','Savasana']),
]),
row('СВ',[
 a('Положение на четвереньках',(94,990,146,1028),aliases=['Бхарманасана','Поза стола']),
 a('Планка',(179,989,242,1030),aliases=['Пхалакасана','Phalakasana']),
 a('Вытяжение из положения на четвереньках',(269,994,337,1030),review=True),
 a('Аштанга Намаскара',(356,998,419,1030),aliases=['Восемь точек опоры','Ashtanga Namaskara']),
 a('Урдхва Мукха Шванасана',(442,982,508,1030),aliases=['Собака мордой вверх','Urdhva Mukha Svanasana']),
 a('Переход в планку с округлением спины',(530,989,601,1030),review=True,masks=[[530,995,542,1008]]),
 a('Адхо Мукха Шванасана',(629,990,688,1030),None,aliases=['Собака мордой вниз','Adho Mukha Svanasana']),
], 'Расшифровка обозначения СВ на исходном листе'),
]

def render(n, rows, title):
    directory=PACK/f'sheet-{n}'
    directory.mkdir(parents=True,exist_ok=True)
    (directory/'assets').mkdir(exist_ok=True)
    source=SOURCE/f'1_{n}.jpg'
    shutil.copyfile(source,directory/'source.jpg')
    im=Image.open(source).convert('RGB')
    cards=[]
    for r in rows:
        for index,c in enumerate(r['cells'],1):
            slug=r['label'].replace('.','-') if r['label']!='СВ' else 'sv'
            c['key']=f's{n}-r{slug}-c{index:02}'
            if c['kind']!='asana':
                c['image']=None
                continue
            crop=im.crop(c['bbox'])
            for rect in c['exclude_rectangles']:
                l,t,_,_=c['bbox']
                ImageDraw.Draw(crop).rectangle([rect[0]-l,rect[1]-t,rect[2]-l,rect[3]-t],fill='white')
            # Only find margins; preserve original, antialiased pixels in the actual crop.
            mask=crop.convert('L').point(lambda p:255 if p<215 else 0)
            extent=mask.getbbox()
            if not extent:raise RuntimeError(c['key'])
            x0,y0,x1,y1=extent
            extent=(max(0,x0-2),max(0,y0-2),min(crop.width,x1+2),min(crop.height,y1+2))
            crop=crop.crop(extent)
            factor=min(424/crop.width,424/crop.height)
            size=(round(crop.width*factor),round(crop.height*factor))
            out=Image.new('RGB',(512,512),'white')
            out.paste(crop.resize(size,Image.Resampling.LANCZOS),((512-size[0])//2,(512-size[1])//2))
            c['image']=f'assets/{slug}-{index:02}.png'
            out.save(directory/c['image'])
            cards.append((r['label'],index,c,out))
    metadata=dict(sheet=n,title=title,source_size=list(im.size),rows=rows,
        method='Original JPG pixels, manually chosen rectangles, white margins, proportional Lanczos enlargement; no redrawing.',
        naming_note='Names are visual descriptions. needs_review marks ambiguous symbolic drawings; no teacher-provided pose-name key was included.')
    (directory/'crops.json').write_text(json.dumps(metadata,ensure_ascii=False,indent=2)+'\n')
    fontpath='/System/Library/Fonts/Supplemental/Arial.ttf'
    font=ImageFont.truetype(fontpath,17)
    small=ImageFont.truetype(fontpath,14)
    for page in range((len(cards)+34)//35):
        subset=cards[page*35:(page+1)*35]
        grid=Image.new('RGB',(1400,((len(subset)+6)//7)*202+44),'#eeeeee')
        d=ImageDraw.Draw(grid)
        d.text((15,10),f'{title} · page {page+1}',font=font,fill='black')
        for i,(label,idx,c,card) in enumerate(subset):
            x=(i%7)*200;y=(i//7)*202+44
            grid.paste(card.resize((160,160),Image.Resampling.LANCZOS),(x+20,y+2))
            d.text((x+8,y+163),f'{label}/{idx:02}'+(' ?' if c['needs_review'] else ''),font=font,fill='black')
            txt=c['name']
            while d.textlength(txt,font=small)>186:txt=txt[:-2]
            d.text((x+8,y+184),txt,font=small,fill='black')
        grid.save(directory/f'contact-sheet-{page+1}.jpg',quality=94)
    print(n, 'asana occurrences:',len(cards), 'rows:',len(rows), 'text cells:',sum(c['kind']=='text' for r in rows for c in r['cells']))

if __name__=='__main__':
    render(1,S1,'1 Х/М — Дуга позвоночника')
    render(2,S2,'2 Х/Б — Дуга позвоночника. Носки')
