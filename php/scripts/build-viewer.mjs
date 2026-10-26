import { build } from 'esbuild';
import { readFileSync,writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const result=await build({entryPoints:[path.join(root,'viewer/model-viewer.mjs'),path.join(root,'viewer/model-upload.mjs')],outdir:path.join(root,'public/assets'),bundle:true,minify:true,format:'esm',target:'es2022',legalComments:'external',write:false});
for(const file of result.outputFiles){
  if(process.argv.includes('--check')){
    if(!readFileSync(file.path).equals(Buffer.from(file.contents)))throw new Error('Rebuild the model viewer with npm run build:viewer');
  }else writeFileSync(file.path,file.contents);
}
console.log('Model viewer bundle '+(process.argv.includes('--check')?'matches source.':'built.'));
