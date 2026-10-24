// One fictional triangle. No imported artwork or third-party model assets.
export function triangleGLB(){
  const binary=Buffer.alloc(44);
  [0,0,0,1,0,0,0,1,0].forEach((value,index)=>binary.writeFloatLE(value,index*4));
  [0,1,2].forEach((value,index)=>binary.writeUInt16LE(value,36+index*2));
  const json=JSON.stringify({asset:{version:'2.0'},scene:0,scenes:[{nodes:[0]}],nodes:[{mesh:0}],meshes:[{primitives:[{attributes:{POSITION:0},indices:1,material:0}]}],materials:[{doubleSided:true,pbrMetallicRoughness:{baseColorFactor:[0.2,0.6,0.9,1],metallicFactor:0,roughnessFactor:0.8}}],buffers:[{byteLength:44}],bufferViews:[{buffer:0,byteOffset:0,byteLength:36,target:34962},{buffer:0,byteOffset:36,byteLength:6,target:34963}],accessors:[{bufferView:0,componentType:5126,count:3,type:'VEC3',min:[0,0,0],max:[1,1,0]},{bufferView:1,componentType:5123,count:3,type:'SCALAR'}]});
  const text=Buffer.from(json+' '.repeat((4-Buffer.byteLength(json)%4)%4));
  const header=Buffer.alloc(20);header.write('glTF');header.writeUInt32LE(2,4);header.writeUInt32LE(28+text.length+binary.length,8);header.writeUInt32LE(text.length,12);header.writeUInt32LE(0x4e4f534a,16);
  const binHeader=Buffer.alloc(8);binHeader.writeUInt32LE(binary.length);binHeader.writeUInt32LE(0x004e4942,4);
  return Buffer.concat([header,text,binHeader,binary]);
}
