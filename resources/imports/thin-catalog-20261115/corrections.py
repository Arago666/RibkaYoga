"""Explicit joints for variations where a generic rotation gives an ambiguous silhouette."""
from copy import deepcopy
from geometry import pose, BASE
from variants import rotate

def corrected(n,p):
 s=p['torso'][0];h=p['torso'][-1]
 if n==7:p['arms']=[[s,[485,220]],[s,[485,220]]]
 if n in [53,199]:
  p=pose([65,310],[[110,310],[270,310]],[[[110,310],[115,155]],[[110,310],[115,455]]],[[[270,310],[290,410],[420,410]],[[270,310],[300,425],[430,425]]],True)
  if n==199:p['legs'][1]=[[270,310],[315,400],[390,360],[420,410]]
 if n in [55,200]:
  p=pose([65,360],[[110,360],[260,360]],[[[110,360],[210,260],[325,180]],[[110,360],[220,280],[350,205]]],[[[260,360],[180,250],[325,180]],[[260,360],[205,275],[350,205]]])
  if n==200:p['legs'][1]=[[260,360],[455,360],[470,335]];p['arms'][1]=[[110,360],[210,405],[260,400]]
 if n==51:
  # Reclined butterfly, viewed from above, laid across the sheet.
  p=deepcopy(BASE['reclined-boundangle'])
  for key in ['torso','arms','legs']:
   if key=='torso':p[key]=[rotate(q,[250,250],-90) for q in p[key]]
   else:p[key]=[[rotate(q,[250,250],-90) for q in line] for line in p[key]]
  p['head']=rotate(p['head'],[250,250],-90)
 if n in [77,78,160]:
  p=deepcopy(BASE['pigeon']);s=p['torso'][0];h=p['torso'][-1]
  p['legs'][1]=[h,[55,350],[85,150]]
  if n==77:p['arms']=[[s,[290,30],[150,70],[85,150]],[s,[190,30],[85,150]]]
  if n==78:p['arms']=[[s,[340,65],[235,40],[180,95]],[s,[160,200],[85,150]]]
  if n==160:p['arms']=[[s,[155,220],[85,150]],[s,[330,280],[345,350]]]
 if n==149:p['arms'][1]=[s,[440,200],[400,170]]
 if n==150:p['arms'][0]=[s,[330,440],[435,440]];p['arms'][1]=[s,[365,70]]
 if n in [153,154]:
  p=deepcopy(BASE['locust']);s=p['torso'][0]
  p['arms']=[[s,[470,225],[500,215]],[s,[470,225],[500,215]]] if n==153 else [[s,[285,240],[180,280]],[s,[290,260],[180,280]]]
 if n in [156,158]:
  p=pose([425,315],[[380,350],[215,350]],[[[380,350],[270,280],[95,235]],[[380,350],[390,410],[470,410]]],[[[215,350],[55,350],[95,235]],[[215,350],[30,370],[5,355]]])
  if n==158:p['legs'][1]=deepcopy(p['legs'][0]);p['arms'][1]=deepcopy(p['arms'][0])
 if n==159:
  p=deepcopy(BASE['bow']);p['legs'][1]=[p['torso'][-1],[25,380],[5,365]];p['arms'][1]=[p['torso'][0],[435,325],[470,330]]
 if n==161:
  p=deepcopy(BASE['camel']);s=p['torso'][0];h=p['torso'][-1];p['arms']=[[s,[275,225],h],[s,[285,250],h]]
 if n in [168,171]:
  p=pose([365,315],[[325,295],[150,310]],[[[325,295],[420,355]],[[325,295],[420,355]]],[[[150,310],[280,390],[120,400],[210,355]],[[150,310],[250,365],[90,390],[180,350]]])
  if n==171:p['arms']=[[p['torso'][0],[225,230],[75,250]],[p['torso'][0],[225,230],[75,250]]]
 if n==172:
  p=deepcopy(BASE['lotus']);s=p['torso'][0];p['arms']=[[s,[175,245],[300,365]],[s,[325,245],[200,365]]]
 if n==174:
  p=deepcopy(BASE['kneeling']);s=p['torso'][0];p['arms']=[[s,[310,65],[260,240]],[s,[175,255],[260,245]]]
 if n==175:
  p=deepcopy(BASE['child']);s=p['torso'][0];p['arms']=[[s,[175,370],[70,375]],[s,[175,370],[70,375]]]
 if n==176:
  p=deepcopy(BASE['child']);h=p['torso'][-1];p['legs'][1]=[h,[180,405],[55,370]]
 if n==178:
  p=deepcopy(BASE['puppy']);s=p['torso'][0];p['arms']=[[s,[365,405],[475,405]],[s,[365,405],[475,405]]]
 if n==181:
  p=deepcopy(BASE['low-lunge']);s=p['torso'][0];h=p['torso'][-1];p['legs'][1]=[h,[105,455],[75,300]];p['arms']=[[s,[155,225],[75,300]],[s,[300,60],[315,20]]]
 if n==185:
  p=deepcopy(BASE['high-lunge']);p['torso']=[[350,260],[235,255]];p['head']=[395,265];p['arms']=[[[350,260],[430,350]],[[350,260],[430,350]]]
 if n==186:
  p=deepcopy(BASE['warrior3']);s=p['torso'][0];p['arms']=[[s,[260,255],[165,270]],[s,[260,255],[165,270]]]
 if n==187:
  p=deepcopy(BASE['warrior2']);s=p['torso'][0];p['arms']=[[s,[195,235],[265,225],[255,150]],[s,[305,230],[245,225],[255,150]]]
 if n==195:
  p=pose([160,145],[[200,175],[245,265],[255,315]],[[[200,175],[275,240],[255,310]],[[200,175],[275,240],[255,310]]],[[[255,315],[230,470],[200,470]],[[255,315],[290,470],[320,470]]])
 # Every limb starts at its actual shoulder/hip, including individually overridden limbs.
 s=p['torso'][0];h=p['torso'][-1]
 for a in p['arms']:a[0]=s.copy()
 for a in p['legs']:a[0]=h.copy()
 return p
