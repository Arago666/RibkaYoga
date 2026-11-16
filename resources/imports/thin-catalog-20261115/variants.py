"""Named anatomical variations for the original symbol set. No random visual variations."""
from copy import deepcopy
import math,re

def unit(v):
 d=math.hypot(*v) or 1;return [v[0]/d,v[1]/d]
def add(p,v,a=1):return [p[0]+v[0]*a,p[1]+v[1]*a]
def norm(s):return ' '.join(re.sub(r'[^a-z0-9]+',' ',s.lower()).split())
RULES=[
 ('spine','sidebend','боковой наклон','Туловище наклонено в сторону.',r'side bend|side stretch|lateral extension'),
 ('spine','fold','наклон вперёд','Туловище направлено вперёд и вниз.',r'forward bend|forward fold|folded forward|head to knee|bowing|prayer bow'),
 ('spine','backbend','прогиб','Грудная клетка раскрыта в прогибе назад.',r'backbend|back bend|heart opener|chest open'),
 ('spine','twist','скручивание','Грудная клетка развёрнута относительно таза.',r'revolved|twist|twisted'),
 ('neck','side','наклон головы в сторону','Голова наклонена в сторону без разворота туловища.',r'neck side|neck stretch|neck release'),
 ('neck','back','голова отклонена назад','Голова немного отклонена назад.',r'neck extension|chin lift|face up'),
 ('neck','front','голова направлена вниз','Подбородок направлен к передней поверхности шеи.',r'neck flexion|face down'),
 ('arms','eagle','руки орла','Предплечья переплетены перед грудной клеткой.',r'eagle arms'),
 ('arms','cowface','замок рук за спиной','Одна рука направлена за спину сверху, другая — снизу.',r'cow face arms|cow face.*arms|shoulder bind'),
 ('arms','reverse-prayer','обратная молитва','Ладони соединены за спиной.',r'reverse prayer|prayer.*behind back|paschima namaskar'),
 ('arms','behind-head','руки за головой','Локти разведены, кисти расположены за головой.',r'behind head|hands neck|hands on head|hands head|hands ears'),
 ('arms','cactus','руки кактусом','Плечи раскрыты в стороны, локти согнуты, предплечья направлены вверх.',r'cactus|elbows bent|hands bent fists'),
 ('arms','elbow-hold','захват локтей','Кисти удерживают противоположные локти.',r'elbow hold|bound elbows|holding.*elbows|hands to elbows'),
 ('arms','crossed','скрещённые руки','Руки пересекаются перед грудной клеткой.',r'crossed arms|arms crossed|crossed hands|bear hug|hugs'),
 ('arms','wrist-hold','захват запястья','Одна кисть удерживает запястье другой руки.',r'wrist hold|hand to wrist|hand wrist'),
 ('arms','hip','руки на талии','Кисти расположены у таза или на талии.',r'hands on hips|hands hips|hands in hip|hand hip|hand on hip|hands.*lower back|hands waist'),
 ('arms','knee','руки на коленях','Кисти опираются на колени.',r'hands.*knee|hands knees|hands to knee|hand knee|hand on thigh|hands on thighs|hands thighs|hands thigh'),
 ('arms','ankle','захват лодыжек','Кисти направлены к лодыжкам.',r'ankle hold|ankle grip|holding.*feet|hold feet|foot hold|feet hold|toe hold|hands ankles|hands to feet|hands.*ankles'),
 ('arms','under-knees','руки под коленями','Кисти поддерживают ноги под коленями.',r'hands under knees'),
 ('arms','behind','руки за спиной','Руки направлены назад за туловище.',r'hands.*behind back|arms.*behind|arms.*backwards|arms back|hands back|arms extended back|bound hands|bound arm|back bound|arms under ankles'),
 ('arms','prayer','ладони перед грудью','Ладони соединены перед грудной клеткой.',r'prayer|namaste|hands.*heart|hands.*chest|hands to chest|hands.*belly'),
 ('arms','overhead','руки вверх','Руки вытянуты над головой по линии туловища.',r'arms up|arms overhead|arms raised|raised arms|raised hands|hands up|arms extended up|overhead|volcano|upward reach|upward salute|upward hand|high reach|hand raised|hand up|one arm up'),
 ('arms','side','руки в стороны','Руки раскрыты в стороны от туловища.',r'open arm|open arms|arms side|arms extended side|airplane arms|arms to side'),
 ('arms','forward','руки вперёд','Руки вытянуты перед туловищем.',r'arm reach|arms extended|arms forward|hands forward|forward reach|reach forward|arms extended.*front|arms extended forward|hands extended chest|arms stretched.*forward|arms extended in front'),
 ('arms','floor','опора кистями','Кисти направлены к опоре перед туловищем.',r'hands down|hands (?:on )?floor|hand (?:on )?floor|palms.*floor|fingertips'),
 ('arms','shoulder','кисти у плеч','Кисти расположены возле плеч.',r'hands shoulders|hand.*upper back'),
 ('arms','forehead','кисть у лба','Одна кисть расположена возле лба.',r'hand forehead|cheek support'),
 ('arms','forearms','опора предплечьями','Предплечья опираются на поверхность.',r'forearm|on forearms|forearms|on elbows|elbows on floor|elbows down'),
 ('legs','tree','нога в положении дерева','Одна стопа расположена у внутренней стороны другой ноги.',r'tree leg'),
 ('legs','eagle','переплетённые ноги','Ноги переплетены одна вокруг другой.',r'eagle legs'),
 ('legs','lotus','нога в полулотосе','Одна стопа размещена на противоположном бедре.',r'half lotus|lotus leg'),
 ('legs','crossed','скрещённые ноги','Ноги перекрещены в проекции туловища.',r'cross legged|legs crossed|crossed legs|crossed ankles'),
 ('legs','scorpion','поднятая согнутая нога','Свободная нога поднята, колено согнуто.',r'\bscorpion\b|\bstag\b|stacked|hip open|open hip|bow leg|quad stretch|foot elbow|foot.*elbow'),
 ('legs','raised','одна нога поднята','Одна нога поднята относительно опорной ноги.',r'one legged|one leg |one leg$|three legged|leg raised|leg lifted|one leg up|one foot|backlift|leg lift|single leg extend|one leg extended'),
 ('legs','kneefloor','колено на полу','Опора дополнена коленом на полу.',r'knee on.*floor|knee floor|knees floor|knees on.*floor|knee to ground|knees down|modified chaturanga|knee down'),
 ('legs','bent','согнутые колени','Колени согнуты в выбранном положении.',r'knees bent|bent knees|bent knee|knee bent|bent legs|legs bent|tuck|kneeling.*round'),
 ('legs','straight','вытянутые ноги','Ноги вытянуты без сгибания коленей.',r'straight legs|legs straight|both legs extend|extended legs|legs extended'),
 ('legs','wide','широкое положение ног','Опора ног расширена в стороны.',r'wide leg|feet wide|feet apart|hip wide|wide child|wide.*frog'),
 ('feet','tiptoes','на носках','Пятки подняты, опора перенесена на переднюю часть стоп.',r'tiptoe|tiptoes|toes pose|toes balance|heels raised'),
 ('feet','together','стопы вместе','Стопы сближены.',r'feet together'),
 ('feet','staggered','разножка','Одна стопа находится впереди другой.',r'staggered'),
 ('feet','turned','развёрнутые стопы','Стопы развёрнуты в стороны.',r'toes wide|feet curl|feet curled|foot curl'),
 ('single_arm','yes','одна рука изменена','Положение верхней конечности выполнено одной рукой; другая сохраняет исходную опору.',r'one hand|one arm|single arm|one cactus|one.*hand|one.*arm|one handed'),
 ('bind','yes','с захватом','Руки образуют захват в выбранном положении.',r'\bbound\b|clasped|interlocked|interlaced|holding mat|hand feet'),
 ('wrist','turned','разворот кистей','Кисти развёрнуты относительно обычной опоры.',r'wrist stretch|palms out|palms forward|palms up')
]
FRONT={'tree','mountain','goddess','star','chair','easyseat','lotus','cowface','wide-seat','boundangle','deer','frog','happy-baby','reclined-boundangle'}
WEIGHT_ARMS={'downdog','dolphin','plank','forearm-plank','chaturanga','updog','sphinx','side-plank','table','cat','cow','bird-dog','tiger','puppy','crow','side-crow','firefly','eightangle','elephant','handstand','forearmstand','headstand','reverse-table','reverse-plank','peacock'}
ALREADY_FOLD={'forwardfold','wide-forwardfold','pyramid','seated-fold','janu','half-split','humble-warrior','child','standing-split','half-forwardfold'}
ALREADY_SIDE={'gate','standing-sidebend','reverse-warrior','side-angle','triangle','halfmoon','sugarcane'}
ALREADY_TWIST={'revolved-triangle','revolved-chair','revolved-halfmoon','revolved-side-angle','seated-twist','supine-twist','thread-needle'}
ALREADY_BACK={'camel','bow','wheel','bridge','sphinx','cobra','updog','fish','reverse-warrior','saddle'}
SEATED={'easyseat','lotus','cowface','wide-seat','boundangle','deer','staff','seated-fold','janu','seated-twist','boat','heron','pigeon','kneeling','seated-kneehug'}
SUPINE={'corpse','constructive-rest','bridge','kneehug','half-kneehug','supine-handtoe','supine-pigeon','supine-twist','reclined-boundangle','happy-baby','legsup','saddle','fish','reclined-easy','reclined-mountain'}

