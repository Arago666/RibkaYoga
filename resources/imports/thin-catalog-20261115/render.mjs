import fs from 'node:fs/promises';
import path from 'node:path';
import {createRequire} from 'node:module';
import {createCanvas,Path2D,loadImage} from '/Users/Arago/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/@napi-rs/canvas/index.js';
const root=path.resolve(import.meta.dirname,'../../..');
const require=createRequire(path.join(root,'package.json'));
const ts=require('typescript');
globalThis.Path2D=Path2D;
const code=ts.transpileModule(await fs.readFile(path.join(root,'resources/js/modules/catalog/drawingGeometry.ts'),'utf8'),{compilerOptions:{target:ts.ScriptTarget.ES2022,module:ts.ModuleKind.ES2022}}).outputText;
const {drawElement}=await import(`data:text/javascript;base64,${Buffer.from(code).toString('base64')}`);
const dir=path.join(root,'.runtime/local-redesign-20261115/artwork');
const manifest=JSON.parse(await fs.readFile(path.join(dir,'manifest.json'),'utf8'));
for(const r of manifest){
 const doc=JSON.parse(await fs.readFile(path.join(dir,`${r.id}.json`),'utf8'));
 const c=createCanvas(1024,1024),ctx=c.getContext('2d');ctx.fillStyle='white';ctx.fillRect(0,0,1024,1024);
 for(const e of doc.elements)drawElement(ctx,e,doc.smoothing);
 await fs.writeFile(path.join(dir,`${r.id}.png`),await c.encode('png'));
}
for(let page=0;page<Math.ceil(manifest.length/42);page++){
 const records=manifest.slice(page*42,(page+1)*42),c=createCanvas(1200,1610),ctx=c.getContext('2d');ctx.fillStyle='#f3f5f1';ctx.fillRect(0,0,1200,1610);
 for(let i=0;i<records.length;i++){
  const r=records[i],x=(i%6)*200,y=Math.floor(i/6)*230;ctx.fillStyle='white';ctx.fillRect(x+3,y+3,194,224);
  const img=await loadImage(path.join(dir,`${r.id}.png`));ctx.drawImage(img,x+15,y+6,170,170);
  ctx.fillStyle='#16291b';ctx.font='12px sans-serif';ctx.fillText(`${page*42+i+1} · ${r.number??'Лист '+r.original_index}`,x+9,y+188);
  const text=r.name;let line='',lineNo=0;
  for(const word of text.split(' ')){if(ctx.measureText(line+' '+word).width>184){ctx.fillText(line,x+9,y+204+lineNo*13);lineNo++;line=word;if(lineNo>1)break;}else line+=(line?' ':'')+word;}
  if(lineNo<2)ctx.fillText(line,x+9,y+204+lineNo*13);
 }
 await fs.writeFile(path.join(dir,`contact-${page+1}.jpg`),await c.encode('jpeg',85));
}
console.log(`Rendered ${manifest.length} PNGs and 9 contact sheets`);
