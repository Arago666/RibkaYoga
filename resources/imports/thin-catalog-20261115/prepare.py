"""Hand-authored vector replacement, not raster thinning. Run against a private catalog snapshot."""
from pathlib import Path
from copy import deepcopy
import sys,json,gzip,math,uuid,hashlib,re
import geometry as g
import variants as v
from corrections import corrected
ROOT=Path(__file__).resolve().parents[3]
OUT=ROOT/'.runtime/local-redesign-20261115/artwork'
OUT.mkdir(parents=True,exist_ok=True)
# Every icon has an explicit anatomical family. The second set preserves the named variation.
first='easyseat mountain tree chair warrior1 warrior2 warrior3 triangle side-angle forwardfold downdog kneeling cobra child cat cow staff seated-fold boat bridge plank chaturanga updog table puppy dolphin forearm-plank side-plank reverse-plank reverse-table sphinx locust bow camel half-camel wheel fish corpse constructive-rest legsup boundangle wide-seat janu seated-twist cowface lotus lotus firelog kneeling saddle reclined-boundangle supine-twist kneehug half-kneehug happy-baby supine-pigeon supine-handtoe anantasana banana crocodile reclined-easy eagle dancer pyramid wide-forwardfold half-forwardfold goddess star skandasana high-lunge low-lunge lizard half-split split pigeon pigeon pigeon pigeon gate halfmoon revolved-triangle revolved-chair revolved-side-angle reverse-warrior humble-warrior sugarcane bird-dog tiger thread-needle lion frog heron compass firefly crow crane side-crow scale scale peacock elephant eightangle koundinya1 bird-paradise standing-handtoe standing-handtoe standing-kneehug standing-split revolved-halfmoon triangle side-angle squat bharadvaja archer tortoise koundinya2 headstand headstand forearmstand handstand shoulderstand plow plow scorpion fish'.split()
last='half-boat boat boat wildthing noose boat bridge bridge bridge downdog downdog downdog downdog downdog dolphin plank plank side-plank plank forearm-plank side-plank side-plank side-plank side-plank side-plank reverse-plank reverse-table locust locust locust frog cobra frog bow pigeon camel easyseat seated-fold wide-seat janu wide-seat easyseat easyseat easyseat easyseat lotus baddha-padma mountain kneeling child child child puppy puppy low-lunge low-lunge low-lunge low-lunge high-lunge high-lunge warrior3 warrior2 chair chair chair tree tree mountain standing-sidebend mountain forwardfold forwardfold wide-forwardfold supine-twist happy-baby'.split()
assert len(first)==125 and len(last)==75
FAMILIES=first+last
# Explicit modifications avoid guessing different poses from arbitrary illustration changes.
OVERRIDES={
 1:{'arms':'knee'},3:{'arms':'overhead-prayer'},7:{'arms':'forward'},47:{'legs':'lotus'},61:{},
 75:{},76:{'spine':'fold'},77:{'legs':'pigeon-catch','arms':'pigeon-catch'},78:{'legs':'pigeon-catch','arms':'pigeon-catch'},
 105:{},106:{'legs':'side'},110:{'arms':'behind','bind':'yes'},111:{'arms':'behind','bind':'yes'},118:{},
 123:{'legs':'bent'},125:{'legs':'lotus'},126:{},127:{'legs':'bent'},128:{'arms':'overhead'},131:{'legs':'wide'},
 132:{'legs':'raised'},133:{'blocks':['hips']},134:{'arms':'overhead'},135:{'legs':'raised'},136:{'legs':'scorpion'},
 137:{'spine':'twist','single_arm':'yes'},138:{'legs':'bent'},139:{'chair':'chair','chair_target':'hands'},140:{'legs':'raised'},
 141:{'legs':'raised'},142:{'legs':'kneefloor'},144:{'legs':'raised','arms':'overhead','single_arm':'yes'},
 145:{'legs':'raised'},146:{'legs':'kneefloor'},147:{'legs':'raised'},148:{'legs':'tree'},149:{'arms':'overhead','single_arm':'yes'},
 150:{},151:{'legs':'raised'},152:{'legs':'raised'},153:{'arms':'overhead'},154:{'arms':'behind','bind':'yes'},155:{'legs':'raised'},
 156:{},157:{},158:{},159:{},160:{'legs':'pigeon-catch','arms':'pigeon-catch'},161:{'arms':'hip'},162:{'arms':'knee'},
 163:{'strap':'feet'},164:{'spine':'fold'},165:{'spine':'sidebend'},166:{'spine':'sidebend'},167:{'spine':'twist'},
 168:{'spine':'fold'},169:{'arms':'overhead'},170:{'spine':'sidebend'},171:{'spine':'fold'},172:{},173:{'arms':'prayer'},
 174:{'arms':'cowface'},175:{'arms':'behind'},176:{'legs':'wide'},177:{'spine':'sidebend'},178:{},179:{'chair':'chair','chair_target':'hands'},
 180:{'spine':'twist'},181:{'legs':'pigeon-catch','arms':'pigeon-catch'},182:{'spine':'sidebend'},183:{'arms':'prayer'},
 184:{'spine':'twist'},185:{'spine':'fold'},186:{'arms':'behind'},187:{'arms':'eagle'},188:{'arms':'prayer'},189:{'arms':'eagle'},
 190:{'feet':'tiptoes'},191:{'arms':'prayer'},192:{'spine':'sidebend'},193:{'arms':'overhead'},194:{'spine':'sidebend'},
 195:{'spine':'backbend'},196:{'legs':'crossed'},197:{'legs':'bent'},198:{'arms':'behind','bind':'yes'},199:{'legs':'crossed'},200:{}
}