def features(name,family):
 n=norm(name);result={};labels=[];sentences=[]
 # 'Bound angle' is a pose name, not an instruction to bind the arms.
 rule_n=re.sub(r'bound angle|bound lotus', '', n)
 if family in ['baddha-padma','bird-paradise','noose']:rule_n=re.sub(r'\bbound\b','',rule_n)

 for field,val,label,desc,pat in RULES:
  if field in result or not re.search(pat,rule_n if field=='bind' else n):continue
  if field=='single_arm' and not re.search(r'one (?:hand|arm|handed|cactus)|single arm',n):continue
  if field=='arms' and val=='behind-head' and re.search(r'head straight|head up',n):continue
  if field=='arms' and val=='prayer' and re.search(r'extended.*chest|extended.*belly',n):continue
  if field=='arms' and val=='knee' and 'under knees' in n:continue
  if field=='spine':
   if val=='sidebend' and any(w in n for w in ['intense side stretch','neck side','neck stretch','neck release']):continue
   if (val=='fold' and family in ALREADY_FOLD) or (val=='twist' and family in ALREADY_TWIST) or (val=='sidebend' and family in ALREADY_SIDE) or (val=='backbend' and family in ALREADY_BACK):continue
  if field=='legs':
   if (val=='crossed' and family in ['easyseat','lotus','reclined-easy']) or (val=='wide' and family in ['wide-seat','wide-forwardfold','frog','goddess','star']):continue
   if val=='kneefloor' and re.search(r'hand.*knee.*hand.*floor',n):continue
   if val=='raised' and family in ['standing-handtoe','standing-kneehug','standing-split','pigeon','janu','koundinya1','koundinya2','supine-handtoe','anantasana']:continue
   if val=='raised' and family=='legsup' and not re.search(r'one|single',n):continue
   if val=='scorpion' and family=='scorpion':continue
   if val=='kneefloor' and family in ['low-lunge','kneeling','child','table','cat','cow','puppy','bird-dog','tiger','gate']:continue
  if field=='single_arm' and not any(w in n for w in ['arm','hand']):continue
  result[field]=val;labels.append(label);sentences.append(desc)
 if family=='mountain' and 'prayer' in n and re.search(r'upward|raised|overhead',n):
  result['arms']='overhead-prayer'
 # A real chair is present only in addition to the name of Utkatasana.
 chair_n=n.replace('chair pose','',1) if family in ['chair','revolved-chair'] else n
 if 'chair' in chair_n:
  result['chair']='wheelchair' if 'wheelchair' in n else 'chair';labels.append('со стулом' if result['chair']=='chair' else 'в кресле-коляске')
  target='seat' if family in SEATED or family in ['chair','revolved-chair'] or 'seated' in n else 'hands'
  if family in SUPINE:target='legs'
  if re.search(r'head.*chair|chair.*head',n):target='head'
  if re.search(r'(?:foot|feet|leg|legs).*chair',n) and 'one legged' not in n:target='feet'
  if re.search(r'one hand|hand chair|holding.*chair|back of chair|hands chair|hands.*chair|elbows.*chair',n):target='hands'
  result['chair_target']=target
 def prop_target(key,default):
  matches=list(re.finditer(r'\b'+key+r's?\b',n))
  if not matches:return default
  before=n[:matches[0].start()].split()[-5:];after=n[matches[0].end():].split()[:4]
  # A second prop starts a new clause. Do not attach all earlier body parts.
  for j,w in enumerate(before):
   if w in ['block','blocks','bolster','bolsters','blanket','blankets','strap','straps','wall','chair','ball','wheel']:before=before[j+1:]
  words=before[-3:]+after[:3]
  targets=[]
  for w in words:
   for target,keys in [('head',['head','face','neck']),('hips',['sacrum','hips','hip','glutes','buttocks','buttock','seat']),('back',['back','spine','abdomen','belly']),('chest',['chest','shoulders','shoulder']),('knees',['knee','knees','shins','shin']),('feet',['feet','foot','heels','heel']),('thighs',['thigh','thighs']),('hands',['hands','hand','elbows','elbow','arms','arm'])]:
    if w in keys:targets.append(target)
  return targets[-1] if targets else default
 if 'block' in n:
  target=prop_target('block','hips' if family in SEATED|{'bridge','fish','corpse','kneeling','squat'} else 'hands')
  result['blocks']=[target]
 for key in ['bolster','blanket','strap','wall','ball','wheel']:
  if key not in n or (key=='wheel' and ('wheelchair' in n or family=='wheel')):continue
  default='hips' if family in SEATED else ('back' if family in SUPINE|{'reclined-backbend'} else 'hands')
  if key=='strap' and family in ['staff','seated-fold','janu','supine-handtoe']:default='feet'
  if key=='blanket' and family in ['child','low-lunge','lizard','kneeling','table']:default='knees'
  result[key]=prop_target(key,default)
 if re.search(r'\bl shape|\bl shaped',n):result['legs']='l-shape'
 if re.search(r'leg(?:s)? (?:to the )?side|one leg to side|leg out to side',n):result['legs']='side'
 if re.search(r'sleeping (?:swan|pigeon)|resting pigeon|pigeon.*forearms',n):result['spine']='fold'
 if family=='toe-balance':result.pop('legs',None)
 if family=='forwardfold' and 'yoga seal' in n:result['arms']='behind';result['bind']='yes'
 if family=='forwardfold' and 'half bound lotus' in n:result['legs']='lotus';result['arms']='behind';result['bind']='yes'
 if re.search(r'king pigeon|mermaid',n):result['legs']='pigeon-catch';result['arms']='pigeon-catch'
 if re.search(r'(?:knees|knee) (?:raised|up)',n) and family in ['table','cat','cow']:result['knees_up']=True
 if re.search(r'arm.*on floor|arm.*floor',n) and family=='locust':result['arms']='locust-single'
 if family in ['reclined-tree','supine-twist','reclined-boundangle','happy-baby']:result['projection']='overhead'
 # Describe the base identity before its specific features, preserving source aliases separately.
 return result,labels,sentences

