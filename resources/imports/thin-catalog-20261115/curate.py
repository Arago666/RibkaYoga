"""Pose-specific editorial plan. Descriptive sheet variants are never assigned invented Sanskrit names."""
from pathlib import Path
import json,gzip,re
ROOT=Path(__file__).resolve().parents[3];OUT=ROOT/'.runtime/local-redesign-20261115'
data=json.loads(gzip.decompress((OUT/'production.json.gz').read_bytes()))['tables']
asanas={a['id']:a for a in data['catalog_asanas']};art=json.loads((OUT/'artwork/manifest.json').read_text())
icons=json.loads((ROOT/'resources/imports/asana-icons-200-20261110/catalog.json').read_text());iconBy={r['number']:r for r in icons}
# Explicit identity mapping for recognizable sheet figures. Other titles remain descriptive.
OLD={1:'downdog',2:'dolphin',3:'downdog',4:'downdog',5:'kneehug',6:'half-boat',7:'half-boat',8:'half-boat',9:'reverse-table',10:'chaturanga',12:'crow',13:'crow',14:'crow',15:'standing-kneehug',16:'child',17:'child',18:'child',19:'easyseat',20:'side-plank',21:'warrior1',22:'table',23:'tree',24:'table',25:'easyseat',26:'half-forwardfold',27:'puppy',28:'squat',29:'squat',30:'table',31:'supine',32:'easyseat',33:'constructive-rest',34:'supine',35:'supine',36:'supine',37:'supine',38:'supine',39:'boat',40:'boat',41:'boat',42:'boat',43:'squat',44:'squat',45:'kneeling',46:'easyseat',47:'standing-handtoe',48:'half-forwardfold',49:'half-forwardfold',50:'half-forwardfold',51:'forwardfold',52:'side-plank',53:'side-plank',54:'easyseat',55:'half-forwardfold',56:'half-forwardfold',57:'puppy',58:'kneeling',59:'forwardfold',60:'forwardfold',61:'forwardfold',62:'chaturanga',63:'chaturanga',64:'sphinx',65:'cobra',66:'puppy',67:'forearm-plank',68:'legsup',69:'legsup',70:'reverse-table',71:'reverse-table',72:'reverse-table',73:'side-plank',74:'kneeling',75:'kneeling',76:'kneeling',77:'handstand',78:'handstand',79:'handstand',80:'handstand',81:'handstand',82:'handstand',83:'plow',84:'plank',85:'plank',86:'plank',87:'plank',88:'plank',89:'plank',90:'plow',91:'plow',92:'table',93:'half-kneehug',94:'supine-handtoe',95:'table',96:'table',97:'table',98:'table',99:'bird-dog',100:'squat',101:'squat',102:'squat',103:'squat',104:'updog',105:'prone',106:'prone',107:'cow',108:'cobra',109:'updog',110:'reverse-plank',111:'squat',112:'squat',113:'squat',114:'boat',115:'boat',116:'boat',117:'boat',118:'boat',119:'half-boat',120:'eagle',121:'shoulderstand',122:'wide-forwardfold',123:'supine',124:'staff',125:'staff',126:'staff',127:'staff',128:'supine-twist',129:'supine-twist',130:'seated-twist',131:'forearmstand',132:'handstand',133:'mountain',134:'easyseat',135:'easyseat',136:'mountain',137:'standing-handtoe',138:'standing-handtoe',139:'puppy',140:'side-plank',141:'updog',142:'chair',143:'chair',144:'forwardfold',145:'forwardfold',146:'forwardfold',147:'plow',148:'plow',149:'corpse',150:'locust',151:'headstand',152:'headstand',153:'headstand'}
GROUPS={
 'standing':set('mountain tree chair eagle dancer halfmoon warrior3 standing-handtoe standing-kneehug standing-split revolved-halfmoon standing-sidebend forwardfold half-forwardfold wide-forwardfold pyramid triangle revolved-triangle bound-triangle star bird-paradise sugarcane'.split()),
 'lunge':set('warrior1 warrior2 side-angle revolved-side-angle reverse-warrior humble-warrior high-lunge low-lunge lizard'.split()),
 'seated':set('easyseat staff seated-fold janu wide-seat boundangle lotus firelog cowface seated-twist deer scale baddha-padma bharadvaja heron compass archer tortoise reclined-easy'.split()),
 'kneeling':set('kneeling camel half-camel child puppy cat cow table bird-dog tiger thread-needle gate lion'.split()),
 'supine':set('supine corpse constructive-rest legsup reclined-boundangle supine-twist kneehug half-kneehug happy-baby supine-pigeon supine-handtoe fish bridge saddle'.split()),
 'prone':set('prone cobra sphinx locust bow crocodile updog seal frog'.split()),
 'arm_balance':set('crow crane side-crow firefly eightangle peacock elephant koundinya1 koundinya2 headstand forearmstand handstand scorpion pendant'.split()),
 'squat':set('squat goddess skandasana noose toe-balance'.split()),
}
INVERTED=set('headstand forearmstand handstand shoulderstand plow scorpion'.split())
BACK=set('camel half-camel wheel fish bridge cobra updog sphinx locust bow saddle reverse-plank reverse-table wildthing'.split())
FOLD=set('forwardfold half-forwardfold wide-forwardfold pyramid seated-fold janu tortoise half-split'.split())
BALANCE=set('tree eagle dancer halfmoon warrior3 standing-handtoe standing-kneehug standing-split bird-paradise sugarcane revolved-halfmoon'.split())
STRENGTH=set('plank forearm-plank chaturanga side-plank reverse-plank reverse-table boat half-boat scale'.split())|GROUPS['arm_balance']
TWIST=set('seated-twist supine-twist revolved-triangle revolved-chair revolved-halfmoon revolved-side-angle bharadvaja thread-needle'.split())
HIP=set('boundangle wide-seat lotus firelog cowface pigeon frog lizard skandasana half-split split happy-baby supine-pigeon reclined-boundangle'.split())
SOURCES='https://www.nccih.nih.gov/health/yoga-effectiveness-and-safety'
P={
 'standing':(['Устойчивость опоры на стопы и контроль положения корпуса.','Координация равновесия в положении стоя.'],['Распределяйте опору по стопе, не сжимая пальцы.','Не блокируйте колени; вытягивайте корпус в доступной амплитуде.'],['При неустойчивости используйте стену или опору для руки.']),
 'lunge':(['Работа ног в разножке и контроль положения таза.','Координация опоры передней и задней ноги.'],['Переднее колено направляйте по линии стопы.','Сохраняйте устойчивую опору задней ноги; не увеличивайте шаг через напряжение поясницы.'],['Сократите длину шага, если теряется опора или появляется дискомфорт в колене.']),
 'seated':(['Контроль положения таза и вертикального вытяжения.','Осознанная работа с подвижностью тазобедренных суставов.'],['Выберите высоту опоры под таз, при которой спина не вынуждена округляться.','Поддерживайте колени, если они остаются без опоры.'],['Не прижимайте колени к полу усилием рук.']),
 'kneeling':(['Координация опор и положения позвоночника.','Контролируемая подвижность корпуса и плечевого пояса.'],['Разместите колени на удобной мягкой опоре.','Сохраняйте доступную амплитуду и плавное дыхание.'],['При чувствительности коленей используйте сложенный плед или замените положение.']),
 'supine':(['Контроль положения таза и конечностей лёжа.','Распознавание лишнего напряжения и расслабление опорных участков.'],['Расположите голову так, чтобы шея оставалась свободной.','Меняйте положение ног постепенно, без рывка.'],['Уменьшите амплитуду, если появляется напряжение поясницы или шеи.']),
 'prone':(['Контроль разгибания корпуса и работы задней поверхности тела.','Координация положения таза, грудной клетки и плеч.'],['Не запрокидывайте голову, продолжайте линию позвоночника.','Направляйте движение через грудной отдел, сохраняя комфорт поясницы.'],['Не поднимайте корпус или ноги до боли в пояснице.']),
 'arm_balance':(['Координация переноса веса на верхние конечности.','Контроль корпуса и устойчивости опоры.'],['Сначала проверьте опору и контролируемый способ выхода.','Уменьшайте нагрузку постепенно, не падайте в суставы.'],['Осваивайте баланс с преподавателем; при боли в опорных суставах прекратите выполнение.']),
 'squat':(['Контроль сгибания ног и опоры стоп.','Координация положения таза над основанием опоры.'],['Колени направляйте в сторону носков.','Выбирайте глубину приседа, в которой дыхание остаётся свободным.'],['Сократите глубину при дискомфорте в коленях, используйте опору.']),
 'floor_reclining':(['Координация корпуса и конечностей в опоре.','Контроль доступной амплитуды движения.'],['Сначала организуйте точки опоры, затем меняйте положение тела.','Сохраняйте ровное дыхание и устойчивость корпуса.'],['Не продолжайте вариант при боли в опорных суставах.']),
}
latinBy={};cyrBy={}
for r in art:
 if r.get('number') and iconBy[r['number']].get('sanskrit_latin'):
  family=r['family'];item=iconBy[r['number']];latinBy.setdefault(family,item['sanskrit_latin']);cyrBy.setdefault(family,item['name'].split(' — ')[0])