def points(d):
 a=g.sample(d)
 if d.rstrip().endswith('Z'):a.append(a[0])
 return a

def document(key, paths, head):
 # Fit the actual geometry uniformly; limb/head proportions never change independently.
 pts=[q for p,c in paths for q in p]
 x,y,rx,ry=head
 pts += [[x-rx,y-ry],[x+rx,y+ry]]
 lo=[min(q[i] for q in pts) for i in [0,1]];hi=[max(q[i] for q in pts) for i in [0,1]]
 scale=min(820/max(1,hi[0]-lo[0]),820/max(1,hi[1]-lo[1]))
 def t(q):return {'x':round(512+(q[0]-(lo[0]+hi[0])/2)*scale,3),'y':round(512+(q[1]-(lo[1]+hi[1])/2)*scale,3)}
 elements=[];seen=set()
 def add(kind,p,c='#202820'):
  signature=json.dumps(p)
  if (kind,signature) in seen:return
  seen.add((kind,signature))
  elements.append({'id':str(uuid.uuid5(uuid.NAMESPACE_URL,'ribka-thin:'+key+':'+str(len(elements)))),'kind':kind,'width':round(4.2*scale,3),'rotation':0,'color':c,'points':[t(q) for q in p]})
 # A hollow head remains legible when a bent arm passes behind it.
 # Clip native centre lines at the ellipse; never paint over or thin a raster image.
 for line,c in paths:
  current=[]
  for a,b in zip(line,line[1:]):
   ax,ay=(a[0]-x)/rx,(a[1]-y)/ry;dx,dy=(b[0]-a[0])/rx,(b[1]-a[1])/ry
   aa=dx*dx+dy*dy;bb=2*(ax*dx+ay*dy);cc=ax*ax+ay*ay-1
   cuts=[0,1];disc=bb*bb-4*aa*cc
   if aa>0 and disc>0:
    cuts+= [t for t in [(-bb-math.sqrt(disc))/(2*aa),(-bb+math.sqrt(disc))/(2*aa)] if 0<t<1]
   cuts=sorted(cuts)
   for lo_t,hi_t in zip(cuts,cuts[1:]):
    mid=(lo_t+hi_t)/2
    if (ax+dx*mid)**2+(ay+dy*mid)**2>=1-1e-8:
     q=[[a[0]+(b[0]-a[0])*t,a[1]+(b[1]-a[1])*t] for t in [lo_t,hi_t]]
     if current and math.dist(current[-1],q[0])>0.01:add('pen',current,c);current=[]
     current.extend(q if not current else q[1:])
    elif current:add('pen',current,c);current=[]
  if len(current)>1:add('pen',current,c)
 add('ellipse',[[x-rx,y-ry],[x+rx,y+ry]])
 return {'format':'ribka-drawing','version':2,'width':1024,'height':1024,'smoothing':0,'elements':elements}

catalog=json.loads((ROOT/'resources/imports/asana-icons-200-20261110/catalog.json').read_text())
prior=json.loads((ROOT/'resources/imports/figure-redraw-bold-20261105/manifest.json').read_text())['items']
source=json.loads(gzip.decompress((ROOT/'.runtime/local-redesign-20261115/production.json.gz').read_bytes()))
bykey={a['import_key']:a for a in source['tables']['catalog_asanas']}
records=[]
for item in prior:
 a=bykey[item['source_key']];p=g.PRIOR[item['index']];paths=[]
 for d in [p['torso'],*p['arms'],*p['legs'],p.get('neck','')]:
  if d:paths.append((points(d),'#202820'))
 for extra in p.get('extra',[]):
  if extra.get('d'):paths.append((points(extra['d']),'#202820'))
 for prop in p.get('props',[]):
  if prop.get('d'):paths.append((points(prop['d']),'#55775f'))
 doc=document(a['import_key'],paths,p['head'])
 records.append({'id':a['id'],'key':a['import_key'],'name':a['name'],'family':None,'drawing':doc,'old_asset_id':a['current_image_asset_id'],'original_index':item['index']})