def rotate(p,pivot,a):
 a=math.radians(a);x,y=p[0]-pivot[0],p[1]-pivot[1]
 return [pivot[0]+x*math.cos(a)-y*math.sin(a),pivot[1]+x*math.sin(a)+y*math.cos(a)]
def flatten(p):
 return [p['head'],*p['torso'],*[a for line in p['arms']+p['legs'] for a in line]]
def rect(x,y,w,h):return [[x,y],[x+w,y],[x+w,y+h],[x,y+h],[x,y]]
def oval(x,y,rx,ry):return [[x+rx*math.cos(i*math.pi/24),y+ry*math.sin(i*math.pi/24)] for i in range(49)]
def landmark(p,target):
 if target=='head':return p['head']
 if target=='hips':return p['torso'][-1]
 if target in ['chest','back']:return p['torso'][len(p['torso'])//2]
 if target=='feet':return p['legs'][0][-1]
 if target in ['knees','thighs']:return p['legs'][0][min(1,len(p['legs'][0])-1)]
 return p['arms'][0][-1]

def vary(base,feature,family):
 p=deepcopy(base);p['front']=base.get('front',False)
 hip=p['torso'][-1];s=p['torso'][0]
 length=max(90,min(190,math.dist(s,hip)));u=unit([s[0]-hip[0],s[1]-hip[1]]);v=[-u[1],u[0]]
 # Structural base twists and bends.
 spine=feature.get('spine')
 if family in ALREADY_TWIST:spine='twist'
 if family in ['humble-warrior']:spine='fold'
 if family in ['reverse-warrior','standing-sidebend']:spine='sidebend'
 if spine in ['sidebend','fold','backbend']:
  a={'sidebend':-27,'fold':65,'backbend':-28}[spine]
  if family in SUPINE:a={'sidebend':16,'fold':-20,'backbend':-12}[spine]
  if not ((spine=='fold' and family in ALREADY_FOLD) or (spine=='backbend' and family in ALREADY_BACK)):
   p['torso']=[rotate(a0,hip,a) for a0 in p['torso']];p['head']=rotate(p['head'],hip,a)
   if spine=='fold' and p['front']:
    p['torso']=[[hip[0],hip[1]+length*.45],hip];p['head']=[hip[0],hip[1]+length*.75]
   p['arms']=[[rotate(q,hip,a) for q in a0] for a0 in p['arms']]
   if family in WEIGHT_ARMS:
    p['arms'][0]=[p['torso'][0],*base['arms'][0][1:]]
 elif spine=='twist':
  p['torso']=[add(q,v,math.sin(i/(len(p['torso'])-1 or 1)*math.pi)*22) for i,q in enumerate(p['torso'])]
  p['head']=add(p['head'],v,12)
 s=p['torso'][0];hip=p['torso'][-1];u=unit([s[0]-hip[0],s[1]-hip[1]]);v=[-u[1],u[0]]
 arm=feature.get('arms')
 if not arm and spine=='twist':arm='knee'
 if not arm and spine=='sidebend':arm='overhead'
 if not arm and family=='humble-warrior':arm='behind'
 if not arm and family=='cowface':arm='cowface'
 if not arm and feature.get('single_arm')=='yes':arm='forward'
 if not arm and family=='standing-table':arm='forward'
 if not arm and feature.get('bind') and family not in WEIGHT_ARMS:arm='behind'
 def local(a,b):return add(add(s,u,a),v,b)
 arms=[]
 for i,side in enumerate([-1,1]):
  side=side if p['front'] else (1 if v[0]>=0 else -1)
  if arm=='overhead-prayer':line=[s,local(length*.6,(-1 if i==0 else 1)*length*.5),local(length*1.5,0)]
  elif arm=='overhead':line=[s,local(length*.35,side*length*.7),local(length*1.4,side*length*.7)]
  elif arm=='side':line=[s,local(0,side*length*1.5)] if p['front'] else [s,local(0,(-1 if i==0 else 1)*length*1.3)]
  elif arm=='forward':line=[s,local(-length*.05,length*1.35)]
  elif arm=='behind':line=[s,local(-length*.7,-length*.8),local(-length*1.2,-length*.9)]
  elif arm=='prayer':line=[s,local(-length*.5,(-1 if i==0 else 1)*length*.6),local(-length*.3,0)]
  elif arm=='reverse-prayer':line=[s,local(-length*.7,(-1 if i==0 else 1)*length*.65),local(-length*.65,0)]
  elif arm=='hip':line=[s,local(-length*.5,(-1 if i==0 else 1)*length*.65),add(hip,v,(-1 if i==0 else 1)*length*.15)]
  elif arm=='knee':line=[s,local(-length*.4,side*length*.3),p['legs'][i][min(1,len(p['legs'][i])-1)]]
  elif arm=='ankle':line=[s,p['legs'][i][-1]]
  elif arm=='under-knees':line=[s,add(p['legs'][i][min(1,len(p['legs'][i])-1)],u,-20)]
  elif arm=='behind-head':line=[s,local(length*.1,(-1 if i==0 else 1)*length*.9),add(p['head'],u,-25)]
  elif arm=='shoulder':line=[s,local(-length*.6,side*length*.7),local(0,-side*length*.15)]
  elif arm=='forehead':line=[s,local(-length*.1,length*.8),add(p['head'],v,25)]
  elif arm=='cactus':line=[s,local(0,(-1 if i==0 else 1)*length*.9),local(length*.8,(-1 if i==0 else 1)*length*.9)]
  elif arm=='elbow-hold':line=[s,local(-length*.6,(-1 if i==0 else 1)*length*.8),local(-length*.5,(-1 if i else 1)*length*.65)]
  elif arm=='crossed':line=[s,local(-length*.7,(-1 if i==0 else 1)*length*.4),local(-length*.3,(-1 if i else 1)*length*.4)]
  elif arm=='eagle':line=[s,local(-length*.5,(-1 if i==0 else 1)*length*.4),local(-length*.45,(-1 if i else 1)*length*.12),local(length*.25,(-1 if i else 1)*length*.08)]
  elif arm=='cowface':line=[s,local(length*.6,-length*.25),local(-length*.35,-length*.1)] if i==0 else [s,local(-length*.9,length*.5),local(-length*.4,length*.1)]
  elif arm=='wrist-hold':line=[s,local(length*.7,(-1 if i==0 else 1)*length*.55),local(length*1.45,length*.1)]
  elif arm=='floor':line=[s,[s[0]+length*.4,max(q[1] for q in flatten(p))+5],[s[0]+length*.55,max(q[1] for q in flatten(p))+5]]
  elif arm=='locust-single':line=p['arms'][i] if i==0 else [s,[s[0]-length*.8,base['torso'][-1][1]+40]]
  elif arm=='pigeon-catch':line=[s,[s[0]-length*.65,s[1]-length*.7],[hip[0]-length*.9,hip[1]-length*.5]]
  elif arm=='forearms':
   old=p['arms'][i];goal=old[-1];line=[s,[(s[0]+goal[0])/2,goal[1]],[goal[0],goal[1]]]
  else:line=p['arms'][i]
  arms.append(deepcopy(line))
 if arm:
  if family=='side-plank' and arm=='forearms':
   p['arms'][0]=arms[0]
  elif family in WEIGHT_ARMS and arm not in ['forearms','floor','ankle']:
   p['arms'][1]=arms[1]
  elif feature.get('single_arm')=='yes':p['arms'][1]=arms[1]
  else:p['arms']=arms
 # Cross-body hand establishes the perspective of twists.
 if spine=='twist' and family not in ['downdog','plank','side-plank']:
  p['arms'][0]=[s,add(hip,v,length*.7)]
 leg=feature.get('legs')
 if leg=='wide':
  if family in ['boat','half-boat']:
   p['legs']=[[hip,add(add(hip,u,length*.8),v,-length*1.3)],[hip,add(add(hip,u,length*.8),v,length*1.3)]]
  elif family in SEATED:
   p['legs']=[[hip,add(add(hip,v,-length*1.6),u,-length*.7)],[hip,add(add(hip,v,length*1.6),u,-length*.7)]]
  elif p['front'] or family in ['chair','squat','frog']:
   p['legs']=[[hip,[hip[0]-length*.65,hip[1]+length*.65],[hip[0]-length*.95,hip[1]+length*1.35]],[hip,[hip[0]+length*.65,hip[1]+length*.65],[hip[0]+length*.95,hip[1]+length*1.35]]]
  else:
   p['legs'][0]=[add(q,v,-15) for q in p['legs'][0]];p['legs'][1]=[add(q,v,15) for q in p['legs'][1]]
 elif leg=='l-shape':p['legs']=[[hip,[hip[0]+length*1.8,hip[1]],[hip[0]+length*1.8,hip[1]-20]]]*2
 elif leg=='pigeon-catch':p['legs'][1]=[hip,[hip[0]-length,hip[1]+30],[hip[0]-length*.9,hip[1]-length*.5]]
 elif leg=='side':p['legs'][1]=[hip,[hip[0]-length*1.8,hip[1]+length*.5]]
 elif leg=='crossed':
  a,b=p['legs'][0][-1],p['legs'][1][-1];mid=[(a[0]+b[0])/2,(a[1]+b[1])/2]
  p['legs']=[[hip,add(mid,v,length*.3),add(mid,v,-length*.2)],[hip,add(mid,v,-length*.3),add(mid,v,length*.2)]]
 elif leg=='tree':p['legs'][1]=[hip,add(add(hip,v,length*.8),u,-length*.3),add(hip,u,-length*.45)]
 elif leg=='lotus':p['legs'][1]=[hip,add(add(hip,v,length*.8),u,-length*.4),add(add(hip,v,-length*.2),u,-length*.15)]
 elif leg=='eagle':
  end=p['legs'][0][-1];p['legs'][1]=[hip,add(add(hip,u,-length*.7),v,-length*.25),add(end,v,length*.3),add(end,u,length*.2)]
 elif leg in ['raised','scorpion']:
  if family in ['downdog','plank','forearm-plank','table','bird-dog','tiger','cow','cat']:
   end=add(hip,unit([hip[0]-s[0],hip[1]-s[1]]),length*1.5);end[1]-=length*.7
  elif family in SUPINE:end=add(hip,v,length*1.7)
  elif family in ['headstand','forearmstand','handstand','shoulderstand']:end=add(hip,v,length*1.8)
  else:end=add(hip,v,length*1.7)
  p['legs'][1]=[hip,end]
  if leg=='scorpion':p['legs'][1]=[hip,end,add(add(end,u,length*.8),v,-length*.6)]
 elif leg=='kneefloor':
  end=p['legs'][0][-1];ground=max(q[1] for q in flatten(p));knee=[(hip[0]+end[0])/2,ground];p['legs']=[[hip,knee,end],[hip,knee,end]]
 elif leg=='bent':
  for i in [0,1] if not feature.get('single_arm') else [1]:
   end=p['legs'][i][-1];p['legs'][i]=[hip,[(hip[0]+end[0])/2+length*.35,(hip[1]+end[1])/2],end]
 elif leg=='straight':p['legs']=[[hip,line[-1]] for line in p['legs']]
 feet=feature.get('feet')
 if feet=='tiptoes':
  for i,line in enumerate(p['legs']):
   end=line[-1];p['legs'][i]=line[:-1]+[[end[0]-15,end[1]-25],[end[0]+15,end[1]]]
 elif feet=='together':
  a,b=p['legs'][0][-1],p['legs'][1][-1];mid=[(a[0]+b[0])/2,(a[1]+b[1])/2];p['legs']=[line[:-1]+[[mid[0]+(i*2-1)*7,mid[1]]] for i,line in enumerate(p['legs'])]
 elif feet=='staggered':p['legs'][1]=[hip,*[add(q,v,30) for q in p['legs'][1][1:]]]
 elif feet=='turned':p['legs']=[line+[[line[-1][0]+(-1 if i==0 else 1)*20,line[-1][1]-12]] for i,line in enumerate(p['legs'])]
 if feature.get('knees_up'):
  for leg in p['legs']:leg[1][1]-=45
 if feature.get('neck'):
  p['head']=add(p['head'],v,{'side':25,'back':-18,'front':18}[feature['neck']])
 if feature.get('wrist'):
  for i,line in enumerate(p['arms']):p['arms'][i]=line+[[line[-1][0]-25,line[-1][1]-5]]
 if feature.get('bind') and feature.get('arms') not in ['eagle','cowface','reverse-prayer','prayer','crossed']:
  p['props'].append({'points':[p['arms'][0][-1],p['arms'][1][-1]],'width':7})
 # Props are separate editable elements; no catalog photo or competitor illustration is embedded.
 if feature.get('chair'):
  target=feature['chair_target'];point=landmark(p,'hips' if target=='seat' else 'hands' if target=='hands' else target)
  x,y=point[0]-45,point[1]+8
  if target=='seat' and family not in ['easyseat','lotus','boundangle','cowface','pigeon','seated-kneehug','staff','boat','wide-seat']:
   h=p['torso'][-1];p['legs']=[[h,[h[0]+120,h[1]],[h[0]+120,h[1]+150],[h[0]+145,h[1]+150]]]*2
  elif target=='seat' and family in ['seated-fold','wide-seat']:
   h=p['torso'][-1];p['legs']=[[h,[h[0]+120,h[1]],[h[0]+120,h[1]+150],[h[0]+145,h[1]+150]]]*2
  if target=='hands':p['arms'][0]=[p['torso'][0],[x+25,y]]
  elif target in ['feet','legs']:
   if family in SUPINE:
    h=p['torso'][-1];p['legs']=[[h,[h[0]+70,h[1]-100],[h[0]+170,h[1]-100]],[h,[h[0]+70,h[1]-100],[h[0]+170,h[1]-100]]];x,y=h[0]+100,h[1]-90
   else:p['legs'][1]=[hip,[hip[0]+length*.8,hip[1]+length*.35],[x+35,y]]
  p['props'].extend([{'points':[[x-10,y-105],[x-10,y],[x+100,y],[x+100,y+110]],'width':7},{'points':[[x-10,y],[x-10,y+110]],'width':7}])
  if feature['chair']=='wheelchair':
   p['props'].extend([{'points':oval(x,y+100,40,40),'width':6},{'points':oval(x+105,y+118,20,20),'width':6}])
 for target in feature.get('blocks',[]):
  q=landmark(p,target);w,h=(40,22) if target not in ['thighs','head'] else (30,35)
  if target=='thighs':q=add(p['torso'][-1],u,-length*.4)
  p['props'].append({'points':rect(q[0]-w/2,q[1]+5,w,h),'width':6})
 for prop in ['bolster','blanket','ball','wheel']:
  if prop not in feature:continue
  q=landmark(p,feature[prop]);w,h={'bolster':(95,30),'blanket':(65,10),'ball':(75,75),'wheel':(55,55)}[prop]
  pts=rect(q[0]-w/2,q[1]+6,w,h) if prop=='blanket' else oval(q[0],q[1]+h+6,w,h)
  p['props'].append({'points':pts,'width':5 if prop=='blanket' else 7})
 if feature.get('wall'):
  pts=flatten(p);x=min(q[0] for q in pts)-35 if 'back' in feature['wall'] else max(q[0] for q in pts)+35
  p['props'].append({'points':[[x,min(q[1] for q in pts)-30],[x,max(q[1] for q in pts)+25]],'width':7})
 if feature.get('strap'):
  q=landmark(p,feature['strap']);p['props'].append({'points':[p['arms'][0][-1],q,p['arms'][1][-1]],'width':5})
 return p