cyrBy.update({'plank':'Фалакасана','dolphin':'Ардха Пинча Маюрасана','sphinx':'Саламба Бхуджангасана','side-plank':'Васиштхасана','corpse':'Шавасана','scale':'Толасана','kneeling':'Ваджрасана'})
plans=[]
for r in art:
 a=asanas[r['id']];number=r.get('number');f=r.get('family') or OLD.get(r.get('original_index'),'unknown');name=a['name'];n=name.lower();feature=r.get('features',{})
 position=next((k for k,fs in GROUPS.items() if f in fs),'floor_reclining')
 if f in ['boat','half-boat']:position='seated'
 if f in INVERTED:position='arm_balance' if f in ['headstand','forearmstand','handstand','scorpion'] else 'floor_reclining'
 if f in ['banana','anantasana']:position='floor_reclining'
 benefits,cues,precautions=[list(x) for x in P[position]]
 effects=['foundation'];zones=['whole_body']
 if f in BACK:effects=['backbend'];zones=['spine_back','chest'];benefits=['Контроль разгибания позвоночника в доступном диапазоне.','Работа с подвижностью грудного отдела и плечевого пояса.'];cues+=['Поднимайте грудную клетку, не сжимая заднюю поверхность шеи.']
 if f in FOLD or feature.get('spine')=='fold' or 'наклон' in n:effects+=['forward_fold','flexibility'];zones+=['spine_back','hamstrings'];cues+=['Начинайте наклон от тазобедренных суставов; при необходимости согните колени.']
 if f in HIP:effects+=['hip_opening','flexibility'];zones+=['hips_glutes'];cues+=['Не усиливайте раскрытие за счёт давления на колено.']
 if f in BALANCE:effects+=['balance'];zones+=['hips_glutes','core'];benefits=['Координация равновесия на уменьшенной площади опоры.','Контроль положения таза и корпуса.']
 if f in STRENGTH:effects+=['strength'];zones+=['core','shoulders_arms'];benefits=['Работа мышц корпуса при удержании заданной формы.','Контроль опоры и положения плечевого пояса.']
 if f in ['plank','forearm-plank','chaturanga','side-plank','reverse-plank','reverse-table','updog','downdog','table','cat','cow','bird-dog','tiger'] or position=='arm_balance':zones+=['shoulders_arms','wrists'];cues+=['Распределяйте нагрузку между всеми доступными точками опоры.'];precautions+=['При дискомфорте в запястьях уменьшите нагрузку и подберите подходящую опору.']
 if f in TWIST or feature.get('spine')=='twist' or 'скручив' in n or 'поворот' in n:effects+=['twist'];zones+=['spine_back'];cues+=['Поворачивайте корпус плавно, не вытягивая себя рычагом руки.']
 if f in INVERTED or 'перевёрнут' in n:effects+=['inversion'];zones+=['core','shoulders_arms'];precautions+=['Перевёрнутые положения осваивают с квалифицированным преподавателем; не начинайте с них самостоятельное обучение.']
 if f in ['easyseat','corpse','child','crocodile','constructive-rest','reclined-boundangle']:effects+=['calming'];benefits=['Удобная опора для спокойного дыхания.','Наблюдение за напряжением и расслаблением тела.']
 if f in ['lotus','baddha-padma','firelog']:precautions+=['Не переносите усилие раскрытия тазобедренных суставов на колени; не затягивайте стопу руками.']
 if feature.get('spine')=='sidebend' or 'боковой наклон' in n:zones+=['side_body'];effects+=['flexibility']
 if f=='unknown':position='standing' if 'стоя' in n or 'ноге' in n else 'floor_reclining';benefits,cues,precautions=[list(x) for x in P[position]]
 equipment=[]
 for token,label in [('стен','Стена'),('блок','Йога-блок'),('стул','Стул'),('ремн','Ремень')]:
  if token in n:equipment.append(label)
 for k,label in [('wall','Стена'),('blocks','Йога-блок'),('chair','Стул'),('strap','Ремень')]:
  if feature.get(k):equipment.append(label)
 old_editorial=json.loads(a['editorial'] or '{}');refs=[old_editorial.get('source','')]
 desc=a['information_description'] or ''
 if number:desc=iconBy[number]['description'];refs=[iconBy[number]['source']['url']]
 elif not desc or any(x in desc for x in ['Учебный вариант движения','показаны на конкретном рисунке','показаны на оригинальной схеме','Перевёрнутое положение;']):
  desc=name.replace(' (уточнить)','').rstrip('.')+'. '+{'standing':'Основная опора — стопы; положение таза, корпуса и рук соответствует варианту на схеме.','kneeling':'Исходная опора — колени; направление корпуса и рук сохранено с авторского листа.','seated':'Таз находится в опоре; взаимное положение ног и рук сохранено с исходного листа.','supine':'Положение лёжа на спине; опора корпуса и движение конечностей показаны на схеме.','prone':'Положение у пола; учитывайте опору таза, грудной клетки и конечностей.','arm_balance':'Вес переносится на верхние конечности; сначала освойте опору и контролируемый выход.','squat':'Ноги согнуты в опоре; глубину приседа выбирают по устойчивости и комфорту.'}.get(position,'Положение тела и точки опоры сохранены с исходного авторского рисунка.')
 # Preserve ambiguous sheet labels; do not invent a canonical Sanskrit identity.
 sans=a['sanskrit_latin'];cyr=a['sanskrit_cyrillic'];alt=a['alternate_name'];reviewed_identity='уточнить' not in name and f!='unknown'
 if number:
  if ' — ' in name:name,alt=name.split(' — ',1)
  elif not alt:alt=iconBy[number]['english']
  if sans:cyr=name.split(' с ')[0] if 'асана' in name.lower() else cyrBy.get(f) or name
  if number==125:sans='Matsyasana';cyr='Матсиасана'
  if number==126:sans='Ardha Navasana';cyr='Ардха Навасана'
  if number==155:sans='Ardha Salabhasana';cyr='Ардха Шалабхасана'
  if number==156:sans='Ardha Bhekasana';cyr='Ардха Бхекасана'
  if number==158:sans='Bhekasana';cyr='Бхекасана'
  if number==174:sans='Virasana';cyr='Вирасана'
  if not sans and f in latinBy:sans=latinBy[f];cyr=cyrBy.get(f)
 aliases=[x['name'] for x in data['catalog_asana_aliases'] if x['asana_id']==a['id']]
 if number:aliases += [iconBy[number]['english'],a['name']]
 aliases=list(dict.fromkeys(x.strip() for x in aliases if x and x.casefold()!=name.casefold()))
 note='Названия и изображённый вариант сопоставлены с источником. '+('Стандартное название сохранено.' if reviewed_identity else 'Схематичный авторский вариант сохраняет описательное название; точное санскритское соответствие не утверждается.')+' Польза сформулирована как двигательная задача, без лечебных обещаний. Индивидуальные ограничения определяет преподаватель. Уровень сложности не назначен по миниатюре.'
 plans.append({'id':a['id'],'expected_revision':a['revision'],'expected_image_asset_id':a['current_image_asset_id'],'family':f,'naming_status':'matched_source' if reviewed_identity else 'descriptive_variant','fields':{'name':name,'alternate_name':alt,'sanskrit_latin':sans,'sanskrit_cyrillic':cyr,'aliases':aliases,'information_description':desc,'teacher_cues':list(dict.fromkeys(cues)),'benefits':benefits,'precautions':list(dict.fromkeys(precautions+['Прекратите выполнение при боли, онемении или головокружении.'])),'equipment':list(dict.fromkeys(equipment)),'adaptations':['Уменьшите амплитуду и используйте доступную опору, сохраняя смысл показанного положения.'],'editorial':{'author':'Редакция RibkaYoga','source':'\n'.join(x for x in refs+[SOURCES] if x),'reviewed':False,'review_note':note},'classification_status':'partial'},'facets':{'position':[position],'effect':list(dict.fromkeys(effects)),'anatomy':list(dict.fromkeys(zones))}})
(OUT/'curation.json').write_text(json.dumps(plans,ensure_ascii=False,indent=2))
print('Prepared editorial fields for',len(plans),'cards;',sum(x['naming_status']=='descriptive_variant' for x in plans),'descriptive sheet variants retained')