for item in catalog:
 n=item['number'];family=FAMILIES[n-1];base=deepcopy(g.BASE[family]);feature=v.features(item['english'],family)[0];feature.update(OVERRIDES.get(n,{}))
 if family=='low-lunge':base['legs'][1]=[[235,255],[105,455],[25,455]]
 # The reference names describe the base, not an additional generic arm/leg modification.
 if n<=125 and n not in OVERRIDES:feature={}
 if n in [1,3,7,47,61,75,105,106,118,123,125]:feature=OVERRIDES[n].copy()
 p=v.vary(base,feature,family)
 # Pose-specific details which a general variation rule cannot express faithfully.
 if n==47:p['legs'][1]=deepcopy(g.BASE['easyseat']['legs'][1])
 if n==70:p['legs'][1]=[[235,255],[120,360],[25,455],[45,425]]
 if n==40:p['props'].append({'points':[[285,60],[285,440]]})
 if n==49:
  p={'head':[250,85],'torso':[[250,135],[250,315]],'arms':[[[250,135],[170,240],[180,340]],[[250,135],[330,240],[320,340]]],'legs':[[[250,315],[190,410],[140,350]],[[250,315],[310,410],[360,350]]],'props':[]}
 if n==77:p['arms']=[[[250,135],[190,30],[85,135]],[[250,135],[310,30],[85,135]]]
 if n==78:p['arms']=[[[250,135],[335,80],[245,35],[150,80]],[[250,135],[150,180],[85,135]]]
 if n==160:p['arms']=[[[250,135],[160,210],[85,135]],[[250,135],[340,180],[360,270]]]
 if n==99:p['legs']=[[[250,320],[290,360],[220,410]],[[250,320],[210,360],[280,410]]]
 if n==123:p['legs']=[[[250,235],[375,365],[90,430],[40,430]],[[250,235],[360,375],[100,440],[50,440]]]
 if n==127:p['legs']=[[[235,365],[365,230],[430,230],[430,210]],[[235,365],[365,230],[430,230],[430,210]]]
 if n==131:p={'head':[250,85],'torso':[[250,135],[250,370]],'arms':[[[250,135],[120,230]],[[250,135],[380,230]]],'legs':[[[250,370],[55,190],[35,175]],[[250,370],[445,190],[465,175]]],'props':[]}
 if n==143:p['legs']=[[[245,285],[55,435],[30,435]],[[245,285],[330,430],[460,430]]]
 if n in [90,162]:p['arms']=deepcopy(g.BASE['easyseat']['arms']) if n==162 else deepcopy(g.BASE['kneeling']['arms'])
 if n==118:p['arms']=[[[250,390],[175,405],[175,465],[200,465]],[[250,390],[325,405],[325,465],[300,465]]]
 if n==139:p['torso']=[[340,240],[235,100]]
 if n in [150,178]:
  for i,arm in enumerate(p['arms']):
   end=arm[-1];start=arm[0];p['arms'][i]=[start,[end[0]-65,end[1]],end]
 if n in [155,156,158,159]:
  p=deepcopy(g.BASE['locust' if n==155 else 'bow' if n==159 else 'crocodile'])
  if n==155:p['legs'][1]=[[235,350],[45,405],[15,390]]
  if n in [156,158]:
   p['legs']=[[[235,365],[70,405],[70,280]],[[235,365],[70,405],[70,280]]] if n==158 else [[[235,365],[70,405],[70,280]],[[235,365],[35,405],[10,390]]]
   p['arms']=[[[355,350],[210,310],[70,280]],[[355,350],[210,310],[70,280]]] if n==158 else [[[355,350],[210,310],[70,280]],[[355,350],[380,405],[450,405]]]
  if n==159:p['legs'][1]=[[240,350],[35,400],[10,390]];p['arms'][1]=[[355,255],[440,320],[465,330]]
 if n==157:p['arms']=[[[350,265],[310,320],[365,355]],[[350,265],[310,320],[365,355]]]
 if n==200:p['legs'][1]=[[250,315],[270,440],[290,445]];p['arms'][1]=[[250,135],[375,245],[400,330]]
 # Front-facing hand/foot hold II is a different plane from the forward leg hold I.
 if n==106:
  p={'head':[250,85],'torso':[[250,135],[250,315]],'arms':[[[250,135],[80,135]],[[250,135],[390,170],[450,245]]],'legs':[[[250,315],[250,470],[275,470]],[[250,315],[450,245],[475,250]]],'props':[]}
 p=corrected(n,p)
 x,y=p['head'];shoulder=p['torso'][0];distance=math.dist([x,y],shoulder);neck=[]
 if distance>28:neck=[[[x+(shoulder[0]-x)*27/distance,y+(shoulder[1]-y)*27/distance],shoulder]]
 paths=[(q,'#202820') for q in [*neck,p['torso'],*p['legs'],*p['arms']]]
 for prop in p.get('props',[]):
  if prop.get('points'):paths.append((prop['points'],'#55775f'))
 a=bykey['ribka-icons-200-20261110:'+item['slug']]
 records.append({'id':a['id'],'key':a['import_key'],'name':a['name'],'family':family,'features':feature,'drawing':document(a['import_key'],paths,[x,y,27,27]),'old_asset_id':a['current_image_asset_id'],'number':n,'reference':item['source'],'description':item['description']})
assert len(records)==353
for r in records:(OUT/(r['id']+'.json')).write_text(json.dumps(r['drawing'],ensure_ascii=False))
(OUT/'manifest.json').write_text(json.dumps([{k:v for k,v in r.items() if k!='drawing'} for r in records],ensure_ascii=False,indent=2))
print('Prepared',len(records),'individual vector drawings')
